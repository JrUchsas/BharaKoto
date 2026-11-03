-- ==========================================================
-- BD CNG & Rickshaw Fare and Route Estimator (BharaKoto?)
-- Database Schema & Seed Data for Dhaka, Bangladesh
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `bd_fare_estimator` 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `bd_fare_estimator`;

-- ----------------------------------------------------------
-- 1. USERS TABLE
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `fare_votes`;
DROP TABLE IF EXISTS `crowdsourced_fares`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL UNIQUE,
    `reputation_points` INT NOT NULL DEFAULT 10,
    `is_blocked` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. POPULAR NODES (DHAKA LANDMARKS & HUBS)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `popular_nodes`;

CREATE TABLE `popular_nodes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name_en` VARCHAR(150) NOT NULL,
    `name_bn` VARCHAR(150) NOT NULL,
    `area_thana` VARCHAR(100) NOT NULL,
    `barikoi_place_id` VARCHAR(100) NULL,
    `latitude` DECIMAL(10,8) NOT NULL,
    `longitude` DECIMAL(11,8) NOT NULL,
    `node_type` ENUM('metro_station','bus_stand','intersection','university','hospital','general') NOT NULL,
    INDEX `idx_coords` (`latitude`, `longitude`),
    INDEX `idx_search` (`name_en`, `name_bn`, `area_thana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. FARE CONFIGURATIONS
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `fare_configs`;

CREATE TABLE `fare_configs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vehicle_type` ENUM('pedal_rickshaw','battery_rickshaw','cng','leguna') NOT NULL UNIQUE,
    `base_fare_bdt` DECIMAL(6,2) NOT NULL,
    `base_distance_km` DECIMAL(4,2) NOT NULL,
    `per_km_official_bdt` DECIMAL(6,2) NOT NULL,
    `per_km_street_bdt` DECIMAL(6,2) NOT NULL,
    `waiting_charge_per_min` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `peak_hour_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    `rain_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. RESTRICTED ZONES (VIP ROAD BANS & FLYOVERS)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `restricted_zones`;

CREATE TABLE `restricted_zones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `zone_name` VARCHAR(150) NOT NULL,
    `city` VARCHAR(50) NOT NULL DEFAULT 'Dhaka',
    `restricted_vehicles` SET('pedal_rickshaw','battery_rickshaw','cng','leguna') NOT NULL,
    `restriction_type` ENUM('vip_road_ban','flyover_ban','time_based_ban','one_way') NOT NULL,
    `active_start_time` TIME NULL,
    `active_end_time` TIME NULL,
    `polygon_geojson` JSON NOT NULL,
    `transfer_hint` VARCHAR(255) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    INDEX `idx_active` (`is_active`, `city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. CROWDSOURCED FARES
-- ----------------------------------------------------------
CREATE TABLE `crowdsourced_fares` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `vehicle_type` ENUM('pedal_rickshaw','battery_rickshaw','cng','leguna') NOT NULL,
    `origin_name` VARCHAR(150) NOT NULL,
    `origin_lat` DECIMAL(10,8) NOT NULL,
    `origin_lng` DECIMAL(11,8) NOT NULL,
    `dest_name` VARCHAR(150) NOT NULL,
    `dest_lat` DECIMAL(10,8) NOT NULL,
    `dest_lng` DECIMAL(11,8) NOT NULL,
    `api_distance_km` DECIMAL(5,2) NOT NULL,
    `fare_paid_bdt` DECIMAL(6,2) NOT NULL,
    `weather_condition` ENUM('clear','rain','waterlogged','extreme_heat') NOT NULL DEFAULT 'clear',
    `traffic_condition` ENUM('normal','moderate','heavy_jam') NOT NULL DEFAULT 'normal',
    `is_peak_hour` BOOLEAN NOT NULL DEFAULT FALSE,
    `upvotes` INT NOT NULL DEFAULT 0,
    `downvotes` INT NOT NULL DEFAULT 0,
    `status` ENUM('approved','flagged','rejected') NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_origin_dest` (`origin_lat`, `origin_lng`, `dest_lat`, `dest_lng`),
    INDEX `idx_status_time` (`status`, `created_at`),
    CONSTRAINT `fk_crowdsourced_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 6. FARE VOTES
-- ----------------------------------------------------------
CREATE TABLE `fare_votes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `fare_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `vote_type` ENUM('accurate','overpaid','too_low_fake') NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_fare_user` (`fare_id`, `user_id`),
    CONSTRAINT `fk_vote_fare` FOREIGN KEY (`fare_id`) REFERENCES `crowdsourced_fares` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vote_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- SEED DATA
-- ==========================================================

-- Seed Initial Users
INSERT INTO `users` (`id`, `name`, `phone`, `reputation_points`, `is_blocked`) VALUES
(1, 'Tanvir Ahmed', '01711000001', 25, 0),
(2, 'Farhana Yasmin', '01811000002', 18, 0),
(3, 'Abdur Rahim (Mama)', '01911000003', 40, 0),
(4, 'Shakil Hasan', '01611000004', 12, 0),
(5, 'Nusrat Jahan', '01511000005', 15, 0);

-- Seed Popular Dhaka Nodes (At least 8 exact points required + additional hubs)
INSERT INTO `popular_nodes` (`name_en`, `name_bn`, `area_thana`, `barikoi_place_id`, `latitude`, `longitude`, `node_type`) VALUES
('Farmgate Khamarbari', 'ফার্মগেট খামারবাড়ি', 'Tejgaon', 'BK-DH-FARM-01', 23.75740000, 90.38990000, 'intersection'),
('Agargaon Metro Station', 'আগারগাঁও মেট্রো স্টেশন', 'Sher-e-Bangla Nagar', 'BK-DH-AGAR-02', 23.77840000, 90.38020000, 'metro_station'),
('Mirpur 10 Gol Chakkar', 'মিরপুর ১০ গোল চক্কর', 'Mirpur', 'BK-DH-MIR10-03', 23.80690000, 90.36870000, 'intersection'),
('Dhanmondi 32', 'ধানমন্ডি ৩২', 'Dhanmondi', 'BK-DH-DHAN32-04', 23.75160000, 90.37790000, 'general'),
('Shahbagh', 'শাহবাগ মোড়', 'Shahbagh', 'BK-DH-SHAH-05', 23.73810000, 90.39580000, 'intersection'),
('Mohakhali Rail Gate', 'মহাখালী রেল গেট', 'Mohakhali', 'BK-DH-MOHA-06', 23.77760000, 90.40430000, 'bus_stand'),
('Rampura Bridge', 'রামপুরা ব্রিজ', 'Rampura', 'BK-DH-RAMP-07', 23.76190000, 90.42190000, 'intersection'),
('Bashundhara Gate', 'বসুন্ধরা আবাসিক গেট', 'Vatara', 'BK-DH-BASH-08', 23.81360000, 90.42410000, 'general'),
('Gulshan 1 Circle', 'গুলশান ১ চত্বর', 'Gulshan', 'BK-DH-GUL1-09', 23.77970000, 90.41680000, 'intersection'),
('Motijheel Shapla Chattar', 'মতিঝিল শাপলা চত্বর', 'Motijheel', 'BK-DH-MOTI-10', 23.72970000, 90.41740000, 'intersection'),
('TSC, Dhaka University', 'টিএসসি, ঢাকা বিশ্ববিদ্যালয়', 'Shahbagh', 'BK-DH-TSC-11', 23.73230000, 90.39380000, 'university'),
('Uttara Sector 3 (Rajlakshmi)', 'উত্তরা সেক্টর ৩ (রাজলক্ষ্মী)', 'Uttara', 'BK-DH-UTT3-12', 23.87110000, 90.39650000, 'bus_stand');

-- Seed Fare Configurations (BD official vs street rates)
INSERT INTO `fare_configs` 
(`vehicle_type`, `base_fare_bdt`, `base_distance_km`, `per_km_official_bdt`, `per_km_street_bdt`, `waiting_charge_per_min`, `peak_hour_multiplier`, `rain_multiplier`) 
VALUES
('pedal_rickshaw',   20.00, 0.50, 20.00, 30.00, 0.00, 1.20, 1.50),
('battery_rickshaw', 20.00, 0.70, 15.00, 22.00, 0.00, 1.15, 1.40),
('cng',              40.00, 2.00, 12.00, 35.00, 2.00, 1.30, 1.50),
('leguna',           10.00, 2.00,  5.00,  7.00, 0.00, 1.00, 1.00);

-- Seed Restricted Zones (GeoJSON Polygon Coordinates in [lng, lat] order)
-- Bijoy Sarani to Agargaon VIP Stretch (Rokeya Sarani) banning pedal_rickshaw & battery_rickshaw
INSERT INTO `restricted_zones` 
(`zone_name`, `city`, `restricted_vehicles`, `restriction_type`, `active_start_time`, `active_end_time`, `polygon_geojson`, `transfer_hint`, `is_active`) 
VALUES
(
    'Bijoy Sarani to Agargaon VIP Stretch (Rokeya Sarani)',
    'Dhaka',
    'pedal_rickshaw,battery_rickshaw',
    'vip_road_ban',
    '07:00:00',
    '22:00:00',
    '{"type":"Polygon","coordinates":[[[90.3885,23.7635],[90.3905,23.7635],[90.3830,23.7785],[90.3810,23.7785],[90.3885,23.7635]]]}',
    'Rickshaws strictly banned on VIP Rokeya Sarani stretch (Bijoy Sarani to Agargaon). Commuters must switch to MRT Line 6, bus, or take alternate side lanes via Monipur/West Kafrul.',
    1
),
(
    'Moghbazar-Mouchak Flyover Upper Deck',
    'Dhaka',
    'pedal_rickshaw,battery_rickshaw',
    'flyover_ban',
    NULL,
    NULL,
    '{"type":"Polygon","coordinates":[[[90.4040,23.7480],[90.4060,23.7485],[90.4130,23.7440],[90.4115,23.7425],[90.4040,23.7480]]]}',
    'Rickshaws are prohibited on the upper deck of Moghbazar-Mouchak flyover. Commuters must use the surface level road.',
    1
);

-- Seed Realistic Commuter Submissions (Farmgate to Agargaon corridor around 80-100 BDT)
-- Farmgate Khamarbari: 23.7574, 90.3899 -> Agargaon Metro: 23.7784, 90.3802 (Distance ~2.8 km)
INSERT INTO `crowdsourced_fares` 
(`user_id`, `vehicle_type`, `origin_name`, `origin_lat`, `origin_lng`, `dest_name`, `dest_lat`, `dest_lng`, `api_distance_km`, `fare_paid_bdt`, `weather_condition`, `traffic_condition`, `is_peak_hour`, `upvotes`, `downvotes`, `status`, `created_at`) 
VALUES
(1, 'pedal_rickshaw',   'Farmgate Khamarbari', 23.75740000, 90.38990000, 'Agargaon Metro Station', 23.77840000, 90.38020000, 2.85, 85.00, 'clear', 'normal', 0, 8, 1, 'approved', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'pedal_rickshaw',   'Farmgate Khamarbari', 23.75740000, 90.38990000, 'Agargaon Metro Station', 23.77840000, 90.38020000, 2.85, 90.00, 'clear', 'moderate', 0, 14, 0, 'approved', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3, 'pedal_rickshaw',   'Farmgate Khamarbari', 23.75740000, 90.38990000, 'Agargaon Metro Station', 23.77840000, 90.38020000, 2.85, 100.00, 'clear', 'heavy_jam', 1, 11, 2, 'approved', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 'battery_rickshaw', 'Farmgate Khamarbari', 23.75740000, 90.38990000, 'Agargaon Metro Station', 23.77840000, 90.38020000, 2.85, 80.00, 'clear', 'normal', 0, 9, 0, 'approved', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5, 'cng',              'Farmgate Khamarbari', 23.75740000, 90.38990000, 'Agargaon Metro Station', 23.77840000, 90.38020000, 2.85, 150.00, 'clear', 'moderate', 1, 19, 1, 'approved', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- Seed Initial Votes
INSERT INTO `fare_votes` (`fare_id`, `user_id`, `vote_type`) VALUES
(1, 2, 'accurate'),
(1, 4, 'accurate'),
(2, 1, 'accurate'),
(3, 4, 'accurate'),
(5, 1, 'accurate');
