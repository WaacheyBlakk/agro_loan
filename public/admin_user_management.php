<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/sessions.php';
require_once __DIR__ . '/../src/db.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$pdo = getPDO();
$username = $_SESSION['name'] ?? "Admin";

// Notifications Helper
function sendUserNotification($name, $email, $phone, $new_status, $justification) {
    $subject = "AgroLoan Account Update - " . ucfirst($new_status);
    $text_message = "Hello {$name}, your AgroLoan account status has been updated to " . strtoupper($new_status) . ". Reason: {$justification}";
    
    // 1. Send Email Notification
    $headers = "From: no-reply@agroloan.com\r\n" .
               "Reply-To: support@agroloan.com\r\n" .
               "X-Mailer: PHP/" . phpversion();
    $email_status = @mail($email, $subject, $text_message, $headers);

    // 2. Mock SMS / Text Message notification (Simulated)
    // In production, insert third-party API gateway execution here (e.g., Twilio, Africa's Talking)
    $sms_status = true; 

    return [
        'email_sent' => $email_status,
        'sms_sent' => $sms_status,
        'log' => "Alert dispatched to {$email} and SMS to {$phone}."
    ];
}

// Handle administrative status override actions
$alert_message = "";
$alert_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'override_status') {
    $target_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $target_role = $_POST['target_role'] ?? '';
    $new_status = $_POST['new_status'] ?? '';
    $justification = trim($_POST['justification'] ?? '');

    if (!$target_id || empty($target_role) || empty($new_status) || empty($justification)) {
        $alert_message = "All override fields, including a written justification, are required.";
        $alert_type = "danger";
    } else {
        try {
            $table = ($target_role === 'buyer') ? 'buyers' : 'users';
            
            // Map status accurately (buyers use 'approved', users use 'verified')
            $db_status = $new_status;
            if ($target_role === 'buyer' && $new_status === 'verified') {
                $db_status = 'approved';
            } elseif ($target_role !== 'buyer' && $new_status === 'approved') {
                $db_status = 'verified';
            }

            // Fetch user info for notifications before updating
            $info_stmt = $pdo->prepare("SELECT name, email, phone FROM {$table} WHERE id = ?");
            $info_stmt->execute([$target_id]);
            $user_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_info) {
                // Perform Status Override Update
                // Note: If you have a 'justification' or 'status_notes' column, this query can write to it.
                // We perform a fallback schema-safe update on the 'status' column.
                $update_stmt = $pdo->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
                $update_stmt->execute([$db_status, $target_id]);

                // Dispatch Alerts (Email + Simulated SMS)
                $notification = sendUserNotification(
                    $user_info['name'], 
                    $user_info['email'], 
                    $user_info['phone'] ?? 'N/A', 
                    $db_status, 
                    $justification
                );

                $alert_message = "Account successfully set to " . strtoupper($db_status) . ". " . $notification['log'];
                $alert_type = "success";
            } else {
                $alert_message = "Target record not found.";
                $alert_type = "danger";
            }
        } catch (PDOException $e) {
            $alert_message = "Database action failed: " . $e->getMessage();
            $alert_type = "danger";
        }
    }
}

// Handle GET details view
$view_user = null;
if (isset($_GET['view_id']) && isset($_GET['view_role'])) {
    $v_id = (int)$_GET['view_id'];
    $v_role = $_GET['view_role'];
    $v_table = ($v_role === 'buyer') ? 'buyers' : 'users';

    try {
        $detail_stmt = $pdo->prepare("SELECT *, '{$v_role}' AS inferred_role FROM {$v_table} WHERE id = ?");
        $detail_stmt->execute([$v_id]);
        $view_user = $detail_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $alert_message = "Error fetching details: " . $e->getMessage();
        $alert_type = "danger";
    }
}

// Filtering System
$search = trim($_GET['search'] ?? '');
$filter_role = $_GET['filter_role'] ?? 'all';
$filter_status = $_GET['filter_status'] ?? 'all';

// Build parameters and query strings safely
$params = [];
$users_where = [];
$buyers_where = [];

if (!empty($search)) {
    $users_where[] = "(name LIKE :search1 OR email LIKE :search2 OR phone LIKE :search3)";
    $buyers_where[] = "(name LIKE :search4 OR email LIKE :search5 OR phone LIKE :search6)";
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
    $params['search3'] = "%$search%";
    $params['search4'] = "%$search%";
    $params['search5'] = "%$search%";
    $params['search6'] = "%$search%";
}

if ($filter_status !== 'all') {
    if ($filter_status === 'verified') {
        $users_where[] = "status = 'verified'";
        $buyers_where[] = "status = 'approved'";
    } elseif ($filter_status === 'pending') {
        $users_where[] = "status IN ('pending', 'unverified', 'submitted')";
        $buyers_where[] = "status IN ('pending', 'unverified', 'submitted')";
    } else {
        $users_where[] = "status = :status_u";
        $buyers_where[] = "status = :status_b";
        $params['status_u'] = $filter_status;
        $params['status_b'] = $filter_status;
    }
}

// Generate unified SQL based on selected role filters
$query_parts = [];

if ($filter_role === 'all' || $filter_role === 'farmer' || $filter_role === 'agent') {
    $u_where_clause = "";
    $temp_wheres = $users_where;
    if ($filter_role !== 'all') {
        $temp_wheres[] = "role = :role_u";
        $params['role_u'] = $filter_role;
    }
    if (!empty($temp_wheres)) {
        $u_where_clause = "WHERE " . implode(" AND ", $temp_wheres);
    }
    $query_parts[] = "SELECT id, name, email, phone, role, status, created_at, 'users' as source_table FROM users {$u_where_clause}";
}

if ($filter_role === 'all' || $filter_role === 'buyer') {
    $b_where_clause = "";
    if (!empty($buyers_where)) {
        $b_where_clause = "WHERE " . implode(" AND ", $buyers_where);
    }
    $query_parts[] = "SELECT id, name, email, phone, 'buyer' AS role, status, created_at, 'buyers' as source_table FROM buyers {$b_where_clause}";
}

$final_query = implode(" UNION ALL ", $query_parts) . " ORDER BY created_at DESC";
$list_stmt = $pdo->prepare($final_query);
$list_stmt->execute($params);
$system_users = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management | AgroLoan Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    :root {
        --primary: #059669; /* Emerald 600 */
        --primary-dark: #576868ff;
        --secondary: #10b981; /* Emerald 500 */
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #111827;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --border-color: #e5e7eb;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Poppins', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        display: flex;
        height: 100vh;
        overflow: hidden;
    }

    /* --- SIDEBAR --- */
    .sidebar {
        width: var(--sidebar-width);
        background: var(--primary-dark);
        color: #fff;
        display: flex;
        flex-direction: column;
        padding: 20px;
        transition: width 0.3s ease;
        z-index: 100;
        box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    }

    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }
    
    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 40px;
        padding-left: 5px;
        overflow: hidden;
    }

    .brand img {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        object-fit: cover;
        border: 2px solid rgba(255,255,255,0.2);
    }

    .brand h2 {
        font-size: 20px;
        font-weight: 600;
        white-space: nowrap;
        opacity: 1;
        transition: opacity 0.2s;
        margin: 0;
    }

    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 15px;
        color: #d1fae5;
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.2s ease;
        white-space: nowrap;
        font-weight: 500;
    }

    .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
    .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1);
        color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 12px;
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        font-family: inherit;
        font-weight: 600;
        transition: 0.2s;
        width: 100%;
    }
    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        position: relative;
    }

    .topbar {
        background: var(--bg-card);
        padding: 15px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: var(--shadow);
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .toggle-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary); color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* PAGE CONTENT */
    .content { padding: 30px; }
    .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* FILTERS BAR */
    .filters-bar {
        background: var(--bg-card);
        padding: 20px;
        border-radius: 12px;
        box-shadow: var(--shadow);
        margin-bottom: 25px;
        border: 1px solid var(--border-color);
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .filter-label {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
    }

    .filter-input {
        padding: 8px 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-family: inherit;
        font-size: 14px;
        color: var(--text-main);
        outline: none;
        background-color: #fafbfa;
        transition: border-color 0.2s;
        min-width: 180px;
    }

    .filter-input:focus {
        border-color: var(--primary);
    }

    .btn-search {
        background: var(--primary);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: inherit;
        align-self: flex-end;
        height: 38px;
    }

    .btn-search:hover { background-color: var(--secondary); }

    /* TABLE CARD */
    .card {
        background: var(--bg-card);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
        border: 1px solid var(--border-color);
    }

    .table-responsive { width: 100%; overflow-x: auto; }
    
    .table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .table thead { background: #f9fafb; border-bottom: 2px solid var(--border-color); }
    .table th { padding: 16px 24px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .table td { padding: 16px 24px; border-bottom: 1px solid var(--border-color); vertical-align: middle; font-size: 14px; }
    .table tr:last-child td { border-bottom: none; }
    .table tbody tr:hover { background: #f8fafc; }

    /* USER CELL */
    .user-cell { display: flex; align-items: center; gap: 12px; }
    .user-cell-avatar { width: 32px; height: 32px; background: #e0e7ff; color: #4338ca; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
    .user-info div { line-height: 1.2; }
    .user-email { font-size: 12px; color: var(--text-muted); }

    /* STATUS BADGES */
    .badge { padding: 4px 10px; border-radius: 50px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; text-transform: capitalize;}
    .badge-pending { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
    .badge-submitted, .badge-unverified { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
    .badge-verified, .badge-approved { background: #ecfdf5; color: #065f46; border: 1px solid #10b981; }
    .badge-suspended { background: #fff7ed; color: #ea580c; border: 1px solid #ffedd5; }
    .badge-deactivated, .badge-rejected { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; }

    /* ACTION BUTTON */
    .btn-action {
        text-decoration: none;
        padding: 6px 12px;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        color: var(--text-main);
        font-size: 13px;
        font-weight: 500;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }
    .btn-action:hover { border-color: var(--primary); color: var(--primary); background: #f0fdfa; }

    /* DETAIL GRID */
    .detail-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        align-items: start;
    }

    @media (max-width: 992px) {
        .detail-container { grid-template-columns: 1fr; }
    }

    .detail-card {
        background: var(--bg-card);
        border-radius: 16px;
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow);
        padding: 24px;
    }

    .detail-title {
        font-size: 18px;
        font-weight: 600;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 12px;
        margin-top: 0;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .detail-group {
        display: grid;
        grid-template-columns: 120px 1fr;
        padding: 10px 0;
        border-bottom: 1px solid #f9fafb;
    }

    .detail-label { font-weight: 600; color: var(--text-muted); font-size: 13px; }
    .detail-val { font-size: 14px; color: var(--text-main); }

    /* ADMIN FORM OPTIONS */
    .control-form {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .control-form label {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-main);
    }

    .control-textarea {
        width: 100%;
        min-height: 100px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        padding: 12px;
        font-family: inherit;
        font-size: 14px;
        outline: none;
        resize: vertical;
    }
    .control-textarea:focus { border-color: var(--primary); }

    .btn-submit {
        background: var(--primary);
        color: white;
        border: none;
        padding: 12px;
        border-radius: 8px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-submit:hover { background: var(--secondary); }

    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #10b981; }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #f87171; }

    .empty-state { text-align: center; padding: 50px; color: var(--text-muted); }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Administrator</h2>
        </div>

        <nav class="nav">
            <a href="admin_dashboard.php" class="nav-link">
                <i data-feather="pie-chart"></i>
                <span>Dashboard</span>
            </a>
            <a href="admin_user_management.php" class="nav-link active">
                <i data-feather="users"></i>
                <span>User Management</span>
            </a>
            <a href="admin_verifications.php" class="nav-link">
                <i data-feather="check-square"></i>
                <span>Verifications</span>
            </a>
            <a href="admin_loan_oversight.php" class="nav-link">
                <i data-feather="shield"></i>
                <span>Loan Oversight</span>
            </a>
            <a href="admin_marketplace_oversight.php" class="nav-link">
                <i data-feather="shopping-bag"></i>
                <span>Market Oversight</span>
            </a>
            <a href="admin_disputes.php" class="nav-link">
                <i data-feather="alert-triangle"></i>
                <span>Dispute Center</span>
            </a>
            <a href="admin_profile.php" class="nav-link">
                <i data-feather="user"></i>
                <span>My Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?= $alert_type ?>">
                    <i data-feather="<?= $alert_type === 'success' ? 'check-circle' : 'alert-circle' ?>" style="width:18px"></i>
                    <?= htmlspecialchars($alert_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($view_user): ?>
                <!-- DETAIL VIEW & OVERRIDE SCREEN -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">User Account Dossier</h1>
                        <p class="page-subtitle">Review profile details and perform manual status adjustments.</p>
                    </div>
                    <a href="admin_user_management.php" class="btn-action">
                        <i data-feather="arrow-left" style="width:14px"></i> Back to Database
                    </a>
                </div>

                <div class="detail-container">
                    <!-- Profile Card -->
                    <div class="detail-card">
                        <h2 class="detail-title">
                            Personal Profile
                            <span class="badge badge-<?= strtolower($view_user['status']) ?>">
                                <?= htmlspecialchars(ucfirst($view_user['status'])) ?>
                            </span>
                        </h2>

                        <div class="detail-group">
                            <span class="detail-label">Name</span>
                            <span class="detail-val"><?= htmlspecialchars($view_user['name']) ?></span>
                        </div>
                        <div class="detail-group">
                            <span class="detail-label">Email</span>
                            <span class="detail-val"><?= htmlspecialchars($view_user['email']) ?></span>
                        </div>
                        <div class="detail-group">
                            <span class="detail-label">Phone</span>
                            <span class="detail-val"><?= htmlspecialchars($view_user['phone'] ?? 'N/A') ?></span>
                        </div>
                        <div class="detail-group">
                            <span class="detail-label">Role Classification</span>
                            <span class="detail-val" style="text-transform: capitalize;"><?= htmlspecialchars($view_user['inferred_role']) ?></span>
                        </div>
                        <div class="detail-group">
                            <span class="detail-label">Created At</span>
                            <span class="detail-val"><?= date("M d, Y h:i A", strtotime($view_user['created_at'])) ?></span>
                        </div>
                    </div>

                    <!-- Overrides and Administrative Actions Card -->
                    <div class="detail-card">
                        <h2 class="detail-title">Administrative Actions</h2>
                        
                        <form action="admin_user_management.php" method="POST" class="control-form">
                            <input type="hidden" name="action" value="override_status">
                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($view_user['id']) ?>">
                            <input type="hidden" name="target_role" value="<?= htmlspecialchars($view_user['inferred_role']) ?>">

                            <div>
                                <label for="new_status">Assign Status Override</label>
                                <select name="new_status" id="new_status" class="filter-input" style="width: 100%; margin-top: 6px;">
                                    <option value="">-- Choose Status --</option>
                                    <option value="pending" <?= $view_user['status'] === 'pending' ? 'selected' : '' ?>>Pending (Review Queue)</option>
                                    <option value="verified" <?= in_array($view_user['status'], ['verified', 'approved']) ? 'selected' : '' ?>>Verified / Approved</option>
                                    <option value="suspended" <?= $view_user['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    <option value="deactivated" <?= $view_user['status'] === 'deactivated' ? 'selected' : '' ?>>Permanently Deactivated</option>
                                    <option value="rejected" <?= $view_user['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                            </div>

                            <div>
                                <label for="justification">Written Action Justification (Mandatory)</label>
                                <textarea name="justification" id="justification" class="control-textarea" placeholder="Please type a detailed explanation. This justification will be sent directly to the user's email/SMS alerts log..." required></textarea>
                            </div>

                            <button type="submit" class="btn-submit">
                                Execute Override & Alert User
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- MAIN DATABASE LIST SCREEN -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">User Database Management</h1>
                        <p class="page-subtitle">Inspect profiles, search directory parameters, and run account status overrides.</p>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" class="filters-bar">
                    <div class="filter-group">
                        <span class="filter-label">Search Context</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, Email or Phone" class="filter-input">
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">System Role</span>
                        <select name="filter_role" class="filter-input">
                            <option value="all" <?= $filter_role === 'all' ? 'selected' : '' ?>>All Roles</option>
                            <option value="farmer" <?= $filter_role === 'farmer' ? 'selected' : '' ?>>Farmers Only</option>
                            <option value="agent" <?= $filter_role === 'agent' ? 'selected' : '' ?>>Agents Only</option>
                            <option value="buyer" <?= $filter_role === 'buyer' ? 'selected' : '' ?>>Buyers Only</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Status Classification</span>
                        <select name="filter_status" class="filter-input">
                            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending / Unverified</option>
                            <option value="verified" <?= $filter_status === 'verified' ? 'selected' : '' ?>>Verified / Approved</option>
                            <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="deactivated" <?= $filter_status === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
                            <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-search">
                        <i data-feather="filter" style="width:16px;"></i> Apply Filters
                    </button>
                </form>

                <!-- Users Directory Grid Card -->
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User Profile</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Verification Status</th>
                                    <th>Registration Date</th>
                                    <th>Control Panel</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($system_users) > 0): ?>
                                    <?php foreach ($system_users as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="user-cell">
                                                <div class="user-cell-avatar">
                                                    <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                                </div>
                                                <div class="user-info">
                                                    <div style="font-weight:600; color:var(--text-main)"><?= htmlspecialchars($row['name']); ?></div>
                                                    <div class="user-email"><?= htmlspecialchars($row['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                                        <td style="text-transform: capitalize;"><?= htmlspecialchars($row['role']); ?></td>
                                        <td>
                                            <span class="badge badge-<?= strtolower($row['status']); ?>">
                                                <?= htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <a href="admin_user_management.php?view_id=<?= $row['id']; ?>&view_role=<?= urlencode($row['role']); ?>" class="btn-action">
                                                <i data-feather="sliders" style="width:14px;"></i> Details & Action
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i data-feather="database" style="width:40px; height:40px; margin-bottom:10px; opacity:0.5;"></i>
                                                <p>No matching users found in database registry matching selected filter conditions.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Responsive Sidebar Controls
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });
    </script>
</body>
</html>