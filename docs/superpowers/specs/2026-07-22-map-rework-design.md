# Client Locations Map Rework Design Specification

**Date**: 2026-07-22  
**Target File**: `templates/dashboard.twig`  
**Status**: Draft for Review  

---

## 1. Overview & Objectives

The current client locations map on the dashboard displays basic glowing circles and raw coordinate-based grouping. The objective of this rework is to deliver a premium, high-tech dark mode visualization for client locations.

### Key Enhancements
1. **City-Based Location Grouping**: Group client nodes primarily by normalized `city` and `country` names (rather than exact lat/lon coordinates) so clients in the same city cluster into a single marker using their centroid (average coordinates).
2. **Cyber Radar Markers**: Replace plain CSS dots with custom SVG/CSS radar ripple markers color-coded by client status:
   - **Online**: Sky Blue (`bg-sky-400`, glowing radar ping)
   - **Idle** (<24h): Amber (`bg-amber-400`)
   - **Offline** (>24h): Rose (`bg-rose-500`)
3. **Numeric Cluster Badges**: Attach a small, high-contrast counter badge to markers that group multiple clients.
4. **Zero Flag Icons**: Remove all flag emojis/icons across map markers, location titles, and overlay panels for a cleaner, modern look.
5. **Glassmorphic Detail Card Overlay**: Refine the floating info panel (`#mapClientCard`) with backdrop blur (`backdrop-blur-md`), dark theme borders (`border-base-300`), compact client list items, and smooth slide-in transitions.

---

## 2. Technical Design & Logic

### 2.1 City-Based Grouping Algorithm
In JavaScript, clients with non-null `latitude` and `longitude` are processed:
```javascript
const locationGroups = {};

mappedClients.forEach(client => {
    const city = (client.city || '').trim().toLowerCase();
    const country = (client.country || '').trim().toLowerCase();
    
    // Primary key is normalized city + country; fallback to rounded lat/lon if city is missing/unknown
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

// Compute centroid (average latitude and longitude) for each group
Object.values(locationGroups).forEach(group => {
    group.lat = group.lats.reduce((a, b) => a + b, 0) / group.lats.length;
    group.lon = group.lons.reduce((a, b) => a + b, 0) / group.lons.length;
});
```

### 2.2 Cyber Radar Marker Implementation (`L.divIcon`)
Markers will be dynamically rendered using Leaflet's `L.divIcon`:
- Outer radar ring with `@keyframes ping` animation (only for online nodes).
- Center glowing pulse dot with status-dependent box-shadow (`0 0 12px <color>`).
- Numeric badge (`span`) rendered at top-right of marker when `group.clients.length > 1`.
- Clean text label below marker (e.g. `Frankfurt (5)` or `London - Laptop-1`).

### 2.3 Glassmorphic Overlay Panel UI
- **Location View**: Lists clients in the selected city with dot status indicators, client names, IP addresses, traffic bytes, and a button to view client details.
- **Detail View**: Full client breakdown (Name, IP, Server, Location, ISP, Total Traffic, Last Seen relative time, direct link to `/clients/{id}`).
- Text-only titles: `${group.city}, ${group.country}` (no flags).

---

## 3. Implementation Plan & Affected Files

### File: `templates/dashboard.twig`
- **CSS Styles (`{% block styles %}`)**: Update `.custom-map-marker` styles, radar ping animations, glassmorphic card backdrop filters, and Leaflet control button dark mode integration.
- **HTML Layout**: Ensure `#mapClientCard` container is styled cleanly with flexbox, max-height scrolling, and dark mode theme classes (`bg-base-200/95`, `backdrop-blur-md`).
- **JavaScript (`{% block scripts %}`)**:
  - Update `mappedClients` filtering and city-based grouping algorithm.
  - Re-implement `customIcon` builder with radar rings and count badge.
  - Refactor `marker.on('click')` handler to display city client list / client detail views seamlessly.

---

## 4. Verification Plan

### Automated / Syntax Check
- Verify Twig template syntax using `php -l` or Twig linter if available.

### Manual Verification
- Load dashboard in browser.
- Verify map renders Dark Carto tiles.
- Verify multiple clients with different IPs in the same city cluster into a single node with a numeric counter badge.
- Verify clicking a city node opens the glassmorphic detail card showing all clients in that city.
- Confirm zero flag emojis appear anywhere on the map or overlay card.
