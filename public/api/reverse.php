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

    $pdo = Database::getConnection();

    // 1. Check local popular_nodes within 600m (0.6 km)
    $stmt = $pdo->prepare("
        SELECT name_en, name_bn, area_thana, latitude, longitude,
               (6371 * ACOS(LEAST(1.0, GREATEST(-1.0, 
                   COS(RADIANS(:lat1)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(:lng)) + 
                   SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude))
               )))) AS distance_km
        FROM popular_nodes
        HAVING distance_km <= 0.60
        ORDER BY distance_km ASC
        LIMIT 1
    ");

    $stmt->execute([
        ':lat1' => $lat,
        ':lng'  => $lng,
        ':lat2' => $lat
    ]);

    $localNode = $stmt->fetch();
    if ($localNode) {
        $displayName = $localNode['name_en'];
        if (!empty($localNode['area_thana']) && !stripos($displayName, $localNode['area_thana'])) {
            $displayName .= ', ' . $localNode['area_thana'];
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'display_name' => $displayName,
                'name_en' => $localNode['name_en'],
                'name_bn' => $localNode['name_bn'],
                'area_thana' => $localNode['area_thana'],
                'latitude' => (float)$localNode['latitude'],
                'longitude' => (float)$localNode['longitude'],
                'distance_meters' => round((float)$localNode['distance_km'] * 1000),
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
        $stmtNear = $pdo->prepare("
            SELECT name_en, name_bn, area_thana, latitude, longitude,
                   (6371 * ACOS(LEAST(1.0, GREATEST(-1.0, 
                       COS(RADIANS(:lat1)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(:lng)) + 
                       SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude))
                   )))) AS distance_km
            FROM popular_nodes
            ORDER BY distance_km ASC
            LIMIT 1
        ");
        $stmtNear->execute([
            ':lat1' => $lat,
            ':lng'  => $lng,
            ':lat2' => $lat
        ]);
        $nearNode = $stmtNear->fetch();
        if ($nearNode) {
            $displayName = "Near {$nearNode['name_en']}" . (!empty($nearNode['area_thana']) ? ", {$nearNode['area_thana']}" : ', Dhaka');
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'display_name' => $displayName,
                    'name_en' => $displayName,
                    'name_bn' => $nearNode['name_bn'],
                    'area_thana' => $nearNode['area_thana'] ?: 'Dhaka',
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
