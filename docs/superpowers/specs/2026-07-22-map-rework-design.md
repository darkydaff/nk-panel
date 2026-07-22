# Client Locations Map Rework Design Specification

**Date**: 2026-07-22  
**Target File**: `templates/dashboard.twig`  
**Status**: Revised - DaisyUI Theme Integration  

---

## 1. Overview & Objectives

The current client locations map on the dashboard displays basic glowing circles and raw coordinate-based grouping. The objective of this rework is to deliver a premium, high-tech visual map for client locations that seamlessly matches the DaisyUI (`night` theme) design system of `nk-panel`.

### Key Enhancements
1. **Full Theme Alignment (DaisyUI & Tailwind)**:
   - Use panel's exact color tokens: `bg-base-200` for cards, `bg-base-300` for map background, `text-base-content` for typography, `badge-success`/`badge-warning`/`badge-error` for status badges.
   - Standard DaisyUI buttons (`btn btn-xs btn-ghost`, `btn-circle`) and FontAwesome 6 icons (`fas fa-globe-americas`, `fas fa-desktop`, `fas fa-microchip`, `fas fa-times`).
   - Leaflet zoom controls styled with `oklch(var(--b2))` background, `oklch(var(--b3))` borders, and `oklch(var(--bc))` text.
2. **City-Based Location Grouping**:
   - Group client nodes primarily by normalized `city` and `country` names (rather than exact lat/lon coordinates) so clients in the same city cluster into a single marker using their centroid (average coordinates).
3. **Cyber Radar Markers**:
   - Status-coded glowing radar markers:
     - **Online**: Sky Blue (`bg-sky-500`, `shadow-[0_0_12px_rgba(56,189,248,0.8)]`, pulsing radar ping ring)
     - **Idle** (<24h): Amber (`bg-amber-400`, `shadow-[0_0_10px_rgba(251,191,36,0.8)]`)
     - **Offline** (>24h): Rose/Red (`bg-rose-500`, `shadow-[0_0_8px_rgba(244,63,94,0.6)]`)
4. **Numeric Cluster Badges**:
   - Attach a small badge (`badge badge-xs badge-primary font-mono font-bold`) to markers grouping multiple clients.
5. **Zero Flag Icons**:
   - Pure text-only location titles (`City, Country`) with zero flag icons or emojis across markers and detail panels.
6. **DaisyUI Styled Detail Card Overlay**:
   - Floating `#mapClientCard` using `bg-base-200/95 backdrop-blur-md border border-base-300 rounded-2xl shadow-2xl`, matching the exact card design of server and client detail modals in `nk-panel`.

---

## 2. Technical Design & Logic

### 2.1 City-Based Grouping Algorithm
In JavaScript, clients with non-null `latitude` and `longitude` are processed:
```javascript
const locationGroups = {};

mappedClients.forEach(client => {
    const city = (client.city || '').trim().toLowerCase();
    const country = (client.country || '').trim().toLowerCase();
    
    // Group by city + country name; fallback to rounded lat/lon if city is unknown
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

// Compute centroid (average latitude and longitude) for each city cluster
Object.values(locationGroups).forEach(group => {
    group.lat = group.lats.reduce((a, b) => a + b, 0) / group.lats.length;
    group.lon = group.lons.reduce((a, b) => a + b, 0) / group.lons.length;
});
```

### 2.2 Cyber Radar Marker Implementation (`L.divIcon`)
Markers will be dynamically rendered using Leaflet's `L.divIcon`:
- Outer radar ring with `@keyframes ping` animation (only for online nodes).
- Center glowing pulse dot with status-dependent box-shadow.
- Numeric badge (`badge badge-xs badge-primary`) rendered at top-right of marker when `group.clients.length > 1`.

### 2.3 Glassmorphic Overlay Panel UI Integration
- **Location View**: Lists clients in the selected city using `bg-base-300/50 border border-base-content/5 hover:bg-base-300`, matching client lists in `templates/servers/view.twig`.
- **Detail View**: Client breakdown formatted with DaisyUI badges (`badge-success`, `badge-warning`, `badge-error`), monospace IP/traffic text, and standard DaisyUI buttons.
- Text-only location header: `${group.city}, ${group.country}` (no flags).

---

## 3. File Changes

### File: `templates/dashboard.twig`
- **CSS Styles (`{% block styles %}`)**:
  - Leaflet map container: `background: oklch(var(--b1)) !important;`
  - Leaflet zoom controls: `border: 1px solid oklch(var(--b3)); background: oklch(var(--b2)); color: oklch(var(--bc));`
  - Radar marker CSS utility classes.
- **HTML Layout**:
  - Re-style `#mapClientCard` overlay container with `bg-base-200/95 backdrop-blur-md border border-base-300 rounded-2xl shadow-2xl`.
- **JavaScript (`{% block scripts %}`)**:
  - Update `mappedClients` filtering and city centroid grouping algorithm.
  - Custom `L.divIcon` HTML constructor matching status colors.
  - Marker click handler populating DaisyUI-themed list/detail views.

---

## 4. Verification Plan

### Automated / Syntax Check
- Twig file structure validation.

### Manual Verification
- Verify the map container and detail overlay perfectly match the colors, typography, border radiuses, and shadows of the surrounding DaisyUI dashboard widgets.
- Verify city clustering groups clients by city name using average coordinates.
- Verify node count badges appear when multiple clients share a city.
- Verify zero flag icons appear anywhere on the map or detail card.
