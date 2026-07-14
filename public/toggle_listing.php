<?php
$src_dir = file_exists(__DIR__ . '/../../src/db.php') ? __DIR__ . '/../../src/' : __DIR__ . '/../src/';
require_once $src_dir . 'security_headers.php';
require_once $src_dir . 'csrf.php';
require_once $src_dir . 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Verify CSRF
try {
    csrf_verify();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$user_role = $_SESSION['role'] ?? 'farmer';

if (!$user_id || $user_role !== 'farmer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$listing_id = filter_input(INPUT_POST, 'listing_id', FILTER_VALIDATE_INT);
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$listing_id || !in_array($action, ['activate', 'deactivate'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid listing ID or action']);
    exit;
}

$pdo = getPDO();

// Verify that the user owns the listing
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM produce_listings WHERE id = ? AND farmer_id = ?");
$checkStmt->execute([$listing_id, $user_id]);
if ($checkStmt->fetchColumn() == 0) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// Determine visibility status value
$new_status = ($action === 'activate') ? 'active' : 'deactivated';

try {
    // Database check to make sure the column is prepared
    $colCheck = $pdo->query("SHOW COLUMNS FROM `produce_listings` LIKE 'status'");
    if ($colCheck && $colCheck->rowCount() > 0) {
        $updateStmt = $pdo->prepare("UPDATE produce_listings SET status = ? WHERE id = ?");
        $success = $updateStmt->execute([$new_status, $listing_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database status column not prepared']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to modify listing visibility']);
}