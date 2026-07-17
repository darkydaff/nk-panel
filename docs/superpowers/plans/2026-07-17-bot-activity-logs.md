# Telegram Bot Activity Logs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement database-backed client Telegram bot activity logging and an admin panel dashboard showing live stats, charts, and paginated logs.

**Architecture:** Create a MySQL table `bot_activity_logs`. Add a logging helper in `inc/TelegramClientBot.php` and inject it at key event triggers. Add the `/bot-logs` route in `public/index.php` that queries stats and page data, and render `templates/bot_logs.twig` using Chart.js for data visualization.

**Tech Stack:** PHP, MySQL, Twig, Chart.js, Tailwind CSS (via DaisyUI).

---

### Task 1: Create SQL Database Migration & Self-Healing Connection Hook

**Files:**
- Create [NEW]: `migrations/035_create_bot_activity_logs_table.sql`
- Modify: `inc/DB.php`

- [ ] **Step 1: Create the SQL migration file**
  Create a new migration file `migrations/035_create_bot_activity_logs_table.sql` with the following content:
  ```sql
  CREATE TABLE IF NOT EXISTS bot_activity_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tg_id BIGINT NOT NULL,
      tg_name VARCHAR(255) NOT NULL,
      client_code VARCHAR(255) NULL,
      action VARCHAR(50) NOT NULL,
      details TEXT NULL,
      raw_data TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_created_at (created_at),
      INDEX idx_tg_id (tg_id),
      INDEX idx_client_code (client_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```

- [ ] **Step 2: Add database self-healing check to `inc/DB.php`**
  Modify `inc/DB.php` inside `checkAndRunMigrations()` at the end of the procedure (just before the closing bracket of the try block) to check for the presence of the `bot_activity_logs` table:
  ```php
        // Check if bot_activity_logs table exists
        try {
          $pdo->query("SELECT 1 FROM bot_activity_logs LIMIT 1");
          $hasLogsTable = true;
        } catch (Throwable $e) {
          $hasLogsTable = false;
        }

        if (!$hasLogsTable) {
          $sqlPath = __DIR__ . '/../migrations/035_create_bot_activity_logs_table.sql';
          if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            $pdo->exec($sql);
          }
        }
  ```

- [ ] **Step 3: Run check command to verify database initialization**
  Run PHP to connect to DB and trigger migration check:
  `php -r "require_once 'inc/Config.php'; require_once 'inc/DB.php'; DB::conn();"`
  Expected: Success without output or error.

- [ ] **Step 4: Commit DB initialization files**
  ```bash
  git add migrations/035_create_bot_activity_logs_table.sql inc/DB.php
  git commit -m "feat(database): add bot_activity_logs migration and self-healing hook"
  ```

---

### Task 2: Implement Logging Helper and Log Commands

**Files:**
- Modify: `inc/TelegramClientBot.php`

- [ ] **Step 1: Add logActivity helper to `inc/TelegramClientBot.php`**
  Add the static method `logActivity` to the `TelegramClientBot` class:
  ```php
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
  ```

- [ ] **Step 2: Log unauthorized access in `handleUpdate`**
  Inside `TelegramClientBot::handleUpdate`, log when the ID is not bound:
  ```php
          if (empty($clients)) {
              self::logActivity($tgId, $tgName, null, 'unauthorized', "Access Denied. Message: '" . ($messageText ?? '') . "' Callback: '" . ($callbackData ?? '') . "'", json_encode($update));
              self::sendMessage($chatId, "❌ **Доступ запрещен**\n\nВаш Telegram ID: `{$tgId}`\nДанный ID не привязан ни к одному клиенту в биллинге. Пожалуйста, сообщите этот ID администратору для привязки к вашей подписке.", $token);
  ```

- [ ] **Step 3: Log showMainMenu and handleShowVersion**
  In `handleUpdate` where it handles commands, log the actions:
  ```php
          // Handle Command
          $normalizedText = strtolower($messageText);
          if (strpos($messageText, '/start') === 0 || strpos($messageText, '/help') === 0) {
              self::logActivity($tgId, $tgName, implode(',', array_column($clients, 'code')), 'view_menu', 'Opened main menu via start/help command', json_encode($update));
              self::showMainMenu($chatId, $tgName, $clients, $token);
          } elseif ($normalizedText === 'show version' || $normalizedText === 'rci show/version' || $normalizedText === '/show_version' || $normalizedText === '/version' || $normalizedText === '/showversion') {
              self::logActivity($tgId, $tgName, implode(',', array_column($clients, 'code')), 'show_version', 'Checked router firmware versions', json_encode($update));
              self::handleShowVersion($chatId, $clients, $token);
  ```

- [ ] **Step 4: Commit logger and basic command logging**
  ```bash
  git add inc/TelegramClientBot.php
  git commit -m "feat(telegram): add logActivity helper and log start and version commands"
  ```

---

### Task 3: Log Callbacks & Server Switching

**Files:**
- Modify: `inc/TelegramClientBot.php`

- [ ] **Step 1: Log callback actions**
  Modify `handleCallback` inside `TelegramClientBot.php` to log callback triggers.
  * For `main_list`:
    ```php
            if ($action === 'main_list') {
                self::logActivity($tgId, $tgName, implode(',', $clientCodes), 'view_menu', 'Opened main menu via callback', json_encode($update));
                self::answerCallbackQuery($callbackQueryId, "", false, $token);
    ```
  * For `select_router`:
    ```php
            if ($action === 'select_router') {
                require_once __DIR__ . '/RouterManager.php';
                $statusInfo = RouterManager::checkRouterStatus($routerId);
                // After fetching router model
                $routerName = $router['router_model'] ?: $router['domain'];
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'select_router', "Selected router: {$routerName} (ID: {$routerId})", json_encode($update));
    ```
  * For `change_server`:
    ```php
            if ($action === 'change_server') {
                $routerName = $router['router_model'] ?: $router['domain'];
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'view_servers', "Requested server list for router: {$routerName} (ID: {$routerId})", json_encode($update));
    ```

- [ ] **Step 2: Log server switching execution results**
  Inside `handleCallback` under `set_server` action:
  * Log the start of switching:
    ```php
                $routerName = $router['router_model'] ?: $router['domain'];
                self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server', "Initiating server switch for router: {$routerName} (ID: {$routerId}) to server: {$server['name']} (ID: {$serverId})", json_encode($update));
    ```
  * Log switching success:
    ```php
                    // Push to Router
                    RouterManager::pushConfigToRouter($routerId, $clientId);
                    
                    self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server_success', "Successfully switched router: {$routerName} (ID: {$routerId}) to server: {$server['name']}", json_encode($update));
    ```
  * Log switching failure in catch block:
    ```php
                } catch (Throwable $e) {
                    self::logActivity($tgId, $tgName, $router['ext_client_code'], 'change_server_error', "Error switching router: {$routerName} (ID: {$routerId}) to server: {$server['name']}. Error: " . $e->getMessage(), json_encode($update));
    ```

- [ ] **Step 3: Commit callback logging**
  ```bash
  git add inc/TelegramClientBot.php
  git commit -m "feat(telegram): add detailed logging for callbacks and server switching results"
  ```

---

### Task 4: Add Admin Controller Routing

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Add `/bot-logs` GET route in `public/index.php`**
  Insert the route before the API routes section (e.g. before line 3431, near `/routers` and `/routing-groups` routes):
  ```php
  Router::get('/bot-logs', function () {
      requireAdmin();
      
      $pdo = DB::conn();
      
      $search = trim($_GET['search'] ?? '');
      $filter = trim($_GET['filter'] ?? 'all');
      $page = max(1, (int)($_GET['page'] ?? 1));
      $perPage = 25;
      $offset = ($page - 1) * $perPage;

      // Stats counts
      $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM bot_activity_logs")->fetchColumn();
      $uniqueUsers = (int)$pdo->query("SELECT COUNT(DISTINCT tg_id) FROM bot_activity_logs")->fetchColumn();
      $serverSwitches = (int)$pdo->query("SELECT COUNT(*) FROM bot_activity_logs WHERE action = 'change_server_success'")->fetchColumn();
      $errorsCount = (int)$pdo->query("SELECT COUNT(*) FROM bot_activity_logs WHERE action IN ('unauthorized', 'change_server_error')")->fetchColumn();

      // Trend data (last 7 days)
      $stmtTrend = $pdo->query("
          SELECT DATE_FORMAT(created_at, '%d.%m') as day_label, COUNT(*) as count 
          FROM bot_activity_logs 
          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
          GROUP BY DATE(created_at)
          ORDER BY DATE(created_at) ASC
      ");
      $trendData = $stmtTrend->fetchAll();

      // Action distribution
      $stmtDist = $pdo->query("
          SELECT action, COUNT(*) as count 
          FROM bot_activity_logs 
          GROUP BY action
      ");
      $distData = $stmtDist->fetchAll();

      // Logs table filtering
      $whereClauses = [];
      $params = [];
      
      if ($filter === 'switches') {
          $whereClauses[] = "action LIKE 'change_server%'";
      } elseif ($filter === 'access') {
          $whereClauses[] = "action IN ('unauthorized', 'view_menu')";
      } elseif ($filter === 'errors') {
          $whereClauses[] = "action IN ('unauthorized', 'change_server_error')";
      }

      if ($search !== '') {
          $whereClauses[] = "(tg_name LIKE ? OR tg_id LIKE ? OR client_code LIKE ? OR details LIKE ?)";
          $like = '%' . $search . '%';
          $params = array_merge($params, [$like, $like, $like, $like]);
      }

      $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
      
      $stmtFilteredCount = $pdo->prepare("SELECT COUNT(*) FROM bot_activity_logs {$whereSql}");
      $stmtFilteredCount->execute($params);
      $filteredCount = (int)$stmtFilteredCount->fetchColumn();
      $totalPages = max(1, (int)ceil($filteredCount / $perPage));

      $selectSql = "
          SELECT * FROM bot_activity_logs 
          {$whereSql} 
          ORDER BY created_at DESC 
          LIMIT ? OFFSET ?
      ";
      $stmtLogs = $pdo->prepare($selectSql);
      $paramIndex = 1;
      foreach ($params as $p) {
          $stmtLogs->bindValue($paramIndex++, $p, PDO::PARAM_STR);
      }
      $stmtLogs->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
      $stmtLogs->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
      $stmtLogs->execute();
      $logs = $stmtLogs->fetchAll();

      View::render('bot_logs.twig', [
          'total_count'     => $totalCount,
          'unique_users'    => $uniqueUsers,
          'server_switches' => $serverSwitches,
          'errors_count'    => $errorsCount,
          'trend_data'      => $trendData,
          'dist_data'       => $distData,
          'logs'            => $logs,
          'current_page'    => $page,
          'total_pages'     => $totalPages,
          'search'          => $search,
          'filter'          => $filter,
      ]);
  });
  ```

- [ ] **Step 2: Commit admin route changes**
  ```bash
  git add public/index.php
  git commit -m "feat(panel): implement /bot-logs admin route with stats and pagination queries"
  ```

---

### Task 5: Navigation Sidebar Link

**Files:**
- Modify: `templates/layout.twig`

- [ ] **Step 1: Add Bot Log sidebar item**
  Open `templates/layout.twig` and locate the menu list (`<ul class="menu menu-md gap-0.5 p-0 w-full">`). Under the routing groups list item, append the bot logs link:
  ```html
                      {% if user.role == 'admin' %}
                      <li>
                          <a href="/bot-logs" class="{% if '/bot-logs' in current_uri %}active{% endif %}">
                              <i class="fas fa-robot w-4 text-center"></i>
                              <span>Bot Log</span>
                          </a>
                      </li>
                      {% endif %}
  ```

- [ ] **Step 2: Commit sidebar navigation**
  ```bash
  git add templates/layout.twig
  git commit -m "feat(ui): add Bot Log tab to admin sidebar layout"
  ```

---

### Task 6: Create Dashboard UI Template

**Files:**
- Create [NEW]: `templates/bot_logs.twig`

- [ ] **Step 1: Write `templates/bot_logs.twig` template**
  Create the template displaying metrics, live-updating charts, filters, and paginated logs list matching the design specs:
  ```html
  {% extends "layout.twig" %}
  {% block title %}Bot Logs{% endblock %}

  {% block breadcrumbs %}
      <li><a href="/dashboard" class="hover:text-base-content transition-colors">Home</a></li>
      <li><span class="text-base-content/70">Bot Logs</span></li>
  {% endblock %}

  {% block content %}
  <div class="w-full space-y-6">
      <!-- Header -->
      <div>
          <h1 class="text-2xl font-bold flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                  <i class="fas fa-robot"></i>
              </div>
              Telegram Bot Log
          </h1>
          <p class="text-sm text-base-content/50 mt-1">Monitor who is using the self-service bot, what they are doing, and switch performance/errors.</p>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="stats shadow bg-base-200 border border-base-300">
              <div class="stat">
                  <div class="stat-figure text-primary">
                      <i class="fas fa-fingerprint text-2xl opacity-80"></i>
                  </div>
                  <div class="stat-title text-xs font-semibold uppercase tracking-wider opacity-60">Total Actions</div>
                  <div class="stat-value text-primary text-2xl mt-1">{{ total_count }}</div>
                  <div class="stat-desc mt-1 text-xs opacity-40">Cumulative logs count</div>
              </div>
          </div>

          <div class="stats shadow bg-base-200 border border-base-300">
              <div class="stat">
                  <div class="stat-figure text-success">
                      <i class="fas fa-users text-2xl opacity-80"></i>
                  </div>
                  <div class="stat-title text-xs font-semibold uppercase tracking-wider opacity-60">Active Clients</div>
                  <div class="stat-value text-success text-2xl mt-1">{{ unique_users }}</div>
                  <div class="stat-desc mt-1 text-xs opacity-40">Unique TG Users</div>
              </div>
          </div>

          <div class="stats shadow bg-base-200 border border-base-300">
              <div class="stat">
                  <div class="stat-figure text-info">
                      <i class="fas fa-exchange-alt text-2xl opacity-80"></i>
                  </div>
                  <div class="stat-title text-xs font-semibold uppercase tracking-wider opacity-60">Server Switches</div>
                  <div class="stat-value text-info text-2xl mt-1">{{ server_switches }}</div>
                  <div class="stat-desc mt-1 text-xs opacity-40">Successful changes</div>
              </div>
          </div>

          <div class="stats shadow bg-base-200 border border-base-300">
              <div class="stat">
                  <div class="stat-figure text-error">
                      <i class="fas fa-exclamation-triangle text-2xl opacity-80"></i>
                  </div>
                  <div class="stat-title text-xs font-semibold uppercase tracking-wider opacity-60">Blocked / Errors</div>
                  <div class="stat-value text-error text-2xl mt-1">{{ errors_count }}</div>
                  <div class="stat-desc mt-1 text-xs opacity-40">Access denied or config errors</div>
              </div>
          </div>
      </div>

      <!-- Stats Analytics Row -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="card bg-base-200 border border-base-300 lg:col-span-2 shadow">
              <div class="card-body p-5">
                  <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                      <i class="fas fa-chart-line text-primary"></i> Activity Trend (Last 7 Days)
                  </h3>
                  <div class="h-48 relative">
                      <canvas id="trendChart"></canvas>
                  </div>
              </div>
          </div>

          <div class="card bg-base-200 border border-base-300 shadow">
              <div class="card-body p-5">
                  <h3 class="font-bold text-sm mb-4 flex items-center gap-2">
                      <i class="fas fa-chart-pie text-secondary"></i> Action Types
                  </h3>
                  <div class="h-48 relative flex justify-center">
                      <canvas id="distributionChart"></canvas>
                  </div>
              </div>
          </div>
      </div>

      <!-- Log Table Card -->
      <div class="card bg-base-200 border border-base-300 shadow">
          <div class="card-body p-5">
              <!-- Filters and Search -->
              <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                  <div class="flex flex-wrap gap-2 items-center">
                      <h3 class="font-bold text-sm mr-2">Activity Stream</h3>
                      <div class="join">
                          <a href="/bot-logs?search={{ search|url_encode }}&filter=all" class="btn btn-xs join-item {% if filter == 'all' or filter == '' %}btn-primary{% endif %}">All</a>
                          <a href="/bot-logs?search={{ search|url_encode }}&filter=switches" class="btn btn-xs join-item {% if filter == 'switches' %}btn-primary{% endif %}">Server Switches</a>
                          <a href="/bot-logs?search={{ search|url_encode }}&filter=access" class="btn btn-xs join-item {% if filter == 'access' %}btn-primary{% endif %}">Access Checks</a>
                          <a href="/bot-logs?search={{ search|url_encode }}&filter=errors" class="btn btn-xs join-item {% if filter == 'errors' %}btn-primary{% endif %}">Errors</a>
                      </div>
                  </div>

                  <form method="GET" action="/bot-logs" class="flex gap-2">
                      <input type="hidden" name="filter" value="{{ filter }}" />
                      <label class="input input-bordered input-sm flex items-center gap-2">
                          <i class="fas fa-search text-xs opacity-50"></i>
                          <input type="text" name="search" value="{{ search }}" placeholder="Search user/code..." class="grow text-xs" />
                      </label>
                      <button type="submit" class="btn btn-sm btn-primary btn-sm px-3">Search</button>
                      {% if search %}<a href="/bot-logs?filter={{ filter }}" class="btn btn-sm btn-ghost px-2"><i class="fas fa-times"></i></a>{% endif %}
                  </form>
              </div>

              <!-- Log Table -->
              <div class="overflow-x-auto">
                  <table class="table table-zebra table-sm w-full">
                      <thead>
                          <tr class="text-xs uppercase tracking-wider opacity-60">
                              <th>User</th>
                              <th>Client Code</th>
                              <th>Action</th>
                              <th>Details</th>
                              <th>Time</th>
                          </tr>
                      </thead>
                      <tbody class="text-xs font-mono">
                          {% if logs is empty %}
                          <tr>
                              <td colspan="5" class="text-center py-8 text-base-content/40 italic">No logs found</td>
                          </tr>
                          {% else %}
                              {% for log in logs %}
                              <tr>
                                  <td>
                                      <div class="font-sans font-semibold text-base-content/90">{% if log.tg_name starts with '@' or log.tg_name != 'Пользователь' %}{{ log.tg_name }}{% else %}TG User: {{ log.tg_id }}{% endif %}</div>
                                      <div class="opacity-40 text-[10px]">ID: {{ log.tg_id }}</div>
                                  </td>
                                  <td>
                                      {% if log.client_code %}
                                          {% set codes = log.client_code|split(',') %}
                                          <div class="flex flex-wrap gap-1">
                                              {% for c in codes %}
                                                  <span class="badge badge-ghost badge-sm font-mono text-[10px]">{{ c }}</span>
                                              {% endfor %}
                                          </div>
                                      {% else %}
                                          <span class="text-error font-sans italic opacity-60">Not Bound</span>
                                      {% endif %}
                                  </td>
                                  <td>
                                      {% if log.action == 'change_server_success' %}
                                          <span class="badge badge-success badge-outline font-semibold uppercase text-[9px] px-1.5 py-0.5">success</span>
                                      {% elseif log.action == 'change_server_error' %}
                                          <span class="badge badge-error badge-outline font-semibold uppercase text-[9px] px-1.5 py-0.5">switch_err</span>
                                      {% elseif log.action == 'unauthorized' %}
                                          <span class="badge badge-error badge-outline font-semibold uppercase text-[9px] px-1.5 py-0.5">blocked</span>
                                      {% elseif log.action == 'change_server' %}
                                          <span class="badge badge-info badge-outline font-semibold uppercase text-[9px] px-1.5 py-0.5">switching</span>
                                      {% elseif log.action == 'show_version' %}
                                          <span class="badge badge-ghost badge-outline font-semibold uppercase text-[9px] px-1.5 py-0.5">version</span>
                                      {% else %}
                                          <span class="badge badge-ghost badge-outline font-semibold uppercase text-[9px] px-1.5 py-0.5">{{ log.action }}</span>
                                      {% endif %}
                                  </td>
                                  <td class="font-sans whitespace-normal break-all max-w-lg">{{ log.details }}</td>
                                  <td class="opacity-60 font-sans whitespace-nowrap">{{ log.created_at|date('d.m.Y H:i:s') }}</td>
                              </tr>
                              {% endfor %}
                          {% endif %}
                      </tbody>
                  </table>
              </div>

              <!-- Pagination -->
              {% if total_pages > 1 %}
              <div class="join flex justify-center mt-6">
                  {% if current_page > 1 %}
                  <a href="/bot-logs?search={{ search|url_encode }}&filter={{ filter }}&page={{ current_page - 1 }}" class="join-item btn btn-xs">&laquo;</a>
                  {% endif %}

                  {% for p in 1..total_pages %}
                      <a href="/bot-logs?search={{ search|url_encode }}&filter={{ filter }}&page={{ p }}" class="join-item btn btn-xs {% if p == current_page %}btn-primary btn-active{% endif %}">{{ p }}</a>
                  {% endfor %}

                  {% if current_page < total_pages %}
                  <a href="/bot-logs?search={{ search|url_encode }}&filter={{ filter }}&page={{ current_page + 1 }}" class="join-item btn btn-xs">&raquo;</a>
                  {% endif %}
              </div>
              {% endif %}
          </div>
      </div>
  </div>

  <script>
      document.addEventListener('DOMContentLoaded', function() {
          const chartTextColors = '#9ca3af'; // slate-400
          const chartGridColors = '#374151'; // slate-700

          // Prepare trend data from twig
          const trendLabels = [];
          const trendValues = [];
          {% for day in trend_data %}
              trendLabels.push("{{ day.day_label }}");
              trendValues.push({{ day.count }});
          {% endfor %}

          if (trendLabels.length === 0) {
              trendLabels.push("No Data");
              trendValues.push(0);
          }

          // Trend Chart
          const ctxTrend = document.getElementById('trendChart').getContext('2d');
          new Chart(ctxTrend, {
              type: 'line',
              data: {
                  labels: trendLabels,
                  datasets: [{
                      label: 'Actions',
                      data: trendValues,
                      borderColor: '#661ae6',
                      backgroundColor: 'rgba(102, 26, 230, 0.1)',
                      tension: 0.4,
                      fill: true,
                      borderWidth: 2
                  }]
              },
              options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { display: false } },
                  scales: {
                      x: { grid: { color: chartGridColors }, ticks: { color: chartTextColors } },
                      y: { grid: { color: chartGridColors }, ticks: { color: chartTextColors } }
                  }
              }
          });

          // Prepare distribution data
          const distLabels = [];
          const distValues = [];
          {% for act in dist_data %}
              distLabels.push("{{ act.action }}");
              distValues.push({{ act.count }});
          {% endfor %}

          if (distLabels.length === 0) {
              distLabels.push("No Data");
              distValues.push(0);
          }

          // Distribution Chart
          const ctxDist = document.getElementById('distributionChart').getContext('2d');
          new Chart(ctxDist, {
              type: 'doughnut',
              data: {
                  labels: distLabels,
                  datasets: [{
                      data: distValues,
                      backgroundColor: [
                          '#661ae6', // primary
                          '#d926a9', // secondary
                          '#00d2ff', // info
                          '#ff5722', // error
                          '#3abff8',
                          '#36d399',
                          '#fbbd23',
                          '#ef6060'
                      ],
                      borderWidth: 0
                  }]
              },
              options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: {
                      legend: {
                          position: 'bottom',
                          labels: { color: chartTextColors, boxWidth: 10, font: { size: 9 } }
                      }
                  }
              }
          });
      });
  </script>
  {% endblock %}
  ```

- [ ] **Step 2: Commit UI Template**
  ```bash
  git add templates/bot_logs.twig
  git commit -m "feat(ui): implement dynamic Bot Logs dashboard template with live Chart.js visualization"
  ```

---

### Task 7: Verification

**Files:**
- Create [NEW]: `scratch/test_bot_logs.php`

- [ ] **Step 1: Write database test script**
  Create `scratch/test_bot_logs.php` to verify logger behaves correctly:
  ```php
  <?php
  require_once __DIR__ . '/../inc/Config.php';
  require_once __DIR__ . '/../inc/DB.php';
  require_once __DIR__ . '/../inc/TelegramClientBot.php';

  echo "Triggering connection and self-healing migrations...\n";
  $pdo = DB::conn();

  echo "Checking bot_activity_logs table...\n";
  $stmt = $pdo->query("SHOW TABLES LIKE 'bot_activity_logs'");
  if ($stmt->rowCount() === 0) {
      die("FAIL: bot_activity_logs table does not exist!\n");
  }
  echo "SUCCESS: Table exists.\n";

  echo "Logging test activities...\n";
  TelegramClientBot::logActivity(99887766, 'test_user', 'NK-99999', 'view_menu', 'Opened main menu in test');
  TelegramClientBot::logActivity(99887766, 'test_user', 'NK-99999', 'change_server_success', 'Switched router to Germany');
  TelegramClientBot::logActivity(88888888, 'Пользователь', null, 'unauthorized', 'Access denied to command /start');

  echo "Querying logged activities...\n";
  $logs = $pdo->query("SELECT * FROM bot_activity_logs ORDER BY id DESC LIMIT 3")->fetchAll();
  foreach ($logs as $log) {
      echo "[{$log['created_at']}] User: {$log['tg_name']} ({$log['tg_id']}) | Action: {$log['action']} | Details: {$log['details']}\n";
  }

  echo "ALL TESTS PASSED!\n";
  ```

- [ ] **Step 2: Execute verification script**
  Run the script:
  `php scratch/test_bot_logs.php`
  Expected: Prints table confirmation, logs activities, queries them, and displays `ALL TESTS PASSED!`.

- [ ] **Step 3: Cleanup scratch scripts**
  Remove the test script:
  `rm scratch/test_bot_logs.php`
  ```bash
  git status
  ```
