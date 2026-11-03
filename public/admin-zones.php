<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/CsrfProtection.php';
$csrfToken = CsrfProtection::getToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VIP Road & Flyover Zone Manager - BharaKoto Admin</title>
    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; script-src 'self' https: 'unsafe-inline' 'unsafe-eval'; style-src 'self' https: 'unsafe-inline'; font-src 'self' https: data:; img-src 'self' https: data: blob:; connect-src 'self' https:;">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        bdgreen: {
                            50: '#ecfdf5',
                            600: '#059669',
                            700: '#047857',
                            800: '#065f46',
                        },
                        cngyellow: {
                            400: '#facc15',
                            500: '#eab308',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Leaflet & Leaflet.draw CDN -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
    <style>
        #adminMap { height: 500px; width: 100%; border-radius: 1rem; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased">

    <!-- Top Navigation -->
    <header class="bg-slate-900 text-white shadow-md sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="index.php" class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-slate-950 font-black text-xl shadow-inner">
                    ভ
                </a>
                <div>
                    <h1 class="text-lg font-bold tracking-tight flex items-center gap-2">
                        <span>VIP Restricted Zones & Flyovers</span>
                        <span class="text-[10px] bg-orange-500 text-white font-bold px-2 py-0.5 rounded-full uppercase">Admin Tool</span>
                    </h1>
                    <p class="text-xs text-slate-400">Draw VIP road bans & flyover restrictions on Dhaka's map</p>
                </div>
            </div>
            <a href="index.php" class="inline-flex items-center gap-1.5 text-xs font-semibold bg-slate-800 hover:bg-slate-700 px-3 py-2 rounded-lg border border-slate-700 transition">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to Fare Estimator</span>
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- LEFT COLUMN: Draw & Save Restricted Zone Form -->
            <div class="lg:col-span-5 space-y-4">
                
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2">
                            <span>✏️ Add New Restricted Zone</span>
                        </h2>
                        <span id="drawStatusBadge" class="text-[11px] bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-md font-medium">
                            Use Polygon Tool on Map
                        </span>
                    </div>

                    <form id="createZoneForm" onsubmit="handleSaveZone(event)" class="space-y-3.5 text-xs">
                        
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Zone Name *</label>
                            <input type="text" id="zoneName" required placeholder="e.g. Bijoy Sarani VIP Intersection, Kuril Flyover"
                                class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-amber-500 focus:border-amber-500" />
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">City</label>
                                <input type="text" id="zoneCity" value="Dhaka" class="w-full text-xs px-3 py-2 rounded-xl border border-slate-300 bg-slate-50" readonly />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Restriction Type</label>
                                <select id="restrictionType" class="w-full text-xs px-3 py-2 rounded-xl border border-slate-300">
                                    <option value="vip_road_ban">VIP Road Ban</option>
                                    <option value="flyover_ban">Flyover Deck Ban</option>
                                    <option value="time_based_ban">Time-Based Ban</option>
                                    <option value="one_way">One-Way Street</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Restricted Vehicles *</label>
                            <div class="grid grid-cols-2 gap-2 p-2.5 bg-slate-50 rounded-xl border border-slate-200">
                                <label class="flex items-center gap-2 cursor-pointer font-medium text-slate-700">
                                    <input type="checkbox" name="restricted_vehicles" value="pedal_rickshaw" checked class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4" />
                                    <span>Pedal Rickshaw</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer font-medium text-slate-700">
                                    <input type="checkbox" name="restricted_vehicles" value="battery_rickshaw" checked class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4" />
                                    <span>Battery Auto</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer font-medium text-slate-700">
                                    <input type="checkbox" name="restricted_vehicles" value="cng" class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4" />
                                    <span>CNG Auto</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer font-medium text-slate-700">
                                    <input type="checkbox" name="restricted_vehicles" value="leguna" class="rounded text-amber-600 focus:ring-amber-500 w-4 h-4" />
                                    <span>Leguna</span>
                                </label>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Active Start Time</label>
                                <input type="time" id="startTime" value="07:00" class="w-full text-xs px-3 py-2 rounded-xl border border-slate-300" />
                            </div>
                            <div>
                                <label class="block font-bold text-slate-700 mb-1">Active End Time</label>
                                <input type="time" id="endTime" value="22:00" class="w-full text-xs px-3 py-2 rounded-xl border border-slate-300" />
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Commuter Transfer / Detour Hint</label>
                            <input type="text" id="transferHint" placeholder="e.g. Rickshaws banned. Take MRT Line 6 or surface bypass."
                                class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-amber-500" />
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1 flex items-center justify-between">
                                <span>Polygon GeoJSON (Auto-Filled from Map Draw)</span>
                                <span id="polyCoordsCount" class="text-[10px] text-slate-400">0 points</span>
                            </label>
                            <textarea id="geojsonInput" required rows="4" readonly placeholder="Draw a polygon on the map to extract coordinates..."
                                class="w-full text-[11px] font-mono px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:outline-none"></textarea>
                        </div>

                        <button type="submit" id="saveZoneBtn" disabled
                            class="w-full py-3 px-4 rounded-xl bg-orange-600 hover:bg-orange-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-bold text-xs tracking-wide shadow-md transition flex items-center justify-center gap-2">
                            <span>Save & Activate Restricted Zone</span>
                        </button>
                    </form>
                </div>

            </div>

            <!-- RIGHT COLUMN: Map with Leaflet.Draw + Existing Zones Table -->
            <div class="lg:col-span-7 space-y-6">
                
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-orange-500 animate-ping"></span>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700">Interactive VIP Road Drawing Canvas</h2>
                        </div>
                        <span class="text-xs text-slate-500">Click the polygon button in the toolbar to trace a road segment</span>
                    </div>

                    <!-- Map Container -->
                    <div id="adminMap"></div>
                </div>

                <!-- Existing Zones Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Active Restricted Zones in Dhaka</h3>
                            <p class="text-[11px] text-slate-500">Official DMP/DTCA rickshaw-free arterial corridors and custom zones</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button onclick="restoreOfficialBans()" class="px-2.5 py-1 text-xs rounded-lg font-semibold bg-emerald-50 text-emerald-700 border border-emerald-300 hover:bg-emerald-100 transition shadow-sm" title="Restore the 4 authentic DMP VIP corridors">
                                ↺ Restore DMP Corridors
                            </button>
                            <button onclick="deactivateAllZones()" class="px-2.5 py-1 text-xs rounded-lg font-semibold bg-amber-50 text-amber-700 border border-amber-300 hover:bg-amber-100 transition shadow-sm" title="Deactivate all zones">
                                ⏸ Deactivate All
                            </button>
                            <button onclick="deleteInactiveZones()" class="px-2.5 py-1 text-xs rounded-lg font-semibold bg-rose-50 text-rose-700 border border-rose-300 hover:bg-rose-100 transition shadow-sm" title="Permanently delete all inactive ban entries">
                                ✕ Delete Inactive
                            </button>
                            <button onclick="loadZonesTable()" class="text-xs text-amber-700 hover:underline font-semibold ml-1">Refresh</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-3">Zone Name</th>
                                    <th class="px-4 py-3">Vehicles Banned</th>
                                    <th class="px-4 py-3">Hours</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="zonesTableBody" class="divide-y divide-slate-100 text-slate-700">
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-slate-400">Loading zones...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <script>
        let adminMap = null;
        let drawnItems = null;
        let existingZonesLayer = null;

        document.addEventListener('DOMContentLoaded', () => {
            initAdminMap();
            loadZonesTable();
        });

        function initAdminMap() {
            // Center of Dhaka (Rokeya Sarani / Agargaon area)
            adminMap = L.map('adminMap').setView([23.7710, 90.3850], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap | BharaKoto Admin'
            }).addTo(adminMap);

            existingZonesLayer = L.layerGroup().addTo(adminMap);
            drawnItems = new L.FeatureGroup().addTo(adminMap);

            // Configure Leaflet.draw control
            const drawControl = new L.Control.Draw({
                position: 'topright',
                draw: {
                    polygon: {
                        allowIntersection: false,
                        showArea: true,
                        shapeOptions: {
                            color: '#ea580c',
                            fillColor: '#f97316',
                            fillOpacity: 0.5,
                            weight: 3
                        }
                    },
                    polyline: false,
                    rectangle: {
                        shapeOptions: {
                            color: '#ea580c',
                            fillColor: '#f97316',
                            fillOpacity: 0.5
                        }
                    },
                    circle: false,
                    circlemarker: false,
                    marker: false
                },
                edit: {
                    featureGroup: drawnItems
                }
            });
            adminMap.addControl(drawControl);

            // Event: When a new polygon is created
            adminMap.on(L.Draw.Event.CREATED, (e) => {
                const layer = e.layer;
                drawnItems.clearLayers();
                drawnItems.addLayer(layer);

                // Convert layer to GeoJSON geometry
                const geojson = layer.toGeoJSON();
                let geometry = geojson.geometry;

                // If rectangle was drawn, it is a Polygon
                if (geometry.type === 'Polygon') {
                    const coords = geometry.coordinates[0];
                    document.getElementById('geojsonInput').value = JSON.stringify(geometry, null, 2);
                    document.getElementById('polyCoordsCount').textContent = `${coords.length} coordinate points`;
                    document.getElementById('drawStatusBadge').textContent = 'Polygon Captured!';
                    document.getElementById('drawStatusBadge').className = 'text-[11px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-md font-bold';
                    document.getElementById('saveZoneBtn').disabled = false;
                }
            });

            // Event: When an edited polygon is saved
            adminMap.on(L.Draw.Event.EDITED, () => {
                const layers = drawnItems.getLayers();
                if (layers.length > 0) {
                    const geometry = layers[0].toGeoJSON().geometry;
                    document.getElementById('geojsonInput').value = JSON.stringify(geometry, null, 2);
                }
            });
        }

        // Load Zones for Map and Table
        async function loadZonesTable() {
            try {
                const res = await fetch('api/zones.php?all=1');
                const data = await res.json();
                if (data.status === 'success' && Array.isArray(data.data)) {
                    renderZonesOnMap(data.data);
                    renderZonesTable(data.data);
                }
            } catch (err) {
                console.error(err);
            }
        }

        // Render Existing Polygons as Road Corridors
        function renderZonesOnMap(zones) {
            existingZonesLayer.clearLayers();
            zones.forEach(zone => {
                if (zone.polygon_geojson && parseInt(zone.is_active) === 1) {
                    const poly = L.geoJSON(zone.polygon_geojson, {
                        style: {
                            color: '#b91c1c',
                            weight: 2.5,
                            fillColor: '#ef4444',
                            fillOpacity: 0.45
                        }
                    });
                    poly.bindTooltip(`🚫 <b>${escapeHtml(zone.zone_name)}</b>`, { sticky: true });
                    poly.bindPopup(`
                        <div class="text-xs space-y-1.5 p-1 min-w-[200px]">
                            <div class="font-bold text-red-700 flex items-center justify-between">
                                <span>🚫 ${escapeHtml(zone.zone_name)}</span>
                                <span class="text-[10px] bg-red-100 text-red-800 px-1.5 py-0.2 rounded font-bold">Active</span>
                            </div>
                            <div><b>Banned:</b> ${escapeHtml(zone.restricted_vehicles.replace(/,/g, ', '))}</div>
                            ${zone.active_start_time ? `<div><b>Hours:</b> ${zone.active_start_time} - ${zone.active_end_time}</div>` : ''}
                            ${zone.transfer_hint ? `<div class="bg-red-50 text-red-800 p-1.5 rounded text-[11px] mt-1">${escapeHtml(zone.transfer_hint)}</div>` : ''}
                            <div class="flex items-center gap-2 pt-1.5 border-t border-slate-100 mt-2">
                                <button onclick="toggleZoneStatus(${zone.id})" class="px-2 py-1 text-[11px] rounded font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 transition cursor-pointer">
                                    Deactivate
                                </button>
                                <button onclick="deleteZoneEntry(${zone.id}, '${escapeHtml(zone.zone_name)}')" class="px-2 py-1 text-[11px] rounded font-semibold bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 transition flex items-center gap-1 cursor-pointer">
                                    <svg class="w-3 h-3 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    <span>Delete</span>
                                </button>
                            </div>
                        </div>
                    `);
                    existingZonesLayer.addLayer(poly);
                }
            });
        }

        // Render Table Body
        function renderZonesTable(zones) {
            const tbody = document.getElementById('zonesTableBody');
            if (zones.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-slate-400">No restricted zones recorded yet.</td></tr>';
                return;
            }

            tbody.innerHTML = '';
            zones.forEach(z => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50 transition';
                const isActive = parseInt(z.is_active) === 1;

                tr.innerHTML = `
                    <td class="px-4 py-3">
                        <div class="font-bold text-slate-900">${escapeHtml(z.zone_name)}</div>
                        <div class="text-[10px] text-slate-400">${escapeHtml(z.city)} • ${escapeHtml(z.restriction_type)}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-block bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-medium">
                            ${escapeHtml(z.restricted_vehicles.replace(/,/g, ', '))}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500 text-[11px]">
                        ${z.active_start_time ? `${z.active_start_time} - ${z.active_end_time}` : '24 Hours'}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold ${isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'}">
                            ${isActive ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <button onclick="toggleZoneStatus(${z.id})" class="px-2.5 py-1 text-[11px] rounded font-semibold border ${isActive ? 'border-amber-300 text-amber-700 hover:bg-amber-50' : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50'} transition cursor-pointer">
                                ${isActive ? 'Deactivate' : 'Activate'}
                            </button>
                            <button onclick="deleteZoneEntry(${z.id}, '${escapeHtml(z.zone_name)}')" class="px-2 py-1 text-[11px] rounded font-semibold bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 transition flex items-center gap-1 cursor-pointer shadow-2xs" title="Permanently delete this ban entry">
                                <svg class="w-3 h-3 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                <span>Delete</span>
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // Save New Zone
        async function handleSaveZone(e) {
            e.preventDefault();
            const btn = document.getElementById('saveZoneBtn');
            btn.disabled = true;
            btn.textContent = 'Saving Zone...';

            const checkedVehicles = [];
            document.querySelectorAll('input[name="restricted_vehicles"]:checked').forEach(cb => {
                checkedVehicles.push(cb.value);
            });

            if (checkedVehicles.length === 0) {
                alert('Please select at least one vehicle.');
                btn.disabled = false;
                btn.textContent = 'Save & Activate Restricted Zone';
                return;
            }

            const rawGeoJson = document.getElementById('geojsonInput').value;
            let parsedGeoJson = null;
            try {
                parsedGeoJson = JSON.parse(rawGeoJson);
            } catch (err) {
                alert('Invalid GeoJSON coordinates.');
                btn.disabled = false;
                btn.textContent = 'Save & Activate Restricted Zone';
                return;
            }

            const payload = {
                zone_name: document.getElementById('zoneName').value,
                city: document.getElementById('zoneCity').value,
                restriction_type: document.getElementById('restrictionType').value,
                restricted_vehicles: checkedVehicles,
                active_start_time: document.getElementById('startTime').value || null,
                active_end_time: document.getElementById('endTime').value || null,
                transfer_hint: document.getElementById('transferHint').value,
                polygon_geojson: parsedGeoJson
            };

            try {
                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'success') {
                    alert('Restricted Zone successfully created and activated on Dhaka map!');
                    document.getElementById('createZoneForm').reset();
                    document.getElementById('geojsonInput').value = '';
                    drawnItems.clearLayers();
                    loadZonesTable();
                } else {
                    alert('Error: ' + json.message);
                }
            } catch (err) {
                alert('Failed to save: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save & Activate Restricted Zone';
            }
        }

        // Toggle Zone Active/Inactive
        async function toggleZoneStatus(id) {
            try {
                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle', id: id })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    loadZonesTable();
                }
            } catch (err) {
                console.error(err);
            }
        }

        // Deactivate all zones
        async function deactivateAllZones() {
            if (!confirm('Are you sure you want to clear/deactivate all restricted zones? Commuters will not see any banned roads.')) {
                return;
            }
            try {
                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deactivate_all' })
                });
                const json = await res.json();
                alert(json.message);
                loadZonesTable();
            } catch (err) {
                alert('Failed to clear zones: ' + err.message);
            }
        }

        // Restore official DMP corridors (server-side)
        async function restoreOfficialBans() {
            try {
                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ action: 'restore_official' })
                });
                const json = await res.json();
                alert(json.message);
                loadZonesTable();
            } catch (err) {
                alert('Failed to restore official bans: ' + err.message);
            }
        }

        // Load precision official DMP bans with OSRM road-following geometries
        async function loadPrecisionOfficialBans() {
            const btn = event?.target;
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Loading OSRM Geometries...';
            }

            try {
                // Define official DMP corridors with waypoints for OSRM
                const corridors = [
                    {
                        name: 'Mirpur Road Corridor (Gabtoli to Azimpur)',
                        type: 'vip_road_ban',
                        vehicles: 'pedal_rickshaw,battery_rickshaw',
                        start_time: '07:00:00',
                        end_time: '22:00:00',
                        hint: 'Official DTCA/DMP ban: Rickshaws prohibited on Mirpur Road main arterial carriageway. Take Dhanmondi residential inner avenues or bus.',
                        waypoints: [
                            [90.3420, 23.7850], // Gabtoli
                            [90.3662, 23.7719], // Shyamoli
                            [90.3725, 23.7601], // Asad Gate
                            [90.3815, 23.7482], // Kalabagan
                            [90.3846, 23.7401], // Science Lab
                            [90.3855, 23.7289]  // Azimpur
                        ]
                    },
                    {
                        name: 'VIP Road / Kazi Nazrul Islam Ave (Shahbagh to Mohakhali)',
                        type: 'vip_road_ban',
                        vehicles: 'pedal_rickshaw,battery_rickshaw',
                        start_time: '07:00:00',
                        end_time: '22:00:00',
                        hint: 'Official DMP VIP arterial: Rickshaws strictly prohibited from Shahbagh to Mohakhali. Use MRT Line 6, bus, or Hatirjheel / Green Road bypass.',
                        waypoints: [
                            [90.3958, 23.7381], // Shahbagh
                            [90.3945, 23.7460], // Bangla Motor
                            [90.3934, 23.7505], // Karwan Bazar
                            [90.3899, 23.7574], // Farmgate
                            [90.3921, 23.7699], // Jahangir Gate
                            [90.4043, 23.7776]  // Mohakhali
                        ]
                    },
                    {
                        name: 'Elephant Road (Science Lab to Shahbagh)',
                        type: 'vip_road_ban',
                        vehicles: 'pedal_rickshaw,battery_rickshaw',
                        start_time: '07:00:00',
                        end_time: '22:00:00',
                        hint: 'Official DTCA/DMP ban: Rickshaws prohibited on Elephant Road thoroughfare. Use Katabon/Hatirpool side lanes.',
                        waypoints: [
                            [90.3846, 23.7401], // Science Lab
                            [90.3892, 23.7408], // Katabon
                            [90.3958, 23.7381]  // Shahbagh
                        ]
                    },
                    {
                        name: 'Moghbazar-Mouchak Flyover (Upper Deck)',
                        type: 'flyover_ban',
                        vehicles: 'pedal_rickshaw,battery_rickshaw',
                        start_time: null,
                        end_time: null,
                        hint: 'High Court & DMP order: Rickshaws strictly prohibited on the elevated flyover deck. Must use ground surface road.',
                        waypoints: [
                            [90.4045, 23.7481], // Moghbazar Wireless
                            [90.4132, 23.7485]  // Mouchak
                        ]
                    }
                ];

                // Fetch OSRM route for each corridor
                const corridorData = [];
                for (const corridor of corridors) {
                    if (btn) {
                        btn.textContent = `Loading ${corridor.name}...`;
                    }

                    const coords = await fetchOsrmRoute(corridor.waypoints);
                    if (coords.length >= 2) {
                        const polygon = bufferPolylineToPolygon(coords, 0.00018);
                        corridorData.push({
                            ...corridor,
                            polygon_geojson: polygon
                        });
                    }
                }

                // Send all corridors to server
                if (btn) {
                    btn.textContent = 'Saving to Database...';
                }

                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ action: 'create_multiple', corridors: corridorData })
                });
                const json = await res.json();

                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Load Precision DMP Bans';
                }

                if (json.status === 'success') {
                    alert(`Successfully loaded ${corridorData.length} precision DMP corridors with road-following geometries!`);
                    loadZonesTable();
                } else {
                    alert('Error: ' + json.message);
                }
            } catch (err) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Load Precision DMP Bans';
                }
                alert('Failed to load precision bans: ' + err.message);
            }
        }

        // Fetch OSRM route for waypoints
        async function fetchOsrmRoute(waypoints) {
            const coordStrings = waypoints.map(wp => `${wp[0]},${wp[1]}`).join(';');
            const url = `https://router.project-osrm.org/route/v1/driving/${coordStrings}?overview=full&geometries=geojson`;

            const response = await fetch(url);
            if (!response.ok) return [];

            const data = await response.json();
            return data.routes?.[0]?.geometry?.coordinates ?? [];
        }

        // Buffer polyline to polygon (corridor)
        function bufferPolylineToPolygon(coords, halfWidth = 0.00018) {
            const n = coords.length;
            if (n < 2) return { type: 'Polygon', coordinates: [[]] };

            const left = [];
            const right = [];

            for (let i = 0; i < n; i++) {
                let dx, dy;
                if (i === 0) {
                    dx = coords[1][0] - coords[0][0];
                    dy = coords[1][1] - coords[0][1];
                } else if (i === n - 1) {
                    dx = coords[n-1][0] - coords[n-2][0];
                    dy = coords[n-1][1] - coords[n-2][1];
                } else {
                    dx = coords[i+1][0] - coords[i-1][0];
                    dy = coords[i+1][1] - coords[i-1][1];
                }

                const len = Math.sqrt(dx * dx + dy * dy);
                let nx, ny;
                if (len < 1e-9) {
                    nx = 0; ny = halfWidth;
                } else {
                    nx = -dy / len;
                    ny = dx / len;
                }

                left.push([
                    Math.round((coords[i][0] + nx * halfWidth) * 1e6) / 1e6,
                    Math.round((coords[i][1] + ny * halfWidth) * 1e6) / 1e6
                ]);
                right.push([
                    Math.round((coords[i][0] - nx * halfWidth) * 1e6) / 1e6,
                    Math.round((coords[i][1] - ny * halfWidth) * 1e6) / 1e6
                ]);
            }

            const ring = [...left, ...right.reverse()];
            ring.push(ring[0]); // Close ring

            return {
                type: 'Polygon',
                coordinates: [ring]
            };
        }

        // Delete a specific restricted zone entry permanently
        async function deleteZoneEntry(id, name) {
            const promptName = name ? ` "${name}"` : ` entry #${id}`;
            if (!confirm(`Are you sure you want to permanently delete restricted zone${promptName}? This will remove it from Dhaka's database and map.`)) {
                return;
            }
            try {
                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id: id })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    loadZonesTable();
                } else {
                    alert('Error: ' + json.message);
                }
            } catch (err) {
                alert('Failed to delete zone: ' + err.message);
            }
        }

        // Delete all inactive ban entries
        async function deleteInactiveZones() {
            if (!confirm('Are you sure you want to permanently delete all inactive / disabled ban entries from the database?')) {
                return;
            }
            try {
                const res = await fetch('api/zones.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_inactive' })
                });
                const json = await res.json();
                alert(json.message);
                loadZonesTable();
            } catch (err) {
                alert('Failed to delete inactive zones: ' + err.message);
            }
        }

        // HTML escaping helper
        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }
    </script>
</body>
</html>
