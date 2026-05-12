<?php
// src/functions.php
require_once __DIR__ . '/db.php';

/**
 * Find a user by email address
 */
function find_user_by_email(string $email): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

/**
 * Register a new user (optional)
 */
function register_user(string $name, string $email, string $password, string $role): bool {
    $pdo = getPDO();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$name, $email, $hash, $role]);
}

/**
 * Verify login credentials — only needs email & password
 */
function verify_user(string $email, string $password): ?array {
    $user = find_user_by_email($email);

    if ($user && password_verify($password, $user['password_hash'])) {
        return $user;
    }

    return null;
}

/*
function uploadFile($field, $dir) {
    if (!isset($_FILES[$field])) return null;

    $name = time() . "_" . basename($_FILES[$field]['name']);
    $target = $dir . $name;

    move_uploaded_file($_FILES[$field]['tmp_name'], $target);
    return $name;
}

function uploadMultiple($field, $dir) {
    $files = [];
    if (!isset($_FILES[$field]['name'][0])) return $files;

    foreach ($_FILES[$field]['name'] as $i => $filename) {
        $newName = time() . "_" . $i . "_" . basename($filename);
        $target = $dir . $newName;

        move_uploaded_file($_FILES[$field]['tmp_name'][$i], $target);
        $files[] = $newName;
    }
    return $files;
} */
?>
