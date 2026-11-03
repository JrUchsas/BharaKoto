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
require_once __DIR__ . '/../../src/RateLimiter.php';

// Rate limiting: 30 requests per minute per IP
RateLimiter::enforce('estimate', 30, 60);

try {
    // Parse input from JSON body or POST form data
    $input = [];
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $jsonDecoded = json_decode($rawBody, true);
        if (is_array($jsonDecoded)) {
            $input = $jsonDecoded;
        }
    }
    if (empty($input)) {
        $input = $_POST;
    }

    $originLat = filter_var($input['origin_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $originLng = filter_var($input['origin_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $destLat   = filter_var($input['dest_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $destLng   = filter_var($input['dest_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $vehicle   = trim((string)($input['vehicle_type'] ?? 'pedal_rickshaw'));

    $overridePeak = isset($input['is_peak_hour']) ? (bool)$input['is_peak_hour'] : null;
    $overrideRain = isset($input['is_raining']) ? (bool)$input['is_raining'] : null;

    if ($originLat === false || $originLng === false || $destLat === false || $destLng === false) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Valid origin (lat, lng) and destination (lat, lng) coordinates are required.'
        ]);
        exit;
    }

    $validVehicles = ['pedal_rickshaw', 'battery_rickshaw', 'cng', 'leguna'];
    if (!in_array($vehicle, $validVehicles, true)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => "Invalid vehicle_type. Must be one of: " . implode(', ', $validVehicles)
        ]);
        exit;
    }

    $service = new FareEstimatorService();
    $result = $service->estimate(
        $originLat,
        $originLng,
        $destLat,
        $destLng,
        $vehicle,
        $overridePeak,
        $overrideRain
    );

    echo json_encode([
        'status' => 'success',
        'data' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    // Sanitize error message - don't expose internal details
    $message = ($config['app']['debug'] ?? false) ? $e->getMessage() : 'Internal server error. Please try again.';
    error_log('Estimate API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
}
