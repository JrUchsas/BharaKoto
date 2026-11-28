<?php
declare(strict_types=1);

/**
 * BD Fare Estimator MongoDB Migration & Initial Seeder CLI Script
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================\n";
echo " BD CNG & Rickshaw Fare Estimator: MongoDB Migration\n";
echo "========================================================\n\n";

try {
    $config = require __DIR__ . '/../config/config.php';
    $mongoConfig = $config['mongodb'];

    echo "[-] Connecting to MongoDB database '{$mongoConfig['database']}'...\n";
    $db = Database::getConnection();
    $db->command(['ping' => 1]);
    echo "[+] Connected to MongoDB successfully.\n";

    $collections = [
        'counters',
        'users',
        'popular_nodes',
        'fare_configs',
        'restricted_zones',
        'crowdsourced_fares',
        'fare_votes',
    ];

    echo "[-] Resetting collections and creating indexes...\n";
    foreach ($collections as $colName) {
        $db->selectCollection($colName)->drop();
    }

    // Create indexes
    $usersCol = $db->selectCollection('users');
    $usersCol->createIndex(['id' => 1], ['unique' => true]);
    $usersCol->createIndex(['phone' => 1], ['unique' => true]);

    $nodesCol = $db->selectCollection('popular_nodes');
    $nodesCol->createIndex(['id' => 1], ['unique' => true]);
    $nodesCol->createIndex(['location' => '2dsphere']);
    $nodesCol->createIndex(['name_en' => 1]);
    $nodesCol->createIndex(['area_thana' => 1]);

    $configsCol = $db->selectCollection('fare_configs');
    $configsCol->createIndex(['id' => 1], ['unique' => true]);
    $configsCol->createIndex(['vehicle_type' => 1], ['unique' => true]);

    $zonesCol = $db->selectCollection('restricted_zones');
    $zonesCol->createIndex(['id' => 1], ['unique' => true]);
    $zonesCol->createIndex(['is_active' => 1, 'restricted_vehicles' => 1]);

    $faresCol = $db->selectCollection('crowdsourced_fares');
    $faresCol->createIndex(['id' => 1], ['unique' => true]);
    $faresCol->createIndex(['origin_location' => '2dsphere']);
    $faresCol->createIndex(['vehicle_type' => 1, 'status' => 1, 'created_at' => -1]);

    $votesCol = $db->selectCollection('fare_votes');
    $votesCol->createIndex(['fare_id' => 1, 'user_id' => 1], ['unique' => true]);

    echo "[+] Indexes created.\n";
    echo "[-] Seeding initial data...\n";

    // 1. Seed Users
    $now = MongoDatabase::utcDateTime();
    $usersCol->insertMany([
        ['id' => 1, 'name' => 'Tanvir Ahmed', 'phone' => '01711000001', 'reputation_points' => 25, 'is_blocked' => false, 'created_at' => $now],
        ['id' => 2, 'name' => 'Farhana Yasmin', 'phone' => '01811000002', 'reputation_points' => 18, 'is_blocked' => false, 'created_at' => $now],
        ['id' => 3, 'name' => 'Abdur Rahim (Mama)', 'phone' => '01911000003', 'reputation_points' => 40, 'is_blocked' => false, 'created_at' => $now],
        ['id' => 4, 'name' => 'Shakil Hasan', 'phone' => '01611000004', 'reputation_points' => 12, 'is_blocked' => false, 'created_at' => $now],
        ['id' => 5, 'name' => 'Nusrat Jahan', 'phone' => '01511000005', 'reputation_points' => 15, 'is_blocked' => false, 'created_at' => $now],
    ]);
    $db->syncSequence('users', 5);

    // 2. Seed Popular Nodes
    $initialNodes = [
        [1, 'Farmgate Khamarbari', 'ফার্মগেট খামারবাড়ি', 'Tejgaon', 'BK-DH-FARM-01', 23.7574, 90.3899, 'intersection'],
        [2, 'Agargaon Metro Station', 'আগারগাঁও মেট্রো স্টেশন', 'Sher-e-Bangla Nagar', 'BK-DH-AGAR-02', 23.7784, 90.3802, 'metro_station'],
        [3, 'Mirpur 10 Gol Chakkar', 'মিরপুর ১০ গোল চক্কর', 'Mirpur', 'BK-DH-MIR10-03', 23.8069, 90.3687, 'intersection'],
        [4, 'Dhanmondi 32', 'ধানমন্ডি ৩২', 'Dhanmondi', 'BK-DH-DHAN32-04', 23.7516, 90.3779, 'general'],
        [5, 'Shahbagh', 'শাহবাগ মোড়', 'Shahbagh', 'BK-DH-SHAH-05', 23.7381, 90.3958, 'intersection'],
        [6, 'Mohakhali Rail Gate', 'মহাখালী রেল গেট', 'Mohakhali', 'BK-DH-MOHA-06', 23.7776, 90.4043, 'bus_stand'],
        [7, 'Rampura Bridge', 'রামপুরা ব্রিজ', 'Rampura', 'BK-DH-RAMP-07', 23.7619, 90.4219, 'intersection'],
        [8, 'Bashundhara Gate', 'বসুন্ধরা আবাসিক গেট', 'Vatara', 'BK-DH-BASH-08', 23.8136, 90.4241, 'general'],
        [9, 'Gulshan 1 Circle', 'গুলশান ১ চত্বর', 'Gulshan', 'BK-DH-GUL1-09', 23.7797, 90.4168, 'intersection'],
        [10, 'Motijheel Shapla Chattar', 'মতিঝিল শাপলা চত্বর', 'Motijheel', 'BK-DH-MOTI-10', 23.7297, 90.4174, 'intersection'],
        [11, 'TSC, Dhaka University', 'টিএসসি, ঢাকা বিশ্ববিদ্যালয়', 'Shahbagh', 'BK-DH-TSC-11', 23.7323, 90.3938, 'university'],
        [12, 'Uttara Sector 3 (Rajlakshmi)', 'উত্তরা সেক্টর ৩ (রাজলক্ষ্মী)', 'Uttara', 'BK-DH-UTT3-12', 23.8711, 90.3965, 'bus_stand'],
    ];
    $nodeDocs = [];
    foreach ($initialNodes as [$id, $en, $bn, $thana, $bkId, $lat, $lng, $type]) {
        $nodeDocs[] = [
            'id' => $id,
            'name_en' => $en,
            'name_bn' => $bn,
            'area_thana' => $thana,
            'barikoi_place_id' => $bkId,
            'latitude' => $lat,
            'longitude' => $lng,
            'location' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'node_type' => $type,
        ];
    }
    $nodesCol->insertMany($nodeDocs);
    $db->syncSequence('popular_nodes', 12);

    // 3. Seed Fare Configs
    $configsCol->insertMany([
        ['id' => 1, 'vehicle_type' => 'pedal_rickshaw', 'base_fare_bdt' => 20.00, 'base_distance_km' => 0.50, 'per_km_official_bdt' => 20.00, 'per_km_street_bdt' => 30.00, 'waiting_charge_per_min' => 0.00, 'peak_hour_multiplier' => 1.20, 'rain_multiplier' => 1.50],
        ['id' => 2, 'vehicle_type' => 'battery_rickshaw', 'base_fare_bdt' => 20.00, 'base_distance_km' => 0.70, 'per_km_official_bdt' => 15.00, 'per_km_street_bdt' => 22.00, 'waiting_charge_per_min' => 0.00, 'peak_hour_multiplier' => 1.15, 'rain_multiplier' => 1.40],
        ['id' => 3, 'vehicle_type' => 'cng', 'base_fare_bdt' => 40.00, 'base_distance_km' => 2.00, 'per_km_official_bdt' => 12.00, 'per_km_street_bdt' => 35.00, 'waiting_charge_per_min' => 2.00, 'peak_hour_multiplier' => 1.30, 'rain_multiplier' => 1.50],
        ['id' => 4, 'vehicle_type' => 'leguna', 'base_fare_bdt' => 10.00, 'base_distance_km' => 2.00, 'per_km_official_bdt' => 5.00, 'per_km_street_bdt' => 7.00, 'waiting_charge_per_min' => 0.00, 'peak_hour_multiplier' => 1.00, 'rain_multiplier' => 1.00],
    ]);
    $db->syncSequence('fare_configs', 4);

    // 4. Restricted Zones collection starts empty; official DMP corridors are seeded by apply_official_dmp_bans.php
    $db->syncSequence('restricted_zones', 0);

    // 5. Seed Crowdsourced Fares
    $seedFares = [
        [1, 1, 'Tanvir Ahmed', 'pedal_rickshaw', 85.00, 'clear', 'normal', false, 8, 1, 2],
        [2, 2, 'Farhana Yasmin', 'pedal_rickshaw', 90.00, 'clear', 'moderate', false, 14, 0, 4],
        [3, 3, 'Abdur Rahim (Mama)', 'pedal_rickshaw', 100.00, 'clear', 'heavy_jam', true, 11, 2, 1],
        [4, 4, 'Shakil Hasan', 'battery_rickshaw', 80.00, 'clear', 'normal', false, 9, 0, 3],
        [5, 5, 'Nusrat Jahan', 'cng', 150.00, 'clear', 'moderate', true, 19, 1, 5],
    ];
    $fareDocs = [];
    foreach ($seedFares as [$id, $uid, $uName, $veh, $paid, $weather, $traffic, $peak, $up, $down, $daysAgo]) {
        $fareDocs[] = [
            'id' => $id,
            'user_id' => $uid,
            'commuter_name' => $uName,
            'vehicle_type' => $veh,
            'origin_name' => 'Farmgate Khamarbari',
            'origin_lat' => 23.7574,
            'origin_lng' => 90.3899,
            'origin_location' => ['type' => 'Point', 'coordinates' => [90.3899, 23.7574]],
            'dest_name' => 'Agargaon Metro Station',
            'dest_lat' => 23.7784,
            'dest_lng' => 90.3802,
            'dest_location' => ['type' => 'Point', 'coordinates' => [90.3802, 23.7784]],
            'api_distance_km' => 2.85,
            'fare_paid_bdt' => $paid,
            'weather_condition' => $weather,
            'traffic_condition' => $traffic,
            'is_peak_hour' => $peak,
            'upvotes' => $up,
            'downvotes' => $down,
            'status' => 'approved',
            'created_at' => MongoDatabase::utcDateTime(time() - ($daysAgo * 86400)),
        ];
    }
    $faresCol->insertMany($fareDocs);
    $db->syncSequence('crowdsourced_fares', 5);

    // 6. Seed Initial Votes
    $votesCol->insertMany([
        ['id' => 1, 'fare_id' => 1, 'user_id' => 2, 'vote_type' => 'accurate', 'created_at' => $now],
        ['id' => 2, 'fare_id' => 1, 'user_id' => 4, 'vote_type' => 'accurate', 'created_at' => $now],
        ['id' => 3, 'fare_id' => 2, 'user_id' => 1, 'vote_type' => 'accurate', 'created_at' => $now],
        ['id' => 4, 'fare_id' => 3, 'user_id' => 4, 'vote_type' => 'accurate', 'created_at' => $now],
        ['id' => 5, 'fare_id' => 5, 'user_id' => 1, 'vote_type' => 'accurate', 'created_at' => $now],
    ]);
    $db->syncSequence('fare_votes', 5);

    echo "\n[+] Migration and Seeding Summary:\n";
    foreach (['users', 'popular_nodes', 'fare_configs', 'restricted_zones', 'crowdsourced_fares', 'fare_votes'] as $col) {
        printf("    - %-20s: %d documents\n", $col, $db->selectCollection($col)->countDocuments());
    }

    echo "\n>>> MongoDB migration completed successfully! <<<\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n[!] Migration Failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "    File: " . $e->getFile() . " on line " . $e->getLine() . "\n");
    exit(1);
}
