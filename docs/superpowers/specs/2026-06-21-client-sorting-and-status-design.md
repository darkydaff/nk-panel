# Design Specification: Client Sorting, Connection Status, and City Columns

This specification details the design for implementing:
1. Robust status classification and filtering for "Never Connected" clients on both the dashboard and server details pages.
2. Interactive client table sorting on the server details page.
3. Exposing and rendering the "City" connection location column in the server details client table.

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

## 2. Expose and Display City Location

### Goal
Include the connection "City" column in the server details client list table, dynamically updating during AJAX background polling.

### API Changes (`public/index.php`)
Include the client's `city` column in the JSON response of `/api/servers/{id}/clients`:
```php
$clientsData[] = [
    // ...
    'city' => $clientData['city'],
];
```

### UI Changes (`templates/servers/view.twig`)
1. **Table Headers**:
   Add a "City" sortable header between IP and Traffic:
   ```html
   <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none hide-mobile" data-sort="city">
       City <i class="fas fa-sort ml-1 opacity-45 text-[9px]" id="sortIcon-city"></i>
   </th>
   ```
2. **Table Cells**:
   Render the cell in `tr.client-row` using `client.city` value:
   ```html
   <td class="px-4 py-3 hide-mobile city-container text-xs text-slate-400">
       {{ client.city|default('—') }}
   </td>
   ```
3. **Data Attributes**:
   Attach `data-city="{{ client.city|default('') }}"` to both desktop table rows and mobile card elements.
4. **Mobile Card Subtitle**:
   Append city name next to the client IP:
   ```html
   <code class="block text-xs text-slate-500 mt-0.5 font-mono">
       {{ client.client_ip }}{% if client.city %} ({{ client.city }}){% endif %}
   </code>
   ```

---

## 3. Client Table Advanced Sorting

### Goal
Provide interactive, client-side sorting of the client list in `templates/servers/view.twig` by clicking on table column headers.

### UI Modifications (`templates/servers/view.twig`)
We will make the table headers clickable by wrapping them with interactive cursor-pointer styles and sorting direction indicator icons:
```html
<thead>
    <tr class="bg-slate-800/50">
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none" data-sort="name">
            Name <i class="fas fa-sort ml-1 opacity-45 text-[9px]" id="sortIcon-name"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none hide-mobile" data-sort="ip">
            IP <i class="fas fa-sort ml-1 opacity-45 text-[9px]" id="sortIcon-ip"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none hide-mobile" data-sort="city">
            City <i class="fas fa-sort ml-1 opacity-45 text-[9px]" id="sortIcon-city"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none" data-sort="traffic">
            Traffic <i class="fas fa-sort ml-1 opacity-45 text-[9px]" id="sortIcon-traffic"></i>
        </th>
        <th class="sortable px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold cursor-pointer select-none hide-mobile" data-sort="handshake">
            Handshake <i class="fas fa-sort ml-1 opacity-45 text-[9px]" id="sortIcon-handshake"></i>
        </th>
        <th class="px-4 py-3 text-right text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Actions</th>
    </tr>
</thead>
```

### Sorting Data Attributes
Each client row `tr.client-row` and card `div.client-card` will specify:
* `data-name`: string (e.g. `John_MacBook`)
* `data-ip`: string (e.g. `10.8.0.2`)
* `data-city`: string (e.g. `New York`)
* `data-bytes`: number (aggregate of `client.bytes_sent + client.bytes_received` in bytes)
* `data-last-handshake`: number (timestamp or `"never"`)

### JavaScript Sorting Algorithm
We will maintain a global sort state:
```javascript
let currentSortColumn = 'name'; // default
let currentSortDirection = 'asc'; // 'asc' or 'desc'
```
Clicking a header toggles the direction (or switches the column), updates the icons, and re-orders the DOM nodes under `#clientTableBody` (and `#clientCards`).

* **Sorting Logic**:
  * **Name / IP / City**: Simple alphabetical string comparison.
  * **Traffic**: Numeric comparison of `data-bytes`.
  * **Handshake**: Numeric comparison of `data-last-handshake` timestamp. `"never"` will be treated as `0` so it is grouped cleanly.

We will integrate the sorting logic into the `pollClients()` lifecycle so that live stats polling updates data attributes and live speeds while preserving the user's active sort order.

---

## Verification Plan

### Manual Verification
1. Verify the "City" column header displays next to "IP".
2. Confirm the city name is rendered correctly for clients with populated GeoIP data, and is updated dynamically during polling.
3. Test sorting the table by City name and verify it orders the list alphabetically (A-Z and Z-A).
4. Verify the mobile card shows the city name inside parenthesis next to the IP address.
