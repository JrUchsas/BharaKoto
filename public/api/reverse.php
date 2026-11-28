<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/RateLimiter.php';

// Rate limiting: 60 requests per minute per IP for reverse geocoding
RateLimiter::enforce('reverse', 60, 60);

try {
    $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($lat === false || $lng === false) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Valid latitude and longitude coordinates are required.'
        ]);
        exit;
    }

    $db = Database::getConnection();
    $nodesCol = $db->selectCollection('popular_nodes');

    // 1. Check local popular_nodes within 600m (0.6 km) via 2dsphere $geoNear
    $nearbyDocs = $nodesCol->aggregate([
        [
            '$geoNear' => [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float)$lng, (float)$lat],
                ],
                'distanceField' => 'distance_m',
                'maxDistance' => 600,
                'spherical' => true,
            ],
        ],
        ['$limit' => 1],
    ]);

    $localNode = $nearbyDocs[0] ?? null;
    if ($localNode) {
        $displayName = (string)$localNode['name_en'];
        $areaThana = (string)($localNode['area_thana'] ?? '');
        if ($areaThana !== '' && stripos($displayName, $areaThana) === false) {
            $displayName .= ', ' . $areaThana;
        }
        $coords = $localNode['location']['coordinates'] ?? null;

        echo json_encode([
            'status' => 'success',
            'data' => [
                'display_name' => $displayName,
                'name_en' => $localNode['name_en'],
                'name_bn' => $localNode['name_bn'] ?? '',
                'area_thana' => $areaThana,
                'latitude' => (float)($localNode['latitude'] ?? ($coords[1] ?? $lat)),
                'longitude' => (float)($localNode['longitude'] ?? ($coords[0] ?? $lng)),
                'distance_meters' => round((float)($localNode['distance_m'] ?? 0)),
                'source' => 'local_landmark'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Fallback to OpenStreetMap Nominatim Reverse Geocoding
    $osmUrl = "https://nominatim.openstreetmap.org/reverse?lat={$lat}&lon={$lng}&format=json&addressdetails=1";
    $ch = curl_init($osmUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'BharaKoto-Dhaka-FareEstimator/1.0 (contact@bharakoto.bd)'
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && is_string($resp)) {
        $osm = json_decode($resp, true);
        if (is_array($osm) && isset($osm['address'])) {
            $addr = $osm['address'];
            $road = $addr['road'] ?? $addr['pedestrian'] ?? $addr['suburb'] ?? '';
            $suburb = $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['quarter'] ?? $addr['city_district'] ?? '';
            $city = $addr['city'] ?? $addr['state_district'] ?? 'Dhaka';

            $parts = array_filter([$road, $suburb, $city]);
            $resolvedName = !empty($parts) ? implode(', ', array_unique($parts)) : ($osm['display_name'] ?? "Location ({$lat}, {$lng})");

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'display_name' => $resolvedName,
                    'name_en' => $resolvedName,
                    'name_bn' => '',
                    'area_thana' => $suburb ?: $city,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'nominatim_reverse'
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 3. Fallback to nearest local landmark in Dhaka
    try {
        $nearestDocs = $nodesCol->aggregate([
            [
                '$geoNear' => [
                    'near' => [
                        'type' => 'Point',
                        'coordinates' => [(float)$lng, (float)$lat],
                    ],
                    'distanceField' => 'distance_m',
                    'spherical' => true,
                ],
            ],
            ['$limit' => 1],
        ]);

        $nearNode = $nearestDocs[0] ?? null;
        if ($nearNode) {
            $areaThana = (string)($nearNode['area_thana'] ?? '');
            $displayName = "Near {$nearNode['name_en']}" . ($areaThana !== '' ? ", {$areaThana}" : ', Dhaka');
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'display_name' => $displayName,
                    'name_en' => $displayName,
                    'name_bn' => $nearNode['name_bn'] ?? '',
                    'area_thana' => $areaThana ?: 'Dhaka',
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'nearest_landmark'
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $ignore) {}

    // 4. Absolute fallback
    echo json_encode([
        'status' => 'success',
        'data' => [
            'display_name' => 'Dhaka City Point',
            'name_en' => 'Dhaka City Point',
            'name_bn' => 'ঢাকা শহর',
            'area_thana' => 'Dhaka',
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => 'city_fallback'
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
