<?php
declare(strict_types=1);

/**
 * BD Fare Estimator Database Migration & Seeder CLI Script
 */

require_once __DIR__ . '/../config/database.php';

echo "========================================================\n";
echo " BD CNG & Rickshaw Fare Estimator: Database Migration\n";
echo "========================================================\n\n";

try {
    $config = require __DIR__ . '/../config/config.php';
    $dbConfig = $config['db'];

    echo "[-] Connecting to MySQL server at {$dbConfig['host']}:{$dbConfig['port']}...\n";
    $pdo = Database::getMigrationConnection();
    echo "[+] Connected to MySQL server successfully.\n";

    $dbName = $dbConfig['database'];
    echo "[-] Ensuring database '{$dbName}' exists...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `{$dbName}`;");
    echo "[+] Using database '{$dbName}'.\n";

    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("Schema file not found at: {$schemaFile}");
    }

    echo "[-] Reading schema and seed definitions...\n";
    $sqlContent = file_get_contents($schemaFile);

    // Split SQL statements by semicolon, avoiding semicolons inside string literals
    // Remove comment lines
    $lines = explode("\n", $sqlContent);
    $cleanedSql = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || $trimmed === '') {
            continue;
        }
        $cleanedSql .= $line . "\n";
    }

    // Split by semicolon followed by newline
    $queries = preg_split('/;\s*[\r\n]+/', $cleanedSql);

    $executedCount = 0;
    foreach ($queries as $query) {
        $stmt = trim($query);
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
        $executedCount++;
    }

    echo "[+] Successfully executed {$executedCount} SQL statements.\n";

    // Verify seeded counts
    $counts = [
        'users' => $pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn(),
        'popular_nodes' => $pdo->query("SELECT COUNT(*) FROM `popular_nodes`")->fetchColumn(),
        'fare_configs' => $pdo->query("SELECT COUNT(*) FROM `fare_configs`")->fetchColumn(),
        'restricted_zones' => $pdo->query("SELECT COUNT(*) FROM `restricted_zones`")->fetchColumn(),
        'crowdsourced_fares' => $pdo->query("SELECT COUNT(*) FROM `crowdsourced_fares`")->fetchColumn(),
        'fare_votes' => $pdo->query("SELECT COUNT(*) FROM `fare_votes`")->fetchColumn(),
    ];

    echo "\n[+] Migration and Seeding Summary:\n";
    foreach ($counts as $table => $count) {
        printf("    - %-20s: %d records\n", $table, $count);
    }

    echo "\n>>> Migration completed successfully! <<<\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "\n[!] Migration Failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "    File: " . $e->getFile() . " on line " . $e->getLine() . "\n");
    exit(1);
}
