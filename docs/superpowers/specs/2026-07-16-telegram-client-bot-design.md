# NK-VPN Telegram Client Bot Design Spec

This document details the design for a self-service Telegram Bot integrated with the Nk-VPN panel. The bot allows clients to manage their connected routers, view subscription info, and dynamically switch VPN servers.

## 1. Database & Synchronization Changes

### 1.1 Cache Table Migration (`migrations/031_add_tgid_to_ext_clients.sql`)
We need to store the client's Telegram ID (`tgid`) in our local MySQL cache of the external client database.
```sql
-- Add tgid field to ext_clients table
ALTER TABLE ext_clients
ADD COLUMN tgid VARCHAR(50) NULL;
```

### 1.2 Migration Verification (`inc/DB.php`)
Check and automatically execute migration `031` if `tgid` is missing in `ext_clients`:
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

### 1.3 Sync Script Updates
Fetch and save the `tgid` column from the external PostgreSQL server in both:
1. **`bin/sync_external_clients.php`**
2. **`public/index.php`** (Route: `/api/ext-clients/sync`)

Query updates:
- SQL query includes `"tgid"`:
  `SELECT "Code", "Name", "Start_Date", "Sub", "Func", "Router", "Domain", "Pass", "tgid" FROM "Clients" WHERE "Code" IS NOT NULL`
- MySQL Insert statement includes `tgid = VALUES(tgid)`.

---

## 2. Settings Management

We will define new settings keys inside the `settings` table (namespace: `client_bot`):
- `enabled`: boolean (`true`/`false`)
- `bot_token`: string (separate Telegram Bot API token)
- `webhook_url`: string (the API endpoint exposed on the public internet)

We will expose this in the settings interface (`templates/settings.twig`) as a new **"Telegram Bot"** tab.

---

## 3. Bot Class Design (`inc/TelegramClientBot.php`)

The new class `TelegramClientBot` will expose two entrypoints:
1. `handleUpdate(array $update)`: Process an incoming update payload.
2. `setWebhook(string $url)` / `deleteWebhook()`: Register or delete the webhook with the Telegram Bot API.

### 3.1 Interaction Flow & Text (Russian)

#### A. Authorization & Main Menu
On `/start` or `/help` command:
- Retrieve user's Telegram ID: `$tgId = $update['message']['from']['id']`.
- Query active clients: `SELECT * FROM ext_clients WHERE tgid = ?`
- **Denied:**
  > ❌ **Доступ запрещен**
  > Ваш Telegram ID: `{TGID}`
  > Данный ID не привязан ни к одному клиенту в биллинге. Пожалуйста, сообщите этот ID администратору для привязки к вашей подписке.
- **Accepted:**
  List subscription details and offer inline buttons for associated routers:
  > 👋 **Приветствуем, {Client Name}!**
  >
  > **Ваши подписки:**
  > 🔑 Код: `{code}` | Статус: `{status}`
  > 📅 Срок действия: `{expiration_date}`
  > 
  > Пожалуйста, выберите роутер для управления ниже:
  
  *Inline Keyboard Buttons:*
  - `[ 📶 Роутер: {domain} ({status}) ]` -> Callback: `select_router:{router_id}`

#### B. Router Management Menu
When clicking a router button (Callback: `select_router:{router_id}`):
- Validate user's ownership of the router's client code.
- Query router details.
  > 📶 **Роутер: {domain}**
  > 🔹 Модель: {router_model}
  > 🔹 Версия ПО: {firmware_version}
  > 🔹 Статус: `{status}`
  > 🔹 Текущий сервер: **{server_name}**
  > *(если статус error: {error_message})*
  
  *Inline Keyboard Buttons:*
  - `[ 🔄 Сменить сервер ]` -> Callback: `change_server:{router_id}`
  - `[ ⚡ Обновить ]` -> Callback: `select_router:{router_id}`
  - `[ 🔙 Назад ]` -> Callback: `main_list`

#### C. Change Server Selection
When clicking "Сменить сервер" (Callback: `change_server:{router_id}`):
- Query all active servers: `SELECT id, name FROM vpn_servers WHERE status = 'active' ORDER BY name ASC`.
- Show servers list as inline keyboard:
  > 🌍 **Выберите новый сервер для роутера {domain}:**
  
  *Inline Keyboard Buttons:*
  - `[ {server_name} ]` -> Callback: `set_server:{router_id}:{server_id}`
  - `[ 🔙 Назад ]` -> Callback: `select_router:{router_id}`

#### D. Switch Server Execution
When selecting a server (Callback: `set_server:{router_id}:{server_id}`):
- Show loader message:
  > 🔄 *Переключаем сервер на **{server_name}**... Пожалуйста, подождите, это может занять до 15 секунд.*
- Search for existing `vpn_clients` config:
  `SELECT id FROM vpn_clients WHERE ext_client_code = ? AND server_id = ? AND status = 'active' LIMIT 1`
- If none exists, create a new one:
  - Generate a safe name: `tg_{client_code}_{server_id}` (only alphanumeric, `_` or `-`).
  - Generate keys, request server, save locally: `VpnClient::create($serverId, $userId, $clientName, null)`.
  - Link it explicitly: `$vpnClient->linkToExtClient($extClientCode)`.
- Call pushing service:
  `RouterManager::pushConfigToRouter($routerId, $vpnClientId)`
- **Success:**
  > ✅ **Сервер успешно изменен!** Роутер переключен на сервер **{server_name}**.
- **Error:**
  > ❌ **Ошибка при переключении сервера:** {error_message}

---

## 4. Hooking Up Entrypoints

### 4.1 Web API Route (`public/index.php`)
```php
Router::post('/api/telegram-bot/webhook', function () {
    header('Content-Type: application/json');
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if ($update) {
        require_once __DIR__ . '/../inc/TelegramClientBot.php';
        TelegramClientBot::handleUpdate($update);
    }
    echo json_encode(['success' => true]);
});
```

### 4.2 CLI Daemon (`bin/telegram_bot_daemon.php`)
A daemon using `getUpdates` for environments where webhooks are not configured.
- Pulls settings.
- Iterates over long-polling requests with `offset` tracking.
- Forwards updates to `TelegramClientBot::handleUpdate($update)`.
