<?php
// src/config.php

// Minimal, zero-dependency .env loader (skip if a var is already set, e.g. by Apache/nginx or the OS environment).
$envPath = __DIR__ . '/../.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        
        // Skip empty lines or lines starting with comments
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        
        // Strip out trailing inline comments
        if (str_contains($line, '#')) {
            // Split by # but only keep the left side
            $parts = explode('#', $line, 2);
            $line = trim($parts[0]);
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));

        // Safely strip matching wrapper quotes if they exist (e.g., "value" or 'value')
        $length = strlen($value);
        if ($length >= 2) {
            $firstChar = $value[0];
            $lastChar = $value[$length - 1];
            if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Synchronize with putenv, $_ENV, and $_SERVER for compatibility
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

return [
    'db' => [
        'dsn' => sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_NAME') ?: 'agro_loan'
        ),
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',

        // PDO options
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    'upload_dir' => __DIR__ . '/../uploads/',
];