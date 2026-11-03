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
    <title>BharaKoto? (ভাড়া কত?) - BD CNG & Rickshaw Fare and Route Estimator</title>
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
                            100: '#d1fae5',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                            800: '#065f46',
                            900: '#064e3b',
                        },
                        bdred: {
                            500: '#ef4444',
                            600: '#dc2626',
                        },
                        cngyellow: {
                            400: '#facc15',
                            500: '#eab308',
                            600: '#ca8a04',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Chart.js for Fare History Trends -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        #map { min-height: 480px; height: 100%; width: 100%; z-index: 10; }
        .custom-popup .leaflet-popup-content-wrapper {
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        /* Custom scrollbars */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased">

    <!-- Top Navigation Bar -->
    <header class="bg-bdgreen-800 text-white shadow-md sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-cngyellow-400 to-amber-500 flex items-center justify-center shadow-inner text-slate-900 font-extrabold text-xl">
                    ভ
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight flex items-center gap-2">
                        <span>BharaKoto?</span>
                        <span class="text-xs bg-cngyellow-400 text-slate-900 font-semibold px-2 py-0.5 rounded-full uppercase tracking-wider">Dhaka Edition</span>
                    </h1>
                    <p class="text-xs text-bdgreen-100">CNG & Rickshaw Fare Estimator with VIP Road Avoidance</p>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <a href="admin-zones.php" class="inline-flex items-center gap-1.5 text-xs font-semibold bg-bdgreen-700 hover:bg-bdgreen-600 px-3 py-2 rounded-lg border border-bdgreen-500 transition shadow-sm">
                    <svg class="w-4 h-4 text-cngyellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                    </svg>
                    <span>VIP Road Admin</span>
                </a>
                <button onclick="openModal()" class="inline-flex items-center gap-1.5 text-xs font-semibold bg-cngyellow-500 hover:bg-cngyellow-400 text-slate-950 px-3 py-2 rounded-lg transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Log Paid Fare</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Two-Column Layout -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- LEFT COLUMN: Fare Calculator Controls & Cards -->
            <div class="lg:col-span-5 space-y-4">
                


                <!-- Input Form Panel -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 space-y-4">
                    
                    <!-- Origin Input -->
                    <div class="relative">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1 flex items-center justify-between">
                            <span class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 ring-2 ring-emerald-200"></span>
                                Origin (কোথা থেকে)
                            </span>
                            <div class="flex items-center gap-1.5">
                                <span id="originCoordsBadge" class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md font-medium">Please select origin</span>
                                <button type="button" id="clearOriginBtn" onclick="clearOrigin()" class="hidden text-[10px] text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-1.5 py-0.5 rounded font-bold transition flex items-center gap-0.5" title="Clear Origin from map & input">
                                    &times; Clear
                                </button>
                            </div>
                        </label>
                        <div class="flex gap-1.5">
                            <div class="relative flex-1">
                                <input type="text" id="originInput" placeholder="Type or click map to select origin..." 
                                    class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-bdgreen-500 focus:border-bdgreen-500 transition shadow-sm bg-slate-50 focus:bg-white pr-8"
                                    autocomplete="off" />
                                <button type="button" id="originInputClearX" onclick="clearOrigin()" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-600 text-sm font-bold w-5 h-5 flex items-center justify-center rounded-full hover:bg-slate-100" title="Clear Origin">
                                    &times;
                                </button>
                                <div id="originSuggestions" class="absolute left-0 right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-200 z-50 max-h-64 overflow-y-auto hidden"></div>
                            </div>
                            <button type="button" onclick="useCurrentGeolocation()" title="Use My Current GPS Location"
                                class="px-3 py-2.5 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-600 rounded-xl border border-slate-300 transition flex items-center justify-center gap-1">
                                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>
                            </button>
                            <button type="button" onclick="activateMapPicker('origin')" title="Click on map to set Origin"
                                class="px-3 py-2.5 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-600 rounded-xl border border-slate-300 transition flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </button>
                        </div>
                        <input type="hidden" id="originLat" value="" />
                        <input type="hidden" id="originLng" value="" />

                    </div>

                    <!-- Swap Directions & Clear Row -->
                    <div class="flex items-center justify-between px-1 py-0.5 text-xs">
                        <button type="button" onclick="swapOriginAndDest()" class="text-slate-600 hover:text-emerald-700 font-semibold flex items-center gap-1.5 hover:bg-slate-100 px-2 py-1 rounded-lg transition" title="Swap Origin and Destination">
                            <span class="text-sm">⇅</span>
                            <span>Swap Route Directions</span>
                        </button>
                        <button type="button" id="clearAllPointsBtn" onclick="clearAllRoutePoints()" class="hidden text-rose-600 hover:text-rose-800 font-semibold flex items-center gap-1 hover:bg-rose-50 px-2 py-1 rounded-lg transition" title="Clear both Origin and Destination from map">
                            <span>✕ Clear Both from Map</span>
                        </button>
                    </div>

                    <!-- Destination Input -->
                    <div class="relative">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1 flex items-center justify-between">
                            <span class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-bdred-500 ring-2 ring-red-200"></span>
                                Destination (কোথায় যাবেন)
                            </span>
                            <div class="flex items-center gap-1.5">
                                <span id="destCoordsBadge" class="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md font-medium">Please select destination</span>
                                <button type="button" id="clearDestBtn" onclick="clearDest()" class="hidden text-[10px] text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-1.5 py-0.5 rounded font-bold transition flex items-center gap-0.5" title="Clear Destination from map & input">
                                    &times; Clear
                                </button>
                            </div>
                        </label>
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <input type="text" id="destInput" placeholder="Type or click map to select destination..." 
                                    class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-bdgreen-500 focus:border-bdgreen-500 transition shadow-sm bg-slate-50 focus:bg-white pr-8"
                                    autocomplete="off" />
                                <button type="button" id="destInputClearX" onclick="clearDest()" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-600 text-sm font-bold w-5 h-5 flex items-center justify-center rounded-full hover:bg-slate-100" title="Clear Destination">
                                    &times;
                                </button>
                                <div id="destSuggestions" class="absolute left-0 right-0 top-full mt-1 bg-white rounded-xl shadow-xl border border-slate-200 z-50 max-h-56 overflow-y-auto hidden"></div>
                            </div>
                            <button type="button" onclick="activateMapPicker('dest')" title="Click on map to set Destination"
                                class="px-3 py-2.5 bg-slate-100 hover:bg-red-50 hover:text-red-700 text-slate-600 rounded-xl border border-slate-300 transition flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </button>
                        </div>
                        <input type="hidden" id="destLat" value="" />
                        <input type="hidden" id="destLng" value="" />
                    </div>

                    <!-- Vehicle Type Selector Pills -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">
                            Vehicle Type (যানবাহন)
                        </label>
                        <div class="grid grid-cols-2 gap-2" id="vehicleSelector">
                            <!-- Pedal Rickshaw -->
                            <button type="button" onclick="selectVehicle('pedal_rickshaw')" data-vehicle="pedal_rickshaw"
                                class="vehicle-btn active flex items-center gap-2 p-3 rounded-xl border-2 border-bdgreen-600 bg-bdgreen-50 text-bdgreen-900 transition text-left">
                                <span class="text-2xl">🚲</span>
                                <div>
                                    <div class="text-xs font-bold leading-tight">Pedal Rickshaw</div>
                                    <div class="text-[11px] text-slate-500 font-medium">প্যাডেল রিকশা</div>
                                </div>
                            </button>
                            <!-- Battery Rickshaw -->
                            <button type="button" onclick="selectVehicle('battery_rickshaw')" data-vehicle="battery_rickshaw"
                                class="vehicle-btn flex items-center gap-2 p-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 transition text-left">
                                <span class="text-2xl">⚡</span>
                                <div>
                                    <div class="text-xs font-bold leading-tight">Battery Auto</div>
                                    <div class="text-[11px] text-slate-500 font-medium">ব্যাটারি রিকশা</div>
                                </div>
                            </button>
                            <!-- CNG Auto Rickshaw -->
                            <button type="button" onclick="selectVehicle('cng')" data-vehicle="cng"
                                class="vehicle-btn flex items-center gap-2 p-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 transition text-left">
                                <span class="text-2xl">🛺</span>
                                <div>
                                    <div class="text-xs font-bold leading-tight">CNG Auto</div>
                                    <div class="text-[11px] text-slate-500 font-medium">সিএনজি মিটার</div>
                                </div>
                            </button>
                            <!-- Leguna -->
                            <button type="button" onclick="selectVehicle('leguna')" data-vehicle="leguna"
                                class="vehicle-btn flex items-center gap-2 p-3 rounded-xl border-2 border-slate-200 bg-white hover:bg-slate-50 text-slate-700 transition text-left">
                                <span class="text-2xl">🚐</span>
                                <div>
                                    <div class="text-xs font-bold leading-tight">Leguna</div>
                                    <div class="text-[11px] text-slate-500 font-medium">হিউম্যান হলার</div>
                                </div>
                            </button>
                        </div>
                        <input type="hidden" id="selectedVehicle" value="pedal_rickshaw" />
                    </div>

                    <!-- Condition Overrides (Dhaka Jam & Monsoon) -->
                    <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="peakHourToggle" onchange="calculateFareEstimate(false)" class="rounded text-bdgreen-600 focus:ring-bdgreen-500 w-4 h-4 cursor-pointer" />
                            <span class="font-medium text-slate-700">Peak Hour (পিক আওয়ার +২০%)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="rainToggle" onchange="calculateFareEstimate(false)" class="rounded text-bdgreen-600 focus:ring-bdgreen-500 w-4 h-4 cursor-pointer" />
                            <span class="font-medium text-slate-700">Rain/বৃষ্টি (+৫০%)</span>
                        </label>
                    </div>

                    <!-- Estimate Action Button -->
                    <button type="button" id="estimateBtn" onclick="calculateFareEstimate(true)"
                        class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-bdgreen-600 to-bdgreen-700 hover:from-bdgreen-700 hover:to-bdgreen-800 text-white font-bold text-sm tracking-wide shadow-md shadow-bdgreen-700/20 active:scale-[0.99] transition flex items-center justify-center gap-2">
                        <svg id="btnSpinner" class="w-5 h-5 animate-spin hidden" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span id="btnText">Estimate Fare & Route (ভাড়া হিসাব করুন)</span>
                    </button>
                </div>

                <!-- RESULTS CARD (Dynamically rendered) -->
                <div id="resultsCard" class="hidden space-y-4">
                    
                    <!-- VIP Road Ban Warning Alert (shown when route intersects restricted zone) -->
                    <div id="vipWarningAlert" class="hidden bg-amber-50 border-l-4 border-amber-500 p-4 rounded-r-xl shadow-sm">
                        <div class="flex items-start">
                            <div class="flex-shrink-0 text-amber-500 text-lg">⚠️</div>
                            <div class="ml-3">
                                <h3 class="text-xs font-bold text-amber-800 uppercase tracking-wider" id="warningTitle">VIP Road Restriction Alert</h3>
                                <p class="text-xs text-amber-700 mt-0.5" id="warningBody">Rickshaws are prohibited on Rokeya Sarani VIP stretch.</p>
                                <div class="mt-2 text-xs font-medium text-amber-900 bg-amber-100/70 p-2 rounded-lg" id="transferHint">
                                    💡 Commuter Tip: Switch to MRT Line 6 at Agargaon or take alternate residential bypass.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Primary Fare Breakdown Card -->
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Estimated Trip Details</span>
                                <div class="text-sm font-semibold text-slate-800" id="routeSummaryText">2.8 km • ~18 mins</div>
                            </div>
                            <div class="flex items-center gap-1.5" id="surgeBadges">
                                <!-- Peak / Rain badges -->
                            </div>
                        </div>

                        <!-- Street / Mama Rate Highlight -->
                        <div class="my-4 bg-gradient-to-br from-bdgreen-50 to-emerald-100/50 p-4 rounded-xl border border-bdgreen-200/80 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold uppercase tracking-wider text-bdgreen-800 flex items-center gap-1">
                                    <span>Real Street / "Mama" Fare</span>
                                    <span class="bg-bdgreen-600 text-white text-[10px] px-1.5 py-0.2 rounded-full font-bold">5-Tk Rounding</span>
                                </span>
                                <p class="text-xs text-slate-600 mt-0.5">What the driver will typically demand</p>
                            </div>
                            <div class="text-right">
                                <div class="text-3xl font-black text-bdgreen-900" id="streetFareDisplay">৳ 85</div>
                                <span class="text-[11px] font-semibold text-bdgreen-700">Fair Dhaka Price</span>
                            </div>
                        </div>

                        <!-- Bargaining Range -->
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200">
                                <span class="text-[11px] font-semibold text-slate-500 block">Bargaining Window (দরদাম)</span>
                                <div class="text-base font-bold text-slate-800 mt-0.5" id="bargainRangeDisplay">৳ 80 - ৳ 100</div>
                                <span class="text-[10px] text-slate-400">Aim for the lower end</span>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200">
                                <span class="text-[11px] font-semibold text-slate-500 block">Govt/Meter Rate (অফিসিয়াল)</span>
                                <div class="text-base font-bold text-slate-800 mt-0.5" id="officialFareDisplay">৳ 67.00</div>
                                <span class="text-[10px] text-slate-400">BRTA legal chart</span>
                            </div>
                        </div>

                        <!-- Crowdsourced Community Badge -->
                        <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-lg">👥</span>
                                <div>
                                    <div class="text-xs font-bold text-amber-900" id="crowdAvgTitle">Community Average: ৳ 90</div>
                                    <div class="text-[11px] text-amber-700" id="crowdSubmissionsCount">Based on 3 recent commuter logs</div>
                                </div>
                            </div>
                            <button onclick="openModal()" class="text-xs font-semibold text-amber-900 bg-amber-200 hover:bg-amber-300 px-2.5 py-1 rounded-lg transition">
                                Log Yours
                            </button>
                        </div>

                        <!-- Fare History Panel -->
                        <div id="fareHistoryPanel" class="hidden bg-white rounded-2xl p-4 shadow-sm border border-slate-200 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500 flex items-center gap-1">
                                    <span class="text-lg">📈</span>
                                    <span>Fare History & Trends</span>
                                </span>
                                <select id="historyTimeRange" onchange="loadFareHistory()" class="text-xs px-2 py-1 rounded-lg border border-slate-300 bg-white">
                                    <option value="30">Last 30 days</option>
                                    <option value="90" selected>Last 90 days</option>
                                    <option value="180">Last 6 months</option>
                                    <option value="365">Last year</option>
                                </select>
                            </div>

                            <!-- Summary Stats Row -->
                            <div id="historySummary" class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center hidden">
                                <div class="bg-emerald-50 p-2 rounded-xl border border-emerald-200">
                                    <div class="text-2xl font-black text-emerald-800" id="histAvgFare">৳ 0</div>
                                    <div class="text-[10px] text-emerald-700">Avg Fare</div>
                                </div>
                                <div class="bg-blue-50 p-2 rounded-xl border border-blue-200">
                                    <div class="text-2xl font-black text-blue-800" id="histMinFare">৳ 0</div>
                                    <div class="text-[10px] text-blue-700">Lowest</div>
                                </div>
                                <div class="bg-rose-50 p-2 rounded-xl border border-rose-200">
                                    <div class="text-2xl font-black text-rose-800" id="histMaxFare">৳ 0</div>
                                    <div class="text-[10px] text-rose-700">Highest</div>
                                </div>
                                <div class="bg-amber-50 p-2 rounded-xl border border-amber-200">
                                    <div class="text-2xl font-black text-amber-800" id="histCount">0</div>
                                    <div class="text-[10px] text-amber-700">Records</div>
                                </div>
                            </div>

                            <!-- Trend Chart Canvas -->
                            <div class="relative h-40">
                                <canvas id="fareTrendChart"></canvas>
                            </div>

                            <!-- Distribution Bars -->
                            <div id="fareDistribution" class="space-y-1 hidden">
                                <div class="text-[11px] font-semibold text-slate-600">Fare Distribution (৳ 10 buckets)</div>
                                <div id="distBars" class="space-y-1"></div>
                            </div>

                            <!-- Recent Records Table -->
                            <div id="historyRecords" class="hidden">
                                <div class="text-[11px] font-semibold text-slate-600 mb-2">Recent Commuter Submissions</div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-[11px]">
                                        <thead>
                                            <tr class="bg-slate-50 text-slate-600 font-bold">
                                                <th class="px-2 py-1.5 text-left">Date</th>
                                                <th class="px-2 py-1.5 text-right">Fare</th>
                                                <th class="px-2 py-1.5 text-center">Conditions</th>
                                                <th class="px-2 py-1.5 text-center">Votes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="historyRecordsBody" class="divide-y divide-slate-100"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>


            </div>

            <!-- RIGHT COLUMN: Interactive Leaflet Map -->
            <div class="lg:col-span-7">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden relative">
                    
                    <!-- Map Toolbar Info Overlay -->
                    <div class="absolute top-3 left-3 right-3 z-20 flex flex-wrap items-center justify-between gap-2 pointer-events-none">
                        <label class="bg-white/95 backdrop-blur-sm px-3 py-1.5 rounded-xl shadow-md border border-red-200 text-xs font-semibold text-slate-800 flex items-center gap-2 pointer-events-auto cursor-pointer select-none">
                            <input type="checkbox" id="toggleVipZonesLayer" checked onchange="toggleVipZonesVisibility(this.checked)" class="rounded text-red-600 focus:ring-red-500 w-3.5 h-3.5" />
                            <span class="w-3.5 h-1.5 rounded-full bg-red-600 inline-block shadow-sm"></span>
                            <span class="text-red-950 font-bold">DMP Official Banned Roads</span>
                        </label>
                        <div class="flex items-center gap-2 pointer-events-auto">
                            <div id="mapPickingIndicator" class="hidden bg-emerald-600 text-white px-3 py-1.5 rounded-xl shadow-md text-xs font-bold animate-pulse">
                                Click on Map to select location
                            </div>
                            <button type="button" id="clearMapPointsBtn" onclick="clearAllRoutePoints()" class="hidden bg-white/95 backdrop-blur-sm hover:bg-rose-50 text-rose-700 hover:text-rose-800 px-3 py-1.5 rounded-xl shadow-md border border-rose-300 text-xs font-bold flex items-center gap-1.5 transition cursor-pointer" title="Clear Origin & Destination pins and route line from map">
                                <svg class="w-3.5 h-3.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                <span>Clear Map Points</span>
                            </button>
                        </div>
                    </div>

                    <!-- The Leaflet Map Element -->
                    <div id="map"></div>

                    <!-- Map Footer Legend -->
                    <div class="p-3 bg-slate-50 border-t border-slate-200 text-xs text-slate-600 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-4">
                            <span class="flex items-center gap-1.5">
                                <span class="w-3 h-3 rounded-full bg-emerald-600 inline-block"></span>
                                <span>Origin</span>
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="w-3 h-3 rounded-full bg-bdred-600 inline-block"></span>
                                <span>Destination</span>
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="w-3 h-1 bg-emerald-600 inline-block rounded"></span>
                                <span>Route Line</span>
                            </span>
                        </div>
                        <div class="text-[11px] text-slate-400">
                            Dhaka Urban Routing & Restricted VIP Zone Bypasses
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- "Log What You Paid" Modal -->
    <div id="paidFareModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 relative animate-in fade-in duration-200">
            <button onclick="closeModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="mb-4">
                <div class="w-10 h-10 rounded-xl bg-cngyellow-100 text-cngyellow-700 flex items-center justify-center text-xl font-bold mb-2">
                    ৳
                </div>
                <h2 class="text-lg font-bold text-slate-800">Log What You Paid (ভাড়া জানান)</h2>
                <p class="text-xs text-slate-500 mt-0.5">Help fellow Dhaka commuters negotiate fair fares.</p>
            </div>

            <form id="submitFareForm" onsubmit="handlePaidFareSubmit(event)" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" id="modalCsrfToken" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>" />
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Vehicle Taken</label>
                    <select id="modalVehicle" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300 focus:ring-2 focus:ring-bdgreen-500">
                        <option value="pedal_rickshaw">Pedal Rickshaw (প্যাডেল রিকশা)</option>
                        <option value="battery_rickshaw">Battery Rickshaw (ব্যাটারি রিকশা)</option>
                        <option value="cng">CNG Auto Rickshaw (সিএনজি)</option>
                        <option value="leguna">Leguna (লেগুনা)</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Origin Landmark</label>
                        <input type="text" id="modalOrigin" required placeholder="e.g. Farmgate" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300" />
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Destination Landmark</label>
                        <input type="text" id="modalDest" required placeholder="e.g. Agargaon" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300" />
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Fare Paid (in BDT / টাকা)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2 font-bold text-slate-400">৳</span>
                        <input type="number" id="modalFare" required min="10" max="2000" placeholder="e.g. 90" class="w-full text-xs pl-8 pr-3 py-2 rounded-lg border border-slate-300 font-bold text-slate-800" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Weather</label>
                        <select id="modalWeather" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                            <option value="clear">Clear / Normal</option>
                            <option value="rain">Raining (বৃষ্টি)</option>
                            <option value="waterlogged">Waterlogged (জলাবদ্ধ)</option>
                            <option value="extreme_heat">Extreme Heat</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Traffic Jam</label>
                        <select id="modalTraffic" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                            <option value="normal">Normal</option>
                            <option value="moderate">Moderate Jam</option>
                            <option value="heavy_jam">Heavy Jam</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Your Mobile Phone (Optional)</label>
                    <input type="text" id="modalPhone" placeholder="017xxxxxxxx (for reputation points)" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300" />
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 font-medium">Cancel</button>
                    <button type="submit" id="modalSubmitBtn" class="px-4 py-2 rounded-lg bg-bdgreen-600 hover:bg-bdgreen-700 text-white font-bold shadow-sm">Submit Fare</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Application JavaScript Logic -->
    <script>

        // State
        let map = null;
        let originMarker = null;
        let destMarker = null;
        let routeLayer = null;
        let zonesLayerGroup = null;
        let activeMapPickingMode = null; // 'origin' or 'dest'

        // Initialize App on DOM Load
        document.addEventListener('DOMContentLoaded', () => {
            initLeafletMap();
            loadActiveRestrictedZones();
            setupAutocomplete('originInput', 'originSuggestions', 'originLat', 'originLng', 'origin');
            setupAutocomplete('destInput', 'destSuggestions', 'destLat', 'destLng', 'dest');
            updateClearUIState();

            // Set CSRF token for modal form from meta tag
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                const modalToken = document.getElementById('modalCsrfToken');
                if (modalToken) modalToken.value = csrfMeta.content;
            }
        });

        // Initialize Leaflet Map
        function initLeafletMap() {
            // Center of Dhaka (Farmgate/Agargaon area)
            map = L.map('map').setView([23.7680, 90.3850], 13);

            // OpenStreetMap tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors | BharaKoto Dhaka'
            }).addTo(map);

            zonesLayerGroup = L.layerGroup().addTo(map);

            // Click listener on map to select Origin or Destination with reverse geocoding
            map.on('click', async (e) => {
                const lat = parseFloat(e.latlng.lat.toFixed(6));
                const lng = parseFloat(e.latlng.lng.toFixed(6));

                let resolvedName = `Location (${lat}, ${lng})`;
                try {
                    const rRes = await fetch(`api/reverse.php?lat=${lat}&lng=${lng}`);
                    const rData = await rRes.json();
                    if (rData.status === 'success' && rData.data) {
                        resolvedName = rData.data.display_name || rData.data.name_en;
                    }
                } catch (err) {}

                if (activeMapPickingMode === 'origin') {
                    setOriginCoords(lat, lng, resolvedName);
                    exitMapPickingMode();
                } else if (activeMapPickingMode === 'dest') {
                    setDestCoords(lat, lng, resolvedName);
                    exitMapPickingMode();
                } else {
                    // Always show choice popup when no active picking mode
                    // (lets user explicitly choose Origin or Destination on first click)
                    const hasOrigin = originMarker !== null;
                    const hasDest = destMarker !== null;

                    const popupContent = document.createElement('div');
                    popupContent.className = 'p-1 text-xs space-y-2 min-w-[200px]';
                    let buttonsHtml = '';

                    if (!hasOrigin) {
                        buttonsHtml += `<button onclick="setOriginCoords(${lat}, ${lng}, '${escapeHtml(resolvedName)}'); map.closePopup();" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded font-medium text-xs w-full flex items-center justify-center gap-1.5"><span>🟢</span> Set as Origin</button>`;
                    }
                    if (!hasDest) {
                        buttonsHtml += `<button onclick="setDestCoords(${lat}, ${lng}, '${escapeHtml(resolvedName)}'); map.closePopup();" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded font-medium text-xs w-full flex items-center justify-center gap-1.5"><span>🔴</span> Set as Destination</button>`;
                    }
                    if (hasOrigin || hasDest) {
                        buttonsHtml += `<button onclick="clearAllRoutePoints(); map.closePopup();" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 rounded font-semibold text-xs w-full flex items-center justify-center gap-1.5"><svg class="w-3.5 h-3.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg> Clear All Points</button>`;
                    }

                    popupContent.innerHTML = `
                        <div class="font-bold text-slate-800 flex items-center gap-1">${escapeHtml(resolvedName)}</div>
                        <div class="text-[11px] text-slate-500">${lat.toFixed(4)}, ${lng.toFixed(4)}</div>
                        <div class="flex flex-col gap-1.5 pt-1 border-t border-slate-100">
                            ${buttonsHtml}
                        </div>
                    `;
                    L.popup().setLatLng(e.latlng).setContent(popupContent).openOn(map);
                }
            });
        }

        // Fetch & Render Active Restricted VIP Polygons
        async function loadActiveRestrictedZones() {
            try {
                const res = await fetch('api/zones.php');
                const data = await res.json();
                if (data.status === 'success' && Array.isArray(data.data)) {
                    zonesLayerGroup.clearLayers();
                    data.data.forEach(zone => {
                        if (zone.polygon_geojson) {
                            const polyLayer = L.geoJSON(zone.polygon_geojson, {
                                style: {
                                    color: '#b91c1c', // Crisp dark red road border
                                    weight: 2.5,
                                    opacity: 0.95,
                                    fillColor: '#ef4444', // Red road corridor highlight
                                    fillOpacity: 0.45
                                }
                            });

                            // Interactive road hover tooltip
                            polyLayer.bindTooltip(`
                                <div class="text-xs font-semibold text-slate-900">
                                    <span class="text-red-600 font-bold">🚫 ${escapeHtml(zone.zone_name)}</span>
                                    <div class="text-[10px] text-slate-500 font-normal">Banned: ${escapeHtml(zone.restricted_vehicles.replace(/_/g, ' '))}</div>
                                </div>
                            `, { sticky: true, className: 'custom-road-tooltip' });

                            polyLayer.on('mouseover', function(e) {
                                e.target.setStyle({
                                    weight: 4,
                                    color: '#7f1d1d',
                                    fillOpacity: 0.75
                                });
                            });

                            polyLayer.on('mouseout', function(e) {
                                e.target.setStyle({
                                    weight: 2.5,
                                    color: '#b91c1c',
                                    fillOpacity: 0.45
                                });
                            });

                            const popupHtml = `
                                <div class="text-xs space-y-1 p-1 max-w-xs">
                                    <div class="flex items-center gap-1 text-red-600 font-bold">
                                        <span>🚫 VIP Restricted Road</span>
                                    </div>
                                    <div class="font-bold text-slate-900 text-sm">${escapeHtml(zone.zone_name)}</div>
                                    <div class="text-slate-600"><span class="font-semibold">Banned for:</span> ${escapeHtml(zone.restricted_vehicles.replace(/_/g, ' '))}</div>
                                    ${zone.active_start_time ? `<div class="text-slate-500"><span class="font-semibold">Hours:</span> ${zone.active_start_time} - ${zone.active_end_time}</div>` : '<div class="text-slate-500"><span class="font-semibold">Hours:</span> 24 Hours</div>'}
                                    ${zone.transfer_hint ? `<div class="bg-red-50 text-red-800 p-2 rounded-lg mt-1 border border-red-200">${escapeHtml(zone.transfer_hint)}</div>` : ''}
                                </div>
                            `;
                            polyLayer.bindPopup(popupHtml, { className: 'custom-popup' });
                            zonesLayerGroup.addLayer(polyLayer);
                        }
                    });
                }
            } catch (err) {
                console.error('Failed to load restricted zones:', err);
            }
        }

        // Toggle VIP Zones Layer visibility
        function toggleVipZonesVisibility(show) {
            if (!map || !zonesLayerGroup) return;
            if (show) {
                if (!map.hasLayer(zonesLayerGroup)) {
                    map.addLayer(zonesLayerGroup);
                }
            } else {
                if (map.hasLayer(zonesLayerGroup)) {
                    map.removeLayer(zonesLayerGroup);
                }
            }
        }
        function selectVehicle(type) {
            document.getElementById('selectedVehicle').value = type;
            document.querySelectorAll('.vehicle-btn').forEach(btn => {
                if (btn.dataset.vehicle === type) {
                    btn.classList.add('border-bdgreen-600', 'bg-bdgreen-50', 'text-bdgreen-900');
                    btn.classList.remove('border-slate-200', 'bg-white', 'text-slate-700');
                } else {
                    btn.classList.remove('border-bdgreen-600', 'bg-bdgreen-50', 'text-bdgreen-900');
                    btn.classList.add('border-slate-200', 'bg-white', 'text-slate-700');
                }
            });

            // If both points exist, recalculate with the new vehicle immediately
            const oLat = parseFloat(document.getElementById('originLat')?.value);
            const dLat = parseFloat(document.getElementById('destLat')?.value);
            if (!isNaN(oLat) && !isNaN(dLat)) {
                calculateFareEstimate(false);
            }
        }


        // Origin Marker Popup HTML with Remove & Clear All buttons
        function getOriginPopupHtml(name) {
            return `
                <div class="p-1 text-xs space-y-1.5 min-w-[180px]">
                    <div class="font-bold text-emerald-700 flex items-center justify-between">
                        <span class="flex items-center gap-1">🟢 Origin</span>
                        <span class="text-[10px] text-slate-400 font-normal">Location</span>
                    </div>
                    <div class="font-semibold text-slate-800 leading-tight">${escapeHtml(name)}</div>
                    <div class="flex items-center gap-1.5 pt-1.5 border-t border-slate-100">
                        <button onclick="clearOrigin()" class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded text-[11px] font-bold flex items-center gap-0.5 transition cursor-pointer">
                            ✕ Remove
                        </button>
                        <button onclick="clearAllRoutePoints()" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded text-[11px] font-medium transition cursor-pointer">
                            Clear Both
                        </button>
                    </div>
                </div>
            `;
        }

        // Destination Marker Popup HTML with Remove & Clear All buttons
        function getDestPopupHtml(name) {
            return `
                <div class="p-1 text-xs space-y-1.5 min-w-[180px]">
                    <div class="font-bold text-red-600 flex items-center justify-between">
                        <span class="flex items-center gap-1">🔴 Destination</span>
                        <span class="text-[10px] text-slate-400 font-normal">Location</span>
                    </div>
                    <div class="font-semibold text-slate-800 leading-tight">${escapeHtml(name)}</div>
                    <div class="flex items-center gap-1.5 pt-1.5 border-t border-slate-100">
                        <button onclick="clearDest()" class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded text-[11px] font-bold flex items-center gap-0.5 transition cursor-pointer">
                            ✕ Remove
                        </button>
                        <button onclick="clearAllRoutePoints()" class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded text-[11px] font-medium transition cursor-pointer">
                            Clear Both
                        </button>
                    </div>
                </div>
            `;
        }

        // Set Origin Coordinates and Marker (Round Green Button)
        function setOriginCoords(lat, lng, name, autoFocus = true) {
            document.getElementById('originLat').value = lat;
            document.getElementById('originLng').value = lng;
            document.getElementById('originInput').value = name;
            const badge = document.getElementById('originCoordsBadge');
            badge.textContent = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
            badge.className = 'text-[10px] text-emerald-800 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-md font-mono font-medium';

            if (originMarker && map) {
                map.removeLayer(originMarker);
            }
            originMarker = L.circleMarker([lat, lng], {
                radius: 8,
                fillColor: '#059669',
                color: '#ffffff',
                weight: 3,
                opacity: 1,
                fillOpacity: 0.95
            }).addTo(map);

            originMarker.bindPopup(getOriginPopupHtml(name));

            updateClearUIState();

            // Real-time map pan / zoom and route calculation
            if (autoFocus && map) {
                const dLat = parseFloat(document.getElementById('destLat').value);
                const dLng = parseFloat(document.getElementById('destLng').value);

                if (!isNaN(dLat) && !isNaN(dLng) && destMarker) {
                    // Both points set: fit map view to show both points and compute route immediately
                    const bounds = L.latLngBounds([[lat, lng], [dLat, dLng]]);
                    map.fitBounds(bounds, { padding: [70, 70], maxZoom: 16 });
                    calculateFareEstimate(false);
                } else {
                    // Only origin set: smoothly fly to the origin point
                    map.flyTo([lat, lng], 15, { duration: 0.8 });
                    originMarker.openPopup();
                }
            }
        }

        // Set Destination Coordinates and Marker (Round Red Button)
        function setDestCoords(lat, lng, name, autoFocus = true) {
            document.getElementById('destLat').value = lat;
            document.getElementById('destLng').value = lng;
            document.getElementById('destInput').value = name;
            const badge = document.getElementById('destCoordsBadge');
            badge.textContent = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
            badge.className = 'text-[10px] text-red-800 bg-red-50 border border-red-200 px-2 py-0.5 rounded-md font-mono font-medium';

            if (destMarker && map) {
                map.removeLayer(destMarker);
            }
            destMarker = L.circleMarker([lat, lng], {
                radius: 8,
                fillColor: '#dc2626',
                color: '#ffffff',
                weight: 3,
                opacity: 1,
                fillOpacity: 0.95
            }).addTo(map);

            destMarker.bindPopup(getDestPopupHtml(name));

            updateClearUIState();

            // Real-time map pan / zoom and route calculation
            if (autoFocus && map) {
                const oLat = parseFloat(document.getElementById('originLat').value);
                const oLng = parseFloat(document.getElementById('originLng').value);

                if (!isNaN(oLat) && !isNaN(oLng) && originMarker) {
                    // Both points set: fit map view to show both points and compute route immediately
                    const bounds = L.latLngBounds([[oLat, oLng], [lat, lng]]);
                    map.fitBounds(bounds, { padding: [70, 70], maxZoom: 16 });
                    calculateFareEstimate(false);
                } else {
                    // Only destination set: smoothly fly to the destination point
                    map.flyTo([lat, lng], 15, { duration: 0.8 });
                    destMarker.openPopup();
                }
            }
        }


        // Clear Origin from map & inputs
        function clearOrigin() {
            document.getElementById('originInput').value = '';
            document.getElementById('originLat').value = '';
            document.getElementById('originLng').value = '';
            const badge = document.getElementById('originCoordsBadge');
            badge.textContent = 'Please select origin';
            badge.className = 'text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md font-medium';

            if (originMarker && map) {
                map.removeLayer(originMarker);
                originMarker = null;
            }
            if (routeLayer && map) {
                map.removeLayer(routeLayer);
                routeLayer = null;
            }
            document.getElementById('resultsCard')?.classList.add('hidden');
            document.getElementById('vipWarningAlert')?.classList.add('hidden');
            updateClearUIState();
        }

        // Clear Destination from map & inputs
        function clearDest() {
            document.getElementById('destInput').value = '';
            document.getElementById('destLat').value = '';
            document.getElementById('destLng').value = '';
            const badge = document.getElementById('destCoordsBadge');
            badge.textContent = 'Please select destination';
            badge.className = 'text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md font-medium';

            if (destMarker && map) {
                map.removeLayer(destMarker);
                destMarker = null;
            }
            if (routeLayer && map) {
                map.removeLayer(routeLayer);
                routeLayer = null;
            }
            document.getElementById('resultsCard')?.classList.add('hidden');
            document.getElementById('vipWarningAlert')?.classList.add('hidden');
            updateClearUIState();
        }

        // Clear Both Origin & Destination from map and inputs
        function clearAllRoutePoints() {
            clearOrigin();
            clearDest();
            exitMapPickingMode();
            if (map) {
                map.closePopup();
            }
        }

        // Swap Origin & Destination
        function swapOriginAndDest() {
            const oName = document.getElementById('originInput').value;
            const oLatStr = document.getElementById('originLat').value;
            const oLngStr = document.getElementById('originLng').value;

            const dName = document.getElementById('destInput').value;
            const dLatStr = document.getElementById('destLat').value;
            const dLngStr = document.getElementById('destLng').value;

            if (!oLatStr && !dLatStr) return;

            const hasDest = Boolean(dLatStr && dLngStr);
            const hasOrig = Boolean(oLatStr && oLngStr);

            if (hasDest) {
                setOriginCoords(parseFloat(dLatStr), parseFloat(dLngStr), dName);
            } else {
                clearOrigin();
            }

            if (hasOrig) {
                setDestCoords(parseFloat(oLatStr), parseFloat(oLngStr), oName);
            } else {
                clearDest();
            }

            if (hasDest && hasOrig) {
                calculateFareEstimate();
            }
        }

        // Synchronize Visibility of all Clear Buttons (badges, inputs, map toolbar)
        function updateClearUIState() {
            const hasOrigin = Boolean(document.getElementById('originLat')?.value);
            const hasDest = Boolean(document.getElementById('destLat')?.value);

            // Origin Clear Buttons
            const clearOriginBtn = document.getElementById('clearOriginBtn');
            const originClearX = document.getElementById('originInputClearX');
            if (clearOriginBtn) clearOriginBtn.classList.toggle('hidden', !hasOrigin);
            if (originClearX) originClearX.classList.toggle('hidden', !hasOrigin);

            // Dest Clear Buttons
            const clearDestBtn = document.getElementById('clearDestBtn');
            const destClearX = document.getElementById('destInputClearX');
            if (clearDestBtn) clearDestBtn.classList.toggle('hidden', !hasDest);
            if (destClearX) destClearX.classList.toggle('hidden', !hasDest);

            // Floating Map Clear Button & Sidebar Clear Both
            const clearMapPointsBtn = document.getElementById('clearMapPointsBtn');
            const clearAllPointsBtn = document.getElementById('clearAllPointsBtn');
            const anyPoint = hasOrigin || hasDest || originMarker !== null || destMarker !== null;

            if (clearMapPointsBtn) clearMapPointsBtn.classList.toggle('hidden', !anyPoint);
            if (clearAllPointsBtn) clearAllPointsBtn.classList.toggle('hidden', !(hasOrigin && hasDest));
        }

        // Map Picking Mode
        function activateMapPicker(mode) {
            activeMapPickingMode = mode;
            const indicator = document.getElementById('mapPickingIndicator');
            indicator.textContent = `Click on Map to select ${mode === 'origin' ? 'ORIGIN' : 'DESTINATION'}`;
            indicator.classList.remove('hidden');
        }

        function exitMapPickingMode() {
            activeMapPickingMode = null;
            document.getElementById('mapPickingIndicator').classList.add('hidden');
        }

        // Helper to escape HTML
        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }

        // Use Browser Geolocation for Origin (with reverse geocoding)
        function useCurrentGeolocation() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }

            const badge = document.getElementById('originCoordsBadge');
            const originalBadge = badge.textContent;
            badge.textContent = 'Locating GPS...';
            badge.className = 'text-[10px] text-emerald-800 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-md font-mono font-medium animate-pulse';

            navigator.geolocation.getCurrentPosition(
                async (pos) => {
                    const lat = parseFloat(pos.coords.latitude.toFixed(6));
                    const lng = parseFloat(pos.coords.longitude.toFixed(6));

                    let placeName = 'My Current Location';
                    try {
                        const rRes = await fetch(`api/reverse.php?lat=${lat}&lng=${lng}`);
                        const rData = await rRes.json();
                        if (rData.status === 'success' && rData.data) {
                            placeName = rData.data.display_name || rData.data.name_en;
                        }
                    } catch (err) {}

                    setOriginCoords(lat, lng, placeName);
                    map.setView([lat, lng], 15);
                },
                (err) => {
                    badge.textContent = originalBadge;
                    badge.className = 'text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-md font-medium';
                    alert('Could not retrieve GPS location: ' + err.message + '. Please click on the map or type in the search box.');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        }

        // Autocomplete Search for Places
        function setupAutocomplete(inputId, dropdownId, latId, lngId, mode) {
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            let debounceTimer = null;

            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                const query = input.value.trim();
                if (query.length > 0) {
                    dropdown.innerHTML = '<div class="p-2.5 text-xs text-slate-500 text-center flex items-center justify-center gap-1.5"><span class="w-3 h-3 border-2 border-bdgreen-600 border-t-transparent rounded-full animate-spin"></span> Searching Dhaka places...</div>';
                    dropdown.classList.remove('hidden');
                }
                debounceTimer = setTimeout(() => {
                    fetchPlaces(query, dropdown, input, latId, lngId, mode);
                }, 200);
            });

            // Enter key selects the first item or activates map picker
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const firstOption = dropdown.querySelector('.place-option');
                    if (firstOption) {
                        firstOption.click();
                    } else if (input.value.trim() !== '') {
                        activateCustomMapPicker(mode, input.value.trim());
                        dropdown.classList.add('hidden');
                    }
                }
            });

            input.addEventListener('focus', () => {
                if (dropdown.children.length > 0) {
                    dropdown.classList.remove('hidden');
                } else {
                    fetchPlaces(input.value.trim(), dropdown, input, latId, lngId, mode);
                }
            });

            document.addEventListener('click', (e) => {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        function activateCustomMapPicker(mode, customName) {
            if (mode === 'origin') {
                document.getElementById('originInput').value = customName;
                activateMapPicker('origin');
            } else {
                document.getElementById('destInput').value = customName;
                activateMapPicker('dest');
            }
            const indicator = document.getElementById('mapPickingIndicator');
            indicator.textContent = `Click on Map to set location for "${customName}"`;
            indicator.classList.remove('hidden');
        }

        async function fetchPlaces(q, dropdown, input, latId, lngId, mode) {
            try {
                const res = await fetch(`api/places.php?q=${encodeURIComponent(q)}`);
                const json = await res.json();
                dropdown.innerHTML = '';

                if (json.status === 'success' && Array.isArray(json.data) && json.data.length > 0) {
                    json.data.forEach(item => {
                        const opt = document.createElement('div');
                        opt.className = 'place-option px-3.5 py-2.5 hover:bg-slate-100 cursor-pointer text-xs border-b border-slate-100 last:border-0 flex items-center justify-between transition';
                        opt.innerHTML = `
                            <div>
                                <div class="font-semibold text-slate-800">${escapeHtml(item.name_en)} ${item.name_bn ? `<span class="font-normal text-slate-400">(${escapeHtml(item.name_bn)})</span>` : ''}</div>
                                <div class="text-[10px] text-slate-400">${escapeHtml(item.area_thana)} • ${escapeHtml(item.node_type ? item.node_type.replace('_', ' ') : 'Dhaka')}</div>
                            </div>
                            <span class="text-[10px] bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded uppercase font-bold">${escapeHtml(item.source)}</span>
                        `;
                        opt.addEventListener('click', () => {
                            const lat = parseFloat(item.latitude);
                            const lng = parseFloat(item.longitude);
                            if (mode === 'origin') {
                                setOriginCoords(lat, lng, item.name_en);
                            } else {
                                setDestCoords(lat, lng, item.name_en);
                            }
                            dropdown.classList.add('hidden');
                        });
                        dropdown.appendChild(opt);
                    });

                    // Add "Use Custom Pin on Map" option at bottom if user typed query
                    if (q !== '') {
                        const customPinOpt = document.createElement('div');
                        customPinOpt.className = 'px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100/70 cursor-pointer text-xs border-t border-slate-200 text-emerald-900 font-medium flex items-center gap-2 transition';
                        customPinOpt.innerHTML = `
                            <span>📍</span>
                            <div>
                                <span class="font-bold">Use "${escapeHtml(q)}"</span>
                                <span class="text-[10px] text-emerald-700 block">Click here to pin exact spot on Dhaka map</span>
                            </div>
                        `;
                        customPinOpt.addEventListener('click', () => {
                            activateCustomMapPicker(mode, q);
                            dropdown.classList.add('hidden');
                        });
                        dropdown.appendChild(customPinOpt);
                    }

                    dropdown.classList.remove('hidden');
                } else {
                    // No matching places found fallback
                    const notFoundBox = document.createElement('div');
                    notFoundBox.className = 'p-3 bg-amber-50 text-xs border border-amber-200 rounded-xl m-1.5 text-slate-800';
                    notFoundBox.innerHTML = `
                        <div class="font-bold text-amber-900 mb-0.5 flex items-center gap-1">
                            <span>📍</span>
                            <span>"${escapeHtml(q)}" not in standard landmarks</span>
                        </div>
                        <p class="text-[11px] text-slate-600 mb-2">You can still use this location by pinning it directly on the map!</p>
                        <button type="button" class="w-full py-1.5 px-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold text-xs shadow-sm transition">
                            Click here to pin "${escapeHtml(q)}" on map
                        </button>
                    `;
                    notFoundBox.querySelector('button').addEventListener('click', () => {
                        activateCustomMapPicker(mode, q);
                        dropdown.classList.add('hidden');
                    });
                    dropdown.appendChild(notFoundBox);
                    dropdown.classList.remove('hidden');
                }
            } catch (err) {
                console.error('Error fetching places:', err);
                dropdown.innerHTML = '<div class="p-3 text-xs text-red-500 text-center">Failed to load places</div>';
                dropdown.classList.remove('hidden');
            }
        }

        // Calculate Fare & Route via Estimate API
        async function calculateFareEstimate(isManual = false) {
            const originLat = parseFloat(document.getElementById('originLat').value);
            const originLng = parseFloat(document.getElementById('originLng').value);
            const destLat = parseFloat(document.getElementById('destLat').value);
            const destLng = parseFloat(document.getElementById('destLng').value);
            const vehicleType = document.getElementById('selectedVehicle').value;
            const isPeak = document.getElementById('peakHourToggle').checked;
            const isRain = document.getElementById('rainToggle').checked;

            if (isNaN(originLat) || isNaN(destLat)) {
                if (isManual) {
                    alert('Please pick valid Origin and Destination locations on Dhaka map.');
                }
                return;
            }

            const btn = document.getElementById('estimateBtn');
            const spinner = document.getElementById('btnSpinner');
            const btnText = document.getElementById('btnText');
            btn.disabled = true;
            spinner.classList.remove('hidden');
            btnText.textContent = 'Calculating Route & Fares...';

            try {
                const payload = {
                    origin_lat: originLat,
                    origin_lng: originLng,
                    dest_lat: destLat,
                    dest_lng: destLng,
                    vehicle_type: vehicleType,
                    is_peak_hour: isPeak ? 1 : 0,
                    is_raining: isRain ? 1 : 0
                };

                const res = await fetch('api/estimate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await res.json();
                if (data.status !== 'success') {
                    throw new Error(data.message || 'Failed to estimate fare');
                }

                renderEstimateResults(data.data);

            } catch (err) {
                console.error(err);
                alert('Estimate error: ' + err.message);
            } finally {
                btn.disabled = false;
                spinner.classList.add('hidden');
                btnText.textContent = 'Estimate Fare & Route (ভাড়া হিসাব করুন)';
            }
        }

        // Render Results on UI & Map
        function renderEstimateResults(res) {
            const card = document.getElementById('resultsCard');
            card.classList.remove('hidden');

            // 1. Street / Mama Fare Display
            document.getElementById('streetFareDisplay').textContent = `৳ ${res.fares.street_fare_bdt}`;
            document.getElementById('bargainRangeDisplay').textContent = `৳ ${res.fares.bargaining_range.min_bdt} - ৳ ${res.fares.bargaining_range.max_bdt}`;
            document.getElementById('officialFareDisplay').textContent = `৳ ${res.fares.official_fare_bdt}`;
            document.getElementById('routeSummaryText').textContent = `${res.distance_km} km • ~${res.duration_min} mins (${res.vehicle.en})`;

            // 2. Surge Badges
            const surgeContainer = document.getElementById('surgeBadges');
            surgeContainer.innerHTML = '';
            if (res.fares.surges.is_peak_hour) {
                surgeContainer.innerHTML += `<span class="bg-amber-100 text-amber-800 text-[10px] font-bold px-2 py-0.5 rounded-full">Peak Hour (+20%)</span>`;
            }
            if (res.fares.surges.is_raining) {
                surgeContainer.innerHTML += `<span class="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded-full">Rain (+50%)</span>`;
            }

            // 3. Crowdsourced Info
            const crowd = res.crowdsourced;
            if (crowd.has_data && crowd.average_fare_bdt) {
                document.getElementById('crowdAvgTitle').textContent = `Community Avg: ৳ ${crowd.average_fare_bdt}`;
                document.getElementById('crowdSubmissionsCount').textContent = `Based on ${crowd.submissions_count} recent commuter logs (within 300m corridor)`;
            } else {
                document.getElementById('crowdAvgTitle').textContent = `No exact community data yet`;
                document.getElementById('crowdSubmissionsCount').textContent = `Be the first commuter to log your paid fare!`;
            }

            // 4. VIP Road Restriction Warnings & Transfer Hints
            const warningAlert = document.getElementById('vipWarningAlert');
            if (res.warnings && res.warnings.length > 0) {
                warningAlert.classList.remove('hidden');
                document.getElementById('warningTitle').textContent = res.warnings[0];
                document.getElementById('warningBody').textContent = `This corridor intersects a restricted VIP zone. A detour has been mapped around the zone for ${res.vehicle.en}.`;
                
                if (res.transfer_hints && res.transfer_hints.length > 0) {
                    document.getElementById('transferHint').innerHTML = `💡 <b>Transfer Hint:</b> ${res.transfer_hints[0]}`;
                    document.getElementById('transferHint').classList.remove('hidden');
                } else {
                    document.getElementById('transferHint').classList.add('hidden');
                }
            } else {
                warningAlert.classList.add('hidden');
            }

            // 5. Draw GeoJSON Route Line on Map
            if (routeLayer) {
                map.removeLayer(routeLayer);
            }

            if (res.route_geojson) {
                routeLayer = L.geoJSON(res.route_geojson, {
                    style: {
                        color: '#059669', // Emerald green
                        weight: 5,
                        opacity: 0.9,
                        lineCap: 'round',
                        lineJoin: 'round'
                    }
                }).addTo(map);

                // Add a hover/click tooltip on the route
                routeLayer.bindTooltip(`Route: ${res.distance_km} km (~${res.duration_min} min)`, {
                    sticky: true,
                    className: 'text-xs font-bold text-emerald-800'
                });

                // Zoom map to fit route
                map.fitBounds(routeLayer.getBounds(), { padding: [50, 50] });
            }
        }

        // Fare History Functions
        let fareTrendChart = null;

        async function loadFareHistory() {
            const originLat = parseFloat(document.getElementById('originLat').value);
            const originLng = parseFloat(document.getElementById('originLng').value);
            const destLat = parseFloat(document.getElementById('destLat').value);
            const destLng = parseFloat(document.getElementById('destLng').value);
            const vehicleType = document.getElementById('selectedVehicle').value;
            const days = parseInt(document.getElementById('historyTimeRange').value);

            if (isNaN(originLat) || isNaN(destLat)) return;

            const panel = document.getElementById('fareHistoryPanel');
            panel.classList.remove('hidden');

            try {
                const res = await fetch(`api/fare-history.php?origin_lat=${originLat}&origin_lng=${originLng}&dest_lat=${destLat}&dest_lng=${destLng}&vehicle_type=${vehicleType}&days=${days}&radius_km=0.30&limit=100`);
                const data = await res.json();

                if (data.status !== 'success') {
                    console.error('Fare history error:', data.message);
                    return;
                }

                renderFareHistory(data);
            } catch (err) {
                console.error('Failed to load fare history:', err);
            }
        }

        function renderFareHistory(data) {
            const summary = data.summary;
            const trends = data.trends;
            const distribution = data.distribution;
            const records = data.records;

            // Show/hide summary based on data availability
            const summaryEl = document.getElementById('historySummary');
            if (summary.total_records > 0) {
                summaryEl.classList.remove('hidden');
                document.getElementById('histAvgFare').textContent = `৳ ${summary.average_fare_bdt ?? 0}`;
                document.getElementById('histMinFare').textContent = `৳ ${summary.min_fare_bdt ?? 0}`;
                document.getElementById('histMaxFare').textContent = `৳ ${summary.max_fare_bdt ?? 0}`;
                document.getElementById('histCount').textContent = summary.total_records;
            } else {
                summaryEl.classList.add('hidden');
            }

            // Render Trend Chart
            renderTrendChart(trends);

            // Render Distribution Bars
            renderDistributionBars(distribution);

            // Render Recent Records Table
            renderHistoryRecords(records);
        }

        function renderTrendChart(trends) {
            const ctx = document.getElementById('fareTrendChart').getContext('2d');

            if (fareTrendChart) {
                fareTrendChart.destroy();
            }

            if (trends.length === 0) {
                // Draw empty state
                ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
                ctx.font = '12px sans-serif';
                ctx.fillStyle = '#94a3b8';
                ctx.textAlign = 'center';
                ctx.fillText('No historical data for this route yet', ctx.canvas.width / 2, ctx.canvas.height / 2);
                return;
            }

            const labels = trends.map(t => t.date);
            const avgData = trends.map(t => t.avg_fare);
            const minData = trends.map(t => t.min_fare);
            const maxData = trends.map(t => t.max_fare);

            fareTrendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Avg Fare (৳)',
                            data: avgData,
                            borderColor: '#059669',
                            backgroundColor: 'rgba(5, 150, 105, 0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            borderWidth: 2,
                        },
                        {
                            label: 'Min Fare (৳)',
                            data: minData,
                            borderColor: '#3b82f6',
                            borderDash: [4, 4],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            borderWidth: 1.5,
                        },
                        {
                            label: 'Max Fare (৳)',
                            data: maxData,
                            borderColor: '#ef4444',
                            borderDash: [4, 4],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            borderWidth: 1.5,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: { size: 10 },
                                usePointStyle: true,
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            titleFont: { size: 11 },
                            bodyFont: { size: 10 },
                            padding: 8,
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ৳ ${context.parsed.y}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { font: { size: 9 }, maxTicksLimit: 10 },
                            grid: { display: false }
                        },
                        y: {
                            beginAtZero: false,
                            ticks: { font: { size: 9 }, callback: v => '৳ ' + v },
                            grid: { color: '#e2e8f0' }
                        }
                    }
                }
            });
        }

        function renderDistributionBars(distribution) {
            const container = document.getElementById('distBars');
            const section = document.getElementById('fareDistribution');

            if (distribution.length === 0) {
                section.classList.add('hidden');
                return;
            }

            section.classList.remove('hidden');
            container.innerHTML = '';

            const maxCount = Math.max(...distribution.map(d => d.count));

            distribution.forEach(d => {
                const pct = (d.count / maxCount) * 100;
                const bar = document.createElement('div');
                bar.className = 'flex items-center gap-2';
                bar.innerHTML = `
                    <span class="text-[10px] font-mono text-slate-500 w-14 text-right">৳ ${d.bucket}-${d.bucket + 9}</span>
                    <div class="flex-1 h-3 bg-slate-200 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" style="width: ${pct}%"></div>
                    </div>
                    <span class="text-[10px] font-bold text-slate-700 w-10">${d.count}</span>
                `;
                container.appendChild(bar);
            });
        }

        function renderHistoryRecords(records) {
            const tbody = document.getElementById('historyRecordsBody');
            const section = document.getElementById('historyRecords');

            if (records.length === 0) {
                section.classList.add('hidden');
                return;
            }

            section.classList.remove('hidden');
            tbody.innerHTML = '';

            records.slice(0, 20).forEach(r => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50';

                const date = new Date(r.created_at);
                const dateStr = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' });

                const conditions = [];
                if (r.is_peak_hour) conditions.push('<span class="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded text-[9px]">Peak</span>');
                if (r.weather_condition === 'rain') conditions.push('<span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-[9px]">Rain</span>');
                if (r.traffic_condition === 'heavy_jam') conditions.push('<span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 rounded text-[9px]">Heavy Jam</span>');

                const votes = r.upvotes - r.downvotes;
                const voteColor = votes > 0 ? 'text-emerald-700' : (votes < 0 ? 'text-rose-700' : 'text-slate-500');

                tr.innerHTML = `
                    <td class="px-2 py-1.5 text-slate-700">${dateStr}</td>
                    <td class="px-2 py-1.5 text-right font-bold text-slate-800">৳ ${r.fare_paid_bdt}</td>
                    <td class="px-2 py-1.5 text-center">${conditions.join(' ') || '<span class="text-slate-400 text-[9px]">Normal</span>'}</td>
                    <td class="px-2 py-1.5 text-center ${voteColor} font-bold">${votes > 0 ? '+' : ''}${votes}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        // Call loadFareHistory after successful estimate
        const originalRenderEstimateResults = renderEstimateResults;
        renderEstimateResults = function(res) {
            originalRenderEstimateResults(res);
            // Auto-load fare history after a short delay
            setTimeout(() => loadFareHistory(), 300);
        };

        // Modal Controls
        function openModal() {
            document.getElementById('modalOrigin').value = document.getElementById('originInput').value || 'Farmgate';
            document.getElementById('modalDest').value = document.getElementById('destInput').value || 'Agargaon';
            document.getElementById('modalVehicle').value = document.getElementById('selectedVehicle').value;
            document.getElementById('paidFareModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('paidFareModal').classList.add('hidden');
        }

        // Submit Commuter Paid Fare
        async function handlePaidFareSubmit(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('modalSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            const csrfToken = document.getElementById('modalCsrfToken')?.value ||
                document.querySelector('meta[name="csrf-token"]')?.content || '';

            const payload = {
                vehicle_type: document.getElementById('modalVehicle').value,
                origin_name: document.getElementById('modalOrigin').value,
                origin_lat: parseFloat(document.getElementById('originLat').value) || 23.7574,
                origin_lng: parseFloat(document.getElementById('originLng').value) || 90.3899,
                dest_name: document.getElementById('modalDest').value,
                dest_lat: parseFloat(document.getElementById('destLat').value) || 23.7784,
                dest_lng: parseFloat(document.getElementById('destLng').value) || 90.3802,
                fare_paid_bdt: parseFloat(document.getElementById('modalFare').value),
                weather_condition: document.getElementById('modalWeather').value,
                traffic_condition: document.getElementById('modalTraffic').value,
                phone: document.getElementById('modalPhone').value,
                csrf_token: csrfToken
            };

            try {
                const res = await fetch('api/crowdsourced.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'success') {
                    alert('Shukriya! Your fare contribution has been recorded.');
                    closeModal();
                    calculateFareEstimate();
                } else {
                    alert('Submission error: ' + json.message);
                }
            } catch (err) {
                alert('Submission failed: ' + err.message);
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Fare';
            }
        }
    </script>
</body>
</html>
