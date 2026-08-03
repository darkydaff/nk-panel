# NK-VPN Telegram Client Bot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a Telegram bot allowing VPN clients to view their subscription, list their routers, and switch their router's VPN server self-service.

**Architecture:** Use a class-based approach `inc/TelegramClientBot.php` that handles Telegram updates. Updates are fed either via an HTTP Webhook endpoint (`/api/telegram-bot/webhook`) or a CLI polling daemon (`bin/telegram_bot_daemon.php`). Settings are managed in the settings page.

**Tech Stack:** PHP 8.2, MySQL 8.4, PostgreSQL, Twig, Telegram Bot API.

---

### Task 1: Database Migration & Schema Updates

**Files:**
- Create: `migrations/031_add_tgid_to_ext_clients.sql`
- Modify: `inc/DB.php`

- [ ] **Step 1: Create SQL migration file**
  Write file contents to `migrations/031_add_tgid_to_ext_clients.sql`:
  ```sql
  -- Add tgid field to ext_clients table to store Telegram ID
  ALTER TABLE ext_clients
  ADD COLUMN tgid VARCHAR(50) NULL;
  ```

- [ ] **Step 2: Update database auto-migration function**
  Modify `inc/DB.php` around line 220, before closing the `checkAndRunMigrations` function, to check and execute migration `031`:
  ```php
        // Check if tgid column exists in ext_clients
        try {
          $stmtExtCols5 = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'tgid'");
          $hasTgidCol = $stmtExtCols5->rowCount() > 0;
        } catch (Throwable $e) {
          $hasTgidCol = false;
        }

        if (!$hasTgidCol) {
          $sqlPath = __DIR__ . '/../migrations/031_add_tgid_to_ext_clients.sql';
          if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            $pdo->exec($sql);
          }
        }
  ```

- [ ] **Step 3: Run database migration via test**
  Run: `php bin/test_auth.php` (this establishes a DB connection and triggers migrations)
  Verify: Run `mysql -u amnezia -pamnezia -h 127.0.0.1 -P 3308 -e "DESCRIBE amnezia_panel.ext_clients"` and ensure the `tgid` field exists.

- [ ] **Step 4: Commit DB changes**
  ```bash
  git add migrations/031_add_tgid_to_ext_clients.sql inc/DB.php
  git commit -m "feat(db): add tgid column to ext_clients table and run auto-migration"
  ```

---

### Task 2: Sync Script CLI Updates

**Files:**
- Modify: `bin/sync_external_clients.php`

- [ ] **Step 1: Modify SELECT statement and Insert fields**
  Modify the Postgres select query and local MySQL insert statement to retrieve and save `tgid`:
  ```php
      $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"Pass\", \"tgid\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
  ```
  And updates MySQL inserts:
  ```php
          $insertStmt = $myPdo->prepare('
              INSERT INTO ext_clients (code, name, start_date, sub, func, router, domain, pass, tgid) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE 
                  name = VALUES(name), 
                  start_date = VALUES(start_date), 
                  sub = VALUES(sub), 
                  func = VALUES(func), 
                  router = VALUES(router),
                  domain = VALUES(domain),
                  pass = VALUES(pass),
                  tgid = VALUES(tgid)
          ');
  ```
  Extract and bind the parameter:
  ```php
              $tgid = isset($row['tgid']) ? trim($row['tgid']) : null;
              if ($tgid === '') $tgid = null;

              $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router, $domain, $pass, $tgid]);
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add bin/sync_external_clients.php
  git commit -m "feat(sync): support tgid field synchronization in sync_external_clients.php CLI"
  ```

---

### Task 3: Sync Script Web Route Updates

**Files:**
- Modify: `public/index.php:973-1008`

- [ ] **Step 1: Modify sync endpoint in `public/index.php`**
  Apply the exact same SELECT query modifications and MySQL INSERT binds to `/api/ext-clients/sync` endpoint around lines 973-1008:
  ```php
          $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"Pass\", \"tgid\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
  ```
  ```php
                  INSERT INTO ext_clients (code, name, start_date, sub, func, router, domain, pass, tgid) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE 
                      name = VALUES(name), 
                      start_date = VALUES(start_date), 
                      sub = VALUES(sub), 
                      func = VALUES(func), 
                      router = VALUES(router),
                      domain = VALUES(domain),
                      pass = VALUES(pass),
                      tgid = VALUES(tgid)
  ```
  ```php
                  $tgid = isset($row['tgid']) ? trim($row['tgid']) : null;
                  if ($tgid === '') $tgid = null;

                  $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router, $domain, $pass, $tgid]);
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add public/index.php
  git commit -m "feat(sync): support tgid field synchronization in web sync API"
  ```

---

### Task 4: Telegram Client Bot Class Implementation

**Files:**
- Create: `inc/TelegramClientBot.php`

- [ ] **Step 1: Write core class logic**
  Create `inc/TelegramClientBot.php` with code handling webhook and daemon updates, checking user authorization, generating configs on demand, and switching servers:
  ```php
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
  ```

- [ ] **Step 2: Commit the Bot class**
  ```bash
  git add inc/TelegramClientBot.php
  git commit -m "feat(telegram): implement TelegramClientBot class for Russian self-service bot"
  ```

---

### Task 5: Web API Endpoint

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Add the Webhook route in `public/index.php`**
  Insert the POST route in `public/index.php` right near other API routes (around line 1680):
  ```php
  // API: Telegram Client Bot Webhook
  Router::post('/api/telegram-bot/webhook', function () {
      header('Content-Type: application/json');
      
      require_once __DIR__ . '/../inc/TelegramClientBot.php';
      if (!TelegramClientBot::isEnabled()) {
          http_response_code(403);
          echo json_encode(['error' => 'Bot is disabled']);
          return;
      }
      
      $input = file_get_contents('php://input');
      $update = json_decode($input, true);
      
      if ($update) {
          try {
              TelegramClientBot::handleUpdate($update);
          } catch (Throwable $e) {
              error_log("Telegram webhook handling error: " . $e->getMessage());
          }
      }
      
      echo json_encode(['success' => true]);
  });
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add public/index.php
  git commit -m "feat(telegram): add webhook API endpoint for client bot"
  ```

---

### Task 6: CLI Daemon Polling Implementation

**Files:**
- Create: `bin/telegram_bot_daemon.php`

- [ ] **Step 1: Implement long-polling loop**
  Write daemon logic inside `bin/telegram_bot_daemon.php`:
  ```php
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
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add bin/telegram_bot_daemon.php
  git commit -m "feat(telegram): implement long-polling daemon bin script"
  ```

---

### Task 7: Settings Controller Extensions

**Files:**
- Modify: `controllers/SettingsController.php`

- [ ] **Step 1: Read Telegram bot configuration**
  Read setting namespace `client_bot` inside `index()` method of `controllers/SettingsController.php` and pass it to Twig:
  ```php
          // Load client bot settings
          $stmtClientBot = $this->pdo->prepare("SELECT `key`, value FROM settings WHERE namespace = 'client_bot'");
          $stmtClientBot->execute();
          $clientBotRows = $stmtClientBot->fetchAll(PDO::FETCH_ASSOC);
          $clientBotSettings = ['enabled' => false, 'bot_token' => '', 'webhook_url' => ''];
          foreach ($clientBotRows as $r) {
              if ($r['key'] === 'enabled') {
                  $clientBotSettings['enabled'] = (bool)json_decode($r['value'], true);
              }
              if ($r['key'] === 'bot_token') {
                  $clientBotSettings['bot_token'] = json_decode($r['value'], true);
              }
              if ($r['key'] === 'webhook_url') {
                  $clientBotSettings['webhook_url'] = json_decode($r['value'], true);
              }
          }
  ```
  Pass to the array `$data` as `'client_bot_settings' => $clientBotSettings`.

- [ ] **Step 2: Commit**
  ```bash
  git add controllers/SettingsController.php
  git commit -m "feat(settings): pass Telegram bot configurations to settings page"
  ```

---

### Task 8: Settings Router Endpoints

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Implement routes to Save Config and manage webhook**
  Add POST endpoints for `client-bot` config and webhook registration/deletion under administrative settings (around lines 2870-2900):
  ```php
  // Save Client Bot settings
  Router::post('/settings/client-bot-config', function () {
      requireAdmin();
      $pdo = DB::conn();
      $enabled = isset($_POST['enabled']) ? true : false;
      $botToken = trim($_POST['bot_token'] ?? '');
      $webhookUrl = trim($_POST['webhook_url'] ?? '');

      $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('client_bot', 'enabled', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
      $stmt->execute([json_encode($enabled)]);

      $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('client_bot', 'bot_token', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
      $stmt->execute([json_encode($botToken)]);

      $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('client_bot', 'webhook_url', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
      $stmt->execute([json_encode($webhookUrl)]);

      $_SESSION['settings_success'] = 'Telegram bot settings saved successfully';
      redirect('/settings#telegram-bot');
  });

  // Set Webhook on Telegram API
  Router::post('/settings/client-bot-webhook-set', function () {
      requireAdmin();
      header('Content-Type: application/json');
      
      $pdo = DB::conn();
      
      // Get Bot Token
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'bot_token'");
      $stmt->execute();
      $botToken = json_decode($stmt->fetchColumn() ?: '""', true);

      // Get Webhook URL
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'webhook_url'");
      $stmt->execute();
      $webhookUrl = json_decode($stmt->fetchColumn() ?: '""', true);

      if (empty($botToken) || empty($webhookUrl)) {
          echo json_encode(['error' => 'Bot Token and Webhook URL are required to set a webhook.']);
          return;
      }

      $url = "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($webhookUrl);
      
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      $res = curl_exec($ch);
      curl_close($ch);

      $data = json_decode($res, true);
      if ($data && isset($data['ok']) && $data['ok'] === true) {
          echo json_encode(['success' => true, 'message' => $data['description']]);
      } else {
          echo json_encode(['error' => $data['description'] ?? 'Telegram API error']);
      }
  });

  // Delete Webhook on Telegram API
  Router::post('/settings/client-bot-webhook-delete', function () {
      requireAdmin();
      header('Content-Type: application/json');
      
      $pdo = DB::conn();
      
      // Get Bot Token
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'client_bot' AND `key` = 'bot_token'");
      $stmt->execute();
      $botToken = json_decode($stmt->fetchColumn() ?: '""', true);

      if (empty($botToken)) {
          echo json_encode(['error' => 'Bot Token is required to delete a webhook.']);
          return;
      }

      $url = "https://api.telegram.org/bot{$botToken}/deleteWebhook";
      
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      $res = curl_exec($ch);
      curl_close($ch);

      $data = json_decode($res, true);
      if ($data && isset($data['ok']) && $data['ok'] === true) {
          echo json_encode(['success' => true, 'message' => $data['description']]);
      } else {
          echo json_encode(['error' => $data['description'] ?? 'Telegram API error']);
      }
  });
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add public/index.php
  git commit -m "feat(settings): add routes to save client bot configurations and toggle webhook"
  ```

---

### Task 9: Settings Web UI Changes

**Files:**
- Modify: `templates/settings.twig`

- [ ] **Step 1: Add new tab button**
  In `templates/settings.twig` around line 43, append the tab header button:
  ```html
          <button role="tab" onclick="showTab('telegram-bot')" id="tab-telegram-bot" class="tab gap-2">
              <i class="fab fa-telegram"></i>Telegram Bot
          </button>
  ```

- [ ] **Step 2: Add tab content**
  Add the form block for the Telegram bot under administrative content:
  ```html
      <!-- Telegram Bot Tab -->
      <div id="content-telegram-bot" class="hidden space-y-6">
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <!-- Bot Config Settings Card -->
              <div class="card bg-base-200 border border-base-300 shadow overflow-hidden lg:col-span-1">
                  <div class="px-5 py-4 border-b border-base-300 bg-base-300/30">
                      <h2 class="font-semibold flex items-center gap-2"><i class="fab fa-telegram text-primary"></i>Bot Settings</h2>
                  </div>
                  <div class="card-body p-5">
                      <form method="POST" action="/settings/client-bot-config" class="space-y-4">
                          <div class="form-control">
                              <label class="label cursor-pointer justify-start gap-3">
                                  <input type="checkbox" name="enabled" value="1" {% if client_bot_settings.enabled %}checked{% endif %} class="toggle toggle-primary toggle-sm">
                                  <span class="label-text font-medium">Enable Client Bot</span>
                              </label>
                          </div>
                          <div class="form-control">
                              <label class="label py-1"><span class="label-text text-xs font-medium">Bot Token (Separate Token)</span></label>
                              <input type="password" name="bot_token" id="client-bot-token" value="{{ client_bot_settings.bot_token }}" class="input input-bordered input-sm" required>
                          </div>
                          <div class="form-control">
                              <label class="label py-1"><span class="label-text text-xs font-medium">Webhook URL</span></label>
                              <input type="text" name="webhook_url" id="client-bot-webhook-url" value="{{ client_bot_settings.webhook_url }}" class="input input-bordered input-sm">
                              <label class="label py-0.5"><span class="label-text-alt text-base-content/40">Suggested: <span class="cursor-pointer underline" onclick="document.getElementById('client-bot-webhook-url').value = window.location.origin + '/api/telegram-bot/webhook'">click to auto-fill</span></span></label>
                          </div>
                          <div class="flex flex-col gap-2">
                              <button type="submit" class="btn btn-primary btn-sm w-full gap-2"><i class="fas fa-save"></i>Save Config</button>
                              <div class="flex gap-2">
                                  <button type="button" onclick="setClientBotWebhook()" class="btn btn-sm btn-outline flex-1 gap-1">Set Webhook</button>
                                  <button type="button" onclick="deleteClientBotWebhook()" class="btn btn-sm btn-outline btn-error flex-1 gap-1">Delete Webhook</button>
                              </div>
                          </div>
                      </form>
                  </div>
              </div>

              <!-- Explanation and Guide Card -->
              <div class="card bg-base-200 border border-base-300 shadow overflow-hidden lg:col-span-2">
                  <div class="px-5 py-4 border-b border-base-300 bg-base-300/30">
                      <h2 class="font-semibold flex items-center gap-2"><i class="fas fa-info-circle text-primary"></i>Client Bot Instructions</h2>
                  </div>
                  <div class="card-body p-5 space-y-4 text-sm">
                      <p>Nk-VPN client bot enables end-users to change active VPN servers on their routers using Telegram.</p>
                      
                      <h3 class="font-bold">Instructions:</h3>
                      <ol class="list-decimal pl-5 space-y-1">
                          <li>Create a new bot via <b>@BotFather</b> on Telegram.</li>
                          <li>Paste the token here, enable the Bot, and click <b>Save Config</b>.</li>
                          <li>If your panel is exposed on a public domain with HTTPS, fill the <b>Webhook URL</b> and click <b>Set Webhook</b>.</li>
                          <li>If you are running the panel locally or inside a private network, run the daemon inside the container:
                              <pre class="bg-base-300 p-2 rounded mt-1 text-xs select-all">php bin/telegram_bot_daemon.php</pre>
                          </li>
                          <li>Add a <code>tgid</code> to clients in your external database (e.g. using @userinfobot to find Telegram IDs). The changes will sync automatically.</li>
                      </ol>
                  </div>
              </div>
          </div>
      </div>
  ```

- [ ] **Step 3: Add Webhook AJAX Scripts**
  Append Javascript functions at the end of the script tag (around line 520):
  ```javascript
  async function setClientBotWebhook() {
      try {
          const res = await fetch('/settings/client-bot-webhook-set', { method: 'POST' });
          const data = await res.json();
          if (data.success) {
              alert('Success: ' + data.message);
          } else {
              alert('Error: ' + data.error);
          }
      } catch (err) {
          alert('Request failed: ' + err.message);
      }
  }

  async function deleteClientBotWebhook() {
      try {
          const res = await fetch('/settings/client-bot-webhook-delete', { method: 'POST' });
          const data = await res.json();
          if (data.success) {
              alert('Success: ' + data.message);
          } else {
              alert('Error: ' + data.error);
          }
      } catch (err) {
          alert('Request failed: ' + err.message);
      }
  }
  ```

- [ ] **Step 4: Commit**
  ```bash
  git add templates/settings.twig
  git commit -m "feat(settings): add Telegram bot setup UI tab and Webhook buttons"
  ```
