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

use MongoDB\BSON\Regex;

// Rate limiting: 60 requests per minute per IP for place searches
RateLimiter::enforce('places', 60, 60);

try {
    $q = trim((string)($_GET['q'] ?? ''));
    $db = Database::getConnection();
    $nodesCol = $db->selectCollection('popular_nodes');

    $results = [];
    $seenCoords = [];
    $seenNames = [];

    // Helper to add unique place to results with name disambiguation and deduplication
    $addResult = function (array $place) use (&$results, &$seenCoords, &$seenNames) {
        $rawName = trim((string)($place['name_en'] ?? ''));
        if ($rawName === '') return;

        $normName = mb_strtolower($rawName);
        $lat = (float)($place['latitude'] ?? 0);
        $lng = (float)($place['longitude'] ?? 0);

        // If another item already has the exact same name
        if (isset($seenNames[$normName])) {
            $existing = &$seenNames[$normName];
            $distKm = 6371 * acos(
                min(1.0, max(-1.0,
                    sin(deg2rad($lat)) * sin(deg2rad((float)$existing['latitude'])) +
                    cos(deg2rad($lat)) * cos(deg2rad((float)$existing['latitude'])) *
                    cos(deg2rad($lng - (float)$existing['longitude']))
                ))
            );

            // If within 2.5 km of each other and have identical names, skip duplicate
            if ($distKm < 2.5) {
                if (($place['source'] ?? '') === 'local' && ($existing['source'] ?? '') !== 'local') {
                    $existing = $place;
                }
                return;
            }

            $area = trim((string)($place['area_thana'] ?? ''));
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

    $formatLocalNode = static function (array $doc): array {
        $coords = $doc['location']['coordinates'] ?? null;
        $lat = (float)($doc['latitude'] ?? ($coords[1] ?? 0));
        $lng = (float)($doc['longitude'] ?? ($coords[0] ?? 0));
        return [
            'id' => $doc['id'] ?? $doc['_id'] ?? null,
            'name_en' => $doc['name_en'] ?? '',
            'name_bn' => $doc['name_bn'] ?? '',
            'area_thana' => $doc['area_thana'] ?? '',
            'latitude' => $lat,
            'longitude' => $lng,
            'node_type' => $doc['node_type'] ?? 'general',
            'source' => 'local',
        ];
    };

    // -----------------------------------------------------------------
    // 1. SEARCH LOCAL DATABASE (popular_nodes)
    // -----------------------------------------------------------------
    if ($q !== '') {
        $tokens = array_filter(explode(' ', $q), fn($t) => mb_strlen($t) >= 2);
        $regex = new Regex(preg_quote($q, '/'), 'i');

        $rows = $nodesCol->find(
            [
                '$or' => [
                    ['name_en' => $regex],
                    ['name_bn' => $regex],
                    ['area_thana' => $regex],
                ]
            ],
            ['limit' => 50]
        );

        $qLower = mb_strtolower($q);
        usort($rows, static function (array $a, array $b) use ($qLower): int {
            $rank = static function (array $row) use ($qLower): int {
                $nameLower = mb_strtolower((string)($row['name_en'] ?? ''));
                $areaLower = mb_strtolower((string)($row['area_thana'] ?? ''));
                if ($nameLower === $qLower) return 1;
                if (str_starts_with($nameLower, $qLower)) return 2;
                if (str_starts_with($areaLower, $qLower)) return 3;
                return 4;
            };
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra !== $rb) return $ra <=> $rb;
            return strcmp((string)($a['name_en'] ?? ''), (string)($b['name_en'] ?? ''));
        });

        foreach (array_slice($rows, 0, 15) as $row) {
            $addResult($formatLocalNode($row));
        }

        // If fewer than 5 results and multiple words, try matching individual tokens
        if (count($results) < 5 && count($tokens) > 1) {
            $orClauses = [];
            foreach ($tokens as $token) {
                $tRegex = new Regex(preg_quote($token, '/'), 'i');
                $orClauses[] = ['name_en' => $tRegex];
                $orClauses[] = ['name_bn' => $tRegex];
                $orClauses[] = ['area_thana' => $tRegex];
            }

            $tokenRows = $nodesCol->find(['$or' => $orClauses], ['limit' => 10]);
            foreach ($tokenRows as $r) {
                $addResult($formatLocalNode($r));
            }
        }
    } else {
        // Empty query: Return default top major Dhaka hubs
        $rows = $nodesCol->find([], ['sort' => ['id' => 1], 'limit' => 10]);
        foreach ($rows as $row) {
            $addResult($formatLocalNode($row));
        }
    }

    // -----------------------------------------------------------------
    // 2. OPENSTREETMAP NOMINATIM DHAKA GEOCODER FALLBACK
    // -----------------------------------------------------------------
    if ($q !== '' && count($results) < 5) {
        try {
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
                    foreach ($osmData as $item) {
                        $lat = (float)($item['lat'] ?? 0);
                        $lon = (float)($item['lon'] ?? 0);

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

                        // Cache in MongoDB silently for future instant lookups
                        try {
                            if (!$nodesCol->findOne(['name_en' => $shortName])) {
                                $nodesCol->insertOne([
                                    'id' => Database::nextSequence('popular_nodes'),
                                    'name_en' => $shortName,
                                    'name_bn' => $placeItem['name_bn'],
                                    'area_thana' => $area,
                                    'barikoi_place_id' => 'OSM-' . ($item['place_id'] ?? rand(100, 999)),
                                    'latitude' => $lat,
                                    'longitude' => $lon,
                                    'location' => [
                                        'type' => 'Point',
                                        'coordinates' => [$lon, $lat],
                                    ],
                                    'node_type' => 'general',
                                ]);
                            }
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
