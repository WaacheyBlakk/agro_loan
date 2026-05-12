<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/loan.php';

require_login();
$user = current_user();
if ($user['role'] !== 'agent') { echo "Only agents."; exit; }

$stage_id = intval($_POST['stage_id']);
$action = $_POST['action'] === 'approved' ? 'approved' : 'rejected';
$notes = $_POST['notes'] ?? null;

approve_stage_by_agent($user['id'], $stage_id, $action, $notes);

header('Location: view_application.php?id=' . intval($_GET['id'] ?? $_POST['app_id'] ?? 0));
exit;
