<?php
/**
 * VPN Server Management Class
 * Handles deployment and management of Amnezia VPN servers
 * Based on amnezia_deploy_v2.php
 */
class VpnServer
{
    private $serverId;
    private $data;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
        if ($serverId) {
            $this->load();
        }
    }

    /**
     * Load server data from database
     */
    private function load(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ?');
        $stmt->execute([$this->serverId]);
        $this->data = $stmt->fetch();
        if (!$this->data) {
            throw new Exception('Server not found');
        }

        // Decode JSON parameters
        if (isset($this->data['awg_params']) && is_string($this->data['awg_params'])) {
            $this->data['awg_params'] = json_decode($this->data['awg_params'], true);
        }
    }

    /**
     * Create new VPN server in database
     */
    public static function create(array $data): int
    {
        $pdo = DB::conn();

        // Validate required fields
        $required = ['user_id', 'name', 'host', 'port', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }

        $stmt = $pdo->prepare('
            INSERT INTO vpn_servers 
            (user_id, name, host, port, username, password, container_name, vpn_port, vpn_subnet, awg_params, status, deployed_at, last_check_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
        ');

        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['container_name'] ?? 'nk-awg-v2',
            $data['vpn_port'] ?? NULL,
            $data['vpn_subnet'] ?? '10.8.1.0/24',
            json_encode(['mimicry_type' => $data['mimicry_type'] ?? 'quic']),
            'deploying'
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Deploy VPN server using amnezia_deploy_v2.php logic
     */
    public function deploy(?string $panelUrl = null): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        // Disable PHP timeout for long builds (Go compilation takes several minutes)
        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $pdo = DB::conn();
        $errors = [];

        try {
            // Update status to deploying
            $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
                ->execute(['deploying', $this->serverId]);

            // Test SSH connection
            if (!$this->testConnection()) {
                throw new Exception('SSH connection failed');
            }

            // Install Docker if needed
            $this->installDocker();

            // Install AmneziaWG kernel module on host for high performance
            $this->installKernelModule();

            // Create directories
            $this->executeCommand('mkdir -p /opt/amnezia/nk-awg-v2', true);

            // Find free UDP port
            $vpnPort = $this->findFreeUdpPort();

            // Create Dockerfile
            $this->createDockerfile();

            // Create start script
            $this->createStartScript();

            // Build Docker image
            $this->buildDockerImage();

            // Run container
            $this->runContainer($vpnPort);

            // Allow UDP port on host
            $this->executeCommand("iptables -A INPUT -p udp --dport {$vpnPort} -j ACCEPT 2>/dev/null || true", true);

            // Enable IP forwarding on host
            $this->executeCommand("sysctl -w net.ipv4.ip_forward=1", true);
            $this->executeCommand("echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf", true);

            // Initialize server config
            $keys = $this->initializeServerConfig($vpnPort);

            // Update database with deployment info
            $stmt = $pdo->prepare('
                UPDATE vpn_servers 
                SET vpn_port = ?, 
                    server_public_key = ?, 
                    preshared_key = ?, 
                    awg_params = ?,
                    status = ?,
                    deployed_at = NOW(),
                    error_message = NULL
                WHERE id = ?
            ');

            $stmt->execute([
                $vpnPort,
                $keys['public_key'],
                $keys['preshared_key'],
                json_encode($keys['awg_params']),
                'active',
                $this->serverId
            ]);

            // Reload data
            $this->load();

            // Sync all clients to the container
            $this->syncAllClientsToContainer();

            // Deploy monitoring agent if panelUrl is provided
            if ($panelUrl) {
                try {
                    $this->deployMonitoringAgent($panelUrl);
                } catch (Exception $e) {
                    // Log warning but don't fail deployment since VPN is already running
                    error_log("Failed to deploy monitoring agent: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'vpn_port' => $vpnPort,
                'public_key' => $keys['public_key']
            ];

        } catch (Exception $e) {
            // Update status to error
            $pdo->prepare('UPDATE vpn_servers SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['error', $e->getMessage(), $this->serverId]);

            throw $e;
        }
    }

    /**
     * Test SSH connection to server
     */
    private function testConnection(): bool
    {
        $testCommand = sprintf(
            "SSHPASS='%s' sshpass -e ssh -p %d -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 %s@%s 'echo test' 2>/dev/null",
            str_replace("'", "'\\''", $this->data['password']),
            $this->data['port'],
            $this->data['username'],
            $this->data['host']
        );

        $result = shell_exec($testCommand);
        return trim($result) === 'test';
    }

    /**
     * Execute command on remote server and return output.
     * Throws an exception if the command exits non-zero.
     */
    public function executeCommand(string $command, bool $sudo = false, bool $checkExit = false): string
    {
        if ($sudo && strtolower($this->data['username']) !== 'root') {
            $command = "echo '{$this->data['password']}' | sudo -S " . $command;
        }

        // Capture both stdout and exit code
        $wrappedCommand = $command . '; echo "__EXIT_CODE__:$?"';
        $escapedCommand = escapeshellarg($wrappedCommand);
        $sshCommand = sprintf(
            "SSHPASS='%s' sshpass -e ssh -p %d -q -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ServerAliveInterval=30 -o ServerAliveCountMax=20 %s@%s %s 2>&1",
            str_replace("'", "'\\''", $this->data['password']),
            $this->data['port'],
            $this->data['username'],
            $this->data['host'],
            $escapedCommand
        );

        $rawOutput = shell_exec($sshCommand) ?? '';

        // Split exit code marker
        if (preg_match('/^(.*?)__EXIT_CODE__:(\d+)\s*$/s', $rawOutput, $m)) {
            $output = $m[1];
            $exitCode = (int) $m[2];
        } else {
            $output = $rawOutput;
            $exitCode = 0;
        }

        if ($checkExit && $exitCode !== 0) {
            throw new Exception("Remote command failed (exit {$exitCode}): " . trim(substr($output, -500)));
        }

        return $output;
    }

    /**
     * Install AmneziaWG kernel module on remote host (Ubuntu/Debian)
     */
    private function installKernelModule(): void
    {
        // Check if already loaded
        $check = $this->executeCommand('lsmod | grep -c amneziawg');
        if (trim($check) === '1') {
            return; // Already loaded
        }

        // Check for apt-get (Debian/Ubuntu)
        $hasApt = $this->executeCommand('which apt-get');
        if (empty(trim($hasApt))) {
            return; // Not a debian-based system, skip kernel module (will fallback to userspace)
        }

        // Install common dependencies
        $this->executeCommand('apt-get update', true);
        $this->executeCommand('apt-get install -y gnupg2 ca-certificates dkms', true);

        // Handle XanMod headers
        $uname = $this->executeCommand('uname -r');
        if (stripos($uname, 'xanmod') !== false) {
            // Find specific xanmod headers
            $this->executeCommand('apt-get install -y linux-headers-xanmod-edge || apt-get install -y linux-headers-xanmod-lts || apt-get install -y linux-headers-xanmod', true);
        } else {
            $this->executeCommand('apt-get install -y linux-headers-$(uname -r)', true);
        }

        // Manual PPA addition (Works on both Debian and Ubuntu)
        $this->executeCommand('apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 57290828', true);
        $ppaUrl = "https://ppa.launchpadcontent.net/amnezia/ppa/ubuntu focal main";
        $this->executeCommand("echo \"deb {$ppaUrl}\" | tee /etc/apt/sources.list.d/amnezia.list", true);
        $this->executeCommand("echo \"deb-src {$ppaUrl}\" | tee -a /etc/apt/sources.list.d/amnezia.list", true);

        $this->executeCommand('apt-get update', true);

        // Install amneziawg (DKMS)
        $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -y amneziawg', true);

        // Load module
        $this->executeCommand('modprobe amneziawg', true);

        // Final verify
        $checkFinal = $this->executeCommand('lsmod | grep -c amneziawg');
        if (trim($checkFinal) !== '1') {
            // Log warning but don't stop deployment as userspace fallback exists
            $pdo = DB::conn();
            $pdo->prepare('UPDATE vpn_servers SET error_message = ? WHERE id = ?')
                ->execute(['Warning: AmneziaWG kernel module failed to install/load. Using slow userspace fallback.', $this->serverId]);
        }
    }

    /**
     * Install Docker on remote server
     */
    private function installDocker(): void
    {
        $dockerVersion = $this->executeCommand('docker --version');
        if (stripos($dockerVersion, 'version') !== false) {
            return; // Docker already installed
        }

        $this->executeCommand('curl -fsSL https://get.docker.com | sh', true);
        $this->executeCommand('systemctl enable --now docker', true);
    }

    /**
     * Find free UDP port on remote server
     */
    private function findFreeUdpPort(): int
    {
        $min = 30000;
        $max = 65000;

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $candidate = random_int($min, $max);
            $cmd = "ss -lun | awk '{print \$4}' | grep -E ':(" . $candidate . ")($| )' || true";
            $out = $this->executeCommand($cmd, false);
            if (trim($out) === '') {
                return $candidate;
            }
        }

        throw new Exception('Could not find free UDP port');
    }

    /**
     * Create Dockerfile on remote server
     */
    private function createDockerfile(): void
    {
        $dockerfile = <<<DOCKERFILE
# Stage 1: Build amneziawg-go and amneziawg-tools
FROM golang:alpine AS builder
RUN apk add --no-cache git make build-base bash libmnl-dev pkgconfig

ARG AMNEZIAWG_GO_REF=master
ARG AMNEZIAWG_TOOLS_REF=v1.0.20260223

# Build amneziawg-go
RUN git clone --depth 1 --branch \${AMNEZIAWG_GO_REF} https://github.com/amnezia-vpn/amneziawg-go.git /build/amneziawg-go && \
    cd /build/amneziawg-go && \
    make && \
    cp amneziawg-go /usr/local/bin/

# Build amneziawg-tools
RUN git clone --depth 1 --branch \${AMNEZIAWG_TOOLS_REF} https://github.com/amnezia-vpn/amneziawg-tools.git /build/amneziawg-tools && \
    cd /build/amneziawg-tools/src && \
    make && \
    make install PREFIX=/usr

# Stage 2: Final Image
FROM alpine:latest
RUN apk add --no-cache bash iptables iproute2 coreutils dumb-init libmnl

# Copy binaries from builder
COPY --from=builder /usr/local/bin/amneziawg-go /usr/local/bin/
COPY --from=builder /usr/bin/awg /usr/local/bin/
COPY --from=builder /usr/bin/awg-quick /usr/local/bin/

# Create necessary directories
RUN mkdir -p /opt/amnezia/awg /etc/amnezia/awg /var/run/amneziawg

# Copy start script
COPY start.sh /opt/amnezia/start.sh
RUN chmod +x /opt/amnezia/start.sh

WORKDIR /opt/amnezia
ENTRYPOINT [ "dumb-init", "/opt/amnezia/start.sh" ]
DOCKERFILE;

        $base64 = base64_encode(trim($dockerfile));
        $this->executeCommand("echo \"{$base64}\" | base64 -d > /opt/amnezia/nk-awg-v2/Dockerfile", true);
    }

    /**
     * Create start script on remote server
     */
    private function createStartScript(): void
    {
        $subnet = $this->data['vpn_subnet'] ?? '10.8.1.0/24';
        $script = <<<BASH
#!/bin/bash
################# старт файла start.sh
echo "Container startup"

# Wait for config if not exists yet
for i in {1..30}; do
    if [ -f /opt/amnezia/awg/wg0.conf ]; then
        break
    fi
    sleep 1
done

# Kill daemons in case of restart
/usr/local/bin/awg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || true

# Start WireGuard
if [ -f /opt/amnezia/awg/wg0.conf ]; then
    export WG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go
    export WG_SUDO=1
    /usr/local/bin/awg-quick up /opt/amnezia/awg/wg0.conf
    echo "WireGuard started"
else
    echo "No wg0.conf found, skipping WireGuard startup"
fi

# Allow traffic on the TUN interface
iptables -A INPUT -i wg0 -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true
iptables -A OUTPUT -o wg0 -j ACCEPT 2>/dev/null || true

# Allow forwarding traffic only from the VPN
iptables -A FORWARD -i wg0 -o eth0 -s {$subnet} -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -o eth1 -s {$subnet} -j ACCEPT 2>/dev/null || true

# State tracking rules
iptables -A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true

# NAT rules
iptables -t nat -A POSTROUTING -s {$subnet} -o eth0 -j MASQUERADE 2>/dev/null || true
iptables -t nat -A POSTROUTING -s {$subnet} -o eth1 -j MASQUERADE 2>/dev/null || true

# MSS Clamping - CRITICAL for websites loading via VPN
iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true

tail -f /dev/null
#################
BASH;

        $base64 = base64_encode(trim($script));
        $this->executeCommand("echo '{$base64}' | base64 -d > /opt/amnezia/nk-awg-v2/start.sh", true);
        $this->executeCommand("chmod +x /opt/amnezia/nk-awg-v2/start.sh", true);
    }

    /**
     * Build Docker image
     */
    private function buildDockerImage(): void
    {
        $containerName = $this->data['container_name'];

        // Cleanup old container/image
        $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
        $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
        $this->executeCommand("docker rmi {$containerName} 2>/dev/null || true", true);

        // Build new image — Go compilation can take 5-10 minutes
        $buildCmd = sprintf(
            'docker build --no-cache --pull -t %s /opt/amnezia/nk-awg-v2 2>&1',
            $containerName
        );
        $buildOutput = $this->executeCommand($buildCmd, true, true);

        // Verify the image was actually created
        $check = trim($this->executeCommand("docker image inspect {$containerName} --format='{{.Id}}' 2>/dev/null", true));
        if (empty($check)) {
            throw new Exception('Docker image build failed. Build output: ' . substr($buildOutput, -1000));
        }
    }

    /**
     * Run Docker container
     */
    private function runContainer(int $vpnPort): void
    {
        $containerName = $this->data['container_name'];

        $runCmd = sprintf(
            'docker run -d --restart always --privileged --cap-add=NET_ADMIN --cap-add=SYS_MODULE -p %d:%d/udp -v /lib/modules:/lib/modules -e WG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go -e WG_THREADS=4 --name %s %s 2>&1',
            $vpnPort,
            $vpnPort,
            $containerName,
            $containerName
        );

        $runOutput = $this->executeCommand($runCmd, true, true);
        sleep(5); // Wait for container to start

        // Verify container is running
        $state = trim($this->executeCommand("docker inspect --format='{{.State.Running}}' {$containerName} 2>/dev/null", true));
        if ($state !== 'true') {
            $logs = $this->executeCommand("docker logs --tail 30 {$containerName} 2>&1", true);
            throw new Exception("Container failed to start. Logs: " . $logs);
        }
    }

    /**
     * Get default mimicry presets for AWG 2.0
     */
public static function getMimicryPresets(): array
    {
        return [
            'none' => [],
            'quic' => [
                'I1' => '<b 0xc700000001><rc 8><t><r 80>',
                'I2' => '<b 0xf6ab3267fa><t><rc 12><r 64>'
            ],
            'dns' => [
                'I1' => '<b 0x123401000001000000000000><rd 4><b 0x03636f6d0000010001><t><r 32>'
            ],
            'stun' => [
                'I1' => '<b 0x000100002112a442><r 12><t><r 48>'
            ],
            'sip' => [
                'I1' => '<b 0x4f5054494f4e53207369703a><rc 12><b 0x205349502f322e300d0a><t><r 40>'
            ],
        ];
    }

    /**
     * Initialize server configuration with AWG parameters
     */
    private function initializeServerConfig(int $vpnPort): array
    {
        $containerName = $this->data['container_name'];

        // Create directory
        $this->executeCommand("docker exec -i {$containerName} mkdir -p /opt/amnezia/awg", true);

        $pdo = DB::conn();

        if (!empty($this->data['server_private_key'])) {
            // Restore existing keys
            $privKey = trim($this->data['server_private_key']);
            $psk = trim($this->data['preshared_key']);

            $this->executeCommand("echo \"{$privKey}\" | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/server_private.key'", true);
            $this->executeCommand("echo \"{$psk}\" | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/wireguard_psk.key'", true);
            $this->executeCommand("docker exec -i {$containerName} sh -c 'cat /opt/amnezia/awg/server_private.key | /usr/local/bin/awg pubkey > /opt/amnezia/awg/wireguard_server_public_key.key'", true);
            $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true);
            
            $pubKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
            
            // Securely clear private key from DB since it is successfully deployed
            $pdo->prepare("UPDATE vpn_servers SET server_private_key = NULL WHERE id = ?")->execute([$this->serverId]);
        } else {
            // Generate keys
            $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && umask 077 && /usr/local/bin/awg genkey | tee server_private.key | /usr/local/bin/awg pubkey > wireguard_server_public_key.key'", true, true);
            $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && /usr/local/bin/awg genpsk > wireguard_psk.key'", true, true);
            $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true, true);
            
            $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key", true));
            $pubKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
            $psk = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_psk.key", true));
        }

        if (empty($privKey) || empty($pubKey) || empty($psk)) {
            throw new Exception('Key generation failed inside container — private/public/psk key is empty.');
        }

        // Decode selected mimicry type
        $params = $this->data['awg_params'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?: [];
        }
        $mimicryType = $params['mimicry_type'] ?? 'quic';

        // Load mimicry payloads if needed
        $mimicry = [];
        if ($mimicryType === 'quic') {
            $mimicry = $this->getDynamicQuicPayloads();
        }
        if (empty($mimicry)) {
            $mimicry = $this->getMimicryPreset();
        }

        // Junk packet profile tuned for stable DPI blur with low overhead.
        // Note: We keep Jmin >= 64 for AWG compatibility.
        $jmin = 64;
        $jmax = random_int(max($jmin + 1, 70), 80);

        if ($mimicryType === 'none') {
            // Standard AWG V1: Single integers for H1-H4, no S3/S4, no I1-I5 payload mimicry.
            $headers = [];
            $used = [];
            foreach (['H1', 'H2', 'H3', 'H4'] as $key) {
                do {
                    $val = random_int(100000000, 2000000000);
                } while (in_array($val, $used));
                $used[] = $val;
                $headers[$key] = $val;
            }

            $awgParams = [
                'mimicry_type' => 'none',
                'Jc' => random_int(3, 5),
                'Jmin' => $jmin,
                'Jmax' => $jmax,
                'S1' => rand(0, 64),
                'S2' => rand(0, 64),
                'H1' => $headers['H1'],
                'H2' => $headers['H2'],
                'H3' => $headers['H3'],
                'H4' => $headers['H4']
            ];
        } else {
            // AWG 2.0: Header ranges for H1-H4, S3/S4, and payload mimicry
            $headerRanges = $this->generateNonOverlappingHeaderRanges();
            $awgParams = array_merge([
                'mimicry_type' => $mimicryType,
                'Jc' => random_int(3, 5),
                'Jmin' => $jmin,
                'Jmax' => $jmax,
                'S1' => rand(0, 64),
                'S2' => rand(0, 64),
                'S3' => rand(0, 64),
                'S4' => rand(0, 32),
                'H1' => $headerRanges['H1'],
                'H2' => $headerRanges['H2'],
                'H3' => $headerRanges['H3'],
                'H4' => $headerRanges['H4']
            ], $mimicry);
        }

        // Create wg0.conf
        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";

        // Use .1 as the server IP for the interface
        $subnetBase = preg_replace('/\.\d+\/\d+$/', '', $this->data['vpn_subnet']);
        $wgConfig .= "Address = {$subnetBase}.1/24\n";
        $wgConfig .= "ListenPort = {$vpnPort}\n";
        $wgConfig .= "MTU = 1280\n";

        foreach ($awgParams as $key => $value) {
            if (empty($value) || $key === 'mimicry_type')
                continue;
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        $base64 = base64_encode($wgConfig);
        $this->executeCommand("echo \"{$base64}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf'", true);
        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true);

        // Create clientsTable
        $this->executeCommand("docker exec -i {$containerName} sh -c 'echo \"[]\" > /opt/amnezia/awg/clientsTable'", true);

        // The start.sh script in the container is already looping and waiting for wg0.conf.
        // Wait for wg0 and fail deployment if interface never appears.
        $wgReady = false;
        for ($i = 0; $i < 10; $i++) {
            $check = $this->executeCommand("docker exec -i {$containerName} ip link show wg0 2>/dev/null | grep -c wg0", true);
            if (trim($check) === '1') {
                $wgReady = true;
                break;
            }
            sleep(1);
        }
        if (!$wgReady) {
            $logs = trim($this->executeCommand("docker logs --tail 60 {$containerName} 2>&1", true));
            $show = trim($this->executeCommand("docker exec -i {$containerName} sh -c '/usr/local/bin/awg show 2>&1 || true'", true));
            throw new Exception('wg0 interface failed to start. awg-quick likely rejected config. '
                . 'Container logs: ' . substr($logs, -800) . ' | awg show: ' . substr($show, -400));
        }

        // Apply firewall rules
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A INPUT -i wg0 -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A OUTPUT -o wg0 -j ACCEPT 2>/dev/null || true'", true);
        $subnet = $this->data['vpn_subnet'] ?: '10.8.1.0/24';
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -A FORWARD -i wg0 -o eth0 -s {$subnet} -j ACCEPT 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -t nat -A POSTROUTING -s {$subnet} -o eth0 -j MASQUERADE 2>/dev/null || true'", true);
        $this->executeCommand("docker exec -i {$containerName} sh -c 'iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || true'", true);

        sleep(2);

        return [
            'public_key' => $pubKey,
            'preshared_key' => $psk,
            'awg_params' => $awgParams
        ];
    }

    /**
     * Get dynamic QUIC payloads for CPS
     */
    private function getDynamicQuicPayloads(): array
    {
        $filePath = dirname(__DIR__) . '/quic-example.txt';
        if (!file_exists($filePath)) {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $payloads = [];
        foreach ($lines as $line) {
            // Format can be "name: 0xHEX" or just hex
            if (preg_match('/:\s*(0x[0-9a-fA-F]+)/', $line, $matches)) {
                $payloads[] = $matches[1];
            } elseif (preg_match('/(0x[0-9a-fA-F]+)/', $line, $matches)) {
                $payloads[] = $matches[1];
            }
        }

        if (empty($payloads)) {
            return [];
        }

        shuffle($payloads);
        $selected = array_slice($payloads, 0, min(2, count($payloads)));

        $params = [];
        for ($i = 0; $i < count($selected); $i++) {
            $key = 'I' . ($i + 1);
            $hex = strtolower(trim($selected[$i]));
            $hex = preg_replace('/^0x/', '', $hex);
            $hex = preg_replace('/[^0-9a-f]/', '', $hex);
            if (strlen($hex) < 20) {
                continue;
            }

            // Keep CPS packets compact to avoid fragmentation / send failures.
            $maxHexChars = 320; // 160 bytes
            $hex = substr($hex, 0, $maxHexChars);
            if ((strlen($hex) % 2) !== 0) {
                $hex = substr($hex, 0, -1);
            }

            $params[$key] = "<b 0x{$hex}><t><r 48>";
        }

        return $params;
    }

    /**
     * Generate random non-overlapping header ranges for H1-H4
     * Each range is within 32-bit unsigned int bounds (0 - 4,294,967,295)
     */
    private function generateNonOverlappingHeaderRanges(): array
    {
        $max32 = 4294967295;
        $ranges = [];
        $cursor = random_int(100000000, 300000000);

        $hKeys = ['H1', 'H2', 'H3', 'H4'];
        foreach ($hKeys as $index => $key) {
            $rangeSize = random_int(64, 4096);
            $remaining = count($hKeys) - $index - 1;

            // Keep enough room for remaining ranges + separators inside uint32 bounds.
            $maxStart = $max32 - (($rangeSize + 50000) * ($remaining + 1));
            if ($cursor > $maxStart) {
                $cursor = max(1, $maxStart);
            }

            $min = $cursor;
            $max = $min + $rangeSize;
            $ranges[$key] = "{$min}-{$max}";

            // Explicit gap to guarantee non-overlap between H1-H4 ranges.
            $cursor = $max + random_int(50000, 5000000);
        }

        return $ranges;
    }

    /**
     * Get mimicry preset based on server data or default
     */
    private function getMimicryPreset(): array
    {
        $params = $this->data['awg_params'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?: [];
        }
        $type = $params['mimicry_type'] ?? 'quic';
        $presets = self::getMimicryPresets();
        return $presets[$type] ?? $presets['quic'];
    }

    /**
     * Get server status from database
     */
    public function getStatus(): string
    {
        return $this->data['status'] ?? 'unknown';
    }

    /**
     * Get all servers for a user
     */
    public static function listByUser(int $userId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   COUNT(c.id) as client_count,
                   COALESCE(SUM(IF(c.status = 'active', c.speed_up_kbps, 0)), 0) as speed_up_kbps,
                   COALESCE(SUM(IF(c.status = 'active', c.speed_down_kbps, 0)), 0) as speed_down_kbps
            FROM vpn_servers s 
            LEFT JOIN vpn_clients c ON s.id = c.server_id 
            WHERE s.user_id = ? 
            GROUP BY s.id 
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all servers (admin only)
     */
    public static function listAll(): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->query("
            SELECT s.*, 
                   ANY_VALUE(u.email) as user_email, 
                   COUNT(c.id) as client_count,
                   COALESCE(SUM(IF(c.status = 'active', c.speed_up_kbps, 0)), 0) as speed_up_kbps,
                   COALESCE(SUM(IF(c.status = 'active', c.speed_down_kbps, 0)), 0) as speed_down_kbps
            FROM vpn_servers s 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN vpn_clients c ON s.id = c.server_id 
            GROUP BY s.id 
            ORDER BY s.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Delete server
     */
    public function delete(): bool
    {
        // Stop and remove container
        try {
            $containerName = $this->data['container_name'];
            $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("rm -rf /opt/amnezia/nk-awg-v2", true);
        } catch (Exception $e) {
            // Ignore errors during cleanup
        }

        // Delete from database
        $pdo = DB::conn();
        $stmt = $pdo->prepare('DELETE FROM vpn_servers WHERE id = ?');
        return $stmt->execute([$this->serverId]);
    }

    /**
     * Get server data
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * Create backup of server configuration and all clients
     * 
     * @param int $userId User who creates the backup
     * @param string $backupType Type: 'manual' or 'automatic'
     * @return int Backup ID
     */
    public function createBackup(int $userId, string $backupType = 'manual'): int
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();
        $backupName = 'backup_' . $this->serverId . '_' . date('Y-m-d_His') . '.json';
        $backupDir = '/var/www/html/backups';
        $backupPath = $backupDir . '/' . $backupName;

        // Create backups directory if not exists
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        try {
            // Get all clients for this server
            $stmt = $pdo->prepare('
                SELECT id, name, client_ip, public_key, private_key, preshared_key, 
                       config, status, expires_at, created_at
                FROM vpn_clients 
                WHERE server_id = ?
            ');
            $stmt->execute([$this->serverId]);
            $clients = $stmt->fetchAll();

            // Prepare backup data
            $backupData = [
                'server' => [
                    'name' => $this->data['name'],
                    'host' => $this->data['host'],
                    'port' => $this->data['port'],
                    'vpn_port' => $this->data['vpn_port'],
                    'vpn_subnet' => $this->data['vpn_subnet'],
                    'container_name' => $this->data['container_name'],
                    'server_public_key' => $this->data['server_public_key'],
                    'preshared_key' => $this->data['preshared_key'],
                    'awg_params' => $this->data['awg_params'],
                ],
                'clients' => $clients,
                'backup_date' => date('Y-m-d H:i:s'),
                'version' => '1.0'
            ];

            // Write backup to file
            $json = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($backupPath, $json);

            $backupSize = filesize($backupPath);

            // Insert backup record
            $stmt = $pdo->prepare('
                INSERT INTO server_backups 
                (server_id, backup_name, backup_path, backup_size, clients_count, backup_type, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $stmt->execute([
                $this->serverId,
                $backupName,
                $backupPath,
                $backupSize,
                count($clients),
                $backupType,
                'completed',
                $userId
            ]);

            return (int) $pdo->lastInsertId();

        } catch (Exception $e) {
            // Mark backup as failed
            if (isset($stmt)) {
                $stmt = $pdo->prepare('
                    INSERT INTO server_backups 
                    (server_id, backup_name, backup_path, backup_type, status, error_message, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');

                $stmt->execute([
                    $this->serverId,
                    $backupName,
                    $backupPath,
                    $backupType,
                    'failed',
                    $e->getMessage(),
                    $userId
                ]);
            }

            throw $e;
        }
    }

    /**
     * List all backups for this server
     * 
     * @return array List of backups
     */
    public function listBackups(): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('
            SELECT b.*, u.name as created_by_name, u.email as created_by_email
            FROM server_backups b
            LEFT JOIN users u ON b.created_by = u.id
            WHERE b.server_id = ?
            ORDER BY b.created_at DESC
        ');
        $stmt->execute([$this->serverId]);
        return $stmt->fetchAll();
    }

    /**
     * Restore server from backup
     * Note: This only restores client configurations to database
     * Server must already be deployed
     * 
     * @param int $backupId Backup ID
     * @return array Restoration results
     */
    public function restoreBackup(int $backupId): array
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        if ($this->data['status'] !== 'active') {
            throw new Exception('Server must be active to restore backup');
        }

        $pdo = DB::conn();

        // Get backup record
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ? AND server_id = ?');
        $stmt->execute([$backupId, $this->serverId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            throw new Exception('Backup not found');
        }

        if (!file_exists($backup['backup_path'])) {
            throw new Exception('Backup file not found');
        }

        // Read backup data
        $backupData = json_decode(file_get_contents($backup['backup_path']), true);

        if (!$backupData || !isset($backupData['clients'])) {
            throw new Exception('Invalid backup format');
        }

        $restored = 0;
        $failed = 0;
        $errors = [];

        foreach ($backupData['clients'] as $clientData) {
            try {
                // Check if client already exists by IP
                $stmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?');
                $stmt->execute([$this->serverId, $clientData['client_ip']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $errors[] = "Client {$clientData['name']} already exists";
                    $failed++;
                    continue;
                }

                // Insert client
                $stmt = $pdo->prepare('
                    INSERT INTO vpn_clients 
                    (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, 
                     config, status, expires_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $stmt->execute([
                    $this->serverId,
                    $this->data['user_id'],
                    $clientData['name'],
                    $clientData['client_ip'],
                    $clientData['public_key'],
                    $clientData['private_key'],
                    $clientData['preshared_key'],
                    $clientData['config'],
                    'disabled', // Restore as disabled for safety
                    $clientData['expires_at']
                ]);

                // Add client to server container
                VpnClient::addClientToServer($this->data, $clientData['public_key'], $clientData['client_ip']);

                $restored++;

            } catch (Exception $e) {
                $failed++;
                $errors[] = "Failed to restore {$clientData['name']}: " . $e->getMessage();
            }
        }

        return [
            'success' => true, // Always success if process completed
            'restored' => $restored,
            'failed' => $failed,
            'total' => count($backupData['clients']),
            'errors' => $errors,
            'message' => $restored > 0 ? "Restored $restored clients" : "No clients restored"
        ];
    }

    /**
     * Delete backup
     * 
     * @param int $backupId Backup ID
     * @return bool Success
     */
    public static function deleteBackup(int $backupId): bool
    {
        $pdo = DB::conn();

        // Get backup path
        $stmt = $pdo->prepare('SELECT backup_path FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return false;
        }

        // Delete file
        if (file_exists($backup['backup_path'])) {
            unlink($backup['backup_path']);
        }

        // Delete record
        $stmt = $pdo->prepare('DELETE FROM server_backups WHERE id = ?');
        return $stmt->execute([$backupId]);
    }

    /**
     * Get backup by ID
     * 
     * @param int $backupId Backup ID
     * @return array|null Backup data
     */
    public static function getBackup(int $backupId): ?array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Deploy monitoring agent script and systemd service on remote server
     */
    public function deployMonitoringAgent(string $panelUrl): void
    {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $containerName = $this->data['container_name'] ?: 'nk-awg-v2';
        $token = $this->data['secret_token'] ?? null;
        
        if (empty($token)) {
            // Generate token if not exists
            $token = bin2hex(random_bytes(32));
            $pdo = DB::conn();
            $pdo->prepare('UPDATE vpn_servers SET secret_token = ? WHERE id = ?')
                ->execute([$token, $this->serverId]);
            $this->data['secret_token'] = $token;
        }

        // Install curl on remote host if missing
        $this->executeCommand("apt-get update && apt-get install -y curl || true", true);

        // Generate script content
        $scriptContent = $this->generateMonitorScript($token, $panelUrl, $containerName);

        // Upload script
        $base64Script = base64_encode($scriptContent);
        $this->executeCommand("mkdir -p /opt/amnezia", true);
        $this->executeCommand("echo '{$base64Script}' | base64 -d > /opt/amnezia/nk-monitor.sh", true);
        $this->executeCommand("chmod +x /opt/amnezia/nk-monitor.sh", true);

        // Upload systemd service
        $serviceContent = <<<INI
[Unit]
Description=Nk VPN Panel Monitoring Agent
After=network.target docker.service
Requires=docker.service

[Service]
Type=simple
ExecStart=/bin/bash /opt/amnezia/nk-monitor.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
INI;

        $base64Service = base64_encode($serviceContent);
        $this->executeCommand("echo '{$base64Service}' | base64 -d > /etc/systemd/system/nk-monitor.service", true);
        
        // Reload systemd and start service
        $this->executeCommand("systemctl daemon-reload", true);
        $this->executeCommand("systemctl enable nk-monitor.service", true);
        $this->executeCommand("systemctl restart nk-monitor.service", true);
    }

    /**
     * Generate the monitor script template
     */
    private function generateMonitorScript(string $token, string $panelUrl, string $containerName): string
    {
        return <<<BASH
#!/bin/bash

# Configuration
TOKEN="{$token}"
PANEL_URL="{$panelUrl}"
CONTAINER_NAME="{$containerName}"
INTERVAL=30

# Clean up function
cleanup() {
    echo "Stopping nk-monitor..."
    exit 0
}
trap cleanup SIGINT SIGTERM

while true; do
    start_time=\$(date +%s)
    
    # Clients Metrics from AWG
    clients_json=""
    if docker ps --format '{{.Names}}' | grep -q "^\${CONTAINER_NAME}\$"; then
        dump_output=\$(docker exec "\${CONTAINER_NAME}" /usr/local/bin/awg show wg0 dump 2>/dev/null)
        
        first_line=true
        while read -r line; do
            [ -z "\$line" ] && continue
            
            if [ "\$first_line" = true ]; then
                first_line=false
                continue
            fi
            
            parts=(\$line)
            if [ \${#parts[@]} -ge 7 ]; then
                pub_key="\${parts[0]}"
                endpoint="\${parts[2]}"
                handshake="\${parts[4]}"
                rx="\${parts[5]}" 
                tx="\${parts[6]}" 
                
                if [ -n "\$clients_json" ]; then
                    clients_json="\${clients_json},"
                fi
                clients_json="\${clients_json}{\\"public_key\\":\\"\${pub_key}\\",\\"endpoint\\":\\"\${endpoint}\\",\\"bytes_sent\\":\${tx},\\"bytes_received\\":\${rx},\\"last_handshake\\":\${handshake}}"
            fi
        done <<< "\$dump_output"
    fi
    
    # Construct Payload
    payload=\$(cat <<EOF
{
  "token": "\${TOKEN}",
  "clients": [
    \${clients_json}
  ]
}
EOF
)

    # POST to panel
    curl -s -X POST \
         -H "Content-Type: application/json" \
         -d "\$payload" \
         "\${PANEL_URL}/api/servers/report-metrics" > /dev/null
         
    end_time=\$(date +%s)
    elapsed=\$((end_time - start_time))
    sleep_time=\$((INTERVAL - elapsed))
    if [ \$sleep_time -gt 0 ]; then
        sleep \$sleep_time
    fi
done
BASH;
    }

    /**
     * Batch synchronization of all server clients from database to remote container.
     */
    public function syncAllClientsToContainer(): bool {
        if (!$this->data) return false;
        $containerName = $this->data['container_name'];
        $pdo = DB::conn();

        // Retrieve server private key from remote container to re-derive/construct
        $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key 2>/dev/null", true));
        if (empty($privKey)) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT name, client_ip, public_key, preshared_key, status FROM vpn_clients WHERE server_id = ?");
        $stmt->execute([$this->serverId]);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build Interface section
        $vpnPort = $this->data['vpn_port'] ?: 51820;
        $subnetBase = substr($this->data['vpn_subnet'], 0, strrpos($this->data['vpn_subnet'], '.'));
        $awgParams = is_string($this->data['awg_params']) ? json_decode($this->data['awg_params'], true) : $this->data['awg_params'];
        $awgParams = $awgParams ?: [];

        $wgConfig = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";
        $wgConfig .= "Address = {$subnetBase}.1/24\n";
        $wgConfig .= "ListenPort = {$vpnPort}\n";
        $wgConfig .= "MTU = 1280\n";
        foreach ($awgParams as $key => $value) {
            if (empty($value) || $key === 'mimicry_type') continue;
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        // Build Peer sections & clientsTable structure
        $clientsTable = [];
        foreach ($clients as $c) {
            if ($c['status'] !== 'active') continue;
            
            $wgConfig .= "[Peer]\n";
            $wgConfig .= "PublicKey = {$c['public_key']}\n";
            if (!empty($c['preshared_key'])) {
                $wgConfig .= "PresharedKey = {$c['preshared_key']}\n";
            }
            $wgConfig .= "AllowedIPs = {$c['client_ip']}/32\n\n";

            $clientsTable[] = [
                'name' => $c['name'],
                'client_ip' => $c['client_ip'],
                'public_key' => $c['public_key'],
                'preshared_key' => $c['preshared_key']
            ];
        }

        $base64Config = base64_encode($wgConfig);
        $base64Table = base64_encode(json_encode($clientsTable));

        $this->executeCommand("echo \"{$base64Config}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf'", true);
        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true);
        $this->executeCommand("echo \"{$base64Table}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/clientsTable'", true);

        // Apply rules and syncconf
        $this->executeCommand("docker exec -i {$containerName} bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'", true);
        return true;
    }
}
