# Dashboard Layout & Vertical Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform the horizontal menu header into a premium, vertical left sidebar dashboard layout with a top utility bar, breadcrumbs, off-canvas mobile drawer, and bottom settings grouping.

**Architecture:** We will implement a flex-based grid where the sidebar is fixed-width (`w-64`) on the left, and the main content scroll viewport fills the remaining space. Pages will define their specific breadcrumb structure using Twig block inheritance.

**Tech Stack:** HTML5, Twig template engine, Tailwind CSS, FontAwesome, PHP 8.x

---

### Task 1: Controller Update for Client Breadcrumbs

**Files:**
- Modify: `public/index.php:580-598`

- [ ] **Step 1: Update client details route in index.php**
  Modify the `Router::get('/clients/{id}', ...)` handler to load server details and pass them to the renderer.
  ```php
  // View client
  Router::get('/clients/{id}', function ($params) {
      requireAuth();
      $clientId = (int)$params['id'];
      
      try {
          $client = new VpnClient($clientId);
          $clientData = $client->getData();
          
          // Check ownership
          $user = Auth::user();
          if ($clientData['user_id'] != $user['id'] && !Auth::isAdmin()) {
              http_response_code(403);
              echo 'Forbidden';
              return;
          }
          $stats = $client->getFormattedStats();
          
          // Fetch server details for client breadcrumbs
          $server = new VpnServer($clientData['server_id']);
          $serverData = $server->getData();
          
          View::render('clients/view.twig', [
              'client' => $clientData,
              'stats' => $stats,
              'server' => $serverData
          ]);
      } catch (Exception $e) {
          http_response_code(404);
          echo 'Client not found';
      }
  });
  ```

- [ ] **Step 2: Verify PHP changes**
  Run syntax check:
  `php -l public/index.php`
  Expected: No syntax errors detected.

- [ ] **Step 3: Commit**
  ```bash
  git add public/index.php
  git commit -m "refactor: load server info in client details route for breadcrumbs"
  ```

---

### Task 2: Vertical Layout and Sidebar Structure in `layout.twig`

**Files:**
- Modify: `templates/layout.twig:223-338`

- [ ] **Step 1: Refactor templates/layout.twig structure**
  Replace the horizontal navbar, body structure, and main tag inside `templates/layout.twig` with the sidebar and top-bar layout.
  Replace:
  ```twig
  <body class="text-slate-100">
      {% if user %}
      <nav class="nav shadow-lg sticky top-0 z-50">
          ...
      </nav>
      {% endif %}

      <main class="flex-grow flex flex-col {% if user %}py-6 px-3 sm:py-8 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full{% endif %}">
          {% block content %}{% endblock %}
      </main>
  ```
  With:
  ```twig
  <body class="text-slate-100 bg-slate-950 font-sans">
      {% if user %}
      <div class="flex min-h-screen bg-slate-950">
          <!-- Off-canvas Mobile Sidebar Overlay -->
          <div id="sidebarOverlay" class="fixed inset-0 z-30 bg-black/60 hidden transition-opacity duration-300 md:hidden" onclick="toggleMobileSidebar()"></div>

          <!-- Vertical Left Sidebar -->
          <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out border-r border-slate-800 bg-slate-900 flex flex-col">
              <!-- Sidebar Header (Logo & Brand) -->
              <div class="h-16 flex items-center px-6 border-b border-slate-800 gap-3 shrink-0">
                  <img src="/img/logo.svg" alt="Logo" class="w-8 h-8">
                  <span class="text-white text-lg font-bold tracking-tight">{{ app_name }}</span>
              </div>

              <!-- Main Navigation Links -->
              <nav class="flex-grow px-4 py-6 space-y-1.5 overflow-y-auto">
                  <a href="/dashboard" class="text-slate-300 hover:text-white hover:bg-slate-800/50 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3 {% if current_uri == '/dashboard' %}bg-slate-800 text-white font-semibold{% endif %}">
                      <i class="fas fa-tachometer-alt w-5 text-center opacity-70"></i>
                      <span>{{ t('menu.dashboard') }}</span>
                  </a>
                  <a href="/servers" class="text-slate-300 hover:text-white hover:bg-slate-800/50 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3 {% if current_uri starts with '/servers' %}bg-slate-800 text-white font-semibold{% endif %}">
                      <i class="fas fa-server w-5 text-center opacity-70"></i>
                      <span>{{ t('menu.servers') }}</span>
                  </a>
              </nav>

              <!-- Sidebar Footer (Settings, Profile & Logout) -->
              <div class="mt-auto border-t border-slate-800 p-4 space-y-4 bg-slate-900/50 shrink-0">
                  <div class="space-y-1">
                      <a href="/settings" class="text-slate-300 hover:text-white hover:bg-slate-800/50 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3 {% if current_uri starts with '/settings' %}bg-slate-800 text-white font-semibold{% endif %}">
                          <i class="fas fa-cog w-5 text-center opacity-70"></i>
                          <span>{{ t('menu.settings') }}</span>
                      </a>
                      <a href="/logout" class="text-slate-400 hover:text-red-400 hover:bg-red-500/10 px-4 py-3 rounded-lg text-sm font-medium transition-all flex items-center gap-3">
                          <i class="fas fa-sign-out-alt w-5 text-center"></i>
                          <span>{{ t('menu.logout') }}</span>
                      </a>
                  </div>

                  <div class="border-t border-slate-800 my-2"></div>

                  <!-- User Widget -->
                  <div class="flex items-center gap-3 px-2 py-1.5 rounded-lg bg-slate-950/40">
                      <div class="w-8 h-8 rounded-full bg-slate-800 flex items-center justify-center font-semibold text-xs text-sky-400 border border-slate-700 select-none">
                          {{ user.name|slice(0, 2)|upper }}
                      </div>
                      <div class="flex flex-col min-w-0">
                          <span class="text-xs font-semibold text-white truncate">{{ user.name }}</span>
                          <span class="text-[9px] text-slate-500 uppercase tracking-wider">{{ user.role }}</span>
                      </div>
                  </div>
              </div>
          </aside>

          <!-- Right side Viewport -->
          <div class="flex-1 flex flex-col min-w-0 md:pl-64">
              <!-- Top Utility Bar -->
              <header class="h-16 border-b border-slate-800 bg-slate-900/30 backdrop-blur sticky top-0 z-30 flex items-center justify-between px-4 sm:px-6">
                  <!-- Breadcrumbs & Hamburger Menu -->
                  <div class="flex items-center gap-3">
                      <button onclick="toggleMobileSidebar()" type="button" class="md:hidden text-slate-300 hover:text-white p-2 rounded-lg hover:bg-slate-800 transition-colors focus:outline-none">
                          <i class="fas fa-bars text-lg"></i>
                      </button>
                      <div class="flex items-center text-xs font-medium text-slate-500 gap-1 sm:gap-2">
                          {% block breadcrumbs %}
                              <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
                          {% endblock %}
                      </div>
                  </div>

                  <!-- Right Utils (Language Dropdown) -->
                  <div class="flex items-center">
                      <div class="relative">
                          <button onclick="toggleLanguageDropdown()" class="text-slate-300 hover:text-white flex items-center px-3 py-2 rounded-lg hover:bg-slate-800 transition-colors focus:outline-none text-sm font-medium">
                              {% for lang in languages %}
                                  {% if lang.code == current_language %}
                                      <span class="mr-2">{{ getFlag(lang.code) }}</span>
                                  {% endif %}
                              {% endfor %}
                              <i class="fas fa-chevron-down ml-1.5 text-[10px] opacity-70"></i>
                          </button>
                          <div id="languageDropdown" class="language-dropdown absolute right-0 mt-2 w-48 panel z-50 shadow-xl p-1 bg-slate-900 border border-slate-800 rounded-lg">
                              {% for lang in languages %}
                              <form method="POST" action="/language/change" class="block">
                                  <input type="hidden" name="language" value="{{ lang.code }}">
                                  <input type="hidden" name="redirect" value="{{ current_uri }}">
                                  <button type="submit" class="w-full text-left px-4 py-2.5 text-sm text-slate-300 hover:text-white hover:bg-slate-800 rounded-lg flex items-center transition-colors
                                      {% if lang.code == current_language %}bg-slate-800 text-white font-medium{% endif %}">
                                      <span class="mr-3">{{ getFlag(lang.code) }}</span>
                                      <span>{{ lang.name }}</span>
                                      {% if lang.code == current_language %}
                                      <i class="fas fa-check text-cyan-400 ml-auto"></i>
                                      {% endif %}
                                  </button>
                              </form>
                              {% endfor %}
                          </div>
                      </div>
                  </div>
              </header>

              <!-- Main Content Workspace -->
              <main class="flex-grow py-6 px-4 sm:px-6 lg:px-8 w-full max-w-7xl mx-auto flex flex-col">
                  {% block content %}{% endblock %}
              </main>

              <!-- Main Footer -->
              <footer class="mt-auto border-t border-slate-800 py-4 bg-slate-900/10 text-center shrink-0">
                  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                      <span class="text-xs text-slate-600">{{ app_name }} &copy; {{ "now"|date("Y") }}</span>
                  </div>
              </footer>
          </div>
      </div>
      {% else %}
      <main class="flex-grow flex flex-col">
          {% block content %}{% endblock %}
      </main>
      {% endif %}
  ```

- [ ] **Step 2: Add sidebar mobile drawer script in templates/layout.twig**
  In `layout.twig` scripts block, add the `toggleMobileSidebar` function.
  Modify the script section:
  ```javascript
  function toggleMobileSidebar() {
      const sidebar = document.getElementById('sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar && overlay) {
          sidebar.classList.toggle('-translate-x-full');
          overlay.classList.toggle('hidden');
          overlay.classList.toggle('opacity-0');
      }
  }
  ```

- [ ] **Step 3: Commit**
  ```bash
  git add templates/layout.twig
  git commit -m "feat: refactor templates/layout.twig to vertical sidebar and top header grid"
  ```

---

### Task 3: Implement Dashboard specific breadcrumbs

**Files:**
- Modify: `templates/dashboard.twig`

- [ ] **Step 1: Add breadcrumbs override block**
  At the top of `templates/dashboard.twig` below the title block, add the breadcrumbs block override.
  ```twig
  {% block breadcrumbs %}
      <span class="text-slate-300">Dashboard</span>
  {% endblock %}
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add templates/dashboard.twig
  git commit -m "feat: add breadcrumbs block to dashboard template"
  ```

---

### Task 4: Implement Server views specific breadcrumbs

**Files:**
- Modify: `templates/servers/index.twig`
- Modify: `templates/servers/create.twig`
- Modify: `templates/servers/deploy.twig`
- Modify: `templates/servers/view.twig`

- [ ] **Step 1: Update servers list template**
  At the top of `templates/servers/index.twig`, add the breadcrumbs override block.
  ```twig
  {% block breadcrumbs %}
      <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <span class="text-slate-300">Servers</span>
  {% endblock %}
  ```

- [ ] **Step 2: Update server creation template**
  At the top of `templates/servers/create.twig`, add the breadcrumbs override block.
  ```twig
  {% block breadcrumbs %}
      <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <a href="/servers" class="hover:text-slate-300 transition-colors">Servers</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <span class="text-slate-300">Add Server</span>
  {% endblock %}
  ```

- [ ] **Step 3: Update server deployment template**
  At the top of `templates/servers/deploy.twig`, add the breadcrumbs override block.
  ```twig
  {% block breadcrumbs %}
      <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <a href="/servers" class="hover:text-slate-300 transition-colors">Servers</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <a href="/servers/{{ server.id }}" class="hover:text-slate-300 transition-colors">{{ server.name }}</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <span class="text-slate-300">Deployment</span>
  {% endblock %}
  ```

- [ ] **Step 4: Update server details view template**
  At the top of `templates/servers/view.twig`, add the breadcrumbs override block.
  ```twig
  {% block breadcrumbs %}
      <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <a href="/servers" class="hover:text-slate-300 transition-colors">Servers</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <span class="text-slate-300">{{ server.name }}</span>
  {% endblock %}
  ```

- [ ] **Step 5: Commit**
  ```bash
  git add templates/servers/*.twig
  git commit -m "feat: add breadcrumbs blocks to all server views templates"
  ```

---

### Task 5: Implement Client details breadcrumbs

**Files:**
- Modify: `templates/clients/view.twig`

- [ ] **Step 1: Update client details template**
  At the top of `templates/clients/view.twig`, add the breadcrumbs override block.
  ```twig
  {% block breadcrumbs %}
      <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <a href="/servers" class="hover:text-slate-300 transition-colors">Servers</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <a href="/servers/{{ client.server_id }}" class="hover:text-slate-300 transition-colors">{{ server.name }}</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <span class="text-slate-300">Client: {{ client.name }}</span>
  {% endblock %}
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add templates/clients/view.twig
  git commit -m "feat: add hierarchical server-to-client breadcrumbs block to client view"
  ```

---

### Task 6: Implement Settings breadcrumbs

**Files:**
- Modify: `templates/settings.twig`

- [ ] **Step 1: Update settings page template**
  At the top of `templates/settings.twig`, add the breadcrumbs override block.
  ```twig
  {% block breadcrumbs %}
      <a href="/dashboard" class="hover:text-slate-300 transition-colors">Home</a>
      <i class="fas fa-chevron-right text-[10px] text-slate-700 mx-1"></i>
      <span class="text-slate-300">Settings</span>
  {% endblock %}
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add templates/settings.twig
  git commit -m "feat: add breadcrumbs block to settings view"
  ```
