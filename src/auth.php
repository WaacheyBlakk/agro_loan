<?php
// src/auth.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sessions.php';
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
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        return true;
    }
    return false;
}

function logout() {
    session_unset();
    session_destroy();
}


