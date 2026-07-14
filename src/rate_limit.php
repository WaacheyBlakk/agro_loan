<?php
// src/rate_limit.php
//
// Very small brute-force / credential-stuffing guard for login forms.
// Requires the `login_attempts` table from sql/init.sql.
//
// USAGE in a login handler:
//   require_once __DIR__ . '/../src/rate_limit.php';
//   require_once __DIR__ . '/../src/db.php';
//   $pdo = getPDO();
//   $identifier = login_rate_limit_key($email);
//   if (is_rate_limited($pdo, $identifier)) {
//       $error = "Too many login attempts. Please try again in 15 minutes.";
//   } else {
//       // ... verify password ...
//       if ($passwordOk) {
//           clear_rate_limit($pdo, $identifier);
//       } else {
//           record_failed_attempt($pdo, $identifier);
//       }
//   }

const RATE_LIMIT_MAX_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW_MINUTES = 15;

/**
 * Builds a per-email+IP key so one bad actor can't lock out a real user
 * from a different IP, while still throttling repeated attempts against
 * a single account or from a single IP.
 */
function login_rate_limit_key(string $email): string {
    // Extract IP safely (supports IPv4, IPv6, or fallback)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Truncate email to protect against DB column size issues
    $cleanEmail = substr(strtolower(trim($email)), 0, 150);
    
    return $cleanEmail . '|' . $ip;
}

/**
 * Checks if the unique identifier has exceeded the failed attempt threshold.
 */
function is_rate_limited(PDO $pdo, string $identifier): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE identifier = ? AND attempt_time > (NOW() - INTERVAL ? MINUTE)"
    );
    
    // Bind parameters explicitly with correct types to prevent string-binding syntax issues in SQL
    $stmt->bindValue(1, $identifier, PDO::PARAM_STR);
    $stmt->bindValue(2, RATE_LIMIT_WINDOW_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    
    return (int)$stmt->fetchColumn() >= RATE_LIMIT_MAX_ATTEMPTS;
}

/**
 * Inserts a failed attempt, and performs automated table maintenance to prevent bloat.
 */
function record_failed_attempt(PDO $pdo, string $identifier): void {
    // 1. Housekeeping: Remove logs older than the window so the table doesn't grow indefinitely
    $cleanup = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_time < (NOW() - INTERVAL ? MINUTE)");
    $cleanup->bindValue(1, RATE_LIMIT_WINDOW_MINUTES, PDO::PARAM_INT);
    $cleanup->execute();

    // 2. Record the new failed attempt
    $stmt = $pdo->prepare("INSERT INTO login_attempts (identifier, attempt_time) VALUES (?, NOW())");
    $stmt->execute([$identifier]);
}

/**
 * Deletes login attempts on a successful authentication flow.
 */
function clear_rate_limit(PDO $pdo, string $identifier): void {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?");
    $stmt->execute([$identifier]);
}