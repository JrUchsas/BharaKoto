<?php
declare(strict_types=1);

/**
 * PDO Singleton Connection Handler
 */
final class Database
{
    private static ?PDO $instance = null;
    private static ?array $config = null;

    private function __construct() {}
    private function __clone() {}

    /**
     * Load configuration once
     */
    private static function loadConfig(): array
    {
        if (self::$config === null) {
            $allConfig = require __DIR__ . '/config.php';
            self::$config = $allConfig['db'];
        }
        return self::$config;
    }

    /**
     * Get primary PDO connection to the application database
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $db = self::loadConfig();
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['database'],
                $db['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$db['charset']} COLLATE {$db['collation']}"
            ];

            try {
                self::$instance = new PDO($dsn, $db['username'], $db['password'], $options);
            } catch (PDOException $e) {
                throw new RuntimeException("Database Connection Error: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }

    /**
     * Get a connection without selecting a database (useful for migration/creation)
     */
    public static function getMigrationConnection(): PDO
    {
        $db = self::loadConfig();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $db['host'],
            $db['port'],
            $db['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$db['charset']}"
        ];

        return new PDO($dsn, $db['username'], $db['password'], $options);
    }
}
