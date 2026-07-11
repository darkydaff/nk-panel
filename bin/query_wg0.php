<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/KeeneticRouter.php';

Config::load(__DIR__ . '/../.env');

if ($argc < 2) {
    echo "Usage: php bin/query_wg0.php <ext_client_code_or_router_id>\n";
    exit(1);
}

$id = $argv[1];
$pdo = DB::conn();
$stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ? OR ext_client_code = ?");
$stmt->execute([$id, $id]);
$router = $stmt->fetch();

if (!$router) {
    echo "Router not found in database.\n";
    exit(1);
}

$login = $router['login'] ?: 'admin';
$adapter = new KeeneticRouter($router['domain'], $router['password'], $login);

$interfaceId = $router['wg_interface_id'];
if (empty($interfaceId)) {
    try {
        $interfaces = $adapter->getInterfaces();
        foreach (array_keys($interfaces) as $name) {
            if (str_starts_with(strtolower($name), 'wireguard')) {
                $interfaceId = $name;
                break;
            }
        }
    } catch (Throwable $e) {
        // Ignore
    }
}
if (empty($interfaceId)) {
    $interfaceId = 'Wireguard0';
}

echo "Querying interface {$interfaceId}...\n";
$res1 = $adapter->request("rci/interface/{$interfaceId}");
echo "Interface config GET Result:\n" . json_encode($res1['body'], JSON_PRETTY_PRINT) . "\n";

echo "Querying show interface {$interfaceId}...\n";
$res2 = $adapter->request("rci/show/interface/{$interfaceId}");
echo "Show Interface GET Result:\n" . json_encode($res2['body'], JSON_PRETTY_PRINT) . "\n";

echo "Querying ip policy config...\n";
$res3 = $adapter->request("rci/ip/policy");
echo "IP Policy config GET Result:\n" . json_encode($res3['body'], JSON_PRETTY_PRINT) . "\n";

echo "Querying show ip policy status...\n";
$res4 = $adapter->request("rci/show/ip/policy");
echo "Show IP Policy GET Result:\n" . json_encode($res4['body'], JSON_PRETTY_PRINT) . "\n";
