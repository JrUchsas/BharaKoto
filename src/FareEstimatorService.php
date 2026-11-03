<?php
declare(strict_types=1);

/**
 * Core Fare & Route Estimator Service for Dhaka, Bangladesh
 */
final class FareEstimatorService
{
    private PDO $pdo;
    private array $config;

    public function __construct(?PDO $pdo = null, ?array $config = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->config = $config ?? (require __DIR__ . '/../config/config.php');
    }

    /**
     * Map internal vehicle type to OpenRouteService profile
     */
    public function getOrsProfile(string $vehicleType): string
    {
        return match ($vehicleType) {
            'pedal_rickshaw', 'battery_rickshaw' => 'cycling-regular',
            'cng', 'leguna' => 'driving-car',
            default => 'cycling-regular',
        };
    }

    /**
     * Human readable name for vehicle
     */
    public static function getVehicleName(string $vehicleType): array
    {
        return match ($vehicleType) {
            'pedal_rickshaw' => ['en' => 'Pedal Rickshaw', 'bn' => 'প্যাডেল রিকশা'],
            'battery_rickshaw' => ['en' => 'Battery Auto / Rickshaw', 'bn' => 'ব্যাটারি রিকশা'],
            'cng' => ['en' => 'CNG Auto Rickshaw', 'bn' => 'সিএনজি অটোরিকশা'],
            'leguna' => ['en' => 'Leguna (Human Hauler)', 'bn' => 'লেগুনা'],
            default => ['en' => 'Rickshaw', 'bn' => 'রিকশা'],
        };
    }

    /**
     * Determine if current time in Dhaka is a peak traffic hour
     * Peak 1: 08:00 - 10:30 (Morning commute)
     * Peak 2: 17:00 - 20:30 (Evening return)
     */
    public function isDhakaPeakHour(?string $timeString = null): bool
    {
        $time = $timeString ? strtotime($timeString) : time();
        $hourMin = (int)date('Gi', $time); // e.g. 830 for 08:30, 1745 for 17:45

        return ($hourMin >= 800 && $hourMin <= 1030) || ($hourMin >= 1700 && $hourMin <= 2030);
    }

    /**
     * Calculate straight-line Haversine distance in kilometers between two lat/lng pairs
     */
    public function calculateHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        // Use asin for better precision at small distances (clamp to avoid floating-point errors)
        $c = 2 * asin(min(1.0, sqrt($a)));

        return $earthRadiusKm * $c;
    }

    /**
     * Fetch active restricted zones for the given vehicle and time in Dhaka
     */
    public function getActiveRestrictedZones(string $vehicleType, ?string $currentTime = null): array
    {
        $currentTime = $currentTime ?? date('H:i:s');

        $sql = "
            SELECT id, zone_name, city, restricted_vehicles, restriction_type,
                   active_start_time, active_end_time, polygon_geojson, transfer_hint, is_active
            FROM restricted_zones
            WHERE is_active = 1
              AND FIND_IN_SET(:vehicle, restricted_vehicles) > 0
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':vehicle' => $vehicleType]);

        $rows = $stmt->fetchAll();
        $matching = [];

        foreach ($rows as $row) {
            $isTimeActive = true;
            if (!empty($row['active_start_time']) && !empty($row['active_end_time'])) {
                $start = $row['active_start_time'];
                $end = $row['active_end_time'];
                if ($start <= $end) {
                    $isTimeActive = ($currentTime >= $start && $currentTime <= $end);
                } else {
                    $isTimeActive = ($currentTime >= $start || $currentTime <= $end);
                }
            }
            $row['is_time_active'] = $isTimeActive;
            $matching[] = $row;
        }

        return $matching;
    }

    /**
     * Build an avoid_polygons GeoJSON object (Polygon or MultiPolygon) for ORS API
     */
    public function buildAvoidPolygonsGeoJson(array $zones): ?array
    {
        if (empty($zones)) {
            return null;
        }

        $allPolygonCoordinates = [];
        foreach ($zones as $zone) {
            $rawJson = $zone['polygon_geojson'];
            $decoded = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
            if (!$decoded || !isset($decoded['type'])) {
                continue;
            }

            if ($decoded['type'] === 'Polygon' && !empty($decoded['coordinates'])) {
                $allPolygonCoordinates[] = $decoded['coordinates'];
            } elseif ($decoded['type'] === 'MultiPolygon' && !empty($decoded['coordinates'])) {
                foreach ($decoded['coordinates'] as $polyRings) {
                    $allPolygonCoordinates[] = $polyRings;
                }
            }
        }

        if (empty($allPolygonCoordinates)) {
            return null;
        }

        if (count($allPolygonCoordinates) === 1) {
            return [
                'type' => 'Polygon',
                'coordinates' => $allPolygonCoordinates[0]
            ];
        }

        return [
            'type' => 'MultiPolygon',
            'coordinates' => $allPolygonCoordinates
        ];
    }

    /**
     * Check if a line segment between (lat1, lng1) and (lat2, lng2) intersects
     * Check if a route between (lat1, lng1) and (lat2, lng2) intersects
     * or passes directly on the road corridor of any restricted polygon.
     */
    public function checkLineIntersectsZones(float $lat1, float $lng1, float $lat2, float $lng2, array $zones): array
    {
        $intersected = [];

        foreach ($zones as $zone) {
            $rawJson = $zone['polygon_geojson'];
            $decoded = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
            if (!$decoded || empty($decoded['coordinates'])) {
                continue;
            }

            // Extract coordinates of first ring
            $coords = $decoded['type'] === 'Polygon'
                ? ($decoded['coordinates'][0] ?? [])
                : ($decoded['coordinates'][0][0] ?? []);

            $count = count($coords);
            if ($count < 3) {
                continue;
            }

            // Find tight bounding box [minLng, minLat, maxLng, maxLat]
            $minLng = 180.0; $maxLng = -180.0;
            $minLat = 90.0;  $maxLat = -90.0;

            foreach ($coords as $pt) {
                $lng = (float)$pt[0];
                $lat = (float)$pt[1];
                if ($lng < $minLng) $minLng = $lng;
                if ($lng > $maxLng) $maxLng = $lng;
                if ($lat < $minLat) $minLat = $lat;
                if ($lat > $maxLat) $maxLat = $lat;
            }

            // Fast rejection test: check if line segment bounding box overlaps polygon bounding box
            $lineMinLng = min($lng1, $lng2) - 0.0005;
            $lineMaxLng = max($lng1, $lng2) + 0.0005;
            $lineMinLat = min($lat1, $lat2) - 0.0005;
            $lineMaxLat = max($lat1, $lat2) + 0.0005;

            if ($lineMaxLng < $minLng || $lineMinLng > $maxLng ||
                $lineMaxLat < $minLat || $lineMinLat > $maxLat) {
                continue;
            }

            // Precision check: sample 25 points along the path and test point-in-polygon or proximity <= 40m (~0.00035 deg)
            $steps = 25;
            $hit = false;
            $threshold = 0.00035; // ~38 meters

            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $sampleLat = $lat1 + $t * ($lat2 - $lat1);
                $sampleLng = $lng1 + $t * ($lng2 - $lng1);

                // Quick boundary check for sample point
                if ($sampleLng < ($minLng - $threshold) || $sampleLng > ($maxLng + $threshold) ||
                    $sampleLat < ($minLat - $threshold) || $sampleLat > ($maxLat + $threshold)) {
                    continue;
                }

                // 1. Ray-casting Point-in-Polygon
                if ($this->isPointInPolygon($sampleLng, $sampleLat, $coords)) {
                    $hit = true;
                    break;
                }

                // 2. Proximity to road corridor segments
                for ($k = 0; $k < $count - 1; $k++) {
                    $dist = $this->distancePointToSegment(
                        $sampleLng, $sampleLat,
                        (float)$coords[$k][0], (float)$coords[$k][1],
                        (float)$coords[$k+1][0], (float)$coords[$k+1][1]
                    );
                    if ($dist <= $threshold) {
                        $hit = true;
                        break 2;
                    }
                }
            }

            if ($hit) {
                $intersected[] = $zone;
            }
        }

        return $intersected;
    }

    private function isPointInPolygon(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float)$ring[$i][0];
            $yi = (float)$ring[$i][1];
            $xj = (float)$ring[$j][0];
            $yj = (float)$ring[$j][1];

            // Check if point's latitude is between the edge's Y coordinates
            $intersectsY = (($yi > $lat) !== ($yj > $lat));
            if (!$intersectsY) {
                continue;
            }

            // For vertical edges (yi == yj), the ray crosses at x = xi
            // For non-vertical, compute intersection X coordinate
            if ($yi == $yj) {
                $intersectX = $xi;
            } else {
                $intersectX = ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi;
            }

            if ($lng < $intersectX) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    private function distancePointToSegment(float $px, float $py, float $x1, float $y1, float $x2, float $y2): float
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lenSq = $dx * $dx + $dy * $dy;
        if ($lenSq < 1e-12) {
            return sqrt(($px - $x1) * ($px - $x1) + ($py - $y1) * ($py - $y1));
        }
        $t = max(0.0, min(1.0, (($px - $x1) * $dx + ($py - $y1) * $dy) / $lenSq));
        $projX = $x1 + $t * $dx;
        $projY = $y1 + $t * $dy;
        return sqrt(($px - $projX) * ($px - $projX) + ($py - $projY) * ($py - $projY));
    }

    /**
     * Compute route using OpenRouteService or generate smart realistic demo GeoJSON
     */
    public function getRoute(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $vehicleType,
        array $activeZones
    ): array {
        $orsKey = $this->config['apis']['ors_key'] ?? 'DEMO_MODE';
        $avoidPolygonsGeoJson = $this->buildAvoidPolygonsGeoJson($activeZones);
        $intersectingZones = $this->checkLineIntersectsZones($originLat, $originLng, $destLat, $destLng, $activeZones);
        $hasRestrictedIntersections = !empty($intersectingZones);

        $useDemoMode = empty($orsKey) || $orsKey === 'DEMO_MODE';

        if (!$useDemoMode) {
            try {
                $orsResult = $this->callOrsDirectionsApi(
                    $originLat,
                    $originLng,
                    $destLat,
                    $destLng,
                    $vehicleType,
                    $orsKey,
                    $avoidPolygonsGeoJson
                );
                if ($orsResult !== null) {
                    $orsResult['is_fallback'] = false;
                    $orsResult['avoided_zones'] = $intersectingZones;
                    return $orsResult;
                }
            } catch (Throwable) {
                // Gracefully fallback to smart generator if API fails
            }
        }

        // 2. Query OSRM (Open Source Routing Machine) for road-snapped geometry along Dhaka's street network
        try {
            $osrmResult = $this->callOsrmRouteApi(
                $originLat,
                $originLng,
                $destLat,
                $destLng,
                $vehicleType,
                $intersectingZones
            );
            if ($osrmResult !== null) {
                return $osrmResult;
            }
        } catch (Throwable) {
            // Silently fall back if offline
        }

        // 3. Smart Dhaka Fallback Route Generator (offline simulation)
        return $this->generateSmartFallbackRoute(
            $originLat,
            $originLng,
            $destLat,
            $destLng,
            $vehicleType,
            $intersectingZones
        );
    }

    /**
     * Call Open Source Routing Machine (OSRM) for road-following directions on Dhaka OpenStreetMap network
     */
    private function callOsrmRouteApi(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $vehicleType,
        array $intersectingZones
    ): ?array {
        $detourNeeded = !empty($intersectingZones);

        // Build coordinates query in lng,lat format
        $coordPairs = [];
        $coordPairs[] = sprintf('%.6f,%.6f', $originLng, $originLat);

        if ($detourNeeded) {
            // Calculate detour waypoint bypassing the restricted polygon
            $zone = $intersectingZones[0];
            $rawJson = $zone['polygon_geojson'];
            $decoded = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
            $coords = $decoded['type'] === 'Polygon'
                ? ($decoded['coordinates'][0] ?? [])
                : ($decoded['coordinates'][0][0] ?? []);

            $minLng = 180.0; $maxLng = -180.0;
            $minLat = 90.0;  $maxLat = -90.0;
            foreach ($coords as $pt) {
                $lng = (float)$pt[0];
                $lat = (float)$pt[1];
                if ($lng < $minLng) $minLng = $lng;
                if ($lng > $maxLng) $maxLng = $lng;
                if ($lat < $minLat) $minLat = $lat;
                if ($lat > $maxLat) $maxLat = $lat;
            }

            // Route via West Kafrul / Taltola bypass to the west of Rokeya Sarani
            $detourLng = $minLng - 0.0075;
            $detourLat = ($minLat + $maxLat) / 2.0;

            $coordPairs[] = sprintf('%.6f,%.6f', $detourLng, $detourLat);
        }

        $coordPairs[] = sprintf('%.6f,%.6f', $destLng, $destLat);

        // Try driving profile first (robustly covers all paved roads, avenues & connectors in Dhaka)
        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%s?overview=full&geometries=geojson',
            implode(';', $coordPairs)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'BharaKoto-Dhaka-Routing/1.0'
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($resp)) {
            $data = json_decode($resp, true);
            if (isset($data['routes'][0]['geometry']['coordinates']) && !empty($data['routes'][0]['geometry']['coordinates'])) {
                $routeData = $data['routes'][0];
                $distKm = round(((float)$routeData['distance']) / 1000.0, 2);

                // Dhaka urban speeds (km/h)
                $speedKmh = match ($vehicleType) {
                    'pedal_rickshaw' => 11.5,
                    'battery_rickshaw' => 17.0,
                    'cng' => 20.0,
                    'leguna' => 15.0,
                    default => 12.0
                };
                $durationMin = round(($distKm / $speedKmh) * 60.0 + 3.5, 1);

                return [
                    'distance_km' => $distKm,
                    'duration_min' => $durationMin,
                    'geojson' => $routeData['geometry'],
                    'is_fallback' => false,
                    'avoided_zones' => $intersectingZones,
                ];
            }
        }

        return null;
    }

    /**
     * Real HTTP Call to OpenRouteService v2 GeoJSON Directions endpoint
     */
    private function callOrsDirectionsApi(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $vehicleType,
        string $apiKey,
        ?array $avoidPolygons
    ): ?array {
        $profile = $this->getOrsProfile($vehicleType);
        $url = "https://api.openrouteservice.org/v2/directions/{$profile}/geojson";

        $body = [
            'coordinates' => [
                [(float)$originLng, (float)$originLat],
                [(float)$destLng, (float)$destLat]
            ],
            'instructions' => false,
            'preference' => 'recommended',
        ];

        if ($avoidPolygons !== null) {
            $body['options'] = [
                'avoid_polygons' => $avoidPolygons
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                "Authorization: {$apiKey}",
                "Content-Type: application/json; charset=utf-8",
                "Accept: application/json, application/geo+json"
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
            $data = json_decode($response, true);
            if (isset($data['features'][0]['properties']['summary'])) {
                $summary = $data['features'][0]['properties']['summary'];
                $distKm = round((float)($summary['distance'] ?? 0) / 1000.0, 2);
                $durationMin = round((float)($summary['duration'] ?? 0) / 60.0, 1);
                $geometry = $data['features'][0]['geometry'] ?? null;

                return [
                    'distance_km' => $distKm,
                    'duration_min' => $durationMin,
                    'geojson' => $geometry,
                    'is_fallback' => false,
                ];
            }
        }

        return null;
    }

    /**
     * Smart simulated Dhaka route generator with realistic detour around VIP zones
     */
    private function generateSmartFallbackRoute(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $vehicleType,
        array $intersectingZones
    ): array {
        $straightDistKm = $this->calculateHaversineKm($originLat, $originLng, $destLat, $destLng);

        // If line cuts through a restricted zone, detour factor is 1.42, otherwise standard urban 1.28
        $detourNeeded = !empty($intersectingZones);
        $windingFactor = $detourNeeded ? 1.42 : 1.28;
        $distanceKm = round($straightDistKm * $windingFactor, 2);

        // Calculate average Dhaka urban speed per vehicle
        $speedKmh = match ($vehicleType) {
            'pedal_rickshaw' => 11.5,
            'battery_rickshaw' => 17.0,
            'cng' => 20.0,
            'leguna' => 15.0,
            default => 12.0
        };

        // Add 3-5 minutes fixed delay for Dhaka traffic signals & intersections
        $durationMin = round(($distanceKm / $speedKmh) * 60.0 + 4.0, 1);

        // Build LineString coordinates in GeoJSON format: [[lng, lat], ...]
        $coordinates = [];
        $coordinates[] = [$originLng, $originLat];

        if ($detourNeeded) {
            // Check if this matches the Farmgate to Agargaon corridor (Rokeya Sarani VIP ban)
            // Detour westward via Manik Mia Avenue / West Kafrul bypass
            $midLat = ($originLat + $destLat) / 2.0;
            $midLng = ($originLng + $destLng) / 2.0;

            // Push the detour path to the west (Dhaka bypass through residential streets)
            $detourLng = min($originLng, $destLng) - 0.0075;
            
            $coordinates[] = [$originLng + ($detourLng - $originLng) * 0.4, $originLat + ($midLat - $originLat) * 0.3];
            $coordinates[] = [$detourLng, $originLat + ($midLat - $originLat) * 0.7];
            $coordinates[] = [$detourLng + 0.001, $midLat];
            $coordinates[] = [$detourLng + ($destLng - $detourLng) * 0.5, $midLat + ($destLat - $midLat) * 0.6];
            $coordinates[] = [$destLng - 0.002, $destLat - 0.001];
        } else {
            // Generate slight street bends for authentic urban road appearance
            $steps = 6;
            for ($i = 1; $i < $steps; $i++) {
                $t = $i / $steps;
                // Add minor deterministic zigzag based on coordinates
                $noise = sin($t * pi() * 2) * 0.0015;
                $interpLat = $originLat + $t * ($destLat - $originLat) + $noise * 0.5;
                $interpLng = $originLng + $t * ($destLng - $originLng) + $noise;
                $coordinates[] = [round($interpLng, 6), round($interpLat, 6)];
            }
        }

        $coordinates[] = [$destLng, $destLat];

        return [
            'distance_km' => $distanceKm,
            'duration_min' => $durationMin,
            'geojson' => [
                'type' => 'LineString',
                'coordinates' => $coordinates
            ],
            'is_fallback' => true,
            'avoided_zones' => $intersectingZones,
        ];
    }

    /**
     * Compute Fare breakdown: Official rate vs Street rate (with 5-taka rounding)
     */
    public function calculateFare(
        string $vehicleType,
        float $distanceKm,
        float $durationMin,
        bool $isPeakHour,
        bool $isRaining
    ): array {
        $stmt = $this->pdo->prepare("SELECT * FROM fare_configs WHERE vehicle_type = :v LIMIT 1");
        $stmt->execute([':v' => $vehicleType]);
        $config = $stmt->fetch();

        if (!$config) {
            throw new InvalidArgumentException("Fare configuration not found for vehicle: {$vehicleType}");
        }

        $baseFare = (float)$config['base_fare_bdt'];
        $baseDistanceKm = (float)$config['base_distance_km'];
        $perKmOfficial = (float)$config['per_km_official_bdt'];
        $perKmStreet = (float)$config['per_km_street_bdt'];
        $waitingChargePerMin = (float)$config['waiting_charge_per_min'];
        $peakMultiplier = (float)$config['peak_hour_multiplier'];
        $rainMultiplier = (float)$config['rain_multiplier'];

        // 1. Official Government / Meter Rate
        $extraDistKm = max(0.0, $distanceKm - $baseDistanceKm);
        $officialFare = $baseFare + ($extraDistKm * $perKmOfficial);

        // CNG Meter includes waiting time in traffic (assume ~30% of trip is waiting in jam)
        if ($vehicleType === 'cng' && $waitingChargePerMin > 0) {
            $estimatedWaitMinutes = max(0.0, $durationMin - ($distanceKm / 0.5)); // rough idle estimate
            $waitMins = min(20.0, max(5.0, $estimatedWaitMinutes));
            $officialFare += ($waitMins * $waitingChargePerMin);
        }
        $officialFare = round($officialFare, 2);

        // 2. Real Street / "Mama" Rate
        $streetBase = $baseFare + ($extraDistKm * $perKmStreet);

        $appliedPeakMultiplier = $isPeakHour ? $peakMultiplier : 1.0;
        $appliedRainMultiplier = $isRaining ? $rainMultiplier : 1.0;

        $rawStreetFare = $streetBase * $appliedPeakMultiplier * $appliedRainMultiplier;

        // 5-Taka Bangladesh Rounding Rule: round($val / 5) * 5
        $estimatedStreetFare = (float)(round($rawStreetFare / 5.0) * 5.0);
        if ($estimatedStreetFare < $baseFare) {
            $estimatedStreetFare = $baseFare;
        }

        // Bargaining range: 0.90x to 1.15x rounded to multiples of 5 BDT
        $minBargain = (float)(round(($estimatedStreetFare * 0.90) / 5.0) * 5.0);
        $maxBargain = (float)(round(($estimatedStreetFare * 1.15) / 5.0) * 5.0);

        if ($minBargain < $baseFare) {
            $minBargain = $baseFare;
        }
        if ($maxBargain < $estimatedStreetFare) {
            $maxBargain = $estimatedStreetFare + 5.0;
        }

        return [
            'vehicle_type' => $vehicleType,
            'official_fare_bdt' => $officialFare,
            'street_fare_bdt' => $estimatedStreetFare,
            'bargaining_range' => [
                'min_bdt' => $minBargain,
                'max_bdt' => $maxBargain,
            ],
            'rates' => [
                'base_fare' => $baseFare,
                'base_distance_km' => $baseDistanceKm,
                'per_km_street' => $perKmStreet,
                'per_km_official' => $perKmOfficial,
            ],
            'surges' => [
                'is_peak_hour' => $isPeakHour,
                'peak_multiplier' => $appliedPeakMultiplier,
                'is_raining' => $isRaining,
                'rain_multiplier' => $appliedRainMultiplier,
            ]
        ];
    }

    /**
     * Query crowdsourced actual commuter fares within 300 meters (0.30 km) of both origin and destination
     */
    public function getCrowdsourcedStats(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $vehicleType,
        float $radiusKm = 0.30
    ): array {
        // Safe spherical law of cosines with LEAST(1.0, GREATEST(-1.0, ...)) to prevent NaN
        $sql = "
            SELECT 
                COUNT(*) as total_submissions,
                COALESCE(AVG(fare_paid_bdt), 0) as avg_fare,
                COALESCE(MIN(fare_paid_bdt), 0) as min_fare,
                COALESCE(MAX(fare_paid_bdt), 0) as max_fare
            FROM crowdsourced_fares
            WHERE vehicle_type = :vehicle
              AND status = 'approved'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
              AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0, 
                  COS(RADIANS(:o_lat1)) * COS(RADIANS(origin_lat)) * COS(RADIANS(origin_lng) - RADIANS(:o_lng)) + 
                  SIN(RADIANS(:o_lat2)) * SIN(RADIANS(origin_lat))
              )))) <= :radius1
              AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0, 
                  COS(RADIANS(:d_lat1)) * COS(RADIANS(dest_lat)) * COS(RADIANS(dest_lng) - RADIANS(:d_lng)) + 
                  SIN(RADIANS(:d_lat2)) * SIN(RADIANS(dest_lat))
              )))) <= :radius2
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':vehicle' => $vehicleType,
            ':o_lat1' => $originLat,
            ':o_lat2' => $originLat,
            ':o_lng' => $originLng,
            ':d_lat1' => $destLat,
            ':d_lat2' => $destLat,
            ':d_lng' => $destLng,
            ':radius1' => $radiusKm,
            ':radius2' => $radiusKm,
        ]);

        $res = $stmt->fetch();
        $total = (int)($res['total_submissions'] ?? 0);

        // Fetch recent matching rides
        $recentRides = [];
        if ($total > 0) {
            $recentSql = "
                SELECT id, fare_paid_bdt, weather_condition, traffic_condition, is_peak_hour, upvotes, created_at
                FROM crowdsourced_fares
                WHERE vehicle_type = :vehicle
                  AND status = 'approved'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                  AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0, 
                      COS(RADIANS(:o_lat1)) * COS(RADIANS(origin_lat)) * COS(RADIANS(origin_lng) - RADIANS(:o_lng)) + 
                      SIN(RADIANS(:o_lat2)) * SIN(RADIANS(origin_lat))
                  )))) <= :radius1
                  AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0, 
                      COS(RADIANS(:d_lat1)) * COS(RADIANS(dest_lat)) * COS(RADIANS(dest_lng) - RADIANS(:d_lng)) + 
                      SIN(RADIANS(:d_lat2)) * SIN(RADIANS(dest_lat))
                  )))) <= :radius2
                ORDER BY created_at DESC
                LIMIT 5
            ";
            $recentStmt = $this->pdo->prepare($recentSql);
            $recentStmt->execute([
                ':vehicle' => $vehicleType,
                ':o_lat1' => $originLat,
                ':o_lat2' => $originLat,
                ':o_lng' => $originLng,
                ':d_lat1' => $destLat,
                ':d_lat2' => $destLat,
                ':d_lng' => $destLng,
                ':radius1' => $radiusKm,
                ':radius2' => $radiusKm,
            ]);
            $recentRides = $recentStmt->fetchAll();
        }

        return [
            'has_data' => $total > 0,
            'submissions_count' => $total,
            'average_fare_bdt' => $total > 0 ? (float)(round(((float)$res['avg_fare']) / 5.0) * 5.0) : null,
            'raw_average_bdt' => $total > 0 ? round((float)$res['avg_fare'], 2) : null,
            'min_fare_bdt' => $total > 0 ? (float)$res['min_fare'] : null,
            'max_fare_bdt' => $total > 0 ? (float)$res['max_fare'] : null,
            'search_radius_meters' => (int)($radiusKm * 1000),
            'recent_submissions' => $recentRides,
        ];
    }

    /**
     * Check Dhaka weather status via OpenWeatherMap API (or default to clear)
     */
    public function checkWeather(?string $apiKey = null): array
    {
        $apiKey = $apiKey ?? ($this->config['apis']['openweather_key'] ?? '');
        if (empty($apiKey)) {
            return [
                'is_raining' => false,
                'condition' => 'clear',
                'description' => 'Normal clear weather in Dhaka',
                'source' => 'default'
            ];
        }

        try {
            $coords = $this->config['apis']['weather_dhaka_coords'] ?? ['lat' => 23.8103, 'lon' => 90.4125];
            $url = sprintf(
                'https://api.openweathermap.org/data/2.5/weather?lat=%.4f&lon=%.4f&appid=%s',
                $coords['lat'],
                $coords['lon'],
                $apiKey
            );

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && is_string($resp)) {
                $data = json_decode($resp, true);
                $weatherMain = strtolower($data['weather'][0]['main'] ?? '');
                $weatherDesc = $data['weather'][0]['description'] ?? '';
                $isRain = in_array($weatherMain, ['rain', 'drizzle', 'thunderstorm'], true);

                return [
                    'is_raining' => $isRain,
                    'condition' => $isRain ? 'rain' : 'clear',
                    'description' => $weatherDesc,
                    'source' => 'openweathermap'
                ];
            }
        } catch (Throwable) {
            // Silently fallback on timeout or error
        }

        return [
            'is_raining' => false,
            'condition' => 'clear',
            'description' => 'Normal weather (Dhaka)',
            'source' => 'fallback'
        ];
    }

    /**
     * Complete End-to-End Estimation Process
     */
    public function estimate(
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        string $vehicleType,
        ?bool $overridePeak = null,
        ?bool $overrideRain = null
    ): array {
        // Validate vehicle type
        $validVehicles = ['pedal_rickshaw', 'battery_rickshaw', 'cng', 'leguna'];
        if (!in_array($vehicleType, $validVehicles, true)) {
            throw new InvalidArgumentException("Invalid vehicle type: {$vehicleType}");
        }

        // Active restricted zones check
        $activeZones = $this->getActiveRestrictedZones($vehicleType);

        // Route routing with avoidance
        $route = $this->getRoute($originLat, $originLng, $destLat, $destLng, $vehicleType, $activeZones);

        // Weather check
        $weatherInfo = $this->checkWeather();
        $isRaining = $overrideRain ?? $weatherInfo['is_raining'];
        $isPeakHour = $overridePeak ?? $this->isDhakaPeakHour();

        // Calculate fares
        $fare = $this->calculateFare(
            $vehicleType,
            $route['distance_km'],
            $route['duration_min'],
            $isPeakHour,
            $isRaining
        );

        // Crowdsourced community lookup
        $crowdsourced = $this->getCrowdsourcedStats($originLat, $originLng, $destLat, $destLng, $vehicleType);

        // Format warnings & transfer hints for any avoided / intersected restricted zones
        $warnings = [];
        $transferHints = [];
        if (!empty($route['avoided_zones'])) {
            foreach ($route['avoided_zones'] as $zone) {
                $warnings[] = sprintf("VIP Road Ban: '%s' is restricted for %s.", $zone['zone_name'], $vehicleType);
                if (!empty($zone['transfer_hint'])) {
                    $transferHints[] = $zone['transfer_hint'];
                }
            }
        }

        return [
            'origin' => ['lat' => $originLat, 'lng' => $originLng],
            'destination' => ['lat' => $destLat, 'lng' => $destLng],
            'vehicle' => array_merge(self::getVehicleName($vehicleType), ['type' => $vehicleType]),
            'distance_km' => $route['distance_km'],
            'duration_min' => $route['duration_min'],
            'fares' => $fare,
            'crowdsourced' => $crowdsourced,
            'weather' => $weatherInfo,
            'warnings' => array_values(array_unique($warnings)),
            'transfer_hints' => array_values(array_unique($transferHints)),
            'route_geojson' => $route['geojson'],
            'is_fallback_route' => $route['is_fallback'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }
}
