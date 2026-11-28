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
    $db = Database::getConnection();
    $faresCol = $db->selectCollection('crowdsourced_fares');
    $usersCol = $db->selectCollection('users');
    $votesCol = $db->selectCollection('fare_votes');

    // ----------------------------------------------------
    // GET: Retrieve Recent Commuter Submissions
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = min(50, max(5, (int)($_GET['limit'] ?? 15)));
        $vehicle = trim((string)($_GET['vehicle'] ?? ''));

        $filter = ['status' => 'approved'];
        if ($vehicle !== '') {
            $filter['vehicle_type'] = $vehicle;
        }

        $docs = $faresCol->find($filter, [
            'sort' => ['created_at' => -1],
            'limit' => $limit,
        ]);

        // Batch resolve commuter names from users collection
        $userIds = array_values(array_unique(array_filter(array_map(
            fn($d) => isset($d['user_id']) ? (int)$d['user_id'] : null,
            $docs
        ))));
        $userMap = [];
        if (!empty($userIds)) {
            foreach ($usersCol->find(['id' => ['$in' => $userIds]]) as $u) {
                $userMap[(int)$u['id']] = $u['name'] ?? 'Commuter';
            }
        }

        $rows = [];
        foreach ($docs as $doc) {
            $uid = isset($doc['user_id']) ? (int)$doc['user_id'] : null;
            $rows[] = [
                'id' => (int)($doc['id'] ?? 0),
                'vehicle_type' => $doc['vehicle_type'] ?? '',
                'origin_name' => $doc['origin_name'] ?? '',
                'origin_lat' => (float)($doc['origin_lat'] ?? 0),
                'origin_lng' => (float)($doc['origin_lng'] ?? 0),
                'dest_name' => $doc['dest_name'] ?? '',
                'dest_lat' => (float)($doc['dest_lat'] ?? 0),
                'dest_lng' => (float)($doc['dest_lng'] ?? 0),
                'api_distance_km' => (float)($doc['api_distance_km'] ?? 0),
                'fare_paid_bdt' => (float)($doc['fare_paid_bdt'] ?? 0),
                'weather_condition' => $doc['weather_condition'] ?? 'clear',
                'traffic_condition' => $doc['traffic_condition'] ?? 'normal',
                'is_peak_hour' => !empty($doc['is_peak_hour']) ? 1 : 0,
                'upvotes' => (int)($doc['upvotes'] ?? 0),
                'downvotes' => (int)($doc['downvotes'] ?? 0),
                'created_at' => $doc['created_at'] ?? null,
                'commuter_name' => ($uid !== null && isset($userMap[$uid]))
                    ? $userMap[$uid]
                    : ($doc['commuter_name'] ?? 'Commuter'),
            ];
        }

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
            $userId = (int)($data['user_id'] ?? 1);
            $voteType = trim((string)($data['vote_type'] ?? 'accurate'));

            if ($fareId <= 0 || !in_array($voteType, ['accurate', 'overpaid', 'too_low_fake'], true)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid vote parameters.']);
                exit;
            }

            $existingVote = $votesCol->findOne(['fare_id' => $fareId, 'user_id' => $userId]);
            if ($existingVote) {
                $votesCol->updateOne(
                    ['fare_id' => $fareId, 'user_id' => $userId],
                    ['$set' => ['vote_type' => $voteType, 'created_at' => MongoDatabase::utcDateTime()]]
                );
            } else {
                $votesCol->insertOne([
                    'id' => Database::nextSequence('fare_votes'),
                    'fare_id' => $fareId,
                    'user_id' => $userId,
                    'vote_type' => $voteType,
                    'created_at' => MongoDatabase::utcDateTime(),
                ]);
            }

            $upvotes = $votesCol->countDocuments(['fare_id' => $fareId, 'vote_type' => 'accurate']);
            $downvotes = $votesCol->countDocuments(['fare_id' => $fareId, 'vote_type' => ['$ne' => 'accurate']]);

            $faresCol->updateOne(
                ['id' => $fareId],
                ['$set' => ['upvotes' => $upvotes, 'downvotes' => $downvotes]]
            );

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
        $isPeak  = !empty($data['is_peak_hour']);

        $phone = trim((string)($data['phone'] ?? ''));
        $name  = trim((string)($data['name'] ?? 'Anonymous Commuter'));

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

        $service = new FareEstimatorService($db);
        $straightKm = $service->calculateHaversineKm($originLat, $originLng, $destLat, $destLng);
        $apiDistanceKm = round($straightKm * 1.30, 2);

        $userId = null;
        if ($phone !== '') {
            $existingUser = $usersCol->findOne(['phone' => $phone]);
            if ($existingUser) {
                $userId = (int)$existingUser['id'];
                $usersCol->updateOne(['id' => $userId], ['$inc' => ['reputation_points' => 2]]);
            } else {
                $userId = Database::nextSequence('users');
                $usersCol->insertOne([
                    'id' => $userId,
                    'name' => $name,
                    'phone' => $phone,
                    'reputation_points' => 12,
                    'is_blocked' => false,
                    'created_at' => MongoDatabase::utcDateTime(),
                ]);
            }
        }

        $fareId = Database::nextSequence('crowdsourced_fares');
        $faresCol->insertOne([
            'id' => $fareId,
            'user_id' => $userId,
            'commuter_name' => $userId !== null ? $name : 'Commuter',
            'vehicle_type' => $vehicle,
            'origin_name' => $originName,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'origin_location' => [
                'type' => 'Point',
                'coordinates' => [$originLng, $originLat],
            ],
            'dest_name' => $destName,
            'dest_lat' => $destLat,
            'dest_lng' => $destLng,
            'dest_location' => [
                'type' => 'Point',
                'coordinates' => [$destLng, $destLat],
            ],
            'api_distance_km' => $apiDistanceKm,
            'fare_paid_bdt' => $farePaid,
            'weather_condition' => in_array($weather, ['clear', 'rain', 'waterlogged', 'extreme_heat'], true) ? $weather : 'clear',
            'traffic_condition' => in_array($traffic, ['normal', 'moderate', 'heavy_jam'], true) ? $traffic : 'normal',
            'is_peak_hour' => $isPeak,
            'upvotes' => 1,
            'downvotes' => 0,
            'status' => 'approved',
            'created_at' => MongoDatabase::utcDateTime(),
        ]);

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
