<?php
// src/users.php
require_once __DIR__ . '/db.php';

// 🔹 Create a new user
function create_user($email, $password, $role, $name, $phone = null) {
    $pdo = getPDO();

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception("Email already exists. Please use another one.");
    }
    
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, name, phone) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$email, $hash, $role, $name, $phone]);
    return $pdo->lastInsertId();
}

// 🔹 Fetch a user by ID (for profile display)
function get_user($id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT id, email, role, name, phone FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* 🔹 Fetch a user by email (for login)
function find_user_by_email($email) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
} 

// 🔹 Verify login credentials
function verify_user($email, $password) {
    $user = find_user_by_email($email);
    if ($user && password_verify($password, $user['password_hash'])) {
        return $user; // Valid user
    }
    return false; // Invalid credentials
} */

// 🔹 Get loan agent profile details
function get_agent_profile($agent_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM agent_profiles WHERE user_id = ?");
    $stmt->execute([$agent_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 🔹 Get farmer profile details
function get_farmer_profile($farmer_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM farmer_profiles WHERE user_id = ?");
    $stmt->execute([$farmer_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
