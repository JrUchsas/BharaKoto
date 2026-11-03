<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/FareEstimatorService.php';
require_once __DIR__ . '/../../src/CsrfProtection.php';
require_once __DIR__ . '/../../src/RateLimiter.php';

// Rate limiting: 30 requests per minute per IP for crowdsourced fares
RateLimiter::enforce('crowdsourced', 30, 60);

try {
    $pdo = Database::getConnection();

    // ----------------------------------------------------
    // GET: Retrieve Recent Commuter Submissions
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = min(50, max(5, (int)($_GET['limit'] ?? 15)));
        $vehicle = trim((string)($_GET['vehicle'] ?? ''));

        $params = [];
        $where = "WHERE cf.status = 'approved'";
        if ($vehicle !== '') {
            $where .= " AND cf.vehicle_type = :vehicle";
            $params[':vehicle'] = $vehicle;
        }

        $sql = "
            SELECT 
                cf.id, cf.vehicle_type, cf.origin_name, cf.origin_lat, cf.origin_lng,
                cf.dest_name, cf.dest_lat, cf.dest_lng, cf.api_distance_km, cf.fare_paid_bdt,
                cf.weather_condition, cf.traffic_condition, cf.is_peak_hour,
                cf.upvotes, cf.downvotes, cf.created_at,
                COALESCE(u.name, 'Commuter') as commuter_name
            FROM crowdsourced_fares cf
            LEFT JOIN users u ON cf.user_id = u.id
            {$where}
            ORDER BY cf.created_at DESC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'status' => 'success',
            'count' => count($rows),
            'data' => $rows
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ----------------------------------------------------
    // POST: Log New Commuter Fare or Cast Vote
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CsrfProtection::requireValid();

        $raw = file_get_contents('php://input');
        $data = !empty($raw) ? json_decode($raw, true) : $_POST;

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
            exit;
        }

        // Action: Vote on existing submission
        if (isset($data['action']) && $data['action'] === 'vote') {
            $fareId = (int)($data['fare_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 1); // default commuter user
            $voteType = trim((string)($data['vote_type'] ?? 'accurate'));

            if ($fareId <= 0 || !in_array($voteType, ['accurate', 'overpaid', 'too_low_fake'], true)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid vote parameters.']);
                exit;
            }

            // Insert or update vote
            $voteStmt = $pdo->prepare("
                INSERT INTO fare_votes (fare_id, user_id, vote_type)
                VALUES (:f, :u, :v)
                ON DUPLICATE KEY UPDATE vote_type = VALUES(vote_type)
            ");
            $voteStmt->execute([':f' => $fareId, ':u' => $userId, ':v' => $voteType]);

            // Update upvotes count on main table
            $pdo->prepare("
                UPDATE crowdsourced_fares 
                SET upvotes = (SELECT COUNT(*) FROM fare_votes WHERE fare_id = :f1 AND vote_type = 'accurate'),
                    downvotes = (SELECT COUNT(*) FROM fare_votes WHERE fare_id = :f2 AND vote_type != 'accurate')
                WHERE id = :f3
            ")->execute([':f1' => $fareId, ':f2' => $fareId, ':f3' => $fareId]);

            echo json_encode(['status' => 'success', 'message' => 'Vote recorded successfully.']);
            exit;
        }

        // Action: Submit Fare Paid
        $originName = trim((string)($data['origin_name'] ?? 'Dhaka'));
        $destName   = trim((string)($data['dest_name'] ?? 'Dhaka'));
        $originLat  = filter_var($data['origin_lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $originLng  = filter_var($data['origin_lng'] ?? null, FILTER_VALIDATE_FLOAT);
        $destLat    = filter_var($data['dest_lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $destLng    = filter_var($data['dest_lng'] ?? null, FILTER_VALIDATE_FLOAT);
        $vehicle    = trim((string)($data['vehicle_type'] ?? 'pedal_rickshaw'));
        $farePaid   = filter_var($data['fare_paid_bdt'] ?? null, FILTER_VALIDATE_FLOAT);

        $weather = trim((string)($data['weather_condition'] ?? 'clear'));
        $traffic = trim((string)($data['traffic_condition'] ?? 'normal'));
        $isPeak  = !empty($data['is_peak_hour']) ? 1 : 0;

        $phone = trim((string)($data['phone'] ?? ''));
        $name  = trim((string)($data['name'] ?? 'Anonymous Commuter'));

        // Validate Bangladeshi phone number format (01X-XXXXXXXX)
        if ($phone !== '' && !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid phone number format. Use Bangladeshi format: 01XXXXXXXXX'
            ]);
            exit;
        }

        if ($originLat === false || $originLng === false || $destLat === false || $destLng === false || $farePaid === false || $farePaid <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Origin, destination coordinates, and valid fare amount (BDT) are required.'
            ]);
            exit;
        }

        $validVehicles = ['pedal_rickshaw', 'battery_rickshaw', 'cng', 'leguna'];
        if (!in_array($vehicle, $validVehicles, true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid vehicle type.']);
            exit;
        }

        // Calculate distance
        $service = new FareEstimatorService($pdo);
        $straightKm = $service->calculateHaversineKm($originLat, $originLng, $destLat, $destLng);
        $apiDistanceKm = round($straightKm * 1.30, 2);

        // Find or create user if phone given
        $userId = null;
        if ($phone !== '') {
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE phone = :phone LIMIT 1");
            $userStmt->execute([':phone' => $phone]);
            $existingUser = $userStmt->fetch();
            if ($existingUser) {
                $userId = (int)$existingUser['id'];
                // Increment reputation
                $pdo->prepare("UPDATE users SET reputation_points = reputation_points + 2 WHERE id = :id")->execute([':id' => $userId]);
            } else {
                $insUser = $pdo->prepare("INSERT INTO users (name, phone, reputation_points) VALUES (:name, :phone, 12)");
                $insUser->execute([':name' => $name, ':phone' => $phone]);
                $userId = (int)$pdo->lastInsertId();
            }
        }

        $insertSql = "
            INSERT INTO crowdsourced_fares
            (user_id, vehicle_type, origin_name, origin_lat, origin_lng, dest_name, dest_lat, dest_lng, api_distance_km, fare_paid_bdt, weather_condition, traffic_condition, is_peak_hour, upvotes, status)
            VALUES
            (:user_id, :vehicle, :orig_name, :orig_lat, :orig_lng, :dest_name, :dest_lat, :dest_lng, :dist, :fare, :weather, :traffic, :peak, 1, 'approved')
        ";

        $insStmt = $pdo->prepare($insertSql);
        $insStmt->execute([
            ':user_id' => $userId,
            ':vehicle' => $vehicle,
            ':orig_name' => $originName,
            ':orig_lat' => $originLat,
            ':orig_lng' => $originLng,
            ':dest_name' => $destName,
            ':dest_lat' => $destLat,
            ':dest_lng' => $destLng,
            ':dist' => $apiDistanceKm,
            ':fare' => $farePaid,
            ':weather' => in_array($weather, ['clear', 'rain', 'waterlogged', 'extreme_heat'], true) ? $weather : 'clear',
            ':traffic' => in_array($traffic, ['normal', 'moderate', 'heavy_jam'], true) ? $traffic : 'normal',
            ':peak' => $isPeak,
        ]);

        $fareId = (int)$pdo->lastInsertId();

        echo json_encode([
            'status' => 'success',
            'message' => 'Thank you! Your fare data has been successfully recorded for the Dhaka commuter community.',
            'data' => [
                'id' => $fareId,
                'fare_paid_bdt' => $farePaid,
                'vehicle_type' => $vehicle,
                'distance_km' => $apiDistanceKm,
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);

} catch (Throwable $e) {
    http_response_code(500);
    $config = require __DIR__ . '/../../config/config.php';
    $message = ($config['app']['debug'] ?? false) ? $e->getMessage() : 'Internal server error. Please try again.';
    error_log('Crowdsourced API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => $message]);
}
