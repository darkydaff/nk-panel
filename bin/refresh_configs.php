<?php
/**
 * Refresh all client configurations to apply new naming format
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';

$pdo = DB::conn();

// Get all clients
$stmt = $pdo->query('SELECT * FROM vpn_clients');
$clients = $stmt->fetchAll();

echo "Refreshing " . count($clients) . " client configurations...\n";

$updated = 0;
foreach ($clients as $c) {
    try {
        $server = new VpnServer($c['server_id']);
        $serverData = $server->getData();
        
        if (!$serverData) continue;
        
        // Use reflection to call private static buildClientConfig if needed, 
        // but it's easier to just add a public method or just replicate logic.
        // Actually, let's make buildClientConfig protected or public if we want to use it here.
        // For now, I'll use direct access if I can or just replicate.
        
        // Actually, the easiest is to add a public method 'regenerateConfig' to VpnClient.
        
        $client = new VpnClient($c['id']);
        // We'll add this method in VpnClient.php
        if (method_exists($client, 'regenerateConfig')) {
            $client->regenerateConfig();
            $updated++;
            echo "Updated client #{$c['id']} ({$c['name']})\n";
        } else {
            echo "Method regenerateConfig not found in VpnClient\n";
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "Error updating client #{$c['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Done! $updated configurations updated.\n";
