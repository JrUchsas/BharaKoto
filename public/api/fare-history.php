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

try {
    $pdo = Database::getConnection();

    // Input parameters
    $originLat  = filter_var($_GET['origin_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $originLng  = filter_var($_GET['origin_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $destLat    = filter_var($_GET['dest_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $destLng    = filter_var($_GET['dest_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $vehicle    = trim((string)($_GET['vehicle_type'] ?? 'pedal_rickshaw'));
    $radiusKm   = filter_var($_GET['radius_km'] ?? 0.30, FILTER_VALIDATE_FLOAT);
    $days       = min(365, max(1, (int)($_GET['days'] ?? 90)));
    $limit      = min(100, max(5, (int)($_GET['limit'] ?? 50)));

    if ($originLat === false || $originLng === false || $destLat === false || $destLng === false) {
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

    // Build query with spherical distance matching for both origin AND destination
    // Using the same approach as FareEstimatorService::getCrowdsourcedStats
    $sql = "
        SELECT
            cf.id,
            cf.fare_paid_bdt,
            cf.api_distance_km,
            cf.weather_condition,
            cf.traffic_condition,
            cf.is_peak_hour,
            cf.upvotes,
            cf.downvotes,
            cf.created_at,
            COALESCE(u.name, 'Commuter') as commuter_name,
            cf.origin_name,
            cf.dest_name,
            (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
                COS(RADIANS(:o_lat)) * COS(RADIANS(cf.origin_lat)) * COS(RADIANS(cf.origin_lng) - RADIANS(:o_lng)) +
                SIN(RADIANS(:o_lat)) * SIN(RADIANS(cf.origin_lat))
            )))) as origin_dist_km,
            (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
                COS(RADIANS(:d_lat)) * COS(RADIANS(cf.dest_lat)) * COS(RADIANS(cf.dest_lng) - RADIANS(:d_lng)) +
                SIN(RADIANS(:d_lat)) * SIN(RADIANS(cf.dest_lat))
            )))) as dest_dist_km
        FROM crowdsourced_fares cf
        LEFT JOIN users u ON cf.user_id = u.id
        WHERE cf.vehicle_type = :vehicle
          AND cf.status = 'approved'
          AND cf.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        HAVING origin_dist_km <= :radius AND dest_dist_km <= :radius
        ORDER BY cf.created_at DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':vehicle', $vehicle, PDO::PARAM_STR);
    $stmt->bindValue(':o_lat', $originLat, PDO::PARAM_STR);
    $stmt->bindValue(':o_lng', $originLng, PDO::PARAM_STR);
    $stmt->bindValue(':d_lat', $destLat, PDO::PARAM_STR);
    $stmt->bindValue(':d_lng', $destLng, PDO::PARAM_STR);
    $stmt->bindValue(':radius', $radiusKm, PDO::PARAM_STR);
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    // Also get aggregate stats
    $statsSql = "
        SELECT
            COUNT(*) as total_records,
            COALESCE(AVG(fare_paid_bdt), 0) as avg_fare,
            COALESCE(MIN(fare_paid_bdt), 0) as min_fare,
            COALESCE(MAX(fare_paid_bdt), 0) as max_fare,
            COALESCE(STDDEV(fare_paid_bdt), 0) as stddev_fare,
            COALESCE(AVG(CASE WHEN is_peak_hour = 1 THEN fare_paid_bdt END), 0) as avg_peak_fare,
            COALESCE(AVG(CASE WHEN is_peak_hour = 0 THEN fare_paid_bdt END), 0) as avg_offpeak_fare,
            COALESCE(AVG(CASE WHEN weather_condition = 'rain' THEN fare_paid_bdt END), 0) as avg_rain_fare,
            COALESCE(AVG(CASE WHEN traffic_condition = 'heavy_jam' THEN fare_paid_bdt END), 0) as avg_heavy_jam_fare
        FROM crowdsourced_fares cf
        WHERE cf.vehicle_type = :vehicle
          AND cf.status = 'approved'
          AND cf.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
          AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
              COS(RADIANS(:o_lat)) * COS(RADIANS(cf.origin_lat)) * COS(RADIANS(cf.origin_lng) - RADIANS(:o_lng)) +
              SIN(RADIANS(:o_lat)) * SIN(RADIANS(cf.origin_lat))
          )))) <= :radius
          AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
              COS(RADIANS(:d_lat)) * COS(RADIANS(cf.dest_lat)) * COS(RADIANS(cf.dest_lng) - RADIANS(:d_lng)) +
              SIN(RADIANS(:d_lat)) * SIN(RADIANS(cf.dest_lat))
          )))) <= :radius
    ";

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute([
        ':vehicle' => $vehicle,
        ':o_lat' => $originLat,
        ':o_lng' => $originLng,
        ':d_lat' => $destLat,
        ':d_lng' => $destLng,
        ':radius' => $radiusKm,
        ':days' => $days,
    ]);
    $stats = $statsStmt->fetch();

    // Get weekly trend data for charting
    $trendSql = "
        SELECT
            DATE(created_at) as fare_date,
            COUNT(*) as submissions,
            ROUND(AVG(fare_paid_bdt), 2) as avg_fare,
            MIN(fare_paid_bdt) as min_fare,
            MAX(fare_paid_bdt) as max_fare
        FROM crowdsourced_fares cf
        WHERE cf.vehicle_type = :vehicle
          AND cf.status = 'approved'
          AND cf.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
          AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
              COS(RADIANS(:o_lat)) * COS(RADIANS(cf.origin_lat)) * COS(RADIANS(cf.origin_lng) - RADIANS(:o_lng)) +
              SIN(RADIANS(:o_lat)) * SIN(RADIANS(cf.origin_lat))
          )))) <= :radius
          AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
              COS(RADIANS(:d_lat)) * COS(RADIANS(cf.dest_lat)) * COS(RADIANS(cf.dest_lng) - RADIANS(:d_lng)) +
              SIN(RADIANS(:d_lat)) * SIN(RADIANS(cf.dest_lat))
          )))) <= :radius
        GROUP BY DATE(created_at)
        ORDER BY fare_date ASC
    ";

    $trendStmt = $pdo->prepare($trendSql);
    $trendStmt->execute([
        ':vehicle' => $vehicle,
        ':o_lat' => $originLat,
        ':o_lng' => $originLng,
        ':d_lat' => $destLat,
        ':d_lng' => $destLng,
        ':radius' => $radiusKm,
        ':days' => $days,
    ]);
    $trends = $trendStmt->fetchAll();

    // Get fare distribution for histogram
    $distSql = "
        SELECT
            FLOOR(fare_paid_bdt / 10) * 10 as fare_bucket,
            COUNT(*) as count
        FROM crowdsourced_fares cf
        WHERE cf.vehicle_type = :vehicle
          AND cf.status = 'approved'
          AND cf.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
          AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
              COS(RADIANS(:o_lat)) * COS(RADIANS(cf.origin_lat)) * COS(RADIANS(cf.origin_lng) - RADIANS(:o_lng)) +
              SIN(RADIANS(:o_lat)) * SIN(RADIANS(cf.origin_lat))
          )))) <= :radius
          AND (6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
              COS(RADIANS(:d_lat)) * COS(RADIANS(cf.dest_lat)) * COS(RADIANS(cf.dest_lng) - RADIANS(:d_lng)) +
              SIN(RADIANS(:d_lat)) * SIN(RADIANS(cf.dest_lat))
          )))) <= :radius
        GROUP BY fare_bucket
        ORDER BY fare_bucket ASC
    ";

    $distStmt = $pdo->prepare($distSql);
    $distStmt->execute([
        ':vehicle' => $vehicle,
        ':o_lat' => $originLat,
        ':o_lng' => $originLng,
        ':d_lat' => $destLat,
        ':d_lng' => $destLng,
        ':radius' => $radiusKm,
        ':days' => $days,
    ]);
    $distribution = $distStmt->fetchAll();

    // Format response
    $formattedRows = [];
    foreach ($rows as $row) {
        $formattedRows[] = [
            'id' => (int)$row['id'],
            'fare_paid_bdt' => (float)$row['fare_paid_bdt'],
            'api_distance_km' => (float)$row['api_distance_km'],
            'weather_condition' => $row['weather_condition'],
            'traffic_condition' => $row['traffic_condition'],
            'is_peak_hour' => (bool)$row['is_peak_hour'],
            'upvotes' => (int)$row['upvotes'],
            'downvotes' => (int)$row['downvotes'],
            'created_at' => $row['created_at'],
            'commuter_name' => $row['commuter_name'],
            'origin_name' => $row['origin_name'],
            'dest_name' => $row['dest_name'],
            'origin_distance_m' => round((float)$row['origin_dist_km'] * 1000),
            'dest_distance_m' => round((float)$row['dest_dist_km'] * 1000),
        ];
    }

    // Calculate 5-taka rounded average for display
    $roundedAvg = $stats['total_records'] > 0 ? (float)(round(((float)$stats['avg_fare']) / 5.0) * 5.0) : null;

    echo json_encode([
        'status' => 'success',
        'query' => [
            'vehicle_type' => $vehicle,
            'radius_km' => $radiusKm,
            'days' => $days,
        ],
        'summary' => [
            'total_records' => (int)$stats['total_records'],
            'average_fare_bdt' => $roundedAvg,
            'raw_average_bdt' => $stats['total_records'] > 0 ? round((float)$stats['avg_fare'], 2) : null,
            'min_fare_bdt' => $stats['total_records'] > 0 ? (float)$stats['min_fare'] : null,
            'max_fare_bdt' => $stats['total_records'] > 0 ? (float)$stats['max_fare'] : null,
            'stddev_bdt' => $stats['total_records'] > 1 ? round((float)$stats['stddev_fare'], 2) : 0,
            'avg_peak_fare_bdt' => $stats['avg_peak_fare'] > 0 ? round((float)$stats['avg_peak_fare'], 2) : null,
            'avg_offpeak_fare_bdt' => $stats['avg_offpeak_fare'] > 0 ? round((float)$stats['avg_offpeak_fare'], 2) : null,
            'avg_rain_fare_bdt' => $stats['avg_rain_fare'] > 0 ? round((float)$stats['avg_rain_fare'], 2) : null,
            'avg_heavy_jam_fare_bdt' => $stats['avg_heavy_jam_fare'] > 0 ? round((float)$stats['avg_heavy_jam_fare'], 2) : null,
        ],
        'trends' => array_map(fn($t) => [
            'date' => $t['fare_date'],
            'submissions' => (int)$t['submissions'],
            'avg_fare' => (float)$t['avg_fare'],
            'min_fare' => (float)$t['min_fare'],
            'max_fare' => (float)$t['max_fare'],
        ], $trends),
        'distribution' => array_map(fn($d) => [
            'bucket' => (int)$d['fare_bucket'],
            'count' => (int)$d['count'],
        ], $distribution),
        'records' => $formattedRows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}