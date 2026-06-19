<?php
// ============================================================
// config/database.php
// Database connection using PDO (safer than mysqli)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'mulawin_fleetops');
define('DB_USER', 'root');       // Change to your XAMPP MySQL username
define('DB_PASS', '');           // Change to your XAMPP MySQL password
define('DB_CHARSET', 'utf8mb4');

function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST
             . ";dbname=" . DB_NAME
             . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Always return assoc arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                    // Use real prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never expose DB errors to the browser in production
            error_log("DB Connection failed: " . $e->getMessage());
            die(json_encode(['success' => false, 'message' => 'Database connection error.']));
        }
    }

    return $pdo;
}
