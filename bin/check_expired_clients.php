<?php
/**
 * CLI script to check for expired and expiring client subscriptions,
 * and send automatic renewal notifications via Telegram Bot.
 * This runs hourly via cron, but uses a state-machine cache to ensure
 * clients are notified exactly once per status transition.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/TelegramClientBot.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

$logPrefix = '[' . date('Y-m-d H:i:s') . '] ';

try {
    $pdo = DB::conn();

    // 1. Check if Client Bot is enabled
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'enabled' LIMIT 1");
    $stmt->execute();
    $enabled = json_decode($stmt->fetchColumn() ?: 'false', true);

    if (!$enabled) {
        echo $logPrefix . "Telegram client bot is disabled. Skipping notifications.\n";
        exit(0);
    }

    // 2. Get Bot Token
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'bot_token' LIMIT 1");
    $stmt->execute();
    $botToken = json_decode($stmt->fetchColumn() ?: '""', true);

    if (empty($botToken)) {
        echo $logPrefix . "Telegram bot token is not configured. Skipping notifications.\n";
        exit(0);
    }

    // 3. Select all clients with Telegram IDs
    $stmt = $pdo->query("SELECT code, tgid, start_date, sub, func, last_notified_status FROM ext_clients WHERE tgid IS NOT NULL AND tgid != ''");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($clients)) {
        echo $logPrefix . "No clients with Telegram IDs found.\n";
        exit(0);
    }

    $now = time();
    $threeDays = 3 * 24 * 60 * 60; // 3 days warning threshold

    $renewUrl = "https://t.me/pod_vpn_nk?text=" . urlencode("Здравствуйте 👋 \nХочу продлить VPN!");
    $replyMarkup = [
        'inline_keyboard' => [[
            ['text' => '💬 Продлить подписку', 'url' => $renewUrl]
        ]]
    ];

    foreach ($clients as $client) {
        $code = $client['code'];
        $tgid = (int)$client['tgid'];
        $func = strtoupper(trim($client['func'] ?? ''));
        $startDateStr = $client['start_date'] ?? null;
        $subMonths = (int)($client['sub'] ?? 0);
        $lastNotified = $client['last_notified_status'] ?? null;

        $currentStatus = 'ok';
        $daysRemaining = 0;

        if ($func === 'PAUSE') {
            $currentStatus = 'paused';
        } elseif ($func === 'WORK') {
            if ($startDateStr && $subMonths > 0) {
                $daysToAdd = $subMonths * 30;
                $expTime = strtotime($startDateStr . " + {$daysToAdd} days");
                
                if ($expTime <= $now) {
                    $currentStatus = 'expired';
                } elseif ($expTime <= $now + $threeDays) {
                    $currentStatus = 'expiring_soon';
                    $daysRemaining = (int)ceil(($expTime - $now) / (24 * 60 * 60));
                    if ($daysRemaining < 0) $daysRemaining = 0;
                }
            }
        }

        // State Machine checking
        if ($currentStatus === 'ok') {
            // If they were previously warned/expired and now they are OK (renewed), reset state
            if ($lastNotified !== null) {
                $stmtUpdate = $pdo->prepare("UPDATE ext_clients SET last_notified_status = NULL, last_notified_at = NULL WHERE code = ?");
                $stmtUpdate->execute([$code]);
                echo $logPrefix . "Client #{$code} reset from notification state '{$lastNotified}' to 'ok'.\n";
            }
        } elseif ($currentStatus === 'expiring_soon') {
            // Warn if not already warned or expired
            if ($lastNotified !== 'expiring_soon' && $lastNotified !== 'expired') {
                $daysStr = $daysRemaining . " " . ($daysRemaining === 1 ? 'день' : (($daysRemaining > 1 && $daysRemaining < 5) ? 'дня' : 'дней'));
                $text = "⚠️ **Ваша подписка на VPN (Код: `{$code}`) истекает через {$daysStr}.**\n\nЧтобы оставаться на связи, не забудьте вовремя продлить её.";
                
                try {
                    TelegramClientBot::sendMessage($tgid, $text, $botToken, $replyMarkup);
                    
                    $stmtUpdate = $pdo->prepare("UPDATE ext_clients SET last_notified_status = 'expiring_soon', last_notified_at = NOW() WHERE code = ?");
                    $stmtUpdate->execute([$code]);
                    echo $logPrefix . "Sent expiring_soon warning to Client #{$code} (TG ID: {$tgid}).\n";
                } catch (Throwable $e) {
                    echo $logPrefix . "ERROR sending warning to Client #{$code}: " . $e->getMessage() . "\n";
                }
            }
        } elseif ($currentStatus === 'expired') {
            // Notify if not already notified as expired
            if ($lastNotified !== 'expired') {
                $text = "⚠️ **Ваша подписка на VPN (Код: `{$code}`) истекла.**\n\nДля продления подписки, пожалуйста, свяжитесь с поддержкой.";
                
                try {
                    TelegramClientBot::sendMessage($tgid, $text, $botToken, $replyMarkup);
                    
                    $stmtUpdate = $pdo->prepare("UPDATE ext_clients SET last_notified_status = 'expired', last_notified_at = NOW() WHERE code = ?");
                    $stmtUpdate->execute([$code]);
                    echo $logPrefix . "Sent expiration notification to Client #{$code} (TG ID: {$tgid}).\n";
                } catch (Throwable $e) {
                    echo $logPrefix . "ERROR sending expiration notification to Client #{$code}: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    echo $logPrefix . "Client subscription check completed.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, $logPrefix . "ERROR in check_expired_clients: " . $e->getMessage() . "\n");
    exit(1);
}
