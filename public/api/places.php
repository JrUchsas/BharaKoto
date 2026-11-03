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
    $q = trim((string)($_GET['q'] ?? ''));
    $pdo = Database::getConnection();

    $results = [];
    $seenCoords = [];
    $seenNames = [];

    // Helper to add unique place to results with name disambiguation and deduplication
    $addResult = function (array $place) use (&$results, &$seenCoords, &$seenNames) {
        $rawName = trim($place['name_en'] ?? '');
        if ($rawName === '') return;

        $normName = mb_strtolower($rawName);
        $lat = (float)($place['latitude'] ?? 0);
        $lng = (float)($place['longitude'] ?? 0);

        // If another item already has the exact same name
        if (isset($seenNames[$normName])) {
            $existing = &$seenNames[$normName];
            // Calculate distance to existing entry
            $distKm = 6371 * acos(
                min(1.0, max(-1.0,
                    sin(deg2rad($lat)) * sin(deg2rad((float)$existing['latitude'])) +
                    cos(deg2rad($lat)) * cos(deg2rad((float)$existing['latitude'])) *
                    cos(deg2rad($lng - (float)$existing['longitude']))
                ))
            );

            // If within 2.5 km of each other and have identical names, skip duplicate
            if ($distKm < 2.5) {
                // If this is a local curated landmark and the existing is OSM, prefer local
                if (($place['source'] ?? '') === 'local' && ($existing['source'] ?? '') !== 'local') {
                    $existing = $place;
                }
                return;
            }

            // If farther away, disambiguate name by adding area or sub-district
            $area = trim($place['area_thana'] ?? '');
            if ($area !== '' && !str_contains($rawName, $area)) {
                $place['name_en'] = "{$rawName} ({$area})";
            } elseif (!empty($place['node_type']) && $place['node_type'] !== 'general') {
                $place['name_en'] = "{$rawName} [{$place['node_type']}]";
            }
            $normName = mb_strtolower($place['name_en']);
        }

        $coordKey = round($lat, 4) . '|' . round($lng, 4);
        if (!isset($seenCoords[$coordKey])) {
            $seenCoords[$coordKey] = true;
            $seenNames[$normName] = &$place;
            $results[] = $place;
        }
    };

    // -----------------------------------------------------------------
    // 1. SEARCH LOCAL DATABASE (popular_nodes)
    // -----------------------------------------------------------------
    if ($q !== '') {
        // Multi-token fuzzy matching
        $tokens = array_filter(explode(' ', $q), fn($t) => mb_strlen($t) >= 2);
        
        // Exact / prefix matching query first
        $exactSql = "
            SELECT id, name_en, name_bn, area_thana, latitude, longitude, node_type, 'local' as source
            FROM popular_nodes
            WHERE name_en LIKE :q_like1
               OR name_bn LIKE :q_like2
               OR area_thana LIKE :q_like3
            ORDER BY 
               CASE 
                   WHEN LOWER(name_en) = LOWER(:q_exact) THEN 1
                   WHEN LOWER(name_en) LIKE LOWER(:q_prefix1) THEN 2
                   WHEN LOWER(area_thana) LIKE LOWER(:q_prefix2) THEN 3
                   ELSE 4
               END,
               name_en ASC
            LIMIT 15
        ";
        
        $stmt = $pdo->prepare($exactSql);
        $stmt->execute([
            ':q_like1' => "%{$q}%",
            ':q_like2' => "%{$q}%",
            ':q_like3' => "%{$q}%",
            ':q_exact' => $q,
            ':q_prefix1' => "{$q}%",
            ':q_prefix2' => "{$q}%"
        ]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $addResult($row);
        }

        // If fewer than 5 results and multiple words, try matching individual tokens
        if (count($results) < 5 && count($tokens) > 1) {
            $tokenClauses = [];
            $tokenParams = [];
            $idx = 0;
            foreach ($tokens as $token) {
                $p = ":t_{$idx}";
                $tokenClauses[] = "(name_en LIKE {$p} OR name_bn LIKE {$p} OR area_thana LIKE {$p})";
                $tokenParams[$p] = "%{$token}%";
                $idx++;
            }

            $tokenSql = "
                SELECT id, name_en, name_bn, area_thana, latitude, longitude, node_type, 'local' as source
                FROM popular_nodes
                WHERE " . implode(' OR ', $tokenClauses) . "
                LIMIT 10
            ";
            $tokenStmt = $pdo->prepare($tokenSql);
            $tokenStmt->execute($tokenParams);
            $tokenRows = $tokenStmt->fetchAll();
            foreach ($tokenRows as $r) {
                $addResult($r);
            }
        }
    } else {
        // Empty query: Return default top major Dhaka hubs
        $stmt = $pdo->query("
            SELECT id, name_en, name_bn, area_thana, latitude, longitude, node_type, 'local' as source
            FROM popular_nodes
            ORDER BY id ASC
            LIMIT 10
        ");
        foreach ($stmt->fetchAll() as $row) {
            $addResult($row);
        }
    }

    // -----------------------------------------------------------------
    // 2. OPENSTREETMAP NOMINATIM DHAKA GEOCODER FALLBACK
    // If local results are fewer than 4 and query is not empty, query OSM
    // -----------------------------------------------------------------
    if ($q !== '' && count($results) < 5) {
        try {
            // Search Dhaka bounded box (minLng: 90.20, minLat: 23.60, maxLng: 90.60, maxLat: 23.95)
            $encodedQuery = urlencode("{$q}, Dhaka, Bangladesh");
            $osmUrl = "https://nominatim.openstreetmap.org/search?q={$encodedQuery}&format=json&addressdetails=1&countrycodes=bd&limit=6";

            $ch = curl_init($osmUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_USERAGENT => 'BharaKoto-Dhaka-FareEstimator/1.0 (contact@bharakoto.bd)'
            ]);
            $osmResp = curl_exec($ch);
            $osmCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($osmCode === 200 && is_string($osmResp)) {
                $osmData = json_decode($osmResp, true);
                if (is_array($osmData) && !empty($osmData)) {
                    $cacheStmt = $pdo->prepare("
                        INSERT INTO popular_nodes (name_en, name_bn, area_thana, latitude, longitude, node_type, barikoi_place_id)
                        SELECT :name, :name_bn, :area, :lat, :lng, 'general', :osm_id
                        WHERE NOT EXISTS (
                            SELECT 1 FROM popular_nodes WHERE name_en = :check_name
                        )
                    ");

                    foreach ($osmData as $item) {
                        $lat = (float)($item['lat'] ?? 0);
                        $lon = (float)($item['lon'] ?? 0);
                        
                        // Sanity check coordinates for Dhaka region (23.5 to 24.1, 90.1 to 90.7)
                        if ($lat < 23.5 || $lat > 24.1 || $lon < 90.1 || $lon > 90.7) {
                            continue;
                        }

                        $displayName = $item['display_name'] ?? 'Dhaka Place';
                        $parts = explode(',', $displayName);
                        $shortName = trim($parts[0] ?? $displayName);
                        if (isset($parts[1]) && !is_numeric(trim($parts[1])) && strlen(trim($parts[1])) > 2) {
                            $shortName .= ', ' . trim($parts[1]);
                        }

                        $type = $item['type'] ?? '';
                        $class = $item['class'] ?? '';
                        if ($class === 'highway' || $type === 'unclassified') {
                            $shortName .= ' (সড়ক / Road)';
                        } elseif ($type === 'administrative' || $class === 'boundary') {
                            $shortName .= ' (থানা এলাকা / Thana Area)';
                        }
                        
                        $area = trim($item['address']['suburb'] ?? $item['address']['neighbourhood'] ?? $item['address']['city_district'] ?? $parts[1] ?? 'Dhaka');
                        
                        $placeItem = [
                            'id' => 'osm_' . ($item['place_id'] ?? uniqid()),
                            'name_en' => $shortName,
                            'name_bn' => $item['namedetails']['name:bn'] ?? '',
                            'area_thana' => $area,
                            'latitude' => $lat,
                            'longitude' => $lon,
                            'node_type' => $class === 'highway' ? 'intersection' : 'general',
                            'source' => 'OpenStreetMap'
                        ];
                        
                        $addResult($placeItem);

                        // Cache in database silently for future instant lookups
                        try {
                            $cacheStmt->execute([
                                ':name' => $shortName,
                                ':name_bn' => $placeItem['name_bn'],
                                ':area' => $area,
                                ':lat' => $lat,
                                ':lng' => $lon,
                                ':osm_id' => 'OSM-' . ($item['place_id'] ?? rand(100, 999)),
                                ':check_name' => $shortName
                            ]);
                        } catch (Throwable) {}
                    }
                }
            }
        } catch (Throwable) {
            // Silently fallback if offline
        }
    }

    // -----------------------------------------------------------------
    // 3. BARIKOI PLACES API PROXY (If key configured)
    // -----------------------------------------------------------------
    $config = require __DIR__ . '/../../config/config.php';
    $barikoiKey = $config['apis']['barikoi_key'] ?? '';

    if (!empty($barikoiKey) && $q !== '' && count($results) < 8) {
        try {
            $encodedQ = urlencode($q);
            $url = "https://barikoi.openapi.bka.sh/v1/api/search/autocomplete/{$barikoiKey}/place?q={$encodedQ}&city=dhaka";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_USERAGENT => 'BharaKoto-App'
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && is_string($response)) {
                $barikoiData = json_decode($response, true);
                if (isset($barikoiData['places']) && is_array($barikoiData['places'])) {
                    foreach ($barikoiData['places'] as $place) {
                        $addResult([
                            'id' => 'barikoi_' . ($place['id'] ?? uniqid()),
                            'name_en' => $place['name'] ?? $place['address'] ?? 'Dhaka Location',
                            'name_bn' => $place['name_bn'] ?? '',
                            'area_thana' => $place['area'] ?? 'Dhaka',
                            'latitude' => (float)($place['latitude'] ?? 0),
                            'longitude' => (float)($place['longitude'] ?? 0),
                            'node_type' => 'general',
                            'source' => 'Barikoi'
                        ]);
                    }
                }
            }
        } catch (Throwable) {
            // Keep existing results
        }
    }

    echo json_encode([
        'status' => 'success',
        'query' => $q,
        'count' => count($results),
        'data' => $results
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
