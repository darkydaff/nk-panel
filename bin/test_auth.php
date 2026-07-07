<?php
/**
 * Test script for Keenetic Router RCI connection & authentication
 * Usage: php test_auth.php <domain> <password> [<login>]
 */

require_once __DIR__ . '/../inc/KeeneticRouter.php';

if ($argc < 3) {
    echo "Usage: php " . $argv[0] . " <domain> <password> [<login>]\n";
    exit(1);
}

$domain = $argv[1];
$password = $argv[2];
$login = isset($argv[3]) ? $argv[3] : 'admin';

echo "Testing connection to router at: $domain (login: $login)...\n";

try {
    $router = new KeeneticRouter($domain, $password, $login);
    
    echo "Authenticating...\n";
    if ($router->authenticate()) {
        echo "Authentication successful!\n\n";
    }
    
    echo "Retrieving system info...\n";
    $connInfo = $router->testConnection();
    if ($connInfo['success']) {
        echo "Router Model: " . $connInfo['router_model'] . "\n";
        echo "OS version:   " . $connInfo['firmware_version'] . "\n\n";
    } else {
        echo "Failed to get system info: " . ($connInfo['error'] ?? 'Unknown error') . "\n\n";
    }
    
    echo "Listing interfaces...\n";
    $interfaces = $router->getInterfaces();
    echo "Found " . count($interfaces) . " network interfaces:\n";
    foreach ($interfaces as $name => $info) {
        $desc = isset($info['description']) ? " - " . $info['description'] : "";
        $type = isset($info['type']) ? " (" . $info['type'] . ")" : "";
        $state = isset($info['state']) ? " [" . $info['state'] . "]" : "";
        echo "  * $name$type$desc$state\n";
    }
    
    echo "\nVerification complete.\n";
    exit(0);
    
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
