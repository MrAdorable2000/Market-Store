<?php
/**
 * config/database.php
 * --------------------------------------------------------------------
 * PDO connection singleton for Isoko Ryacu.
 *
 * All queries in this project MUST use PDO prepared statements to
 * protect against SQL injection.  Never concatenate user input into SQL.
 *
 * Usage:
 *   require_once __DIR__ . '/../config/database.php';
 *   $db = db();           // returns the shared PDO instance
 *   $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
 *   $stmt->execute([$email]);
 *   $user = $stmt->fetch();
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Returns the shared PDO connection (creates it on first call).
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In production, log this. In debug, show a friendly message.
            if (APP_DEBUG) {
                http_response_code(500);
                die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
            }
            http_response_code(500);
            die('We are unable to reach the database right now. Please try again later.');
        }
    }

    return $pdo;
}
