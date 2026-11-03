<?php
declare(strict_types=1);

/**
 * BD CNG & Rickshaw Fare and Route Estimator
 * Application Configuration
 */

// Set Dhaka Timezone (UTC+6)
date_default_timezone_set('Asia/Dhaka');

// Load environment variables if .env file exists
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_contains($trimmed, '=')) {
            [$key, $val] = explode('=', $trimmed, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// Return unified configuration array
return [
    'app' => [
        'name' => 'BharaKoto? BD CNG & Rickshaw Fare Estimator',
        'env' => $_ENV['APP_ENV'] ?? 'development',
        'debug' => (bool)($_ENV['APP_DEBUG'] ?? true),
        'demo_mode' => (bool)($_ENV['DEMO_MODE'] ?? true),
        'timezone' => 'Asia/Dhaka',
        'default_center' => [
            'lat' => 23.8103,
            'lng' => 90.4125,
            'zoom' => 13,
            'city' => 'Dhaka'
        ]
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_NAME'] ?? 'bd_fare_estimator',
        'username' => $_ENV['DB_USER'] ?? 'root',
        'password' => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'apis' => [
        // OpenRouteService: https://openrouteservice.org/
        // If empty or DEMO_MODE, smart simulated Dhaka routing is used
        'ors_key' => $_ENV['ORS_API_KEY'] ?? 'DEMO_MODE',
        
        // Barikoi Places API: https://barikoi.com/
        'barikoi_key' => $_ENV['BARIKOI_API_KEY'] ?? '',
        
        // OpenWeatherMap API: https://openweathermap.org/
        'openweather_key' => $_ENV['OPENWEATHER_API_KEY'] ?? '',
        'weather_dhaka_coords' => [
            'lat' => 23.8103,
            'lon' => 90.4125,
        ]
    ]
];
