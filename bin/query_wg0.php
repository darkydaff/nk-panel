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

echo "Querying interface Wireguard0...\n";
$res1 = $adapter->request("rci/interface/Wireguard0");
echo "Interface config GET Result:\n" . json_encode($res1['body'], JSON_PRETTY_PRINT) . "\n";

echo "Querying show interface Wireguard0...\n";
$res2 = $adapter->request("rci/show/interface/Wireguard0");
echo "Show Interface GET Result:\n" . json_encode($res2['body'], JSON_PRETTY_PRINT) . "\n";
