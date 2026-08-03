# Daily External Database Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement automated daily synchronization with the external PostgreSQL database triggered in the background during admin dashboard load, featuring execution locking to prevent concurrency issues.

**Architecture:** Refactor client/router synchronization logic into `ExtDB::sync()` with system lock checks. Create a global `needs_db_sync` flag calculated during routing in `public/index.php`. Append a background AJAX trigger script in `templates/layout.twig` to hit `/api/ext-clients/sync` when `needs_db_sync` is active, and update the CLI runner to use the new method.

**Tech Stack:** PHP, MySQL, PostgreSQL, Twig, JavaScript.

---

### Task 1: Refactor Synchronization and Implement Lock in `inc/ExtDB.php`

**Files:**
- Modify: `inc/ExtDB.php`

- [ ] **Step 1: Add the sync method to `inc/ExtDB.php`**
  Modify `inc/ExtDB.php` to add the `sync()` static method:
  ```php
      /**
       * Synchronize external Postgres database clients to local MySQL ext_clients table.
       * Implements locking to prevent concurrent sync executions.
       * 
       * @return array Array containing success status, records synced count, and warning/error messages.
       * @throws Exception If PostgreSQL connection fails.
       */
      public static function sync(): array {
          $pdo = DB::conn();

          // 1. Acquire execution lock
          $stmtLock = $pdo->prepare("SELECT `value` FROM system_settings WHERE `key` = 'ext_clients_sync_running' LIMIT 1");
          $stmtLock->execute();
          $lockVal = $stmtLock->fetchColumn();
          if ($lockVal && (time() - strtotime($lockVal)) < 300) {
              return [
                  'success' => true,
                  'message' => 'Sync already in progress.',
                  'count' => 0
              ];
          }

          $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('ext_clients_sync_running', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
              ->execute([date('Y-m-d H:i:s')]);

          try {
              // 2. Fetch records from PostgreSQL
              if (!self::isAvailable()) {
                  throw new Exception("External PostgreSQL database is unreachable.");
              }

              $pgPdo = self::conn();
              $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
              $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"Pass\", \"tgid\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
              $rawClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

              // 3. Import to MySQL in transaction
              $pdo->beginTransaction();
              $syncedCount = 0;
              $activeCodes = [];

              if (!empty($rawClients)) {
                  $insertStmt = $pdo->prepare('
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

                  foreach ($rawClients as $row) {
                      $code = trim($row['Code'] ?? '');
                      if ($code === '') continue;
                      $activeCodes[] = $code;
                      
                      $name = isset($row['Name']) ? trim($row['Name']) : null;
                      $startDate = isset($row['Start_Date']) ? trim($row['Start_Date']) : null;
                      if ($startDate === '') $startDate = null;
                      $sub = isset($row['Sub']) ? (int)$row['Sub'] : null;
                      $func = isset($row['Func']) ? trim($row['Func']) : null;
                      $router = isset($row['Router']) ? trim($row['Router']) : null;
                      $domain = isset($row['Domain']) ? trim($row['Domain']) : null;
                      $pass = isset($row['Pass']) ? trim($row['Pass']) : null;
                      $tgid = isset($row['tgid']) ? trim($row['tgid']) : null;
                      if ($tgid === '') $tgid = null;

                      $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router, $domain, $pass, $tgid]);
                      $syncedCount++;
                  }

                  // Remove deprecated clients
                  if (!empty($activeCodes)) {
                      $placeholders = implode(',', array_fill(0, count($activeCodes), '?'));
                      $deleteStmt = $pdo->prepare("DELETE FROM ext_clients WHERE code NOT IN ($placeholders)");
                      $deleteStmt->execute($activeCodes);
                  } else {
                      $pdo->exec('DELETE FROM ext_clients');
                  }
              } else {
                  $pdo->exec('DELETE FROM ext_clients');
              }
              $pdo->commit();

              // 4. Auto-link and Router synchronization hooks
              $warnings = [];
              try {
                  VpnClient::autoLinkAll();
              } catch (Throwable $e) {
                  $warnings[] = 'Auto-linking clients failed: ' . $e->getMessage();
              }

              try {
                  require_once __DIR__ . '/RouterManager.php';
                  RouterManager::syncRoutersFromExtClients();
              } catch (Throwable $e) {
                  $warnings[] = 'Router connections sync failed: ' . $e->getMessage();
              }

              // 5. Update last sync timestamp
              $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('last_ext_clients_sync', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                  ->execute([date('Y-m-d H:i:s')]);

              // 6. Release execution lock
              $pdo->prepare("DELETE FROM system_settings WHERE `key` = 'ext_clients_sync_running'")->execute();

              return [
                  'success' => true,
                  'count' => $syncedCount,
                  'warnings' => $warnings
              ];

          } catch (Throwable $e) {
              if ($pdo->inTransaction()) {
                  $pdo->rollBack();
              }
              // Cleanup lock on failure
              try {
                  $pdo->prepare("DELETE FROM system_settings WHERE `key` = 'ext_clients_sync_running'")->execute();
              } catch (Throwable $lockEx) {}
              
              throw $e;
          }
      }
  ```

- [ ] **Step 2: Run a quick syntax check on `inc/ExtDB.php`**
  Run: `php -l inc/ExtDB.php`
  Expected: No syntax errors detected in inc/ExtDB.php

- [ ] **Step 3: Commit refactored database module**
  ```bash
  git add inc/ExtDB.php
  git commit -m "refactor(sync): implement ExtDB::sync helper with locking and router triggers"
  ```

---

### Task 2: Adapt API Endpoint and CLI Executable

**Files:**
- Modify: `public/index.php`
- Modify: `bin/sync_external_clients.php`

- [ ] **Step 1: Replace implementation in `/api/ext-clients/sync` endpoint**
  Search for `Router::post('/api/ext-clients/sync'` in `public/index.php` and replace the callback logic to use `ExtDB::sync()`:
  ```php
  // API: Manual trigger to synchronize Postgres to MySQL
  Router::post('/api/ext-clients/sync', function () {
      requireAuth();
      header('Content-Type: application/json');

      try {
          $result = ExtDB::sync();
          echo json_encode($result);
      } catch (Throwable $e) {
          http_response_code(500);
          echo json_encode(['success' => false, 'error' => $e->getMessage()]);
      }
  });
  ```

- [ ] **Step 2: Replace implementation in `bin/sync_external_clients.php`**
  Modify `bin/sync_external_clients.php` to clean up the duplicate code and delegate logic to `ExtDB::sync()`:
  ```php
  <?php
  /**
   * CLI Script to sync external PostgreSQL client codes to local MySQL ext_clients table.
   * Can be run manually or via cron.
   */
  require_once __DIR__ . '/../vendor/autoload.php';
  require_once __DIR__ . '/../inc/Config.php';
  require_once __DIR__ . '/../inc/DB.php';
  require_once __DIR__ . '/../inc/ExtDB.php';

  // Load environment configuration
  Config::load(__DIR__ . '/../.env');

  $logPrefix = '[' . date('Y-m-d H:i:s') . '] ';

  try {
      echo $logPrefix . "Starting client synchronization...\n";
      $result = ExtDB::sync();
      
      if (isset($result['message'])) {
          echo $logPrefix . $result['message'] . "\n";
      } else {
          echo $logPrefix . "Successfully synchronized {$result['count']} clients to MySQL cached ext_clients table.\n";
      }
      
      if (!empty($result['warnings'])) {
          foreach ($result['warnings'] as $warning) {
              echo $logPrefix . "WARNING: " . $warning . "\n";
          }
      }
      exit(0);
  } catch (Throwable $e) {
      fwrite(STDERR, $logPrefix . "ERROR: " . $e->getMessage() . "\n");
      exit(1);
  }
  ```

- [ ] **Step 3: Run syntax check on the modified files**
  Run: `php -l public/index.php` and `php -l bin/sync_external_clients.php`
  Expected: No syntax errors detected.

- [ ] **Step 4: Commit endpoint and executable updates**
  ```bash
  git add public/index.php bin/sync_external_clients.php
  git commit -m "feat(sync): delegate API and CLI sync tasks to unified ExtDB::sync"
  ```

---

### Task 3: Implement Dashboard Check and Frontend Auto-Trigger Script

**Files:**
- Modify: `public/index.php`
- Modify: `templates/layout.twig`

- [ ] **Step 1: Calculate global `needs_db_sync` parameter in `public/index.php`**
  Around lines 80-90 of `public/index.php` where `View::init` is called:
  Calculate `$needsDbSync` and inject it to the View:
  ```php
  $needsDbSync = false;
  if ($user && Auth::isAdmin()) {
      try {
          $pdo = DB::conn();
          $stmtSync = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'last_ext_clients_sync' LIMIT 1");
          $lastSyncVal = $stmtSync->fetchColumn();
          if (!$lastSyncVal || (time() - strtotime($lastSyncVal)) > 86400) {
              $needsDbSync = true;
          }
      } catch (Throwable $e) {
          // Table doesn't exist yet
      }
  }

  View::init(__DIR__ . '/../templates', [
      'app_name' => $appName,
      'user' => $user,
      'current_language' => Translator::getCurrentLanguage(),
      'languages' => Translator::getSupportedLanguages(),
      'current_uri' => $_SERVER['REQUEST_URI'] ?? '/dashboard',
      'needs_db_sync' => $needsDbSync,
      't' => function($key, $params = []) {
          return Translator::t($key, $params);
      }
  ]);
  ```

- [ ] **Step 2: Append AJAX trigger block to `templates/layout.twig`**
  Modify `templates/layout.twig` just before the closing `</body>` tag (around line 248) to inject the background fetch script:
  ```twig
  {% if needs_db_sync %}
  <script>
      document.addEventListener('DOMContentLoaded', function () {
          // Perform silent background synchronization of external database clients
          fetch('/api/ext-clients/sync', { 
              method: 'POST',
              headers: {
                  'X-Requested-With': 'XMLHttpRequest'
              }
          })
          .then(response => response.json())
          .then(data => {
              if (data.success) {
                  console.log('[Sync] Background client synchronization completed. ' + (data.message || ('Synced ' + (data.count || 0) + ' records.')));
              } else {
                  console.warn('[Sync] Background sync failed: ' + (data.error || 'Unknown error'));
              }
          })
          .catch(error => {
              console.error('[Sync] Error during background synchronization request: ', error);
          });
      });
  </script>
  {% endif %}
  ```

- [ ] **Step 3: Verify templates syntax compile check**
  Run: `php -l public/index.php`
  Expected: Success without syntax error.

- [ ] **Step 4: Commit page-load checks and layout template**
  ```bash
  git add public/index.php templates/layout.twig
  git commit -m "feat(sync): trigger automatic background sync on panel load if last sync > 24h"
  ```

---

### Task 4: Manual & Automated Verification

- [ ] **Step 1: Test CLI Sync execution**
  Run: `php bin/sync_external_clients.php`
  Expected: Success logging either successful sync count or "Sync already in progress."

- [ ] **Step 2: Test Locking Mechanism**
  Directly set the `ext_clients_sync_running` key in `system_settings` manually:
  Run: `php -r "require_once 'inc/Config.php'; require_once 'inc/DB.php'; Config::load('.env'); DB::conn()->prepare(\"INSERT INTO system_settings (\`key\`, \`value\`) VALUES ('ext_clients_sync_running', ?) ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`)\")->execute([date('Y-m-d H:i:s')]);"`
  Run the CLI sync again:
  Run: `php bin/sync_external_clients.php`
  Expected: Logs "[...] Starting client synchronization..." followed by "[...] Sync already in progress."
  Clean up lock settings:
  Run: `php -r "require_once 'inc/Config.php'; require_once 'inc/DB.php'; Config::load('.env'); DB::conn()->exec(\"DELETE FROM system_settings WHERE \`key\` = 'ext_clients_sync_running'\");"`

- [ ] **Step 3: Test Automatic Page Load Trigger**
  Set `last_ext_clients_sync` to a date in the past:
  Run: `php -r "require_once 'inc/Config.php'; require_once 'inc/DB.php'; Config::load('.env'); DB::conn()->prepare(\"INSERT INTO system_settings (\`key\`, \`value\`) VALUES ('last_ext_clients_sync', ?) ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`)\")->execute(['2020-01-01 00:00:00']);"`
  Open browser subagent or manually visit dashboard. Wait for request to execute. Check system logs or database system_settings values.
  Run: `php -r "require_once 'inc/Config.php'; require_once 'inc/DB.php'; Config::load('.env'); $last = DB::conn()->query(\"SELECT \`value\` FROM system_settings WHERE \`key\` = 'last_ext_clients_sync'\")->fetchColumn(); echo 'Last sync was updated to: ' . $last . PHP_EOL;"`
  Expected: The output should show the current date/time in UTC, proving that the page load triggered a successful background sync.
