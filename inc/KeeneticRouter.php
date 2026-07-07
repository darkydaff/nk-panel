<?php
/**
 * Keenetic Router RCI API Adapter
 * Handles connection, authentication, interface configuration, and AmneziaWG (ASC) setup
 */
class KeeneticRouter {
    private string $domain;
    private string $password;
    private string $login;
    private ?string $cookie = null;
    private array $lastHeaders = [];
    private int $timeout = 15;
    private int $authTimeout = 10;

    public function __construct(string $domain, string $password, string $login = 'admin') {
        $this->domain = trim($domain);
        $this->password = $password;
        $this->login = trim($login);
    }

    /**
     * Set connection timeouts
     */
    public function setTimeout(int $seconds): void {
        $this->timeout = $seconds;
        $this->authTimeout = max(3, $seconds);
    }

    /**
     * Perform HTTP request to the router
     */
    public function request(string $path, string $method = 'GET', $body = null, bool $isRetry = false): array {
        $url = $this->domain;
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'http://' . $url;
        }
        $url = rtrim($url, '/') . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($this->cookie) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($body !== null) {
            $jsonBody = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        // Capture headers case-insensitively
        $this->lastHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $val = trim($parts[1]);
                $this->lastHeaders[$key] = $val;
            }
            return $len;
        });

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Connection to router failed: " . $curlError);
        }

        // Handle 401 Unauthorized by re-authenticating
        if ($httpCode === 401 && !$isRetry) {
            $this->authenticate();
            return $this->request($path, $method, $body, true);
        }

        $decoded = json_decode($response, true);
        return [
            'code' => $httpCode,
            'body' => $decoded !== null ? $decoded : $response
        ];
    }

    /**
     * Authenticate with the router using Challenge-Response protocol
     */
    public function authenticate(): bool {
        $url = $this->domain;
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'http://' . $url;
        }
        $url = rtrim($url, '/') . '/auth';

        // 1. Get challenge
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->authTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) use (&$headers) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $val = trim($parts[1]);
                if ($key === 'set-cookie') {
                    if (isset($headers['set-cookie'])) {
                        $headers['set-cookie'] .= '; ' . $val;
                    } else {
                        $headers['set-cookie'] = $val;
                    }
                } else {
                    $headers[$key] = $val;
                }
            }
            return $len;
        });

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $realm = $headers['x-ndm-realm'] ?? null;
        $challenge = $headers['x-ndm-challenge'] ?? null;

        if (!$realm || !$challenge) {
            throw new Exception("Router authentication headers missing (Realm/Challenge). Is RCI/HTTP proxy enabled on the router?");
        }

        $initialCookie = null;
        if (isset($headers['set-cookie'])) {
            $cookieParts = explode(';', $headers['set-cookie']);
            $initialCookie = trim($cookieParts[0]);
        }

        // 2. Compute response hashes
        $md5 = md5($this->login . ':' . $realm . ':' . $this->password);
        $sha = hash('sha256', $challenge . $md5);

        // 3. Post auth request
        $postHeaders = ['Content-Type: application/json'];
        if ($initialCookie) {
            $postHeaders[] = 'Cookie: ' . $initialCookie;
        }

        $ch2 = curl_init($url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, $this->authTimeout);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, $postHeaders);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode([
            'login' => $this->login,
            'password' => $sha
        ]));

        $authHeaders = [];
        curl_setopt($ch2, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) use (&$authHeaders) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $val = trim($parts[1]);
                $authHeaders[$key] = $val;
            }
            return $len;
        });

        $res = curl_exec($ch2);
        $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($code !== 200) {
            throw new Exception("Authentication failed with status code " . $code . ". Response: " . $res . "\nRealm: " . $realm . "\nChallenge: " . $challenge);
        }

        // 4. Capture session cookie
        if (isset($authHeaders['set-cookie'])) {
            $cookiePart = explode(';', $authHeaders['set-cookie'])[0];
            $this->cookie = $cookiePart;
            return true;
        } elseif ($initialCookie) {
            $this->cookie = $initialCookie;
            return true;
        }

        throw new Exception("Failed to retrieve session cookie from router.");
    }

    /**
     * Test connection to the router and retrieve system info
     */
    public function testConnection(): array {
        try {
            $res = $this->request('rci/show/system');
            if ($res['code'] !== 200 || !is_array($res['body'])) {
                return ['success' => false, 'error' => 'Invalid router response'];
            }
            $sys = $res['body'];
            
            $model = $sys['model'] ?? 'Keenetic';
            if (isset($sys['description']) && trim($sys['description']) !== '') {
                $model = trim($sys['description']);
            }
            
            $version = 'Unknown';
            if (isset($sys['release']) && trim($sys['release']) !== '') {
                $version = trim($sys['release']);
            } elseif (isset($sys['version']) && trim($sys['version']) !== '') {
                $version = trim($sys['version']);
            } elseif (isset($sys['firmware']) && trim($sys['firmware']) !== '') {
                $version = trim($sys['firmware']);
            } elseif (isset($sys['ndms']) && trim($sys['ndms']) !== '') {
                $version = trim($sys['ndms']);
            }

            return [
                'success' => true,
                'router_model' => $model,
                'firmware_version' => $version,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List all network interfaces
     */
    public function getInterfaces(): array {
        $res = $this->request('rci/show/interface');
        if ($res['code'] === 200 && is_array($res['body'])) {
            return $res['body'];
        }
        return [];
    }

    /**
     * Find a WireGuard interface by its description
     */
    public function findWgInterface(?string $description): ?array {
        if (empty($description)) return null;
        $interfaces = $this->getInterfaces();
        foreach ($interfaces as $name => $info) {
            if (str_starts_with($name, 'Wireguard') && isset($info['description']) && $info['description'] === $description) {
                $info['id'] = $name;
                return $info;
            }
        }
        return null;
    }

    /**
     * Retrieve status of a specific interface
     */
    public function getInterfaceStatus(string $interfaceId): array {
        $res = $this->request("rci/show/interface/{$interfaceId}");
        if ($res['code'] === 200 && is_array($res['body'])) {
            return $res['body'];
        }
        return [];
    }

    /**
     * Save running-config to startup-config
     */
    public function saveConfig(): bool {
        $res = $this->request('rci/system/configuration/save', 'POST', new stdClass());
        return $res['code'] === 200;
    }

    /**
     * Parse WireGuard configuration file
     */
    public static function parseWgConfig(string $content): array {
        $lines = explode("\n", $content);
        $config = [
            'interface' => [],
            'peer' => []
        ];
        $currentSection = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (preg_match('/^\[(.*?)\]$/', $line, $m)) {
                $currentSection = strtolower($m[1]);
                continue;
            }
            if ($currentSection && preg_match('/^(.*?)=(.*)$/', $line, $m)) {
                $key = trim($m[1]);
                $val = trim($m[2]);
                if ($currentSection === 'interface') {
                    $config['interface'][$key] = $val;
                } elseif ($currentSection === 'peer') {
                    $config['peer'][$key] = $val;
                }
            }
        }
        return $config;
    }

    /**
     * Create or update a WireGuard interface and configure it (excluding ASC params)
     */
    public function importWgConfig(string $confContent, string $description, ?string $forcedInterfaceId = null): string {
        $parsed = self::parseWgConfig($confContent);
        if (empty($parsed['interface']) || empty($parsed['peer'])) {
            throw new Exception("Invalid WireGuard configuration file format.");
        }

        // 1. Find or choose interface ID
        $interfaceId = $forcedInterfaceId;
        if (!$interfaceId) {
            $existing = $this->findWgInterface($description);
            if ($existing) {
                $interfaceId = $existing['id'];
            } else {
                // Find next free Wireguard index
                $interfaces = $this->getInterfaces();
                $idx = 0;
                while (isset($interfaces["Wireguard{$idx}"])) {
                    $idx++;
                }
                $interfaceId = "Wireguard{$idx}";
            }
        }

        // 2. Create/enable interface and configure description, security-level
        // In Keenetic, we configure these properties.
        $this->request("rci/interface/{$interfaceId}", 'POST', [
            'description' => $description,
            'security-level' => 'private',
            'up' => true
        ]);

        // Configure IP address (parsed from Address)
        $address = $parsed['interface']['Address'] ?? '10.8.1.2/32';
        $ipParts = explode('/', $address);
        $ipAddr = $ipParts[0];
        $maskInt = isset($ipParts[1]) ? (int)$ipParts[1] : 32;
        $netmask = $this->maskIntToDotted($maskInt);

        $this->request("rci/interface/{$interfaceId}/ip/address", 'POST', [
            'address' => $ipAddr,
            'mask' => $netmask
        ]);

        // Enable NAT (masquerade) on the interface
        $this->request("rci/ip/nat", 'POST', [
            'name' => $interfaceId
        ]);

        // 3. Configure WireGuard settings
        $privKey = $parsed['interface']['PrivateKey'] ?? '';
        $port = 51820; // Default or randomly chosen
        $endpoint = $parsed['peer']['Endpoint'] ?? '';
        if (preg_match('/:(\d+)$/', $endpoint, $m)) {
            $port = (int)$m[1];
        }
        $mtu = isset($parsed['interface']['MTU']) ? (int)$parsed['interface']['MTU'] : 1280;

        $this->request("rci/interface/{$interfaceId}/wireguard", 'POST', [
            'private-key' => $privKey,
            'port' => $port,
            'mtu' => $mtu
        ]);

        // 4. Configure WireGuard Peer
        $pubKey = $parsed['peer']['PublicKey'] ?? '';
        $psk = $parsed['peer']['PresharedKey'] ?? '';
        $endpointHost = preg_replace('/:\d+$/', '', $endpoint);
        $keepalive = isset($parsed['peer']['PersistentKeepalive']) ? (int)$parsed['peer']['PersistentKeepalive'] : 25;
        
        // Remove existing peers on this interface first to prevent collision
        $this->request("rci/interface/{$interfaceId}/wireguard/peer", 'POST', [
            'public-key' => $pubKey,
            'no' => true
        ]);

        // Add the new peer config
        $peerConfig = [
            'public-key' => $pubKey,
            'endpoint' => $endpointHost,
            'port' => $port,
            'keepalive' => $keepalive,
            'allowed-ips' => [
                [
                    'address' => '0.0.0.0',
                    'mask' => '0.0.0.0'
                ]
            ]
        ];

        if (!empty($psk)) {
            $peerConfig['preshared-key'] = $psk;
        }

        $this->request("rci/interface/{$interfaceId}/wireguard/peer", 'POST', $peerConfig);

        // 5. Apply ASC Obfuscation Parameters
        $this->applyObfuscation($interfaceId, $parsed['interface']);

        // 6. Save Configuration
        $this->saveConfig();

        return $interfaceId;
    }

    /**
     * Apply Obfuscation parameters (with adaptive fallback for different KeeneticOS versions)
     */
    public function applyObfuscation(string $interfaceId, array $awgParams): array {
        // Collect all possible parameters from input
        $paramsMap = [
            'jc' => isset($awgParams['Jc']) ? (int)$awgParams['Jc'] : null,
            'jmin' => isset($awgParams['Jmin']) ? (int)$awgParams['Jmin'] : null,
            'jmax' => isset($awgParams['Jmax']) ? (int)$awgParams['Jmax'] : null,
            's1' => isset($awgParams['S1']) ? (int)$awgParams['S1'] : null,
            's2' => isset($awgParams['S2']) ? (int)$awgParams['S2'] : null,
            'h1' => $awgParams['H1'] ?? null,
            'h2' => $awgParams['H2'] ?? null,
            'h3' => $awgParams['H3'] ?? null,
            'h4' => $awgParams['H4'] ?? null,
            's3' => isset($awgParams['S3']) ? (int)$awgParams['S3'] : null,
            's4' => isset($awgParams['S4']) ? (int)$awgParams['S4'] : null,
            'i1' => $awgParams['I1'] ?? null,
            'i2' => $awgParams['I2'] ?? null,
            'i3' => $awgParams['I3'] ?? null,
            'i4' => $awgParams['I4'] ?? null,
            'i5' => $awgParams['I5'] ?? null,
        ];

        // Clean out nulls
        $cleanParams = array_filter($paramsMap, fn($v) => $v !== null);
        if (empty($cleanParams)) {
            return ['applied' => [], 'fallback' => false];
        }

        // Try applying ALL parameters first (including v2 parameters and header ranges)
        try {
            $res = $this->postAscParameters($interfaceId, $cleanParams);
            if ($res['code'] === 200 && (!is_array($res['body']) || !isset($res['body']['status']) || $this->hasNoErrorStatus($res['body']))) {
                return ['applied' => $cleanParams, 'fallback' => false];
            }
        } catch (Throwable $e) {
            // Log and fall back
        }

        // FALLBACK FLOW 1: Handle dynamic header ranges by converting them to midpoint integers
        $normalizedParams = $cleanParams;
        foreach (['h1', 'h2', 'h3', 'h4'] as $key) {
            if (isset($normalizedParams[$key]) && is_string($normalizedParams[$key]) && strpos($normalizedParams[$key], '-') !== false) {
                if (preg_match('/^(\d+)-(\d+)$/', $normalizedParams[$key], $m)) {
                    $normalizedParams[$key] = (int)round(($m[1] + $m[2]) / 2);
                }
            }
        }

        // Try applying with normalized headers
        try {
            $res = $this->postAscParameters($interfaceId, $normalizedParams);
            if ($res['code'] === 200 && (!is_array($res['body']) || !isset($res['body']['status']) || $this->hasNoErrorStatus($res['body']))) {
                return ['applied' => $normalizedParams, 'fallback' => true, 'fallback_reason' => 'midpoint_headers'];
            }
        } catch (Throwable $e) {
            // Log and fall back
        }

        // FALLBACK FLOW 2: Downgrade strictly to AWG v1 parameters (drop S3, S4, I1-I5)
        $v1Params = [];
        $v1Keys = ['jc', 'jmin', 'jmax', 's1', 's2', 'h1', 'h2', 'h3', 'h4'];
        foreach ($v1Keys as $key) {
            if (isset($normalizedParams[$key])) {
                $v1Params[$key] = $normalizedParams[$key];
            }
        }

        if (!empty($v1Params)) {
            $res = $this->postAscParameters($interfaceId, $v1Params);
            if ($res['code'] === 200 && (!is_array($res['body']) || !isset($res['body']['status']) || $this->hasNoErrorStatus($res['body']))) {
                return ['applied' => $v1Params, 'fallback' => true, 'fallback_reason' => 'awg_v1_downgrade'];
            }
            
            // If the router rejected even the v1 parameters, throw an exception
            $errorMsg = is_array($res['body']) ? json_encode($res['body']) : (string)$res['body'];
            throw new Exception("Router rejected both AmneziaWG v2 and v1 configurations. Details: " . $errorMsg);
        }

        throw new Exception("No valid AmneziaWG configuration parameters found to apply.");
    }

    /**
     * Send ASC parameters to RCI interface path
     */
    private function postAscParameters(string $interfaceId, array $params): array {
        // In Keenetic RCI, we POST to `/rci/interface/{id}/wireguard/asc` with the argument values.
        // Wait, how does the RCI daemon expect them?
        // Since CLI is `wireguard asc {jc} {jmin} {jmax} {s1} {s2} {h1} {h2} {h3} {h4} [{s3} {s4} {i1} {i2} {i3} {i4} {i5}]`,
        // NDMS expects them mapped to keys or as a single space-separated argument.
        // Let's try sending as a single configuration object first:
        return $this->request("rci/interface/{$interfaceId}/wireguard/asc", 'POST', $params);
    }

    /**
     * Check if the status response has any errors
     */
    private function hasNoErrorStatus($body): bool {
        if (!is_array($body)) return true;
        
        // NDMS RCI returns errors in the "status" field of the response
        // Format is: [{"code": "error", "message": "..."}]
        if (isset($body['status']) && is_array($body['status'])) {
            foreach ($body['status'] as $s) {
                if (isset($s['code']) && $s['code'] === 'error') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Delete an interface
     */
    public function removeInterface(string $interfaceId): bool {
        $res = $this->request("rci/interface/{$interfaceId}", 'POST', [
            'no' => true
        ]);
        $this->saveConfig();
        return $res['code'] === 200;
    }

    /**
     * Convert integer subnet mask to dotted decimal
     */
    private function maskIntToDotted(int $mask): string {
        $dotted = long2ip(-1 << (32 - $mask));
        return $dotted ? $dotted : '255.255.255.255';
    }
}
