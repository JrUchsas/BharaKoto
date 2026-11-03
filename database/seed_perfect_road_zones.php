<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function fetchOsrmRoute(float $startLng, float $startLat, float $endLng, float $endLat): array
{
    $url = "https://router.project-osrm.org/route/v1/driving/{$startLng},{$startLat};{$endLng},{$endLat}?overview=full&geometries=geojson";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'BharaKoto-Zone-Generator'
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!is_string($resp)) return [];
    $data = json_decode($resp, true);
    return $data['routes'][0]['geometry']['coordinates'] ?? [];
}

function bufferPolylineToPolygon(array $coords, float $halfWidth = 0.00018): array
{
    $n = count($coords);
    if ($n < 2) return [];

    $left = [];
    $right = [];

    for ($i = 0; $i < $n; $i++) {
        if ($i === 0) {
            $dx = $coords[1][0] - $coords[0][0];
            $dy = $coords[1][1] - $coords[0][1];
        } elseif ($i === $n - 1) {
            $dx = $coords[$n - 1][0] - $coords[$n - 2][0];
            $dy = $coords[$n - 1][1] - $coords[$n - 2][1];
        } else {
            $dx = $coords[$i + 1][0] - $coords[$i - 1][0];
            $dy = $coords[$i + 1][1] - $coords[$i - 1][1];
        }

        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 1e-9) {
            $nx = 0.0;
            $ny = $halfWidth;
        } else {
            $nx = -$dy / $len;
            $ny = $dx / $len;
        }

        $left[] = [
            round($coords[$i][0] + $nx * $halfWidth, 6),
            round($coords[$i][1] + $ny * $halfWidth, 6)
        ];
        $right[] = [
            round($coords[$i][0] - $nx * $halfWidth, 6),
            round($coords[$i][1] - $ny * $halfWidth, 6)
        ];
    }

    $ring = array_merge($left, array_reverse($right));
    $ring[] = $ring[0];

    return [
        'type' => 'Polygon',
        'coordinates' => [$ring]
    ];
}

echo "[-] Generating precision road corridors for Dhaka VIP bans...\n";

// 1. Begum Rokeya Sarani: Bijoy Sarani to Agargaon
echo "  -> Fetching Rokeya Sarani (Bijoy Sarani to Agargaon)...\n";
$rokeyaCoords = fetchOsrmRoute(90.3877, 23.7656, 90.3802, 23.7784);
$rokeyaPoly = bufferPolylineToPolygon($rokeyaCoords, 0.00018);

// 2. Kazi Nazrul Islam VIP Avenue: Shahbagh to Farmgate
echo "  -> Fetching VIP Avenue (Shahbagh to Farmgate)...\n";
$vipRoadCoords = fetchOsrmRoute(90.3958, 23.7381, 90.3899, 23.7574);
$vipRoadPoly = bufferPolylineToPolygon($vipRoadCoords, 0.00018);

// 3. Mirpur Road: Science Lab to Asad Gate
echo "  -> Fetching Mirpur Road (Science Lab to Asad Gate)...\n";
$mirpurRoadCoords = fetchOsrmRoute(90.3846, 23.7401, 90.3725, 23.7601);
$mirpurRoadPoly = bufferPolylineToPolygon($mirpurRoadCoords, 0.00018);

// 4. Moghbazar-Mouchak Flyover
echo "  -> Fetching Moghbazar-Mouchak Flyover...\n";
$moghbazarCoords = fetchOsrmRoute(90.4045, 23.7481, 90.4132, 23.7485);
$moghbazarPoly = bufferPolylineToPolygon($moghbazarCoords, 0.00018);

// 5. Rokeya Sarani Extension: Agargaon to Mirpur 10
echo "  -> Fetching Rokeya Sarani Extension (Agargaon to Mirpur 10)...\n";
$rokeyaExtCoords = fetchOsrmRoute(90.3802, 23.7784, 90.3687, 23.8069);
$rokeyaExtPoly = bufferPolylineToPolygon($rokeyaExtCoords, 0.00018);

$zonesToSeed = [
    [
        'id' => 1,
        'name' => 'Bijoy Sarani to Agargaon VIP Stretch (Rokeya Sarani)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $rokeyaPoly,
        'hint' => 'Rickshaws strictly banned on VIP Rokeya Sarani stretch (Bijoy Sarani to Agargaon). Commuters must switch to MRT Line 6, bus, or take alternate side lanes via Monipur/West Kafrul.'
    ],
    [
        'id' => 2,
        'name' => 'Moghbazar-Mouchak Flyover Upper Deck',
        'type' => 'flyover_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => null,
        'end_time' => null,
        'polygon' => $moghbazarPoly,
        'hint' => 'Rickshaws are prohibited on the upper deck of Moghbazar-Mouchak flyover. Commuters must use the surface level road.'
    ],
    [
        'id' => null,
        'name' => 'Kazi Nazrul Islam VIP Avenue (Shahbagh to Farmgate)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $vipRoadPoly,
        'hint' => 'Rickshaws strictly prohibited on Kazi Nazrul Islam VIP Avenue. Commuters can use metro/bus or take Green Road / Hatirjheel side roads.'
    ],
    [
        'id' => null,
        'name' => 'Mirpur Road VIP Corridor (Science Lab to Asad Gate)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $mirpurRoadPoly,
        'hint' => 'Rickshaws prohibited on Mirpur Road main arterial carriageway. Take Dhanmondi residential inner roads.'
    ],
    [
        'id' => null,
        'name' => 'Rokeya Sarani North Stretch (Agargaon to Mirpur 10)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $rokeyaExtPoly,
        'hint' => 'Rickshaws banned along the MRT viaduct expressway. Take Kazipara/Shewrapara side alleys or Metro.'
    ]
];

try {
    $pdo = Database::getConnection();

    // Deactivate scribbled test zones
    $pdo->exec("UPDATE restricted_zones SET is_active = 0 WHERE zone_name IN ('qaefae', 'badda')");

    $updateStmt = $pdo->prepare("
        UPDATE restricted_zones 
        SET zone_name = :name,
            restriction_type = :type,
            restricted_vehicles = :vehicles,
            active_start_time = :start_time,
            active_end_time = :end_time,
            polygon_geojson = :polygon,
            transfer_hint = :hint,
            is_active = 1
        WHERE id = :id
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO restricted_zones 
        (zone_name, city, restricted_vehicles, restriction_type, active_start_time, active_end_time, polygon_geojson, transfer_hint, is_active)
        VALUES
        (:name, 'Dhaka', :vehicles, :type, :start_time, :end_time, :polygon, :hint, 1)
    ");

    foreach ($zonesToSeed as $z) {
        $jsonStr = json_encode($z['polygon'], JSON_UNESCAPED_SLASHES);
        if ($z['id'] !== null) {
            $updateStmt->execute([
                ':name' => $z['name'],
                ':type' => $z['type'],
                ':vehicles' => $z['vehicles'],
                ':start_time' => $z['start_time'],
                ':end_time' => $z['end_time'],
                ':polygon' => $jsonStr,
                ':hint' => $z['hint'],
                ':id' => $z['id']
            ]);
            echo "[+] Updated zone ID {$z['id']}: {$z['name']}\n";
        } else {
            // Check if already exists by name
            $check = $pdo->prepare("SELECT id FROM restricted_zones WHERE zone_name = :name");
            $check->execute([':name' => $z['name']]);
            $existingId = $check->fetchColumn();

            if ($existingId) {
                $updateStmt->execute([
                    ':name' => $z['name'],
                    ':type' => $z['type'],
                    ':vehicles' => $z['vehicles'],
                    ':start_time' => $z['start_time'],
                    ':end_time' => $z['end_time'],
                    ':polygon' => $jsonStr,
                    ':hint' => $z['hint'],
                    ':id' => $existingId
                ]);
                echo "[+] Updated existing zone ID {$existingId}: {$z['name']}\n";
            } else {
                $insertStmt->execute([
                    ':name' => $z['name'],
                    ':type' => $z['type'],
                    ':vehicles' => $z['vehicles'],
                    ':start_time' => $z['start_time'],
                    ':end_time' => $z['end_time'],
                    ':polygon' => $jsonStr,
                    ':hint' => $z['hint']
                ]);
                echo "[+] Inserted new road zone: {$z['name']}\n";
            }
        }
    }

    $activeCount = $pdo->query("SELECT COUNT(*) FROM restricted_zones WHERE is_active = 1")->fetchColumn();
    echo "[*] Complete! Active road-following restricted zones in DB: {$activeCount}\n";

} catch (Throwable $e) {
    echo "[!] Error updating zones: " . $e->getMessage() . "\n";
    exit(1);
}
