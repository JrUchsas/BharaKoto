<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/MongoStore.php';

/**
 * MongoDB Singleton Connection Handler (with optional legacy MySQL helper for data migration)
 */
final class Database
{
    private static ?MongoDatabase $instance = null;
    private static ?array $config = null;

    private function __construct() {}
    private function __clone() {}

    private static function loadAllConfig(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/config.php';
        }
        return self::$config;
    }

    /**
     * Get primary MongoDB database handle
     */
    public static function getConnection(): MongoDatabase
    {
        if (self::$instance === null) {
            $all = self::loadAllConfig();
            $mongo = $all['mongodb'] ?? [];
            $uri = trim((string)($mongo['uri'] ?? ''));
            $dbName = trim((string)($mongo['database'] ?? 'bd_fare_estimator'));

            if ($uri === '') {
                throw new RuntimeException('MONGODB_URI is not configured in .env or environment variables.');
            }

            try {
                $manager = new MongoDB\Driver\Manager($uri);
                self::$instance = new MongoDatabase($manager, $dbName);
            } catch (Throwable $e) {
                throw new RuntimeException('MongoDB Connection Error: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }

    public static function nextSequence(string $collectionName): int
    {
        return self::getConnection()->nextSequence($collectionName);
    }

    public static function syncSequence(string $collectionName, int $minValue): void
    {
        self::getConnection()->syncSequence($collectionName, $minValue);
    }

    /**
     * Optional legacy MySQL PDO connection (used only for migrating existing MySQL data to MongoDB)
     */
    public static function getMysqlConnection(): PDO
    {
        $all = self::loadAllConfig();
        $db = $all['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        return new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$db['charset']} COLLATE {$db['collation']}",
        ]);
    }
}
