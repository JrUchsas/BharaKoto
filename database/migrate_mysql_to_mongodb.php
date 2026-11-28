<?php
declare(strict_types=1);

/**
 * Migrate existing MySQL data (including custom admin-drawn restricted_zones)
 * from local MySQL (DB_* in .env) into MongoDB (MONGODB_URI in .env).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "========================================================\n";
echo " MySQL -> MongoDB Data Migrator (BharaKoto)\n";
echo "========================================================\n\n";

try {
    $pdo = Database::getMysqlConnection();
    $db = Database::getConnection();
    $db->command(['ping' => 1]);
    echo "[+] Connected to both MySQL and MongoDB.\n";

    // 1. Users
    $usersCol = $db->selectCollection('users');
    $mysqlUsers = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
    $userNames = [];
    $maxUserId = 0;
    foreach ($mysqlUsers as $u) {
        $id = (int)$u['id'];
        $maxUserId = max($maxUserId, $id);
        $userNames[$id] = $u['name'];
        $doc = [
            'id' => $id,
            'name' => $u['name'],
            'phone' => $u['phone'],
            'reputation_points' => (int)$u['reputation_points'],
            'is_blocked' => !empty($u['is_blocked']),
            'created_at' => MongoDatabase::utcDateTime(strtotime((string)$u['created_at']) ?: time()),
        ];
        $usersCol->updateOne(['id' => $id], ['$set' => $doc], ['upsert' => true]);
    }
    $db->syncSequence('users', $maxUserId);
    echo "[+] Migrated users: " . count($mysqlUsers) . "\n";

    // 2. Popular Nodes
    $nodesCol = $db->selectCollection('popular_nodes');
    $mysqlNodes = $pdo->query("SELECT * FROM popular_nodes ORDER BY id ASC")->fetchAll();
    $maxNodeId = 0;
    foreach ($mysqlNodes as $n) {
        $id = (int)$n['id'];
        $maxNodeId = max($maxNodeId, $id);
        $lat = (float)$n['latitude'];
        $lng = (float)$n['longitude'];
        $doc = [
            'id' => $id,
            'name_en' => $n['name_en'],
            'name_bn' => $n['name_bn'],
            'area_thana' => $n['area_thana'],
            'barikoi_place_id' => $n['barikoi_place_id'],
            'latitude' => $lat,
            'longitude' => $lng,
            'location' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'node_type' => $n['node_type'],
        ];
        $nodesCol->updateOne(['id' => $id], ['$set' => $doc], ['upsert' => true]);
    }
    $db->syncSequence('popular_nodes', $maxNodeId);
    echo "[+] Migrated popular_nodes: " . count($mysqlNodes) . "\n";

    // 3. Fare Configs
    $configsCol = $db->selectCollection('fare_configs');
    $mysqlConfigs = $pdo->query("SELECT * FROM fare_configs ORDER BY id ASC")->fetchAll();
    $maxCfgId = 0;
    foreach ($mysqlConfigs as $c) {
        $id = (int)$c['id'];
        $maxCfgId = max($maxCfgId, $id);
        $doc = [
            'id' => $id,
            'vehicle_type' => $c['vehicle_type'],
            'base_fare_bdt' => (float)$c['base_fare_bdt'],
            'base_distance_km' => (float)$c['base_distance_km'],
            'per_km_official_bdt' => (float)$c['per_km_official_bdt'],
            'per_km_street_bdt' => (float)$c['per_km_street_bdt'],
            'waiting_charge_per_min' => (float)$c['waiting_charge_per_min'],
            'peak_hour_multiplier' => (float)$c['peak_hour_multiplier'],
            'rain_multiplier' => (float)$c['rain_multiplier'],
        ];
        $configsCol->updateOne(['vehicle_type' => $c['vehicle_type']], ['$set' => $doc], ['upsert' => true]);
    }
    $db->syncSequence('fare_configs', $maxCfgId);
    echo "[+] Migrated fare_configs: " . count($mysqlConfigs) . "\n";

    // 4. Restricted Zones (Admin Banned Zones)
    $zonesCol = $db->selectCollection('restricted_zones');
    $mysqlZones = $pdo->query("SELECT id, zone_name, city, restricted_vehicles, restriction_type, active_start_time, active_end_time, polygon_geojson, transfer_hint, is_active FROM restricted_zones ORDER BY id ASC")->fetchAll();
    $maxZoneId = 0;
    foreach ($mysqlZones as $z) {
        $id = (int)$z['id'];
        $maxZoneId = max($maxZoneId, $id);
        $polygon = is_string($z['polygon_geojson']) ? json_decode($z['polygon_geojson'], true) : $z['polygon_geojson'];
        $vehicles = array_values(array_filter(array_map('trim', explode(',', (string)$z['restricted_vehicles']))));
        $doc = [
            'id' => $id,
            'zone_name' => $z['zone_name'],
            'city' => $z['city'],
            'restricted_vehicles' => $vehicles,
            'restriction_type' => $z['restriction_type'],
            'active_start_time' => $z['active_start_time'],
            'active_end_time' => $z['active_end_time'],
            'polygon_geojson' => $polygon,
            'transfer_hint' => $z['transfer_hint'],
            'is_active' => !empty($z['is_active']),
        ];
        $zonesCol->updateOne(['id' => $id], ['$set' => $doc], ['upsert' => true]);
    }
    $db->syncSequence('restricted_zones', $maxZoneId);
    echo "[+] Migrated restricted_zones (admin banned zones): " . count($mysqlZones) . "\n";

    // 5. Crowdsourced Fares
    $faresCol = $db->selectCollection('crowdsourced_fares');
    $mysqlFares = $pdo->query("SELECT * FROM crowdsourced_fares ORDER BY id ASC")->fetchAll();
    $maxFareId = 0;
    foreach ($mysqlFares as $f) {
        $id = (int)$f['id'];
        $maxFareId = max($maxFareId, $id);
        $uid = $f['user_id'] !== null ? (int)$f['user_id'] : null;
        $oLat = (float)$f['origin_lat'];
        $oLng = (float)$f['origin_lng'];
        $dLat = (float)$f['dest_lat'];
        $dLng = (float)$f['dest_lng'];
        $doc = [
            'id' => $id,
            'user_id' => $uid,
            'commuter_name' => ($uid !== null && isset($userNames[$uid])) ? $userNames[$uid] : 'Commuter',
            'vehicle_type' => $f['vehicle_type'],
            'origin_name' => $f['origin_name'],
            'origin_lat' => $oLat,
            'origin_lng' => $oLng,
            'origin_location' => ['type' => 'Point', 'coordinates' => [$oLng, $oLat]],
            'dest_name' => $f['dest_name'],
            'dest_lat' => $dLat,
            'dest_lng' => $dLng,
            'dest_location' => ['type' => 'Point', 'coordinates' => [$dLng, $dLat]],
            'api_distance_km' => (float)$f['api_distance_km'],
            'fare_paid_bdt' => (float)$f['fare_paid_bdt'],
            'weather_condition' => $f['weather_condition'],
            'traffic_condition' => $f['traffic_condition'],
            'is_peak_hour' => !empty($f['is_peak_hour']),
            'upvotes' => (int)$f['upvotes'],
            'downvotes' => (int)$f['downvotes'],
            'status' => $f['status'],
            'created_at' => MongoDatabase::utcDateTime(strtotime((string)$f['created_at']) ?: time()),
        ];
        $faresCol->updateOne(['id' => $id], ['$set' => $doc], ['upsert' => true]);
    }
    $db->syncSequence('crowdsourced_fares', $maxFareId);
    echo "[+] Migrated crowdsourced_fares: " . count($mysqlFares) . "\n";

    // 6. Fare Votes
    $votesCol = $db->selectCollection('fare_votes');
    $mysqlVotes = $pdo->query("SELECT * FROM fare_votes ORDER BY id ASC")->fetchAll();
    $maxVoteId = 0;
    foreach ($mysqlVotes as $v) {
        $id = (int)$v['id'];
        $maxVoteId = max($maxVoteId, $id);
        $doc = [
            'id' => $id,
            'fare_id' => (int)$v['fare_id'],
            'user_id' => (int)$v['user_id'],
            'vote_type' => $v['vote_type'],
            'created_at' => MongoDatabase::utcDateTime(strtotime((string)$v['created_at']) ?: time()),
        ];
        $votesCol->updateOne(
            ['fare_id' => (int)$v['fare_id'], 'user_id' => (int)$v['user_id']],
            ['$set' => $doc],
            ['upsert' => true]
        );
    }
    $db->syncSequence('fare_votes', $maxVoteId);
    echo "[+] Migrated fare_votes: " . count($mysqlVotes) . "\n";

    echo "\n>>> Full MySQL -> MongoDB migration completed! <<<\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n[!] Migration Error: " . $e->getMessage() . "\n");
    exit(1);
}
