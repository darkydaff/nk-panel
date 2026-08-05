<?php
/**
 * Telegram Client Bot CLI Long-Polling Daemon
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/TelegramClientBot.php';

Config::load(__DIR__ . '/../.env');

if (!TelegramClientBot::isEnabled()) {
    echo "[" . date('Y-m-d H:i:s') . "] Telegram bot is disabled in settings. Exiting.\n";
    exit(0);
}

$token = TelegramClientBot::getBotToken();
if (!$token) {
    echo "[" . date('Y-m-d H:i:s') . "] Telegram bot token not configured. Exiting.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting Telegram Bot Daemon (Long-Polling mode)...\n";

$offset = 0;
$url = "https://api.telegram.org/bot{$token}/getUpdates";

while (true) {
    // Check if bot is disabled at runtime
    if (!TelegramClientBot::isEnabled()) {
        echo "[" . date('Y-m-d H:i:s') . "] Telegram bot disabled at runtime. Exiting.\n";
        exit(0);
    }

    $pollUrl = $url . "?offset=" . $offset . "&timeout=30";
    
    $ch = curl_init($pollUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    Config::applyCurlProxy($ch);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "[" . date('Y-m-d H:i:s') . "] Error fetching updates (HTTP Code: {$httpCode}). Sleeping 5s...\n";
        sleep(5);
        continue;
    }

    $data = json_decode($res, true);
    if (isset($data['result']) && is_array($data['result'])) {
        foreach ($data['result'] as $update) {
            $offset = $update['update_id'] + 1;
            try {
                TelegramClientBot::handleUpdate($update);
            } catch (Throwable $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Update error: " . $e->getMessage() . "\n";
            }
        }
    }

    // Small sleep to control loop pacing
    usleep(200000); // 200ms
}
