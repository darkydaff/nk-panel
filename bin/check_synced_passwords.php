<?php
/**
 * CLI script to diagnose synced passwords and check format
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/KeeneticRouter.php';

Config::load(__DIR__ . '/../.env');

try {
    $pdo = DB::conn();
    $stmt = $pdo->query("SELECT id, ext_client_code, domain, password, login FROM routers LIMIT 15");
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== PASSWORD DIAGNOSTIC ===\n";
    foreach ($routers as $r) {
        $pass = $r['password'];
        $len = strlen($pass);
        $isHex = preg_match('/^[a-f0-9]+$/i', $pass);
        
        echo "Client Code: {$r['ext_client_code']}\n";
        echo "  Domain: {$r['domain']}\n";
        echo "  Password Length: {$len}\n";
        echo "  Is Hex-only (could be hash): " . ($isHex ? "YES" : "NO") . "\n";
        
        // Print first 2 and last 2 characters to avoid showing password completely
        if ($len > 4) {
            echo "  Format: " . substr($pass, 0, 2) . str_repeat('*', $len - 4) . substr($pass, -2) . "\n";
        } else {
            echo "  Format: " . str_repeat('*', $len) . "\n";
        }
        
        // Check character encoding
        $isUtf8 = mb_check_encoding($pass, 'UTF-8');
        echo "  Is UTF-8: " . ($isUtf8 ? "YES" : "NO") . "\n";
        
        // Attempt authentication directly to debug
        try {
            $adapter = new KeeneticRouter($r['domain'], $r['password'], $r['login']);
            $adapter->setTimeout(3);
            echo "  Attempting auth...\n";
            $ok = $adapter->authenticate();
            echo "  [OK] Auth successful!\n";
        } catch (Exception $e) {
            echo "  [FAIL] " . $e->getMessage() . "\n";
        }
        
        echo "---------------------------------\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
