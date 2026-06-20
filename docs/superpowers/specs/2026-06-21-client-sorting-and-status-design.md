# Design Specification: Client Sorting and Connection Status Filters

This specification details the design for implementing:
1. Robust status classification and filtering for "Never Connected" clients on both the dashboard and server details pages.
2. Interactive client table sorting on the server details page.

---

## 1. Fix "Never Connected" Status Classification

### Goal
Ensure that clients who have never established a successful connection are classified as "Never Connected" instead of "Offline". This must work dynamically on initial page load (PHP/Twig) and during periodic AJAX polling updates (JS).

### Backend Changes (`public/index.php`)
We will ensure the API `/api/servers/{id}/clients` correctly flags clients with zero connections by checking for blank, zero, or epoch timestamps in the `last_handshake` database column:
```php
$lh = $clientData['last_handshake'];
$isNever = !$lh || $lh === '0000-00-00 00:00:00' || $lh === '1970-01-01 00:00:00' || $lh === '0';

// API response
$clientsData[] = [
    // ...
    'last_handshake' => $isNever ? null : $lh,
    'last_handshake_raw' => $isNever ? null : strtotime($lh),
];
```

### Dashboard View (`templates/dashboard.twig`)
We will adjust the twig loop counting client statuses so it parses blank/zero dates as "Never Connected":
```twig
{% set online_clients_count = 0 %}
{% set offline_clients_count = 0 %}
{% set never_connected_count = 0 %}
{% set current_time = "now"|date("U") %}

{% for c in clients %}
    {% set is_never = true %}
    {% if c.last_handshake is not empty and c.last_handshake != '0000-00-00 00:00:00' and c.last_handshake != '1970-01-01 00:00:00' and c.last_handshake != '0' %}
        {% set is_never = false %}
    {% endif %}

    {% if not is_never %}
        {% set handshake_time = c.last_handshake|date("U") %}
        {% if (current_time - handshake_time) < 300 %}
            {% set online_clients_count = online_clients_count + 1 %}
        {% else %}
            {% set offline_clients_count = offline_clients_count + 1 %}
        {% endif %}
    {% else %}
        {% set never_connected_count = never_connected_count + 1 %}
    {% endif %}
{% endfor %}
```

### Server Details View (`templates/servers/view.twig`)
1. **Twig Initialization**:
   Add the helper logic to identify `is_never` connection status for both table rows (desktop) and card grids (mobile):
   ```twig
   {% set is_never = true %}
   {% if client.last_handshake is not empty and client.last_handshake != '0000-00-00 00:00:00' and client.last_handshake != '1970-01-01 00:00:00' and client.last_handshake != '0' %}
       {% set is_never = false %}
   {% endif %}
   ```
   Set `data-last-handshake="{% if not is_never %}{{ client.last_handshake|date('U') }}{% else %}never{% endif %}"` on `tr.client-row` and `div.client-card`.

2. **JavaScript Filtering Logic**:
   Update `applyFilters()` to cleanly differentiate "Never" status:
   ```javascript
   const handshakeVal = el.dataset.lastHandshake;
   const handshakeTime = parseInt(handshakeVal);
   const isNever = !handshakeVal || handshakeVal === 'never' || isNaN(handshakeTime) || handshakeTime <= 0;
   
   if (currentStatusFilter === 'never') {
       statusMatch = isNever;
   } else if (currentStatusFilter === 'online') {
       statusMatch = !isNever && (currentTime - handshakeTime) < 300;
   } else if (currentStatusFilter === 'offline') {
       statusMatch = !isNever && (currentTime - handshakeTime) >= 300;
   }
   ```

---

## 2. Client Table Advanced Sorting

### Goal
Provide interactive, client-side sorting of the client list in `templates/servers/view.twig` by clicking on table column headers.

### UI Modifications (`templates/servers/view.twig`)
We will make the table headers clickable by wrapping them with interactive cursor-pointer styles and sorting direction indicator icons:
```html
<thead>
    <tr class="bg-slate-800/50">
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none" data-sort="name">
            Name <i class="fas fa-sort ml-1 opacity-40 text-[9px]"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none hide-mobile" data-sort="ip">
            IP <i class="fas fa-sort ml-1 opacity-40 text-[9px]"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none" data-sort="traffic">
            Traffic <i class="fas fa-sort ml-1 opacity-40 text-[9px]"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none hide-mobile" data-sort="handshake">
            Handshake <i class="fas fa-sort ml-1 opacity-40 text-[9px]"></i>
        </th>
        <th class="px-4 py-3 text-right text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Actions</th>
    </tr>
</thead>
```

### Sorting Data Attributes
Each client row `tr.client-row` and card `div.client-card` will specify:
* `data-name`: string (e.g. `John_MacBook`)
* `data-ip`: string (e.g. `10.8.0.2`)
* `data-bytes`: number (aggregate of `client.bytes_sent + client.bytes_received` in bytes)
* `data-last-handshake`: number (timestamp or `"never"`)

We will add the `data-bytes` attribute dynamically to both table rows and cards:
`data-bytes="{{ client.bytes_sent + client.bytes_received }}"`

### JavaScript Sorting Algorithm
We will maintain a global sort state:
```javascript
let currentSortColumn = 'name'; // default
let currentSortDirection = 'asc'; // 'asc' or 'desc'
```
Clicking a header toggles the direction (or switches the column), updates the icons, and re-orders the DOM nodes under `#clientTableBody` (and `#clientCards`).

* **Sorting Logic**:
  * **Name / IP**: Simple alphabetical string comparison.
  * **Traffic**: Numeric comparison of `data-bytes`.
  * **Handshake**: Numeric comparison of `data-last-handshake` timestamp. `"never"` will be treated as `0` so it is grouped cleanly.

We will integrate the sorting logic into the `pollClients()` lifecycle so that live stats polling updates data attributes and live speeds while preserving the user's active sort order.

---

## Verification Plan

### Manual Verification
1. Open the dashboard and verify that the "Never Connected" count matches the actual number of clients who have never established a connection.
2. Open `/servers/ID` and verify the "Never" filter pill correctly shows only the never-connected clients.
3. In `/servers/ID` and mobile layout, test clicking the table headers (Name, IP, Traffic, Handshake):
   * Confirm the table rows instantly re-order.
   * Confirm the sort indicator icons switch between `fa-sort-up`, `fa-sort-down`, and default `fa-sort`.
   * Confirm sorting is maintained when the table auto-refreshes every 5 seconds.
