<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "[-] Seeding detailed Badda hubs into popular_nodes...\n";

$baddaNodes = [
    [
        'name_en' => 'Madhya Badda (Badda Bazar)',
        'name_bn' => 'মধ্য বাড্ডা (বাড্ডা বাজার)',
        'area_thana' => 'Badda',
        'lat' => 23.7832,
        'lng' => 90.4248,
        'type' => 'intersection'
    ],
    [
        'name_en' => 'Dokkhin Badda (South Badda)',
        'name_bn' => 'দক্ষিণ বাড্ডা',
        'area_thana' => 'Badda',
        'lat' => 23.7748,
        'lng' => 90.4241,
        'type' => 'general'
    ],
    [
        'name_en' => 'Badda Link Road (Pragati Sarani)',
        'name_bn' => 'বাড্ডা লিংক রোড',
        'area_thana' => 'Badda',
        'lat' => 23.7781,
        'lng' => 90.4239,
        'type' => 'intersection'
    ],
    [
        'name_en' => 'Uttar Badda (North Badda Bus Stand)',
        'name_bn' => 'উত্তর বাড্ডা বাস স্ট্যান্ড',
        'area_thana' => 'Badda',
        'lat' => 23.7885,
        'lng' => 90.4255,
        'type' => 'bus_stand'
    ],
    [
        'name_en' => 'Merul Badda (BRAC University)',
        'name_bn' => 'মেরুল বাড্ডা ব্র্যাক বিশ্ববিদ্যালয়',
        'area_thana' => 'Badda',
        'lat' => 23.7712,
        'lng' => 90.4230,
        'type' => 'university'
    ],
    [
        'name_en' => 'Shahjadpur Bus Stand (Badda)',
        'name_bn' => 'শাহজাদপুর বাস স্ট্যান্ড (বাড্ডা)',
        'area_thana' => 'Badda',
        'lat' => 23.7925,
        'lng' => 90.4250,
        'type' => 'bus_stand'
    ],
    [
        'name_en' => 'Suvastu Nazar Valley (Shahjadpur / Badda)',
        'name_bn' => 'সুবাস্তু নজর ভ্যালি',
        'area_thana' => 'Badda',
        'lat' => 23.7938,
        'lng' => 90.4255,
        'type' => 'general'
    ],
    [
        'name_en' => 'Badda General Hospital / Post Office',
        'name_bn' => 'বাড্ডা জেনারেল হাসপাতাল ও পোস্ট অফিস',
        'area_thana' => 'Badda',
        'lat' => 23.7818,
        'lng' => 90.4252,
        'type' => 'hospital'
    ],
    [
        'name_en' => 'Aftabnagar Main Gate (Merul Badda)',
        'name_bn' => 'আফতাবনগর মেইন গেট',
        'area_thana' => 'Badda',
        'lat' => 23.7630,
        'lng' => 90.4245,
        'type' => 'general'
    ],
    [
        'name_en' => 'Adarsha Nagar (Middle Badda)',
        'name_bn' => 'আদর্শ নগর (মধ্য বাড্ডা)',
        'area_thana' => 'Badda',
        'lat' => 23.7845,
        'lng' => 90.4290,
        'type' => 'general'
    ],
    [
        'name_en' => 'Gopipara (Badda)',
        'name_bn' => 'গোপীপাড়া (বাড্ডা)',
        'area_thana' => 'Badda',
        'lat' => 23.7795,
        'lng' => 90.4270,
        'type' => 'general'
    ],
    [
        'name_en' => 'DIT Project (Merul Badda)',
        'name_bn' => 'ডিআইটি প্রজেক্ট (মেরুল বাড্ডা)',
        'area_thana' => 'Badda',
        'lat' => 23.7690,
        'lng' => 90.4260,
        'type' => 'general'
    ]
];

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        INSERT INTO popular_nodes (name_en, name_bn, area_thana, latitude, longitude, node_type, barikoi_place_id)
        SELECT :name_en, :name_bn, :area, :lat, :lng, :type, :bk_id
        WHERE NOT EXISTS (
            SELECT 1 FROM popular_nodes WHERE name_en = :check_en
        )
    ");

    $count = 0;
    foreach ($baddaNodes as $node) {
        $bkId = 'BK-DH-BADDA-' . rand(100, 999);
        $stmt->execute([
            ':name_en' => $node['name_en'],
            ':name_bn' => $node['name_bn'],
            ':area' => $node['area_thana'],
            ':lat' => $node['lat'],
            ':lng' => $node['lng'],
            ':type' => $node['type'],
            ':bk_id' => $bkId,
            ':check_en' => $node['name_en']
        ]);
        if ($stmt->rowCount() > 0) {
            $count++;
        }
    }

    echo "[+] Done! Added {$count} detailed Badda locations to database.\n";

} catch (Throwable $e) {
    echo "[!] Error seeding Badda hubs: " . $e->getMessage() . "\n";
    exit(1);
}
