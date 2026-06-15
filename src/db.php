<?php
// src/db.php

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
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }

    return $pdo;
}