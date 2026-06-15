# Design Specification: Dashboard Layout & Vertical Navigation

This document defines the structural UI/UX changes to transition the panel from a top horizontal menu to a left-side vertical sidebar with top utility bars, breadcrumbs, and responsive mobile behavior.

## Overview
To elevate the panel into a premium, real dashboard interface, we are:
1. Replacing the horizontal top header navigation with a **sticky left vertical sidebar**.
2. Restructuring the page container into a side-by-side flex layout.
3. Adding a **top utility bar** that handles page context via **breadcrumbs** on the left, and secondary functions (language, user profile, theme toggles) on the right.
4. Implementing a mobile off-canvas drawer that slides out when the hamburger menu is tapped.
5. Putting the **Settings** link and the user profile at the bottom of the left sidebar.

## Detailed Layout Changes

### 1. Main Page Wrapper (`layout.twig`)
We will refactor the page structure inside the main layout. When a user is authenticated, the layout will look like:
```html
<div class="flex min-h-screen bg-slate-950 text-slate-100 font-sans">
    <!-- Sidebar -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out border-r border-slate-800 bg-slate-900/90 backdrop-blur flex flex-col">
        <!-- Sidebar content -->
    </aside>

    <!-- Mobile sidebar overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 z-30 bg-black/50 hidden md:hidden" onclick="toggleMobileSidebar()"></div>

    <!-- Main Content wrapper -->
    <div class="flex-1 flex flex-col min-w-0 md:pl-64">
        <!-- Top bar -->
        <header class="h-16 border-b border-slate-800 bg-slate-900/40 backdrop-blur sticky top-0 z-30 flex items-center justify-between px-4 sm:px-6">
            <!-- Left: Mobile toggle + Breadcrumbs -->
            <!-- Right: User info + Language dropdown -->
        </header>

        <!-- Main Workspace -->
        <main class="flex-grow py-6 px-4 sm:px-6 lg:px-8 w-full max-w-7xl mx-auto overflow-y-auto">
            {% block content %}{% endblock %}
        </main>

        <!-- Compact Footer -->
        <footer class="py-4 border-t border-slate-800 bg-slate-900/20 text-center text-xs text-slate-500">
            {{ app_name }} &copy; {{ "now"|date("Y") }}
        </footer>
    </div>
</div>
```

### 2. Vertical Left Sidebar Composition
The sidebar has a flex column layout (`flex flex-col h-full`):
* **Top Header**: Logo image and `{{ app_name }}` title.
* **Middle Section (`flex-1`)**: Main navigation items:
  * **Dashboard** (`/dashboard` with `<i class="fas fa-tachometer-alt">`)
  * **Servers** (`/servers` with `<i class="fas fa-server">`)
* **Bottom Section (`mt-auto border-t border-slate-800 pt-4 pb-6 px-4`)**:
  * **Settings** (`/settings` with `<i class="fas fa-cog">`, visible to all roles).
  * **User Profile widget**: Minimal pill containing the user's initials avatar and name.
  * **Logout link** (`/logout` with `<i class="fas fa-sign-out-alt">`, styled with soft red text on hover).

### 3. Top Utility Bar & Breadcrumbs
The top header will house the breadcrumb navigation to trace the user's path. We use a Twig block `{% block breadcrumbs %}` to let each view override it dynamically:
* **Dashboard View**: `Dashboard`
* **Servers list**: `Home / Servers`
* **Server Details**: `Home / Servers / [Server Name]`
* **Client Details**: `Home / Servers / [Server Name] / Client: [Client Name]`

To support the Client Details breadcrumbs, the `/clients/{id}` route in `public/index.php` will be updated to fetch and pass the server details object (`server => $serverData`) to the `clients/view.twig` template:
```php
$server = new VpnServer($clientData['server_id']);
$serverData = $server->getData();
View::render('clients/view.twig', [
    'client' => $clientData,
    'stats' => $stats,
    'server' => $serverData
]);
```

### 4. Responsive/Mobile Interactivity
* Screen size `< 768px` hides the sidebar off-screen (`-translate-x-full`).
* A hamburger menu icon button `<button onclick="toggleMobileSidebar()">` is added to the left of the breadcrumbs.
* Tapping it transitions the sidebar into view (`translate-x-0`).
* Clicking the backdrop overlay hides it again.

## Styling & Quality Standards
* Font definitions: `Outfit` and `Inter` will remain the core sans-serif fonts, with `Geist Mono` for IP addresses/technical data.
* Color palette: Standard dark modes utilizing tailwind `slate` / `zinc` dark values (e.g. `bg-slate-950`, `border-slate-800`) to maintain a premium feel.
* Ensure theme changes and dynamic Chart.js configurations continue to respect layout styling.

## Verification Plan

### Manual Verification
1. Log in and verify that the layout displays a left sidebar and a top bar.
2. Confirm the Settings link is located in the bottom section of the sidebar.
3. Test layout responsiveness: scale the window down to mobile width (<768px), verify the sidebar slides off, click the hamburger button to open the drawer overlay, and click the backdrop to close.
4. Go to `/dashboard`, `/servers`, `/servers/ID`, and `/clients/ID` and verify the breadcrumbs show the correct path.
5. In client view, confirm that the breadcrumb shows the actual parent Server Name.
6. Verify language switching, logout, and notifications function properly in the new layout.
