<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
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

    if (!is_string($resp)) {
        return $waypoints;
    }
    $data = json_decode($resp, true);
    $coords = $data['routes'][0]['geometry']['coordinates'] ?? [];
    return count($coords) >= 2 ? $coords : $waypoints;
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

echo "  [1/4] Generating Mirpur Road corridor (Gabtoli -> Asad Gate -> Science Lab -> Azimpur)...\n";
$mirpurWaypoints = [
    [90.3420, 23.7850],
    [90.3662, 23.7719],
    [90.3725, 23.7601],
    [90.3815, 23.7482],
    [90.3846, 23.7401],
    [90.3855, 23.7289]
];
$mirpurCoords = fetchOsrmMultiStop($mirpurWaypoints);
echo "        Points: " . count($mirpurCoords) . "\n";
$mirpurPoly = bufferPolylineToRoadCorridor($mirpurCoords, 0.00018);

echo "  [2/4] Generating VIP Road corridor (Shahbagh -> Farmgate -> Mohakhali)...\n";
$vipWaypoints = [
    [90.3958, 23.7381],
    [90.3945, 23.7460],
    [90.3934, 23.7505],
    [90.3899, 23.7574],
    [90.3921, 23.7699],
    [90.4043, 23.7776]
];
$vipCoords = fetchOsrmMultiStop($vipWaypoints);
echo "        Points: " . count($vipCoords) . "\n";
$vipPoly = bufferPolylineToRoadCorridor($vipCoords, 0.00018);

echo "  [3/4] Generating Elephant Road corridor (Science Lab -> Katabon -> Shahbagh)...\n";
$elephantWaypoints = [
    [90.3846, 23.7401],
    [90.3892, 23.7408],
    [90.3958, 23.7381]
];
$elephantCoords = fetchOsrmMultiStop($elephantWaypoints);
echo "        Points: " . count($elephantCoords) . "\n";
$elephantPoly = bufferPolylineToRoadCorridor($elephantCoords, 0.00018);

echo "  [4/4] Generating Moghbazar-Mouchak Flyover ramp corridor...\n";
$flyoverWaypoints = [
    [90.4045, 23.7481],
    [90.4132, 23.7485]
];
$flyoverCoords = fetchOsrmMultiStop($flyoverWaypoints);
echo "        Points: " . count($flyoverCoords) . "\n";
$flyoverPoly = bufferPolylineToRoadCorridor($flyoverCoords, 0.00018);

$officialBans = [
    [
        'name' => 'Mirpur Road Corridor (Gabtoli to Azimpur)',
        'type' => 'vip_road_ban',
        'vehicles' => ['pedal_rickshaw', 'battery_rickshaw'],
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $mirpurPoly,
        'hint' => 'Official DTCA/DMP ban: Rickshaws prohibited on Mirpur Road main arterial carriageway. Take Dhanmondi residential inner avenues or bus.'
    ],
    [
        'name' => 'VIP Road / Kazi Nazrul Islam Ave (Shahbagh to Mohakhali)',
        'type' => 'vip_road_ban',
        'vehicles' => ['pedal_rickshaw', 'battery_rickshaw'],
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $vipPoly,
        'hint' => 'Official DMP VIP arterial: Rickshaws strictly prohibited from Shahbagh to Mohakhali. Use MRT Line 6, bus, or Hatirjheel / Green Road bypass.'
    ],
    [
        'name' => 'Elephant Road (Science Lab to Shahbagh)',
        'type' => 'vip_road_ban',
        'vehicles' => ['pedal_rickshaw', 'battery_rickshaw'],
        'start_time' => '07:00:00',
        'end_time' => '22:00:00',
        'polygon' => $elephantPoly,
        'hint' => 'Official DTCA/DMP ban: Rickshaws prohibited on Elephant Road thoroughfare. Use Katabon/Hatirpool side lanes.'
    ],
    [
        'name' => 'Moghbazar-Mouchak Flyover (Upper Deck)',
        'type' => 'flyover_ban',
        'vehicles' => ['pedal_rickshaw', 'battery_rickshaw'],
        'start_time' => null,
        'end_time' => null,
        'polygon' => $flyoverPoly,
        'hint' => 'High Court & DMP order: Rickshaws strictly prohibited on the elevated flyover deck. Must use ground surface road.'
    ]
];

try {
    $db = Database::getConnection();
    $zonesCol = $db->selectCollection('restricted_zones');

    // Safely deactivate any old/trial records
    $zonesCol->updateMany([], ['$set' => ['is_active' => false]]);

    foreach ($officialBans as $ban) {
        $existing = $zonesCol->findOne(['zone_name' => $ban['name']]);
        $doc = [
            'zone_name' => $ban['name'],
            'city' => 'Dhaka',
            'restricted_vehicles' => $ban['vehicles'],
            'restriction_type' => $ban['type'],
            'active_start_time' => $ban['start_time'],
            'active_end_time' => $ban['end_time'],
            'polygon_geojson' => $ban['polygon'],
            'transfer_hint' => $ban['hint'],
            'is_active' => true,
        ];

        if ($existing) {
            $zonesCol->updateOne(['id' => (int)$existing['id']], ['$set' => $doc]);
        } else {
            $doc['id'] = Database::nextSequence('restricted_zones');
            $zonesCol->insertOne($doc);
        }
        echo "[+] Landed official ban: {$ban['name']}\n";
    }

    $count = $zonesCol->countDocuments(['is_active' => true]);
    echo "[*] Success! Authenticated official Dhaka rickshaw-banned corridors in MongoDB: {$count}\n";
} catch (Throwable $e) {
    echo "[!] MongoDB Error: " . $e->getMessage() . "\n";
    exit(1);
}
