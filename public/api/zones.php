<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/CsrfProtection.php';
require_once __DIR__ . '/../../src/RateLimiter.php';

use MongoDB\BSON\Regex;

// Rate limiting: 30 requests per minute per IP for zone management
RateLimiter::enforce('zones', 30, 60);

try {
    $db = Database::getConnection();
    $zonesCol = $db->selectCollection('restricted_zones');

    // ----------------------------------------------------
    // GET: List zones
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $includeInactive = isset($_GET['all']) && (int)$_GET['all'] === 1;
        $filter = $includeInactive ? [] : ['is_active' => true];
        $rows = $zonesCol->find($filter, ['sort' => ['id' => -1]]);

        $zones = [];
        foreach ($rows as $row) {
            $polygon = $row['polygon_geojson'] ?? null;
            if (is_string($polygon)) {
                $polygon = json_decode($polygon, true);
            }

            $vehicles = $row['restricted_vehicles'] ?? [];
            if (is_string($vehicles)) {
                $vehiclesList = array_values(array_filter(array_map('trim', explode(',', $vehicles))));
            } else {
                $vehiclesList = array_values((array)$vehicles);
            }

            $zones[] = [
                'id' => (int)($row['id'] ?? 0),
                'zone_name' => $row['zone_name'] ?? '',
                'city' => $row['city'] ?? 'Dhaka',
                'restricted_vehicles' => implode(',', $vehiclesList),
                'restricted_vehicles_list' => $vehiclesList,
                'restriction_type' => $row['restriction_type'] ?? 'vip_road_ban',
                'active_start_time' => $row['active_start_time'] ?? null,
                'active_end_time' => $row['active_end_time'] ?? null,
                'polygon_geojson' => $polygon,
                'transfer_hint' => $row['transfer_hint'] ?? null,
                'is_active' => !empty($row['is_active']) ? 1 : 0,
            ];
        }

        echo json_encode([
            'status' => 'success',
            'count' => count($zones),
            'data' => $zones
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ----------------------------------------------------
    // POST: Create or Toggle Zone
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CsrfProtection::requireValid();

        $raw = file_get_contents('php://input');
        $data = !empty($raw) ? json_decode($raw, true) : $_POST;

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
            exit;
        }

        // Action: toggle active status
        if (isset($data['action']) && $data['action'] === 'toggle') {
            $zoneId = (int)($data['id'] ?? 0);
            if ($zoneId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Valid zone ID is required for toggle.']);
                exit;
            }
            $existing = $zonesCol->findOne(['id' => $zoneId]);
            if ($existing) {
                $newStatus = empty($existing['is_active']);
                $zonesCol->updateOne(['id' => $zoneId], ['$set' => ['is_active' => $newStatus]]);
            }

            echo json_encode(['status' => 'success', 'message' => 'Zone status updated successfully.']);
            exit;
        }

        // Deactivate all bans
        if (isset($data['action']) && $data['action'] === 'deactivate_all') {
            $zonesCol->updateMany([], ['$set' => ['is_active' => false]]);
            echo json_encode(['status' => 'success', 'message' => 'All VIP bans have been cleared and deactivated.']);
            exit;
        }

        // Delete a single restricted zone entry
        if (isset($data['action']) && $data['action'] === 'delete') {
            $zoneId = (int)($data['id'] ?? 0);
            if ($zoneId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Valid zone ID is required for delete.']);
                exit;
            }
            $zonesCol->deleteOne(['id' => $zoneId]);

            echo json_encode(['status' => 'success', 'message' => 'Restricted zone entry deleted permanently.']);
            exit;
        }

        // Delete all inactive / disabled ban entries
        if (isset($data['action']) && $data['action'] === 'delete_inactive') {
            $deletedCount = $zonesCol->deleteMany(['is_active' => false]);
            echo json_encode(['status' => 'success', 'message' => "Successfully deleted {$deletedCount} inactive ban entries."]);
            exit;
        }

        // Restore official DMP bans
        if (isset($data['action']) && $data['action'] === 'restore_official') {
            $zonesCol->updateMany(
                [
                    '$or' => [
                        ['zone_name' => new Regex('Mirpur Road Corridor', 'i')],
                        ['zone_name' => new Regex('VIP Road / Kazi Nazrul Islam', 'i')],
                        ['zone_name' => new Regex('Elephant Road', 'i')],
                        ['zone_name' => new Regex('Moghbazar-Mouchak', 'i')],
                    ]
                ],
                ['$set' => ['is_active' => true]]
            );
            echo json_encode(['status' => 'success', 'message' => 'Official DMP VIP bans restored and activated.']);
            exit;
        }

        // Create multiple precision corridors from OSRM (upsert by zone_name to prevent duplicates)
        if (isset($data['action']) && $data['action'] === 'create_multiple') {
            $corridors = $data['corridors'] ?? [];
            if (!is_array($corridors) || empty($corridors)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'No corridors provided.']);
                exit;
            }

            $processed = 0;
            foreach ($corridors as $corridor) {
                $name = trim((string)($corridor['name'] ?? ''));
                if ($name === '') continue;

                $vehiclesRaw = $corridor['vehicles'] ?? 'pedal_rickshaw,battery_rickshaw';
                $vehiclesArr = is_array($vehiclesRaw)
                    ? array_values(array_filter(array_map('trim', $vehiclesRaw)))
                    : array_values(array_filter(array_map('trim', explode(',', (string)$vehiclesRaw))));

                $type = trim((string)($corridor['type'] ?? 'vip_road_ban'));
                $startTime = $corridor['start_time'] ?? null;
                $endTime = $corridor['end_time'] ?? null;
                $hint = trim((string)($corridor['hint'] ?? ''));
                $polygon = $corridor['polygon_geojson'] ?? null;

                if (!$polygon || !isset($polygon['type']) || $polygon['type'] !== 'Polygon') continue;

                $doc = [
                    'zone_name' => $name,
                    'city' => 'Dhaka',
                    'restricted_vehicles' => $vehiclesArr,
                    'restriction_type' => $type,
                    'active_start_time' => $startTime,
                    'active_end_time' => $endTime,
                    'polygon_geojson' => $polygon,
                    'transfer_hint' => $hint !== '' ? $hint : null,
                    'is_active' => true,
                ];

                $matches = $zonesCol->find(['zone_name' => $name], ['sort' => ['id' => -1]]);
                if (!empty($matches)) {
                    $keepId = (int)$matches[0]['id'];
                    $zonesCol->updateOne(['id' => $keepId], ['$set' => $doc]);
                    // Clean up any legacy duplicate records with the same zone_name
                    foreach (array_slice($matches, 1) as $dup) {
                        $zonesCol->deleteOne(['id' => (int)$dup['id']]);
                    }
                } else {
                    $doc['id'] = Database::nextSequence('restricted_zones');
                    $zonesCol->insertOne($doc);
                }
                $processed++;
            }

            echo json_encode([
                'status' => 'success',
                'message' => "Successfully loaded {$processed} precision DMP corridors with road-following geometries.",
                'count' => $processed
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        // Validation for new zone
        $zoneName = trim((string)($data['zone_name'] ?? ''));
        $city = trim((string)($data['city'] ?? 'Dhaka'));
        $restrictionType = trim((string)($data['restriction_type'] ?? 'vip_road_ban'));
        $transferHint = trim((string)($data['transfer_hint'] ?? ''));
        $startTime = !empty($data['active_start_time']) ? trim((string)$data['active_start_time']) : null;
        $endTime = !empty($data['active_end_time']) ? trim((string)$data['active_end_time']) : null;

        if ($zoneName === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Zone name is required.']);
            exit;
        }

        // Parse restricted vehicles
        $vehicles = $data['restricted_vehicles'] ?? [];
        if (is_string($vehicles)) {
            $vehicles = array_filter(array_map('trim', explode(',', $vehicles)));
        }
        $validVehicles = ['pedal_rickshaw', 'battery_rickshaw', 'cng', 'leguna'];
        $filteredVehicles = array_values(array_intersect((array)$vehicles, $validVehicles));
        if (empty($filteredVehicles)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Select at least one restricted vehicle.']);
            exit;
        }

        // Validate GeoJSON Polygon
        $polygon = $data['polygon_geojson'] ?? null;
        if (is_string($polygon)) {
            $polygon = json_decode($polygon, true);
        }

        if (!is_array($polygon) || ($polygon['type'] ?? '') !== 'Polygon' || empty($polygon['coordinates'][0])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'A valid GeoJSON Polygon with coordinates ring is required.']);
            exit;
        }

        $ring = $polygon['coordinates'][0];
        if (count($ring) < 4) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Polygon must contain at least 4 coordinate pairs.']);
            exit;
        }

        $first = $ring[0];
        $last = end($ring);
        if (abs($first[0] - $last[0]) > 0.000001 || abs($first[1] - $last[1]) > 0.000001) {
            $ring[] = $first;
            $polygon['coordinates'][0] = $ring;
        }

        foreach ($ring as $pt) {
            if (!is_numeric($pt[0]) || !is_numeric($pt[1])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Coordinate values must be numeric [lng, lat].']);
                exit;
            }
        }

        $newId = Database::nextSequence('restricted_zones');
        $zonesCol->insertOne([
            'id' => $newId,
            'zone_name' => $zoneName,
            'city' => $city,
            'restricted_vehicles' => $filteredVehicles,
            'restriction_type' => $restrictionType,
            'active_start_time' => $startTime,
            'active_end_time' => $endTime,
            'polygon_geojson' => $polygon,
            'transfer_hint' => $transferHint !== '' ? $transferHint : null,
            'is_active' => true,
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Restricted VIP zone successfully created and activated.',
            'data' => [
                'id' => $newId,
                'zone_name' => $zoneName,
                'restricted_vehicles' => $filteredVehicles,
                'polygon_geojson' => $polygon,
                'transfer_hint' => $transferHint
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ----------------------------------------------------
    // DELETE: Delete zone entry by ID
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        CsrfProtection::requireValid();

        $zoneId = (int)($_GET['id'] ?? 0);
        if ($zoneId <= 0) {
            $raw = file_get_contents('php://input');
            $data = !empty($raw) ? json_decode($raw, true) : [];
            $zoneId = (int)($data['id'] ?? 0);
        }
        if ($zoneId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Valid zone ID is required for deletion.']);
            exit;
        }
        $zonesCol->deleteOne(['id' => $zoneId]);

        echo json_encode(['status' => 'success', 'message' => 'Restricted zone entry deleted permanently.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);

} catch (Throwable $e) {
    http_response_code(500);
    $config = require __DIR__ . '/../../config/config.php';
    $message = ($config['app']['debug'] ?? false) ? $e->getMessage() : 'Internal server error. Please try again.';
    error_log('Zones API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => $message]);
}
