<?php
// src/db.php
// --------------------------------------
// Central PDO database connection file
// --------------------------------------

$config = require __DIR__ . '/config.php';

function getPDO(): PDO {
    static $pdo = null;
    global $config;

    if ($pdo === null) {
        $db = $config['db'];

        try {
            $pdo = new PDO(
                $db['dsn'],
                $db['user'],
                $db['pass'],
                $db['options']
            );
        } catch (PDOException $e) {
            // Graceful error handling
            die("❌ Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }

    return $pdo;
}
