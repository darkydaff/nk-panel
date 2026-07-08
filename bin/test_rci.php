<?php
/**
 * CLI Debug tool to test Keenetic RCI calls step-by-step
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/KeeneticRouter.php';
require_once __DIR__ . '/../inc/RouterManager.php';

Config::load(__DIR__ . '/../.env');

if ($argc < 2) {
    echo "Usage: php bin/test_rci.php <ext_client_code_or_router_id>\n";
    exit(1);
}

$id = $argv[1];
$pdo = DB::conn();

// Find router
$stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ? OR ext_client_code = ?");
$stmt->execute([$id, $id]);
$router = $stmt->fetch();

if (!$router) {
    echo "Router not found in database for ID or Client Code: $id\n";
    exit(1);
}

echo "Found Router:\n";
echo "ID: " . $router['id'] . "\n";
echo "Client Code: " . $router['ext_client_code'] . "\n";
echo "Domain: " . $router['domain'] . "\n";
echo "Login: " . ($router['login'] ?: 'admin') . "\n";
echo "WG Interface: " . ($router['wg_interface_id'] ?: '(Auto)') . "\n";
echo "Status: " . $router['status'] . "\n";
echo "\nConnecting and testing RCI...\n";

$login = $router['login'] ?: 'admin';
$adapter = new KeeneticRouter($router['domain'], $router['password'], $login);

try {
    echo "Testing Connection / Authentication...\n";
    $conn = $adapter->testConnection();
    echo "Connection test result: " . json_encode($conn) . "\n";
    if (!$conn['success']) {
        exit(1);
    }

    echo "\nRetrieving current interfaces (rci/show/interface)...\n";
    $resShow = $adapter->request('rci/show/interface');
    echo "HTTP Code: " . $resShow['code'] . "\n";
    echo "Show Interface Keys: " . implode(', ', array_keys($resShow['body'] ?? [])) . "\n";

    echo "\nRetrieving current running interface config (rci/interface)...\n";
    $resConf = $adapter->request('rci/interface');
    echo "HTTP Code: " . $resConf['code'] . "\n";
    echo "Interface Config Keys: " . implode(', ', array_keys($resConf['body'] ?? [])) . "\n";

    // Get active VPN client config
    $stmtClient = $pdo->prepare("
        SELECT c.* 
        FROM vpn_clients c
        WHERE c.ext_client_code = ? AND c.status = 'active'
        ORDER BY c.created_at DESC LIMIT 1
    ");
    $stmtClient->execute([$router['ext_client_code']]);
    $vpnClient = $stmtClient->fetch();
    
    if (!$vpnClient) {
        echo "\nNo active VPN client configuration found in DB for code: " . $router['ext_client_code'] . "\n";
        exit(1);
    }
    
    $configContent = $vpnClient['config'];
    echo "\nFound VPN Client config (length: " . strlen($configContent) . " bytes).\n";
    
    $description = "NKPanel-" . $router['ext_client_code'];
    
    // We override request() temporarily to print everything!
    echo "\n--- STARTING IMPORT AND CONFIG LOGGING ---\n";
    
    $parsed = KeeneticRouter::parseWgConfig($configContent);
    $interfaceId = $router['wg_interface_id'];
    if (!$interfaceId) {
        $existing = $adapter->findWgInterface($description);
        if ($existing) {
            $interfaceId = $existing['id'];
        } else {
            $interfaces = $adapter->getInterfaces();
            $idx = 0;
            while (isset($interfaces["Wireguard{$idx}"])) {
                $idx++;
            }
            $interfaceId = "Wireguard{$idx}";
        }
    }
    echo "Target Interface ID: $interfaceId\n";
    
    // Step 2: Create / configure interface
    echo "\nRequest 1: Configure description & security-level\n";
    $res1 = $adapter->request("rci/interface", 'POST', [
        $interfaceId => [
            'description' => $description,
            'security-level' => [
                'public' => true
            ]
        ]
    ]);
    echo "Result Code: " . $res1['code'] . "\nBody: " . json_encode($res1['body']) . "\n";

    // Configure IP address
    $address = $parsed['interface']['Address'] ?? '10.8.1.2/32';
    $ipParts = explode('/', $address);
    $ipAddr = $ipParts[0];
    $maskInt = isset($ipParts[1]) ? (int)$ipParts[1] : 32;
    $netmask = $adapter->maskIntToDotted($maskInt);

    echo "\nRequest 2: Configure IP address ($ipAddr / $netmask)\n";
    $res2 = $adapter->request("rci/interface/{$interfaceId}/ip/address", 'POST', [
        'address' => $ipAddr,
        'mask' => $netmask
    ]);
    echo "Result Code: " . $res2['code'] . "\nBody: " . json_encode($res2['body']) . "\n";

    // NAT
    echo "\nRequest 3: Configure NAT\n";
    $res3 = $adapter->request("rci/ip/nat", 'POST', [
        'interface' => $interfaceId
    ]);
    echo "Result Code: " . $res3['code'] . "\nBody: " . json_encode($res3['body']) . "\n";

    // IP Global, MTU
    $mtu = 1280;
    if (isset($parsed['interface']['MTU'])) {
        $mtu = (int)$parsed['interface']['MTU'];
    } elseif (isset($parsed['peer']['MTU'])) {
        $mtu = (int)$parsed['peer']['MTU'];
    }
    
    echo "\nRequest 4: Configure IP global & mtu\n";
    $res4 = $adapter->request("rci/interface/{$interfaceId}/ip", 'POST', [
        'global' => [
            'priority' => 100
        ],
        'mtu' => $mtu
    ]);
    echo "Result Code: " . $res4['code'] . "\nBody: " . json_encode($res4['body']) . "\n";

    // Adjust MSS
    echo "\nRequest 5: Configure TCP adjust-mss\n";
    $res5 = $adapter->request("rci/interface/{$interfaceId}/ip/tcp", 'POST', [
        'adjust-mss' => [
            'pmtu' => true
        ]
    ]);
    echo "Result Code: " . $res5['code'] . "\nBody: " . json_encode($res5['body']) . "\n";

    // WireGuard settings
    $privKey = $parsed['interface']['PrivateKey'] ?? '';
    echo "\nRequest 6: Configure WireGuard private key\n";
    $res6 = $adapter->request("rci/interface/{$interfaceId}/wireguard", 'POST', [
        'private-key' => $privKey
    ]);
    echo "Result Code: " . $res6['code'] . "\nBody: " . json_encode($res6['body']) . "\n";

    // Peer
    $pubKey = $parsed['peer']['PublicKey'] ?? '';
    $psk = $parsed['peer']['PresharedKey'] ?? '';
    $endpoint = $parsed['peer']['Endpoint'] ?? '';
    $keepalive = isset($parsed['peer']['PersistentKeepalive']) ? (int)$parsed['peer']['PersistentKeepalive'] : 25;

    $allowedIpsList = [];
    $allowedIpsRaw = $parsed['peer']['AllowedIPs'] ?? '0.0.0.0/0';
    $cidrs = explode(',', $allowedIpsRaw);
    foreach ($cidrs as $cidr) {
        $cidr = trim($cidr);
        if (empty($cidr)) continue;
        $parts = explode('/', $cidr);
        $ip = $parts[0];
        $prefix = isset($parts[1]) ? (int)$parts[1] : 32;
        $mask = $adapter->maskIntToDotted($prefix);
        $allowedIpsList[] = ['address' => $ip, 'mask' => $mask];
    }

    echo "\nRequest 7: Remove existing peer\n";
    $res7 = $adapter->request("rci/interface/{$interfaceId}/wireguard/peer", 'POST', [
        'key' => $pubKey,
        'no' => true
    ]);
    echo "Result Code: " . $res7['code'] . "\nBody: " . json_encode($res7['body']) . "\n";

    // Configure peer
    $peerConfig = [
        'key' => $pubKey,
        'endpoint' => [
            'address' => $endpoint
        ],
        'keepalive-interval' => [
            'interval' => $keepalive
        ],
        'allow-ips' => $allowedIpsList
    ];
    $peerDesc = $parsed['peer']['Name'] ?? '';
    if (!empty($peerDesc)) {
        $peerConfig['comment'] = $peerDesc;
    }
    $hasDefaultRoute = false;
    foreach ($allowedIpsList as $item) {
        if ($item['address'] === '0.0.0.0' && $item['mask'] === '0.0.0.0') {
            $hasDefaultRoute = true;
            break;
        }
    }
    if ($hasDefaultRoute) {
        $peerConfig['connect'] = [
            'via' => 'ISP'
        ];
    }
    if (!empty($psk)) {
        $peerConfig['preshared-key'] = $psk;
    }

    echo "\nRequest 8: Configure peer details\n";
    $res8 = $adapter->request("rci/interface/{$interfaceId}/wireguard/peer", 'POST', $peerConfig);
    echo "Result Code: " . $res8['code'] . "\nBody: " . json_encode($res8['body']) . "\n";

    // DNS
    if (!empty($parsed['interface']['DNS'])) {
        $dnsServers = array_map('trim', explode(',', $parsed['interface']['DNS']));
        foreach ($dnsServers as $dns) {
            if (filter_var($dns, FILTER_VALIDATE_IP)) {
                echo "\nRequest 9: Configure DNS server $dns\n";
                $res9 = $adapter->request("rci/ip/name-server", 'POST', [
                    'address' => $dns,
                    'on' => $interfaceId
                ]);
                echo "Result Code: " . $res9['code'] . "\nBody: " . json_encode($res9['body']) . "\n";
            }
        }
    }

    // Policy
    echo "\nRequest 10: Configure routing policy Policy0 permit global\n";
    $res10 = $adapter->request("rci/ip/policy", 'POST', [
        'Policy0' => [
            'permit' => [
                'interface' => $interfaceId
            ]
        ]
    ]);
    echo "Result Code: " . $res10['code'] . "\nBody: " . json_encode($res10['body']) . "\n";

    // Obfuscation
    echo "\nRequest 11: Configure ASC Obfuscation\n";
    try {
        $obf = $adapter->applyObfuscation($interfaceId, $parsed['interface']);
        echo "Obfuscation Applied: " . json_encode($obf) . "\n";
    } catch (Throwable $obfEx) {
        echo "Obfuscation Failed: " . $obfEx->getMessage() . "\n";
    }

    // Up
    echo "\nRequest 12: Bring interface UP\n";
    $res12 = $adapter->request("rci/interface/{$interfaceId}", 'POST', [
        'up' => true
    ]);
    echo "Result Code: " . $res12['code'] . "\nBody: " . json_encode($res12['body']) . "\n";

    // Save
    echo "\nRequest 13: Save running configuration to startup-config\n";
    $res13 = $adapter->request('rci/system/configuration/save', 'POST', new stdClass());
    echo "Result Code: " . $res13['code'] . "\nBody: " . json_encode($res13['body']) . "\n";

    echo "\n--- RCI TEST COMPLETED ---\n";

} catch (Throwable $e) {
    echo "\nException caught: " . $e->getMessage() . "\n";
}
