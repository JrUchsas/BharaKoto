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

try {
    $pdo = Database::getConnection();

    // ----------------------------------------------------
    // GET: List zones
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $includeInactive = isset($_GET['all']) && (int)$_GET['all'] === 1;
        $sql = "SELECT * FROM `restricted_zones` " . ($includeInactive ? "" : "WHERE `is_active` = 1 ") . "ORDER BY `id` DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        $zones = [];
        foreach ($rows as $row) {
            $row['polygon_geojson'] = json_decode($row['polygon_geojson'], true);
            $row['restricted_vehicles_list'] = explode(',', $row['restricted_vehicles']);
            $zones[] = $row;
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

        // Check for toggle action
        if (isset($data['action']) && $data['action'] === 'toggle') {
            $zoneId = (int)($data['id'] ?? 0);
            if ($zoneId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Valid zone ID is required for toggle.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE `restricted_zones` SET `is_active` = NOT `is_active` WHERE `id` = :id");
            $stmt->execute([':id' => $zoneId]);

            echo json_encode(['status' => 'success', 'message' => 'Zone status updated successfully.']);
            exit;
        }

        // Deactivate all bans
        if (isset($data['action']) && $data['action'] === 'deactivate_all') {
            $pdo->exec("UPDATE `restricted_zones` SET `is_active` = 0");
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
            $stmt = $pdo->prepare("DELETE FROM `restricted_zones` WHERE `id` = :id");
            $stmt->execute([':id' => $zoneId]);

            echo json_encode(['status' => 'success', 'message' => 'Restricted zone entry deleted permanently.']);
            exit;
        }

        // Delete all inactive / disabled ban entries
        if (isset($data['action']) && $data['action'] === 'delete_inactive') {
            $deletedCount = (int)$pdo->exec("DELETE FROM `restricted_zones` WHERE `is_active` = 0");
            echo json_encode(['status' => 'success', 'message' => "Successfully deleted {$deletedCount} inactive ban entries."]);
            exit;
        }

        // Restore official DMP bans
        if (isset($data['action']) && $data['action'] === 'restore_official') {
            $pdo->exec("UPDATE `restricted_zones` SET `is_active` = 1 WHERE `zone_name` LIKE '%Mirpur Road Corridor%' OR `zone_name` LIKE '%VIP Road / Kazi Nazrul Islam%' OR `zone_name` LIKE '%Elephant Road%' OR `zone_name` LIKE '%Moghbazar-Mouchak%'");
            echo json_encode(['status' => 'success', 'message' => 'Official DMP VIP bans restored and activated.']);
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
        $filteredVehicles = array_intersect($vehicles, $validVehicles);
        if (empty($filteredVehicles)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Select at least one restricted vehicle.']);
            exit;
        }
        $vehiclesSet = implode(',', $filteredVehicles);

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

        // Check if ring is closed (first coord equals last coord)
        $first = $ring[0];
        $last = end($ring);
        if (abs($first[0] - $last[0]) > 0.000001 || abs($first[1] - $last[1]) > 0.000001) {
            // Automatically close the ring
            $ring[] = $first;
            $polygon['coordinates'][0] = $ring;
        }

        // Validate coordinates are within greater Dhaka bounding box roughly (23.5 - 24.1, 90.1 - 90.7)
        foreach ($ring as $pt) {
            if (!is_numeric($pt[0]) || !is_numeric($pt[1])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Coordinate values must be numeric [lng, lat].']);
                exit;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO `restricted_zones` 
            (`zone_name`, `city`, `restricted_vehicles`, `restriction_type`, `active_start_time`, `active_end_time`, `polygon_geojson`, `transfer_hint`, `is_active`)
            VALUES
            (:name, :city, :vehicles, :type, :start_t, :end_t, :geojson, :hint, 1)
        ");

        $stmt->execute([
            ':name' => $zoneName,
            ':city' => $city,
            ':vehicles' => $vehiclesSet,
            ':type' => $restrictionType,
            ':start_t' => $startTime,
            ':end_t' => $endTime,
            ':geojson' => json_encode($polygon, JSON_UNESCAPED_SLASHES),
            ':hint' => $transferHint !== '' ? $transferHint : null,
        ]);

        $newId = (int)$pdo->lastInsertId();

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
        $stmt = $pdo->prepare("DELETE FROM `restricted_zones` WHERE `id` = :id");
        $stmt->execute([':id' => $zoneId]);

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
