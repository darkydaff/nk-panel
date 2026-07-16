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
            self::sendMessage($chatId, "❌ **Доступ запрещен**\n\nВаш Telegram ID: `{$tgId}`\nДанный ID не привязан ни к одному клиенту в биллинге. Пожалуйста, сообщите этот ID администратору для привязки к вашей подписке.", $token);
            if ($callbackQueryId) {
                self::answerCallbackQuery($callbackQueryId, "Доступ запрещен", false, $token);
            }
            return;
        }

        // Handle Callback Query (Buttons)
        if ($callbackData) {
            self::handleCallback($chatId, $tgId, $tgName, $callbackQueryId, $callbackData, $clients, $token);
            return;
        }

        // Handle Command
        if (strpos($messageText, '/start') === 0 || strpos($messageText, '/help') === 0) {
            self::showMainMenu($chatId, $tgName, $clients, $token);
        } else {
            self::sendMessage($chatId, "Пожалуйста, используйте кнопки меню для управления серверами роутеров.", $token);
        }
    }

    private static function showMainMenu(int $chatId, string $tgName, array $clients, string $token): void {
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
            $status = ($client['func'] === 'active' || (int)$client['sub'] > 0) ? '🟢 Активна' : '🔴 Приостановлена';
            $exp = $client['start_date'] ? date('Y-m-d', strtotime($client['start_date'] . " + " . ($client['sub'] ?? 0) . " days")) : 'Без лимита';
            $text .= "🔑 Код: `{$client['code']}` | {$status} | До: {$exp}\n";
        }

        $keyboard = ['inline_keyboard' => []];
        if (!empty($routers)) {
            $text .= "\nПожалуйста, выберите роутер для управления:";
            foreach ($routers as $r) {
                $statusIcon = ($r['status'] === 'connected') ? '🟢' : (($r['status'] === 'offline') ? '⚪' : '⚠️');
                $keyboard['inline_keyboard'][] = [[
                    'text' => "{$statusIcon} Роутер: {$r['domain']}",
                    'callback_data' => "select_router:{$r['id']}"
                ]];
            }
        } else {
            $text .= "\nЗа вашими кодами подписок не закреплено ни одного настроенного роутера.";
        }

        self::sendMessage($chatId, $text, $token, $keyboard);
    }

    private static function handleCallback(int $chatId, string $tgId, string $tgName, string $callbackQueryId, string $callbackData, array $clients, string $token): void {
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
            self::answerCallbackQuery($callbackQueryId, "", false, $token);
            self::showMainMenu($chatId, $tgName, $clients, $token);
            return;
        }

        if ($action === 'select_router') {
            require_once __DIR__ . '/RouterManager.php';
            // Check current router status asynchronously if needed, or get from DB
            $statusInfo = RouterManager::checkRouterStatus($routerId);
            
            // Refetch router to get updated check timestamp and status
            $stmt = $pdo->prepare("SELECT r.*, s.name as server_name FROM routers r LEFT JOIN vpn_servers s ON r.server_id = s.id WHERE r.id = ?");
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

            $text = "📶 **Роутер: {$router['domain']}**\n";
            $text .= "🔹 Модель: " . ($router['router_model'] ?: 'Keenetic') . "\n";
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

            self::sendMessage($chatId, $text, $token, $keyboard);
            self::answerCallbackQuery($callbackQueryId, "Обновлено", false, $token);
            return;
        }

        if ($action === 'change_server') {
            // List all active servers
            $stmt = $pdo->query("SELECT id, name FROM vpn_servers WHERE status = 'active' ORDER BY name ASC");
            $servers = $stmt->fetchAll();

            $text = "🌍 **Выберите новый сервер для роутера {$router['domain']}:**";
            $keyboard = ['inline_keyboard' => []];
            foreach ($servers as $s) {
                $keyboard['inline_keyboard'][] = [[
                    'text' => $s['name'],
                    'callback_data' => "set_server:{$routerId}:{$s['id']}"
                ]];
            }
            $keyboard['inline_keyboard'][] = [[
                'text' => '🔙 Назад',
                'callback_data' => "select_router:{$routerId}"
            ]];

            self::sendMessage($chatId, $text, $token, $keyboard);
            self::answerCallbackQuery($callbackQueryId, "", false, $token);
            return;
        }

          if ($action === 'set_server') {
              $serverId = (int)$parts[2];
              
              $stmt = $pdo->prepare("SELECT * FROM vpn_servers WHERE id = ? AND status = 'active'");
              $stmt->execute([$serverId]);
              $server = $stmt->fetch();
              
              if (!$server) {
                  self::answerCallbackQuery($callbackQueryId, "Выбранный сервер недоступен", true, $token);
                  return;
              }

              self::answerCallbackQuery($callbackQueryId, "", false, $token);
              $progressMsgId = self::sendMessage($chatId, "🔄 *Переключаем сервер на {$server['name']}... Пожалуйста, подождите, это может занять до 15 секунд.*", $token);

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
                      // Sanitize and clean client code for safe naming standard
                      $safeCode = preg_replace('/[^a-zA-Z0-9.-]/', '_', $extCode);
                      $clientName = 'tg_' . $safeCode . '_' . $serverId;
                      
                      $userId = (int)$server['user_id'];
                      $clientId = VpnClient::create($serverId, $userId, $clientName, null);
                      
                      // Explicitly link config to client code
                      $vpnClient = new VpnClient($clientId);
                      $vpnClient->linkToExtClient($extCode);
                  }

                  // Push to Router
                  RouterManager::pushConfigToRouter($routerId, $clientId);

                  // Update progress message to success
                  self::editMessageText($chatId, $progressMsgId, "✅ **Сервер успешно изменен!** Роутер переключен на сервер **{$server['name']}**.", $token);
              } catch (Throwable $e) {
                  self::editMessageText($chatId, $progressMsgId, "❌ **Ошибка при переключении сервера:** " . $e->getMessage(), $token);
              }
              return;
          }
    }

    private static function sendMessage(int $chatId, string $text, string $token, ?array $replyMarkup = null): int {
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

    private static function editMessageText(int $chatId, int $messageId, string $text, string $token): void {
        $url = "https://api.telegram.org/bot{$token}/editMessageText";
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

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
        curl_close($ch);
    }
}
