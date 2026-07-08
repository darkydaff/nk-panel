<?php
require_once __DIR__ . '/../inc/KeeneticRouter.php';

$router = [
    'domain' => 'https://darkydaff.netcraze.link/',
    'password' => 'w11q22e3',
    'login' => 'admin'
];

$adapter = new KeeneticRouter($router['domain'], $router['password'], $router['login']);

echo "Querying interface Wireguard0...\n";
$res1 = $adapter->request("rci/interface/Wireguard0");
echo "Interface config GET Result:\n" . json_encode($res1['body'], JSON_PRETTY_PRINT) . "\n";

echo "Querying show interface Wireguard0...\n";
$res2 = $adapter->request("rci/show/interface/Wireguard0");
echo "Show Interface GET Result:\n" . json_encode($res2['body'], JSON_PRETTY_PRINT) . "\n";
