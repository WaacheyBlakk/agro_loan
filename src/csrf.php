<?php
// src/csrf.php
//
// Shared CSRF protection helpers.

// Safely ensure the session is active before using CSRF functions.
// This prevents PHP warnings while ensuring $_SESSION is always accessible.
if (session_status() === PHP_SESSION_NONE) {
    // Detect if running over HTTPS to avoid dropping sessions on local HTTP development
    $is_secure_conn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                      (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
                      (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    if (!headers_sent()) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $is_secure_conn ? '1' : '0');
    }
    session_start();
}

/**
 * Returns the current CSRF token, generating one if it doesn't exist yet.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies the csrf_token submitted in $_POST against the session token.
 * Aborts the request with HTTP 403 if it's missing or doesn't match.
 */
function csrf_verify(): void {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken  = $_POST['csrf_token'] ?? '';

    if ($sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        http_response_code(403);
        die('Invalid or expired security token. Please go back, refresh the page, and try again.');
    }
}

/**
 * Verifies the CSRF token for AJAX/JSON endpoints.
 * Supports standard $_POST, raw JSON payloads, and the X-CSRF-TOKEN header.
 */
function csrf_verify_json(): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($sessionToken === '') {
        return false;
    }

    $postedToken = '';

    // 1. Check standard POST data
    if (isset($_POST['csrf_token'])) {
        $postedToken = $_POST['csrf_token'];
    } 
    // 2. Check HTTP Headers (commonly used in fetch/axios AJAX requests)
    elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $postedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } 
    // 3. Check JSON request body
    else {
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData) && isset($jsonData['csrf_token'])) {
            $postedToken = $jsonData['csrf_token'];
        }
    }

    return hash_equals($sessionToken, $postedToken);
}