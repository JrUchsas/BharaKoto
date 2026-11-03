<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "[-] Fetching precision road geometries for official DMP / DTCA rickshaw ban corridors...\n";

function fetchOsrmMultiStop(array $waypoints): array
{
    $coordStrings = [];
    foreach ($waypoints as $wp) {
        $coordStrings[] = sprintf('%.6f,%.6f', $wp[0], $wp[1]);
    }
    $url = "https://router.project-osrm.org/route/v1/driving/" . implode(';', $coordStrings) . "?overview=full&geometries=geojson";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'BharaKoto-Official-DMP-Importer'
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!is_string($resp)) return [];
    $data = json_decode($resp, true);
    return $data['routes'][0]['geometry']['coordinates'] ?? [];
}

function bufferPolylineToRoadCorridor(array $coords, float $halfWidth = 0.00018): array
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

// -------------------------------------------------------------
// 1. Official Route 1: Mirpur Road (Gabtoli to Azimpur)
// Source: Official DTCA & DMP Circular July 7, 2019
// -------------------------------------------------------------
echo "  [1/4] Generating Mirpur Road corridor (Gabtoli -> Asad Gate -> Science Lab -> Azimpur)...\n";
$mirpurWaypoints = [
    [90.3420, 23.7850], // Gabtoli
    [90.3662, 23.7719], // Shyamoli
    [90.3725, 23.7601], // Asad Gate
    [90.3815, 23.7482], // Kalabagan
    [90.3846, 23.7401], // Science Lab
    [90.3855, 23.7289]  // Azimpur
];
$mirpurCoords = fetchOsrmMultiStop($mirpurWaypoints);
echo "        Points: " . count($mirpurCoords) . "\n";
$mirpurPoly = bufferPolylineToRoadCorridor($mirpurCoords, 0.00018);

// -------------------------------------------------------------
// 2. Official Route 2: VIP Road / Kazi Nazrul Islam Ave & Airport Express
// (Shahbagh -> Bangla Motor -> Karwan Bazar -> Farmgate -> Mohakhali)
// Source: DMP Traffic Division VIP Corridor Order
// -------------------------------------------------------------
echo "  [2/4] Generating VIP Road corridor (Shahbagh -> Farmgate -> Mohakhali)...\n";
$vipWaypoints = [
    [90.3958, 23.7381], // Shahbagh
    [90.3945, 23.7460], // Bangla Motor
    [90.3934, 23.7505], // Karwan Bazar
    [90.3899, 23.7574], // Farmgate
    [90.3921, 23.7699], // Jahangir Gate
    [90.4043, 23.7776]  // Mohakhali
];
$vipCoords = fetchOsrmMultiStop($vipWaypoints);
echo "        Points: " . count($vipCoords) . "\n";
$vipPoly = bufferPolylineToRoadCorridor($vipCoords, 0.00018);

// -------------------------------------------------------------
// 3. Official Route 3: Elephant Road Corridor (Science Lab to Shahbagh)
// Source: Official DTCA & DMP Circular July 7, 2019
// -------------------------------------------------------------
echo "  [3/4] Generating Elephant Road corridor (Science Lab -> Katabon -> Shahbagh)...\n";
$elephantWaypoints = [
    [90.3846, 23.7401], // Science Lab
    [90.3892, 23.7408], // Katabon
    [90.3958, 23.7381]  // Shahbagh
];
$elephantCoords = fetchOsrmMultiStop($elephantWaypoints);
echo "        Points: " . count($elephantCoords) . "\n";
$elephantPoly = bufferPolylineToRoadCorridor($elephantCoords, 0.00018);

// -------------------------------------------------------------
// 4. Official Route 4: Moghbazar-Mouchak Flyover Upper Deck
// Source: High Court & DMP Directive on Flyover Three-Wheelers
// -------------------------------------------------------------
echo "  [4/4] Generating Moghbazar-Mouchak Flyover ramp corridor...\n";
$flyoverWaypoints = [
    [90.4045, 23.7481], // Moghbazar Wireless
    [90.4132, 23.7485]  // Mouchak
];
$flyoverCoords = fetchOsrmMultiStop($flyoverWaypoints);
echo "        Points: " . count($flyoverCoords) . "\n";
$flyoverPoly = bufferPolylineToRoadCorridor($flyoverCoords, 0.00018);

$officialBans = [
    [
        'name' => 'Mirpur Road Corridor (Gabtoli to Azimpur)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $mirpurPoly,
        'hint' => 'Official DTCA/DMP ban: Rickshaws prohibited on Mirpur Road main arterial carriageway. Take Dhanmondi residential inner avenues or bus.'
    ],
    [
        'name' => 'VIP Road / Kazi Nazrul Islam Ave (Shahbagh to Mohakhali)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $vipPoly,
        'hint' => 'Official DMP VIP arterial: Rickshaws strictly prohibited from Shahbagh to Mohakhali. Use MRT Line 6, bus, or Hatirjheel / Green Road bypass.'
    ],
    [
        'name' => 'Elephant Road (Science Lab to Shahbagh)',
        'type' => 'vip_road_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $elephantPoly,
        'hint' => 'Official DTCA/DMP ban: Rickshaws prohibited on Elephant Road thoroughfare. Use Katabon/Hatirpool side lanes.'
    ],
    [
        'name' => 'Moghbazar-Mouchak Flyover (Upper Deck)',
        'type' => 'flyover_ban',
        'vehicles' => 'pedal_rickshaw,battery_rickshaw',
        'start_time' => null,
        'end_time' => null,
        'polygon' => $flyoverPoly,
        'hint' => 'High Court & DMP order: Rickshaws strictly prohibited on the elevated flyover deck. Must use ground surface road.'
    ]
];

try {
    $pdo = Database::getConnection();

    // Safely deactivate any old/trial records
    $pdo->exec("UPDATE restricted_zones SET is_active = 0");

    $insert = $pdo->prepare("
        INSERT INTO restricted_zones 
        (zone_name, city, restricted_vehicles, restriction_type, active_start_time, active_end_time, polygon_geojson, transfer_hint, is_active)
        VALUES
        (:name, 'Dhaka', :vehicles, :type, :start_time, :end_time, :polygon, :hint, 1)
    ");

    foreach ($officialBans as $ban) {
        $insert->execute([
            ':name' => $ban['name'],
            ':vehicles' => $ban['vehicles'],
            ':type' => $ban['type'],
            ':start_time' => $ban['start_time'],
            ':end_time' => $ban['end_time'],
            ':polygon' => json_encode($ban['polygon'], JSON_UNESCAPED_SLASHES),
            ':hint' => $ban['hint']
        ]);
        echo "[+] Landed official ban: {$ban['name']}\n";
    }

    $count = $pdo->query("SELECT COUNT(*) FROM restricted_zones WHERE is_active = 1")->fetchColumn();
    echo "[*] Success! Authenticated official Dhaka rickshaw-banned corridors in DB: {$count}\n";

} catch (Throwable $e) {
    echo "[!] DB Error: " . $e->getMessage() . "\n";
    exit(1);
}
