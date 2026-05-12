<?php
/**
 * Global Session Handler for AgroLoan System
 * Handles:
 *  - session start
 *  - authentication check
 *  - role-based access
 *  - user info loading
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper: Require login
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
}

// Helper: Require a specific role
function require_role($role) {
    require_login();

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        header("Location: login.php");
        exit();
    }
}

// Helper: Restrict to multiple roles
function require_roles($roles = []) {
    require_login();

    if (!in_array($_SESSION['role'], $roles)) {
        header("Location: login.php");
        exit();
    }
}

// Helper: Quickly get logged-in user information
function current_user() {
    return [
        'id'    => $_SESSION['user_id'] ?? null,
        'name'  => $_SESSION['name'] ?? null,
        'role'  => $_SESSION['role'] ?? null,
        'status'=> $_SESSION['status'] ?? null
    ];
}
?>
