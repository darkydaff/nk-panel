# Client Locations Map Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the client locations map on the dashboard into a high-tech Cyber Radar visualization with city-based grouping, DaisyUI night theme alignment, zero flag icons, and glassmorphic detail cards.

**Architecture:** Group client locations by city/country centroid, render custom Leaflet `divIcon` radar markers with online/idle/offline status colors and numeric cluster count badges, and update the overlay card panel to match DaisyUI theme components.

**Tech Stack:** Leaflet.js, Tailwind CSS, DaisyUI (`night` theme), FontAwesome 6, Twig.

---

### Task 1: Update Map Styles & Container UI

**Files:**
- Modify: `templates/dashboard.twig:205-225` and `templates/dashboard.twig:280-313`

- [ ] **Step 1: Update Leaflet container & marker CSS rules in `templates/dashboard.twig`**

Replace lines 283-312 of `templates/dashboard.twig` with DaisyUI night theme styles:

```html
<style>
    .leaflet-container {
        background: oklch(var(--b1)) !important;
        font-family: inherit;
    }
    .leaflet-bar {
        border: 1px solid oklch(var(--b3)) !important;
        box-shadow: none !important;
        border-radius: 8px !important;
        overflow: hidden;
    }
    .leaflet-bar a {
        background-color: oklch(var(--b2)) !important;
        color: oklch(var(--bc)) !important;
        border-bottom: 1px solid oklch(var(--b3)) !important;
        transition: background-color 0.2s, color 0.2s;
    }
    .leaflet-bar a:hover {
        background-color: oklch(var(--b3)) !important;
        color: oklch(var(--p)) !important;
    }
    .leaflet-bar a:last-child { border-bottom: none !important; }
    .leaflet-control-attribution {
        background: oklch(var(--b1) / 0.8) !important;
        color: oklch(var(--bc) / 0.4) !important;
        font-size: 9px !important;
    }
    .leaflet-control-attribution a { color: oklch(var(--p)) !important; }
    .custom-map-marker { background: transparent !important; border: none !important; }
</style>
```

- [ ] **Step 2: Verify `#mapClientCard` overlay container styling**

Ensure lines 208-225 in `templates/dashboard.twig` use DaisyUI night theme card components:

```html
<div id="mapClientCard" class="absolute top-4 right-4 z-[1000] w-80 bg-base-200/95 backdrop-blur-md border border-base-300 rounded-2xl p-4 shadow-2xl hidden transition-all duration-300 transform translate-y-2 opacity-0 flex flex-col max-h-[360px]">
```

- [ ] **Step 3: Commit Task 1**

```bash
git add templates/dashboard.twig
git commit -m "style(map): update Leaflet and card overlay to DaisyUI night theme tokens"
```

---

### Task 2: Implement City Centroid Grouping & Cyber Radar Markers JS Logic

**Files:**
- Modify: `templates/dashboard.twig:550-650`

- [ ] **Step 1: Replace raw coordinate grouping with City Centroid Grouping**

In `templates/dashboard.twig`, replace lines 550-556 with normalized city + country grouping logic:

```javascript
    const locationGroups = {};
    mappedClients.forEach(client => {
        const city = (client.city || '').trim().toLowerCase();
        const country = (client.country || '').trim().toLowerCase();
        
        const key = (city && city !== 'unknown')
            ? `${city}_${country}`
            : `${parseFloat(client.latitude).toFixed(2)},${parseFloat(client.longitude).toFixed(2)}`;

        if (!locationGroups[key]) {
            locationGroups[key] = {
                city: client.city || 'Unknown',
                country: client.country || 'Unknown',
                lats: [],
                lons: [],
                clients: []
            };
        }
        locationGroups[key].lats.push(parseFloat(client.latitude));
        locationGroups[key].lons.push(parseFloat(client.longitude));
        locationGroups[key].clients.push(client);
    });

    Object.values(locationGroups).forEach(group => {
        group.lat = group.lats.reduce((a, b) => a + b, 0) / group.lats.length;
        group.lon = group.lons.reduce((a, b) => a + b, 0) / group.lons.length;
    });
```

- [ ] **Step 2: Update Cyber Radar Icon generation & Click Detail Handler**

Replace lines 561-650 in `templates/dashboard.twig` with the updated status radar marker and text-only detail handlers:

```javascript
    Object.values(locationGroups).forEach(group => {
        let groupStatus = 'offline';
        group.clients.forEach(c => {
            const act = getClientActivity(c.last_handshake);
            if (act.status === 'online') groupStatus = 'online';
            else if (act.status === 'idle' && groupStatus !== 'online') groupStatus = 'idle';
        });

        let pulseColorClass = 'bg-rose-500 shadow-[0_0_10px_rgba(244,63,94,0.7)]';
        let pingColorClass = 'bg-rose-500';
        let isOnline = false;

        if (groupStatus === 'online') {
            pulseColorClass = 'bg-sky-400 shadow-[0_0_12px_rgba(56,189,248,0.8)]';
            pingColorClass = 'bg-sky-400';
            isOnline = true;
        } else if (groupStatus === 'idle') {
            pulseColorClass = 'bg-amber-400 shadow-[0_0_10px_rgba(251,191,36,0.8)]';
            pingColorClass = 'bg-amber-400';
        }

        const countBadgeHtml = group.clients.length > 1
            ? `<span class="absolute -top-1.5 -right-1.5 badge badge-xs badge-primary font-mono font-bold px-1.5 py-0.5 text-[9px] shadow-md z-10">${group.clients.length}</span>`
            : '';

        const pingHtml = isOnline
            ? `<span class="animate-ping absolute inline-flex h-full w-full rounded-full ${pingColorClass} opacity-50"></span>`
            : '';

        const customIcon = L.divIcon({
            className: 'custom-map-marker',
            html: `<div class="relative flex items-center justify-center cursor-pointer" style="width:24px;height:24px;">
                     ${pingHtml}
                     <span class="relative inline-flex rounded-full ${pulseColorClass} border-2 border-base-100" style="width:12px;height:12px;"></span>
                     ${countBadgeHtml}
                   </div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });

        const marker = L.marker([group.lat, group.lon], { icon: customIcon });

        function showClientDetails(client, activity) {
            document.getElementById('cardClientName').textContent = client.name;
            const statusBadge = document.getElementById('cardClientStatus');
            statusBadge.className = `badge badge-sm mt-1 w-max ${activity.colorClass}`;
            statusBadge.textContent = activity.text;
            document.getElementById('cardClientIp').textContent = client.client_ip;
            document.getElementById('cardClientServer').textContent = client.server_name || 'N/A';
            document.getElementById('cardClientLoc').textContent = `${client.city || 'Unknown'}, ${client.country || 'Unknown'}`;
            document.getElementById('cardClientIsp').textContent = client.isp || 'N/A';
            document.getElementById('cardClientTraffic').textContent = formatBytes((parseInt(client.bytes_sent) || 0) + (parseInt(client.bytes_received) || 0));
            document.getElementById('cardClientLastSeen').textContent = formatRelativeTime(client.last_handshake);
            document.getElementById('cardClientLink').href = `/clients/${client.id}`;
            document.getElementById('cardListView').classList.add('hidden');
            document.getElementById('cardDetailView').classList.remove('hidden');
        }

        marker.on('click', function(e) {
            map.setView([group.lat, group.lon], Math.max(map.getZoom(), 5), { animate: true });
            document.getElementById('cardBackButton').onclick = function() {
                document.getElementById('cardDetailView').classList.add('hidden');
                document.getElementById('cardListView').classList.remove('hidden');
            };

            if (group.clients.length === 1) {
                const client = group.clients[0];
                showClientDetails(client, getClientActivity(client.last_handshake));
                document.getElementById('cardBackButton').classList.add('hidden');
            } else {
                document.getElementById('cardLocationTitle').textContent = `${group.city}, ${group.country}`;
                document.getElementById('cardClientCount').textContent = `${group.clients.length} clients`;
                const listContainer = document.getElementById('cardClientList');
                listContainer.innerHTML = '';
                group.clients.forEach(client => {
                    const activity = getClientActivity(client.last_handshake);
                    const item = document.createElement('div');
                    item.className = 'flex items-center justify-between p-2 rounded-xl bg-base-300/50 border border-base-content/5 hover:bg-base-300 transition-colors';
                    let dotColor = 'bg-error';
                    if (activity.status === 'online') dotColor = 'bg-sky-400 shadow-[0_0_6px_rgba(56,189,248,0.8)]';
                    else if (activity.status === 'idle') dotColor = 'bg-warning';
                    
                    item.innerHTML = `
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="w-2 h-2 rounded-full ${dotColor} shrink-0"></span>
                            <div class="flex flex-col min-w-0">
                                <span class="text-xs font-semibold truncate max-w-[130px]">${client.name}</span>
                                <span class="text-[9px] text-base-content/40 font-mono">${client.client_ip}</span>
                            </div>
                        </div>
                        <button class="btn btn-ghost btn-xs text-primary font-medium">Details</button>
                    `;
                    item.querySelector('button').addEventListener('click', function() {
                        showClientDetails(client, activity);
                        document.getElementById('cardBackButton').classList.remove('hidden');
                    });
                    listContainer.appendChild(item);
                });
                document.getElementById('cardListView').classList.remove('hidden');
                document.getElementById('cardDetailView').classList.add('hidden');
            }

            const card = document.getElementById('mapClientCard');
            card.classList.remove('hidden');
            setTimeout(() => { card.classList.remove('translate-y-2', 'opacity-0'); }, 10);
        });

        marker.addTo(markerGroup);
    });
```

- [ ] **Step 3: Commit Task 2**

```bash
git add templates/dashboard.twig
git commit -m "feat(map): implement city centroid grouping and cyber radar markers with DaisyUI integration"
```

---

### Task 3: Verification & Polish

- [ ] **Step 1: Check Twig template for syntax errors**

```bash
php -l public/index.php
```

- [ ] **Step 2: Commit any final polish**

```bash
git add templates/dashboard.twig
git commit -m "chmod/polish: final map UI refinements"
```
