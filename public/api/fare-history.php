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
require_once __DIR__ . '/../../src/FareEstimatorService.php';
require_once __DIR__ . '/../../src/RateLimiter.php';

// Rate limiting: 30 requests per minute per IP for fare history
RateLimiter::enforce('fare-history', 30, 60);

try {
    $db = Database::getConnection();
    $faresCol = $db->selectCollection('crowdsourced_fares');
    $usersCol = $db->selectCollection('users');
    $service = new FareEstimatorService($db);

    // Input parameters
    $originLat  = filter_var($_GET['origin_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $originLng  = filter_var($_GET['origin_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $destLat    = filter_var($_GET['dest_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $destLng    = filter_var($_GET['dest_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $vehicle    = trim((string)($_GET['vehicle_type'] ?? 'pedal_rickshaw'));
    $radiusKm   = filter_var($_GET['radius_km'] ?? 0.30, FILTER_VALIDATE_FLOAT);
    $days       = min(365, max(1, (int)($_GET['days'] ?? 90)));
    $limit      = min(100, max(5, (int)($_GET['limit'] ?? 50)));

    if ($originLat === false || $originLng === false || $destLat === false || $destLng === false || $radiusKm === false) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Valid origin and destination coordinates are required.'
        ]);
        exit;
    }

    $validVehicles = ['pedal_rickshaw', 'battery_rickshaw', 'cng', 'leguna'];
    if (!in_array($vehicle, $validVehicles, true)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid vehicle type.'
        ]);
        exit;
    }

    $cutoff = MongoDatabase::utcDateTime(time() - ($days * 86400));

    // Query nearby origin records using 2dsphere index
    $candidates = $faresCol->aggregate([
        [
            '$geoNear' => [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [$originLng, $originLat],
                ],
                'distanceField' => 'origin_dist_m',
                'maxDistance' => $radiusKm * 1000.0,
                'spherical' => true,
                'query' => [
                    'vehicle_type' => $vehicle,
                    'status' => 'approved',
                    'created_at' => ['$gte' => $cutoff],
                ],
            ],
        ],
        ['$sort' => ['created_at' => -1]],
    ]);

    // Filter matching destinations within radiusKm
    $matched = [];
    foreach ($candidates as $row) {
        $dCoords = $row['dest_location']['coordinates'] ?? null;
        $dLat = (float)($row['dest_lat'] ?? ($dCoords[1] ?? 0));
        $dLng = (float)($row['dest_lng'] ?? ($dCoords[0] ?? 0));
        $destDistKm = $service->calculateHaversineKm($destLat, $destLng, $dLat, $dLng);

        if ($destDistKm <= $radiusKm) {
            $row['_origin_dist_km'] = ((float)($row['origin_dist_m'] ?? 0)) / 1000.0;
            $row['_dest_dist_km'] = $destDistKm;
            $matched[] = $row;
        }
    }

    // Batch resolve commuter names
    $limitedRows = array_slice($matched, 0, $limit);
    $userIds = array_values(array_unique(array_filter(array_map(
        fn($d) => isset($d['user_id']) ? (int)$d['user_id'] : null,
        $limitedRows
    ))));
    $userMap = [];
    if (!empty($userIds)) {
        foreach ($usersCol->find(['id' => ['$in' => $userIds]]) as $u) {
            $userMap[(int)$u['id']] = $u['name'] ?? 'Commuter';
        }
    }

    $formattedRows = [];
    foreach ($limitedRows as $row) {
        $uid = isset($row['user_id']) ? (int)$row['user_id'] : null;
        $formattedRows[] = [
            'id' => (int)($row['id'] ?? 0),
            'fare_paid_bdt' => (float)$row['fare_paid_bdt'],
            'api_distance_km' => (float)($row['api_distance_km'] ?? 0),
            'weather_condition' => $row['weather_condition'] ?? 'clear',
            'traffic_condition' => $row['traffic_condition'] ?? 'normal',
            'is_peak_hour' => !empty($row['is_peak_hour']),
            'upvotes' => (int)($row['upvotes'] ?? 0),
            'downvotes' => (int)($row['downvotes'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'commuter_name' => ($uid !== null && isset($userMap[$uid]))
                ? $userMap[$uid]
                : ($row['commuter_name'] ?? 'Commuter'),
            'origin_name' => $row['origin_name'] ?? '',
            'dest_name' => $row['dest_name'] ?? '',
            'origin_distance_m' => round($row['_origin_dist_km'] * 1000),
            'dest_distance_m' => round($row['_dest_dist_km'] * 1000),
        ];
    }

    // Compute aggregate summary, daily trends, and 10-BDT histogram
    $totalRecords = count($matched);
    $allFares = [];
    $peakFares = [];
    $offpeakFares = [];
    $rainFares = [];
    $heavyJamFares = [];
    $trendBuckets = [];
    $histBuckets = [];

    foreach ($matched as $row) {
        $fare = (float)$row['fare_paid_bdt'];
        $allFares[] = $fare;

        if (!empty($row['is_peak_hour'])) {
            $peakFares[] = $fare;
        } else {
            $offpeakFares[] = $fare;
        }
        if (($row['weather_condition'] ?? '') === 'rain') {
            $rainFares[] = $fare;
        }
        if (($row['traffic_condition'] ?? '') === 'heavy_jam') {
            $heavyJamFares[] = $fare;
        }

        $dateStr = substr((string)($row['created_at'] ?? date('Y-m-d')), 0, 10);
        if (!isset($trendBuckets[$dateStr])) {
            $trendBuckets[$dateStr] = [];
        }
        $trendBuckets[$dateStr][] = $fare;

        $bucket = (int)(floor($fare / 10) * 10);
        $histBuckets[$bucket] = ($histBuckets[$bucket] ?? 0) + 1;
    }

    $rawAvg = $totalRecords > 0 ? array_sum($allFares) / $totalRecords : 0.0;
    $roundedAvg = $totalRecords > 0 ? (float)(round($rawAvg / 5.0) * 5.0) : null;

    $stddev = 0.0;
    if ($totalRecords > 1) {
        $variance = array_sum(array_map(fn($v) => ($v - $rawAvg) ** 2, $allFares)) / $totalRecords;
        $stddev = round(sqrt($variance), 2);
    }

    ksort($trendBuckets);
    $trends = [];
    foreach ($trendBuckets as $date => $vals) {
        $trends[] = [
            'date' => $date,
            'submissions' => count($vals),
            'avg_fare' => round(array_sum($vals) / count($vals), 2),
            'min_fare' => min($vals),
            'max_fare' => max($vals),
        ];
    }

    ksort($histBuckets);
    $distribution = [];
    foreach ($histBuckets as $bucket => $count) {
        $distribution[] = [
            'bucket' => (int)$bucket,
            'count' => (int)$count,
        ];
    }

    echo json_encode([
        'status' => 'success',
        'query' => [
            'vehicle_type' => $vehicle,
            'radius_km' => $radiusKm,
            'days' => $days,
        ],
        'summary' => [
            'total_records' => $totalRecords,
            'average_fare_bdt' => $roundedAvg,
            'raw_average_bdt' => $totalRecords > 0 ? round($rawAvg, 2) : null,
            'min_fare_bdt' => $totalRecords > 0 ? min($allFares) : null,
            'max_fare_bdt' => $totalRecords > 0 ? max($allFares) : null,
            'stddev_bdt' => $stddev,
            'avg_peak_fare_bdt' => !empty($peakFares) ? round(array_sum($peakFares) / count($peakFares), 2) : null,
            'avg_offpeak_fare_bdt' => !empty($offpeakFares) ? round(array_sum($offpeakFares) / count($offpeakFares), 2) : null,
            'avg_rain_fare_bdt' => !empty($rainFares) ? round(array_sum($rainFares) / count($rainFares), 2) : null,
            'avg_heavy_jam_fare_bdt' => !empty($heavyJamFares) ? round(array_sum($heavyJamFares) / count($heavyJamFares), 2) : null,
        ],
        'trends' => $trends,
        'distribution' => $distribution,
        'records' => $formattedRows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
