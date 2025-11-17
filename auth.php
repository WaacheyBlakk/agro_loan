<?php
// src/auth.php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function login($email, $password) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT id, password_hash, role, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'role' => $user['role'],
            'name' => $user['name']
        ];
        return true;
    }
    return false;
}

function logout() {
    session_unset();
    session_destroy();
}


function current_user() {
    return $_SESSION['user'] ?? null;
}
