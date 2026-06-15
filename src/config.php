<?php
// src/config.php

return [
    'db' => [
        // Database name must match what you created in phpMyAdmin
        'dsn' => 'mysql:host=127.0.0.1;dbname=agro_loan;charset=utf8mb4',

        //Default XAMPP MySQL credentials
        'user' => 'root',
        'pass' => '',

        // PDO options
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    'upload_dir' => __DIR__ . '/../uploads/',
];

$host = "localhost";
$user = "root";
$pass = "";
$db   = "agro_loan";

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>


