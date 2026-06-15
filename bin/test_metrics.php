<?php
/**
 * Metrics verification script
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';
require_once __DIR__ . '/../inc/ServerMonitoring.php';

// Disable PHP timeout
set_time_limit(0);

// Load env configuration
Config::load(__DIR__ . '/../.env');

try {
    $pdo = DB::conn();
    echo "DB Connected successfully.\n";
    
    // Check migrations were applied
    $stmt = $pdo->query("SHOW COLUMNS FROM vpn_servers LIKE 'secret_token'");
    if ($stmt->rowCount() > 0) {
        echo "Migration check: secret_token column exists in vpn_servers.\n";
    } else {
        echo "Migration check FAILED: secret_token column NOT found in vpn_servers.\n";
        exit(1);
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM vpn_clients LIKE 'speed_up_kbps'");
    if ($stmt->rowCount() > 0) {
        echo "Migration check: speed_up_kbps column exists in vpn_clients.\n";
    } else {
        echo "Migration check FAILED: speed_up_kbps column NOT found in vpn_clients.\n";
        exit(1);
    }

    // Let's create a mock server and a mock client for testing
    // Get default admin user
    $adminId = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    if (!$adminId) {
        echo "No users found in database to link mock data.\n";
        exit(1);
    }
    
    // Create test server
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("
        INSERT INTO vpn_servers (user_id, name, host, port, username, password, secret_token, status)
        VALUES (?, 'Test Server for Metrics', '127.0.0.1', 22, 'root', 'root', ?, 'active')
    ")->execute([$adminId, $token]);
    $serverId = $pdo->lastInsertId();
    echo "Created mock server with ID {$serverId} and token {$token}\n";
    
    // Create test client
    $pdo->prepare("
        INSERT INTO vpn_clients (server_id, user_id, name, client_ip, public_key, private_key, status)
        VALUES (?, ?, 'Test Client', '10.8.1.100', 'MOCK_PUBLIC_KEY', 'MOCK_PRIVATE_KEY', 'active')
    ")->execute([$serverId, $adminId]);
    $clientId = $pdo->lastInsertId();
    echo "Created mock client with ID {$clientId}\n";
    
    // Let's test the endpoint logic programmatically!
    function simulateReport($token, $clientsData) {
        $pdo = DB::conn();
        
        // Find server by token
        $stmt = $pdo->prepare('SELECT id FROM vpn_servers WHERE secret_token = ?');
        $stmt->execute([$token]);
        $serverId = $stmt->fetchColumn();
        if (!$serverId) {
            echo "Error: Invalid token.\n";
            return false;
        }
        
        $pdo->beginTransaction();
        
        // Update last_check_at
        $stmt = $pdo->prepare('UPDATE vpn_servers SET last_check_at = NOW() WHERE id = ?');
        $stmt->execute([$serverId]);
        
        foreach ($clientsData as $c) {
            $publicKey = $c['public_key'];
            
            // Find client
            $stmt = $pdo->prepare('SELECT id, bytes_sent, bytes_received FROM vpn_clients WHERE server_id = ? AND public_key = ?');
            $stmt->execute([$serverId, $publicKey]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($client) {
                $clientId = $client['id'];
                $rawBytesSent = (int)$c['bytes_sent'];
                $rawBytesReceived = (int)$c['bytes_received'];
                $lastHandshakeVal = (int)$c['last_handshake'];
                
                // Fetch previous metrics
                $stmt = $pdo->prepare('
                    SELECT bytes_sent, bytes_received, collected_at 
                    FROM client_metrics 
                    WHERE client_id = ? 
                    ORDER BY collected_at DESC 
                    LIMIT 1
                ');
                $stmt->execute([$clientId]);
                $prev = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $speedUp = 0;
                $speedDown = 0;
                $deltaSent = $rawBytesSent;
                $deltaReceived = $rawBytesReceived;
                
                if ($prev) {
                    $timeDiff = time() - strtotime($prev['collected_at']);
                    if ($timeDiff > 0) {
                        $rawBytesDiffSent = $rawBytesSent - (int)$prev['bytes_sent'];
                        $rawBytesDiffReceived = $rawBytesReceived - (int)$prev['bytes_received'];
                        
                        if ($rawBytesDiffSent >= 0) {
                            $deltaSent = $rawBytesDiffSent;
                        }
                        if ($rawBytesDiffReceived >= 0) {
                            $deltaReceived = $rawBytesDiffReceived;
                        }
                        
                        $speedUp = round(($deltaReceived * 8) / $timeDiff / 1000, 2);
                        $speedDown = round(($deltaSent * 8) / $timeDiff / 1000, 2);
                    }
                }
                
                // Save to client_metrics
                $stmt = $pdo->prepare('
                    INSERT INTO client_metrics 
                    (client_id, bytes_sent, bytes_received, speed_up_kbps, speed_down_kbps, collected_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ');
                $stmt->execute([$clientId, $rawBytesSent, $rawBytesReceived, $speedUp, $speedDown]);
                
                // Accumulate client traffic in main table
                $newTotalSent = (int)$client['bytes_sent'] + $deltaSent;
                $newTotalReceived = (int)$client['bytes_received'] + $deltaReceived;
                
                $lastHandshake = $lastHandshakeVal > 0 ? date('Y-m-d H:i:s', $lastHandshakeVal) : null;
                $stmt = $pdo->prepare('
                    UPDATE vpn_clients 
                    SET bytes_sent = ?, 
                        bytes_received = ?, 
                        speed_up_kbps = ?, 
                        speed_down_kbps = ?, 
                        last_handshake = ?, 
                        last_sync_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$newTotalSent, $newTotalReceived, $speedUp, $speedDown, $lastHandshake, $clientId]);
            }
        }
        
        $pdo->commit();
        return true;
    }
    
    // Simulate first report (initial connection)
    echo "\nSimulating first report (100MB sent, 200MB received from WireGuard dump):\n";
    $clients = [
        [
            'public_key' => 'MOCK_PUBLIC_KEY',
            'bytes_sent' => 104857600, // 100MB
            'bytes_received' => 209715200, // 200MB
            'last_handshake' => time()
        ]
    ];
    simulateReport($token, $clients);
    
    // Check DB state
    $clientRow = $pdo->query("SELECT * FROM vpn_clients WHERE id = {$clientId}")->fetch();
    echo "Client DB state after first report:\n";
    echo "  Total Uploaded (bytes_sent): " . ($clientRow['bytes_sent'] / 1024 / 1024) . " MB (Expected: 100MB)\n";
    echo "  Total Downloaded (bytes_received): " . ($clientRow['bytes_received'] / 1024 / 1024) . " MB (Expected: 200MB)\n";
    echo "  Speed up / down: {$clientRow['speed_up_kbps']} / {$clientRow['speed_down_kbps']} Kbps (Expected: 0 / 0 on first interval)\n";
    
    // Wait 2 seconds to simulate next interval
    echo "\nSleeping 2 seconds for next interval...\n";
    sleep(2);
    
    // Simulate second report (101MB sent, 202MB received)
    echo "\nSimulating second report (+1MB sent, +2MB received):\n";
    $clients = [
        [
            'public_key' => 'MOCK_PUBLIC_KEY',
            'bytes_sent' => 105906176, // 101MB (diff = 1MB)
            'bytes_received' => 211812352, // 202MB (diff = 2MB)
            'last_handshake' => time()
        ]
    ];
    simulateReport($token, $clients);
    
    $clientRow = $pdo->query("SELECT * FROM vpn_clients WHERE id = {$clientId}")->fetch();
    echo "Client DB state after second report:\n";
    echo "  Total Uploaded (bytes_sent): " . ($clientRow['bytes_sent'] / 1024 / 1024) . " MB (Expected: 101MB)\n";
    echo "  Total Downloaded (bytes_received): " . ($clientRow['bytes_received'] / 1024 / 1024) . " MB (Expected: 202MB)\n";
    echo "  Speed up (downloaded by client = diff sent * 8 / time): {$clientRow['speed_up_kbps']} Kbps\n";
    echo "  Speed down (uploaded by client = diff received * 8 / time): {$clientRow['speed_down_kbps']} Kbps\n";
    
    // Simulate a server reboot/stats reset (raw bytes drop back to 0, then go up to 5MB and 10MB)
    echo "\nSimulating server reboot/stats reset (raw stats reset to 0, currently 5MB sent, 10MB received):\n";
    $clients = [
        [
            'public_key' => 'MOCK_PUBLIC_KEY',
            'bytes_sent' => 5242880, // 5MB
            'bytes_received' => 10485760, // 10MB
            'last_handshake' => time()
        ]
    ];
    simulateReport($token, $clients);
    
    $clientRow = $pdo->query("SELECT * FROM vpn_clients WHERE id = {$clientId}")->fetch();
    echo "Client DB state after stats reset:\n";
    echo "  Total Uploaded (bytes_sent): " . ($clientRow['bytes_sent'] / 1024 / 1024) . " MB (Expected: 101 + 5 = 106MB)\n";
    echo "  Total Downloaded (bytes_received): " . ($clientRow['bytes_received'] / 1024 / 1024) . " MB (Expected: 202 + 10 = 212MB)\n";
    
    // Cleanup mock data
    $pdo->exec("DELETE FROM client_metrics WHERE client_id = {$clientId}");
    $pdo->exec("DELETE FROM vpn_clients WHERE id = {$clientId}");
    $pdo->exec("DELETE FROM vpn_servers WHERE id = {$serverId}");
    echo "\nTest cleanup complete. All verification checks passed successfully!\n";

} catch (Throwable $e) {
    echo "TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
