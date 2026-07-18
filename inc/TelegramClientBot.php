<?php

class TelegramClientBot {
    
    public static function getBotToken(): ?string {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'bot_token' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val ? json_decode($val, true) : null;
    }

    public static function isEnabled(): bool {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'enabled' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val ? (bool)json_decode($val, true) : false;
    }

    public static function handleUpdate(array $update): void {
        $token = self::getBotToken();
        if (!$token) {
            return;
        }

        $chatId = null;
        $tgId = null;
        $tgName = 'Пользователь';
        $callbackQueryId = null;
        $callbackData = null;
        $messageText = null;

        if (isset($update['message'])) {
            $chatId = $update['message']['chat']['id'];
            $tgId = $update['message']['from']['id'];
            $tgName = $update['message']['from']['first_name'] ?? ($update['message']['from']['username'] ?? 'Пользователь');
            $messageText = trim($update['message']['text'] ?? '');
        } elseif (isset($update['callback_query'])) {
            $chatId = $update['callback_query']['message']['chat']['id'];
            $tgId = $update['callback_query']['from']['id'];
            $tgName = $update['callback_query']['from']['first_name'] ?? ($update['callback_query']['from']['username'] ?? 'Пользователь');
            $callbackQueryId = $update['callback_query']['id'];
            $callbackData = $update['callback_query']['data'];
        }

        if (!$chatId || !$tgId) {
            return;
        }

        // Check authorization
        $pdo = DB::conn();
        $stmt = $pdo->prepare("SELECT * FROM ext_clients WHERE tgid = ?");
        $stmt->execute([$tgId]);
        $clients = $stmt->fetchAll();

        if (empty($clients)) {
            self::logActivity($tgId, $tgName, null, 'unauthorized', "Access Denied. Message: '" . ($messageText ?? '') . "' Callback: '" . ($callbackData ?? '') . "'", json_encode($update));
            self::sendMessage($chatId, "❌ **Доступ запрещен**\n\nВаш Telegram ID: `{$tgId}`\nДанный ID не привязан ни к одному клиенту в биллинге. Пожалуйста, сообщите этот ID администратору для привязки к вашей подписке.", $token);
            if ($callbackQueryId) {
                self::answerCallbackQuery($callbackQueryId, "Доступ запрещен", false, $token);
            }
            return;
        }

        // Handle Callback Query (Buttons)
        if ($callbackData) {
            $messageId = $update['callback_query']['message']['message_id'] ?? null;
            self::handleCallback($chatId, $messageId, $tgId, $tgName, $callbackQueryId, $callbackData, $clients, $token);
            return;
        }

        // Handle Command
        $normalizedText = strtolower($messageText);
        $clientCodesStr = implode(',', array_column($clients, 'code'));
        if (strpos($messageText, '/start') === 0 || strpos($messageText, '/help') === 0) {
            self::logActivity($tgId, $tgName, $clientCodesStr, 'view_menu', 'Opened main menu via start/help command', json_encode($update));
            self::showMainMenu($chatId, $tgName, $clients, $token);
        } elseif ($normalizedText === 'show version' || $normalizedText === 'rci show/version' || $normalizedText === '/show_version' || $normalizedText === '/version' || $normalizedText === '/showversion') {
            self::logActivity($tgId, $tgName, $clientCodesStr, 'show_version', 'Checked router firmware versions', json_encode($update));
            self::handleShowVersion($chatId, $clients, $token);
        } else {
            self::logActivity($tgId, $tgName, $clientCodesStr, 'unknown_command', "Sent unknown message: '" . ($messageText ?? '') . "'", json_encode($update));
            self::sendMessage($chatId, "Пожалуйста, используйте кнопки меню для управления серверами роутеров.", $token);
        }
    }

    private static function showMainMenu(int $chatId, string $tgName, array $clients, string $token, ?int $messageId = null): void {
        $clientCodes = array_column($clients, 'code');
        
        $pdo = DB::conn();
        // Find routers linked to these clients
        $inQuery = implode(',', array_fill(0, count($clientCodes), '?'));
        $stmt = $pdo->prepare("SELECT * FROM routers WHERE ext_client_code IN ($inQuery)");
        $stmt->execute($clientCodes);
        $routers = $stmt->fetchAll();

        $text = "👋 **Приветствуем, {$tgName}!**\n\n";
        $text .= "**Ваши подписки:**\n";
        foreach ($clients as $client) {
            $func = strtoupper(trim($client['func'] ?? ''));
            $startDateStr = $client['start_date'] ?? null;
            $subMonths = (int)($client['sub'] ?? 0);
            
            $status = '⚪ Неизвестно';
            $exp = 'Без лимита';
            
            if ($func === 'PAUSE') {
                $status = '🔴 Приостановлена';
                if ($startDateStr) {
                    $daysToAdd = $subMonths * 30;
                    $exp = date('d.m.Y', strtotime($startDateStr . " + {$daysToAdd} days"));
                }
            } elseif ($func === 'WORK') {
                if ($startDateStr && $subMonths > 0) {
                    $daysToAdd = $subMonths * 30;
                    $expTime = strtotime($startDateStr . " + {$daysToAdd} days");
                    $exp = date('d.m.Y', $expTime);
                    
                    $now = time();
                    $tenDaysFromNow = $now + (10 * 24 * 60 * 60);
                    
                    if ($expTime <= $now) {
                        $status = '🔴 Истекла';
                    } elseif ($expTime <= $tenDaysFromNow) {
                        $status = '🟡 Истекает скоро';
                    } else {
                        $status = '🟢 Активна';
                    }
                } else {
                    $status = '🟢 Активна';
                    $exp = 'Без лимита';
                }
            } else {
                // Fallback
                $status = '⚪ Неизвестно';
                if ($startDateStr) {
                    $daysToAdd = $subMonths * 30;
                    $exp = date('d.m.Y', strtotime($startDateStr . " + {$daysToAdd} days"));
                }
            }
            $text .= "🔑 Код: `{$client['code']}` | {$status} | До: {$exp}\n";
        }

        $keyboard = ['inline_keyboard' => []];
        if (!empty($routers)) {
            $text .= "\nПожалуйста, выберите роутер для управления:";
            foreach ($routers as $r) {
                $statusIcon = ($r['status'] === 'connected') ? '🟢' : (($r['status'] === 'offline') ? '⚪' : '⚠️');
                $routerName = $r['router_model'] ?: $r['domain'];
                $keyboard['inline_keyboard'][] = [[
                    'text' => "{$statusIcon} Роутер: {$routerName}",
                    'callback_data' => "select_router:{$r['id']}"
                ]];
            }
        } else {
            $text .= "\nЗа вашими кодами подписок не закреплено ни одного настроенного роутера.";
        }

        // Add permanent Support/Renew button
        $renewUrl = "https://t.me/pod_vpn_nk?text=" . urlencode("Здравствуйте 👋 \nХочу продлить VPN!");
        $keyboard['inline_keyboard'][] = [[
            'text' => '💬 Поддержка / Продлить подписку',
            'url' => $renewUrl
        ]];

        if ($messageId) {
            self::editMessageText($chatId, $messageId, $text, $token, $keyboard);
        } else {
            self::sendMessage($chatId, $text, $token, $keyboard);
        }
    }

    private static function handleCallback(int $chatId, ?int $messageId, string $tgId, string $tgName, string $callbackQueryId, string $callbackData, array $clients, string $token): void {
        $parts = explode(':', $callbackData);
        $action = $parts[0];
        $routerId = isset($parts[1]) ? (int)$parts[1] : null;
        
        $pdo = DB::conn();
        $clientCodes = array_column($clients, 'code');

        // Check router ownership
        if ($routerId) {
            $stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch();
            if (!$router || !in_array($router['ext_client_code'], $clientCodes)) {
                self::answerCallbackQuery($callbackQueryId, "Ошибка доступа к роутеру", true, $token);
                return;
            }
        }

        if ($action === 'main_list') {
            self::logActivity($tgId, $tgName, implode(',', $clientCodes), 'view_menu', 'Opened main menu via callback', json_encode($update));
            self::answerCallbackQuery($callbackQueryId, "", false, $token);
            self::showMainMenu($chatId, $tgName, $clients, $token, $messageId);
            return;
        }

        if ($action === 'select_router') {
            require_once __DIR__ . '/RouterManager.php';
            // Check current router status asynchronously if needed, or get from DB
            $statusInfo = RouterManager::checkRouterStatus($routerId);
            
            // Refetch router to get updated check timestamp and status
            $stmt = $pdo->prepare("SELECT r.*, s.name as server_name, s.description as server_desc FROM routers r LEFT JOIN vpn_servers s ON r.server_id = s.id WHERE r.id = ?");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch();

            $statusMap = [
                'connected' => '🟢 Подключен',
                'offline' => '⚪ Вне сети',
                'error' => '⚠️ Ошибка',
                'pending' => '🔄 Ожидание',
                'unknown' => '❔ Неизвестно'
            ];
            $statusStr = $statusMap[$router['status']] ?? $router['status'];
            $serverName = $router['server_name'] ?: 'Не назначен';
            if ($router['server_name'] && !empty($router['server_desc'])) {
                $serverName = $router['server_desc'] . " [" . $router['server_name'] . "]";
            }

            $routerName = $router['router_model'] ?: $router['domain'];
            self::logActivity($tgId, $tgName, $router['ext_client_code'], 'select_router', "Selected router: {$routerName} (ID: {$routerId}), Status: {$statusStr}", json_encode($update));

            $text = "📶 **Роутер: {$routerName}**\n";
            if ($router['firmware_version']) {
                $text .= "🔹 Версия OS: `{$router['firmware_version']}`\n";
            }
            $text .= "🔹 Статус: `{$statusStr}`\n";
            $text .= "🔹 Текущий сервер: **{$serverName}**\n";
            if ($router['error_message']) {
                $text .= "⚠️ Ошибка: _" . htmlspecialchars($router['error_message']) . "_\n";
            }

            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '🔄 Сменить сервер', 'callback_data' => "change_server:{$routerId}"],
                    ['text' => '⚡ Обновить', 'callback_data' => "select_router:{$routerId}"]
                ],
                [
                    ['text' => '🔙 Назад к списку', 'callback_data' => 'main_list']
                ]
            ]];

            if ($messageId) {
                self::editMessageText($chatId, $messageId, $text, $token, $keyboard);
            } else {
                self::sendMessage($chatId, $text, $token, $keyboard);
            }
            self::answerCallbackQuery($callbackQueryId, "Обновлено", false, $token);
            return;
        }

        if ($action === 'change_server') {
            // List all active servers and apply access control filters
            $stmt = $pdo->query("SELECT id, name, description, show_in_bot, allowed_clients, blocked_clients FROM vpn_servers WHERE status = 'active' ORDER BY name ASC");
            $allServers = $stmt->fetchAll();

            $servers = [];
            $clientCode = trim($router['ext_client_code'] ?? '');
            foreach ($allServers as $s) {
                // 1. Global visibility check
                if (isset($s['show_in_bot']) && !(bool)$s['show_in_bot']) {
                    continue;
                }
                // 2. Whitelist check
                if (!empty($s['allowed_clients'])) {
                    $allowed = array_filter(array_map('trim', explode(',', $s['allowed_clients'])));
                    if (!empty($allowed) && !in_array($clientCode, $allowed)) {
                        continue;
                    }
                }
                // 3. Blacklist check
                if (!empty($s['blocked_clients'])) {
                    $blocked = array_filter(array_map('trim', explode(',', $s['blocked_clients'])));
                    if (!empty($blocked) && in_array($clientCode, $blocked)) {
                        continue;
                    }
                }
                $servers[] = $s;
            }

            $routerName = $router['router_model'] ?: $router['domain'];
            self::logActivity($tgId, $tgName, $router['ext_client_code'], 'view_servers', "Requested server list for router: {$routerName} (ID: {$routerId})", json_encode($update));

            $text = "🌍 **Выберите новый сервер для роутера {$routerName}:**";
            $keyboard = ['inline_keyboard' => []];
            foreach ($servers as $s) {
                $displayText = $s['name'];
                if (!empty($s['description'])) {
                    $displayText = $s['description'] . " [" . $s['name'] . "]";
                }
                $keyboard['inline_keyboard'][] = [[
                    'text' => $displayText,
                    'callback_data' => "set_server:{$routerId}:{$s['id']}"
                ]];
            }
            $keyboard['inline_keyboard'][] = [[
                'text' => '🔙 Назад',
                'callback_data' => "select_router:{$routerId}"
            ]];

            if ($messageId) {
                self::editMessageText($chatId, $messageId, $text, $token, $keyboard);
            } else {
                self::sendMessage($chatId, $text, $token, $keyboard);
            }
            self::answerCallbackQuery($callbackQueryId, "", false, $token);
            return;
        }

        if ($action === 'set_server') {
            $serverId = (int)$parts[2];
            $routerName = $router['router_model'] ?: $router['domain'];
            
            $stmt = $pdo->prepare("SELECT * FROM vpn_servers WHERE id = ? AND status = 'active'");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            if (!$server) {
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server_error', "Failed to switch server for router: {$routerName} (ID: {$routerId}). Error: Selected server ID {$serverId} is unavailable.", json_encode($update));
                self::answerCallbackQuery($callbackQueryId, "Выбранный сервер недоступен", true, $token);
                return;
            }

            // Access Control checks
            $clientCode = trim($router['ext_client_code'] ?? '');
            $isAllowed = true;
            
            // 1. Global visibility check
            if (isset($server['show_in_bot']) && !(bool)$server['show_in_bot']) {
                $isAllowed = false;
            }
            // 2. Whitelist check
            if ($isAllowed && !empty($server['allowed_clients'])) {
                $allowed = array_filter(array_map('trim', explode(',', $server['allowed_clients'])));
                if (!empty($allowed) && !in_array($clientCode, $allowed)) {
                    $isAllowed = false;
                }
            }
            // 3. Blacklist check
            if ($isAllowed && !empty($server['blocked_clients'])) {
                $blocked = array_filter(array_map('trim', explode(',', $server['blocked_clients'])));
                if (!empty($blocked) && in_array($clientCode, $blocked)) {
                    $isAllowed = false;
                }
            }

            if (!$isAllowed) {
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'unauthorized', "Access Denied: User has no access to server '{$server['name']}' (ID: {$serverId}) for router: {$routerName} (ID: {$routerId})", json_encode($update));
                self::answerCallbackQuery($callbackQueryId, "Вы не имеете доступа к этому серверу", true, $token);
                return;
            }

            $serverLabel = $server['name'];
            if (!empty($server['description'])) {
                $serverLabel = $server['description'] . " [" . $server['name'] . "]";
            }

            self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server', "Initiating server switch for router: {$routerName} (ID: {$routerId}) to server: {$serverLabel} (ID: {$serverId})", json_encode($update));
            self::answerCallbackQuery($callbackQueryId, "", false, $token);
            
            if ($messageId) {
                self::editMessageText($chatId, $messageId, "🔄 *Переключаем сервер на {$serverLabel}... Пожалуйста, подождите, это может занять до 15 секунд.*", $token);
            } else {
                $messageId = self::sendMessage($chatId, "🔄 *Переключаем сервер на {$serverLabel}... Пожалуйста, подождите, это может занять до 15 секунд.*", $token);
            }

            try {
                require_once __DIR__ . '/VpnClient.php';
                require_once __DIR__ . '/RouterManager.php';

                $extCode = $router['ext_client_code'];
                
                // Check if a client configuration already exists on the server
                $stmtClient = $pdo->prepare("SELECT id FROM vpn_clients WHERE ext_client_code = ? AND server_id = ? AND status = 'active' LIMIT 1");
                $stmtClient->execute([$extCode, $serverId]);
                $clientId = $stmtClient->fetchColumn();

                if (!$clientId) {
                    // Create new client config
                    // Sanitize and clean client code and server name for safe naming standard
                    $safeCode = preg_replace('/[^a-zA-Z0-9.-]/', '_', $extCode);
                    $safeServerName = preg_replace('/[^a-zA-Z0-9.-]/', '_', $server['name']);
                    $clientName = 'tg_' . $safeCode . '_' . $safeServerName;
                    
                    $userId = (int)$server['user_id'];
                    $clientId = VpnClient::create($serverId, $userId, $clientName, null);
                    
                    // Explicitly link config to client code
                    $vpnClient = new VpnClient($clientId);
                    $vpnClient->linkToExtClient($extCode);
                }

                // Push to Router
                RouterManager::pushConfigToRouter($routerId, $clientId);

                // Update progress message to success
                $backKeyboard = ['inline_keyboard' => [[
                    ['text' => '🔙 К роутеру', 'callback_data' => "select_router:{$routerId}"]
                ]]];
                self::editMessageText($chatId, $messageId, "✅ **Сервер успешно изменен!** Роутер переключен на сервер **{$serverLabel}**.", $token, $backKeyboard);
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server_success', "Successfully switched router: {$routerName} (ID: {$routerId}) to server: {$serverLabel}", json_encode($update));
            } catch (Throwable $e) {
                $errKeyboard = ['inline_keyboard' => [[
                    ['text' => '🔙 К роутеру', 'callback_data' => "select_router:{$routerId}"]
                ]]];
                self::editMessageText($chatId, $messageId, "❌ **Ошибка при переключении сервера:** " . $e->getMessage(), $token, $errKeyboard);
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server_error', "Error switching router: {$routerName} (ID: {$routerId}) to server: {$serverLabel}. Error: " . $e->getMessage(), json_encode($update));
            }
            return;
        }
    }

    private static function handleShowVersion(int $chatId, array $clients, string $token): void {
        $clientCodes = array_column($clients, 'code');
        
        $pdo = DB::conn();
        // Find routers linked to these clients
        $inQuery = implode(',', array_fill(0, count($clientCodes), '?'));
        $stmt = $pdo->prepare("SELECT * FROM routers WHERE ext_client_code IN ($inQuery)");
        $stmt->execute($clientCodes);
        $routers = $stmt->fetchAll();

        if (empty($routers)) {
            self::sendMessage($chatId, "За вашими кодами подписок не закреплено ни одного настроенного роутера.", $token);
            return;
        }

        $response = "";
        foreach ($routers as $r) {
            $domain = $r['domain'];
            $description = $r['router_model'] ?? '';
            $osVersion = $r['firmware_version'] ?? '';
            
            if (!empty($description) && !empty($osVersion)) {
                $response .= "📶 **Роутер: {$description}**\n";
                $response .= "🔹 Версия OS: `{$osVersion}`\n\n";
            } else {
                require_once __DIR__ . '/KeeneticRouter.php';
                try {
                    $login = $r['login'] ?: 'admin';
                    $password = $r['password'];
                    $adapter = new KeeneticRouter($domain, $password, $login);
                    $adapter->setTimeout(5);
                    
                    $connTest = $adapter->testConnection();
                    if ($connTest['success']) {
                        $description = $connTest['router_model'];
                        $osVersion = $connTest['firmware_version'];
                        
                        // Save to database
                        $stmtUpdate = $pdo->prepare("UPDATE routers SET router_model = ?, firmware_version = ?, last_check_at = NOW() WHERE id = ?");
                        $stmtUpdate->execute([$description, $osVersion, $r['id']]);
                        
                        $response .= "📶 **Роутер: {$description}**\n";
                        $response .= "🔹 Версия OS: `{$osVersion}`\n\n";
                    } else {
                        $response .= "📶 **Роутер: {$domain}**\n";
                        $response .= "❌ Не удалось получить данные от роутера: " . ($connTest['error'] ?? 'Unknown error') . "\n\n";
                    }
                } catch (Throwable $e) {
                    $response .= "📶 **Роутер: {$domain}**\n";
                    $response .= "❌ Ошибка подключения: " . $e->getMessage() . "\n\n";
                }
            }
        }
        
        self::sendMessage($chatId, rtrim($response), $token);
    }

    public static function sendMessage(int $chatId, string $text, string $token, ?array $replyMarkup = null): int {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($res, true);
        return $data['result']['message_id'] ?? 0;
    }

    private static function editMessageText(int $chatId, int $messageId, string $text, string $token, ?array $replyMarkup = null): void {
        $url = "https://api.telegram.org/bot{$token}/editMessageText";
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }

    private static function answerCallbackQuery(string $callbackQueryId, string $text, bool $showAlert, string $token): void {
        $url = "https://api.telegram.org/bot{$token}/answerCallbackQuery";
        $params = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $res = curl_close($ch);
    }

    public static function logActivity(int $tgId, string $tgName, ?string $clientCode, string $action, ?string $details = null, ?string $rawData = null): void {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare("
                INSERT INTO bot_activity_logs (tg_id, tg_name, client_code, action, details, raw_data)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tgId, $tgName, $clientCode, $action, $details, $rawData]);
        } catch (Throwable $e) {
            error_log("Failed to log bot activity: " . $e->getMessage());
        }
    }
}

