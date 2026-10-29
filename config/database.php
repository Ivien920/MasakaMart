<?php
/**
 * KATALE — Database connection.
 * Single PDO connection reused across the request via Database::conn().
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $connection = null;

    public static function conn(): PDO
    {
        if (self::$connection === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            try {
                self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(500);
                die('We are unable to connect right now. Please try again shortly.');
            }
        }
        return self::$connection;
    }
}
