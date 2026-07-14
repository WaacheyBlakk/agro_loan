<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
//agent_repayments.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$agent_id = $_SESSION['user_id'];
$pdo      = getPDO();

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repayment_id'], $_POST['action'])) {
    csrf_verify();
    
    $repayment_id = (int) $_POST['repayment_id'];
    $action       = in_array($_POST['action'], ['confirmed', 'rejected']) ? $_POST['action'] : null;
    $agent_note   = trim($_POST['agent_note'] ?? '');

    if (!$action) {
        $errorMsg = "Invalid action specified.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Fetch the repayment record (must belong to one of this agent's Stage > 3 loans)
            $stmt = $pdo->prepare("
                SELECT r.*, la.farmer_id, la.outstanding_balance, la.amount, la.agent_id,
                       ap.interest_rate
                  FROM loan_repayments r
                  JOIN loan_applications la ON r.loan_id = la.id
                  LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
                 WHERE r.id = ? 
                   AND la.agent_id = ? 
                   AND r.status = 'pending' 
                   AND la.current_stage > 3
            ");
            $stmt->execute([$repayment_id, $agent_id]);
            $repayment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$repayment) {
                throw new Exception("Repayment record not found, already processed, or loan has not reached the eligible stage.");
            }

            // 2. Update the repayment record status
            $upd = $pdo->prepare("
                UPDATE loan_repayments
                   SET status = ?, agent_note = ?, reviewed_by = ?, reviewed_at = NOW()
                 WHERE id = ?
            ");
            $upd->execute([$action, $agent_note ?: null, $agent_id, $repayment_id]);

            // 3. If CONFIRMED → update the loan's outstanding_balance
            if ($action === 'confirmed') {
                $principal    = (float) $repayment['amount'];
                $interestRate = (float) ($repayment['interest_rate'] ?? 0);
                $totalRepayable = $principal + ($principal * $interestRate / 100);

                // Current outstanding: use persisted value or compute fresh
                $currentOutstanding = $repayment['outstanding_balance'] !== null
                    ? (float) $repayment['outstanding_balance']
                    : $totalRepayable;

                $newOutstanding = max(0, $currentOutstanding - (float) $repayment['amount_paid']);

                // Persist new outstanding balance
                $updLoan = $pdo->prepare("
                    UPDATE loan_applications
                       SET outstanding_balance = ?
                     WHERE id = ?
                ");
                $updLoan->execute([$newOutstanding, $repayment['loan_id']]);

                // If fully paid, mark loan as completed
                if ($newOutstanding <= 0.01) {
                    $completeLoan = $pdo->prepare("
                        UPDATE loan_applications SET status = 'completed' WHERE id = ?
                    ");
                    $completeLoan->execute([$repayment['loan_id']]);
                }

                $successMsg = "Repayment of GHc " . number_format($repayment['amount_paid'], 2) . " confirmed. New outstanding balance: GHc " . number_format($newOutstanding, 2) . ".";
            } else {
                $successMsg = "Repayment rejected. The farmer will be notified.";
            }

            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorMsg = "Error: " . $e->getMessage();
        }
    }
}

// Fetch pending repayments specifically for loans in Stage > 3 (where farmers make repayments)
$pendingStmt = $pdo->prepare("
    SELECT r.*,
           la.title   AS loan_title,
           la.amount  AS loan_principal,
           la.outstanding_balance,
           u.name     AS farmer_name,
           u.email    AS farmer_email,
           ap.interest_rate
      FROM loan_repayments r
      JOIN loan_applications la ON r.loan_id = la.id
      JOIN users u               ON r.farmer_id = u.id
      LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
     WHERE la.agent_id = ? 
       AND r.status = 'pending' 
       AND la.current_stage > 3
     ORDER BY r.submitted_at DESC
");
$pendingStmt->execute([$agent_id]);
$pending_repayments = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$histStmt = $pdo->prepare("
    SELECT r.*,
           la.title   AS loan_title,
           la.amount  AS loan_principal,
           u.name     AS farmer_name,
           ap.interest_rate
      FROM loan_repayments r
      JOIN loan_applications la ON r.loan_id = la.id
      JOIN users u               ON r.farmer_id = u.id
      LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
     WHERE la.agent_id = ? AND r.status != 'pending'
     ORDER BY r.reviewed_at DESC
     LIMIT 50
");
$histStmt->execute([$agent_id]);
$reviewed_repayments = $histStmt->fetchAll(PDO::FETCH_ASSOC);

// Quick stats
$total_pending    = count($pending_repayments);
$total_confirmed  = count(array_filter($reviewed_repayments, fn($r) => $r['status'] === 'confirmed'));
$total_rejected   = count(array_filter($reviewed_repayments, fn($r) => $r['status'] === 'rejected'));
$total_confirmed_amt = array_sum(array_map(
    fn($r) => $r['status'] === 'confirmed' ? (float) $r['amount_paid'] : 0,
    $reviewed_repayments
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Repayment Review | AgroLoan Agent</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/feather-icons"></script>
<style>
    :root {
        --primary: #1e40af;
        --primary-dark: #172554;
        --secondary: #3b82f6;
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --danger: #ef4444;
        --warning: #f59e0b;
        --success: #10b981;
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        --shadow-lg: 0 10px 15px -3px rgba(15, 23, 42, 0.04), 0 4px 6px -4px rgba(15, 23, 42, 0.04);
        --border-color: #e2e8f0;
        --radius-sm: 8px;
        --radius-md: 12px;
        --radius-lg: 16px;
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; font-family: 'Poppins', sans-serif;
        background: var(--bg-body); color: var(--text-main);
        display: flex; height: 100vh; overflow: hidden;
    }

    /* ── SIDEBAR ── */
    .sidebar {
        width: var(--sidebar-width); background: var(--primary-dark); color: #fff;
        display: flex; flex-direction: column; padding: 20px;
        transition: width .3s; z-index: 100; box-shadow: 4px 0 10px rgba(0,0,0,.1);
        overflow: hidden;
    }
    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }
    .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; overflow: hidden; }
    .brand img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 2px solid rgba(255,255,255,.2); }
    .brand h2 { font-size: 20px; font-weight: 600; white-space: nowrap; opacity: 1; transition: opacity .2s; margin: 0; }
    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .nav-link {
        display: flex; align-items: center; gap: 14px; padding: 12px 15px;
        color: #dbeafe; text-decoration: none; border-radius: 10px;
        transition: all .2s; white-space: nowrap; font-weight: 500;
    }
    .nav-link:hover { background: rgba(255,255,255,.1); color: #fff; transform: translateX(4px); }
    .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(59,130,246,.4); }
    .nav-link svg { width: 20px; height: 20px; flex-shrink: 0; }
    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }
    .logout-btn {
        background: rgba(239,68,68,.1); color: #fca5a5; border: 1px solid rgba(239,68,68,.2);
        padding: 12px; border-radius: 10px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 10px; font-family: inherit; font-weight: 600; transition: .2s; width: 100%;
    }
    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* ── MAIN ── */
    .main { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
    .topbar {
        background: var(--bg-card); padding: 15px 30px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: var(--shadow-sm); position: sticky; top: 0; z-index: 50;
        border-bottom: 1px solid var(--border-color);
    }
    .toggle-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; }
    .toggle-btn:hover { color: var(--primary); }
    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary); color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* ── CONTENT ── */
    .content { padding: 32px 30px; max-width: 1400px; width: 100%; margin: 0 auto; }
    .page-title { font-size: 26px; font-weight: 700; color: var(--text-main); margin: 0 0 4px; letter-spacing: -0.5px; }
    .page-subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 32px; }

    /* Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 36px; }
    .stat-card {
        background: var(--bg-card); padding: 24px; border-radius: var(--radius-lg);
        box-shadow: var(--shadow); display: flex; align-items: center; gap: 20px;
        border: 1px solid var(--border-color); transition: var(--transition);
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
    .icon-box {
        width: 52px; height: 52px; border-radius: var(--radius-md);
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        box-shadow: inset 0 -2px 4px rgba(0,0,0,0.03);
    }
    .icon-box svg { width: 24px; height: 24px; stroke-width: 2.2px; }
    .stat-info h3 { margin: 0; font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; }
    .stat-info p  { margin: 6px 0 0; font-size: 24px; font-weight: 700; color: var(--text-main); line-height: 1.1; }
    .theme-orange { background: #fff7ed; color: #f97316; }
    .theme-green  { background: #f0fdf4; color: #16a34a; }
    .theme-red    { background: #fef2f2; color: #dc2626; }
    .theme-blue   { background: #eff6ff; color: #2563eb; }

    /* Alert Styling */
    .alert {
        padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 30px;
        display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 500;
        box-shadow: var(--shadow-sm); border-left: 4px solid;
    }
    .alert svg { flex-shrink: 0; width: 20px; height: 20px; }
    .alert.success { background: #f0fdf4; color: #15803d; border-color: #16a34a; }
    .alert.error   { background: #fef2f2; color: #b91c1c; border-color: #dc2626; }

    /* Section Header */
    .section-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
    .section-title  { font-size: 20px; font-weight: 700; color: var(--text-main); margin: 0; letter-spacing: -0.3px; }
    .count-badge {
        background: var(--warning); color: white; padding: 2px 10px;
        border-radius: 20px; font-size: 12px; font-weight: 700;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.2);
    }
    .count-badge.zero { background: #e2e8f0; color: var(--text-muted); box-shadow: none; }

    /* Repayment Cards Container */
    .repayment-cards { display: flex; flex-direction: column; gap: 24px; margin-bottom: 40px; }
    .repayment-card {
        background: var(--bg-card); border-radius: var(--radius-lg); padding: 28px;
        box-shadow: var(--shadow); border: 1px solid var(--border-color);
        display: flex; flex-direction: column; gap: 20px; transition: var(--transition);
        position: relative; overflow: hidden;
    }
    .repayment-card:hover { box-shadow: var(--shadow-lg); border-color: #cbd5e1; }
    .repayment-card::before {
        content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%;
        background: var(--secondary);
    }

    .rc-top { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 18px; }
    .rc-farmer-details { display: flex; flex-direction: column; }
    .rc-farmer-name { font-size: 18px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
    .rc-farmer-email { font-size: 13px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 6px; }
    .rc-farmer-email::before { content: '•'; color: var(--secondary); font-size: 16px; line-height: 1; }
    .rc-loan-title { font-size: 13px; color: var(--primary); font-weight: 600; margin-top: 8px; background: #eff6ff; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; width: fit-content; }
    
    .rc-type-badge { padding: 6px 14px; border-radius: 30px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .rc-type-badge.partial { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .rc-type-badge.full    { background: #faf5ff; color: #6d28d9; border: 1px solid #e9d5ff; }

    .rc-amounts {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
        background: #f8fafc; border-radius: var(--radius-md); padding: 18px;
        border: 1px solid #f1f5f9;
    }
    .rc-amt-item label { display: block; font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .8px; font-weight: 600; margin-bottom: 6px; }
    .rc-amt-item .val { font-size: 16px; font-weight: 700; color: var(--text-main); }
    .rc-amt-item .val.paid { color: var(--success); }
    .rc-amt-item .val.balance { color: var(--danger); }

    .rc-meta {
        display: flex; flex-wrap: wrap; gap: 16px 24px; font-size: 13px; color: var(--text-muted);
        background: #fafccc; border: 1px dashed #e2e8f0; border-radius: var(--radius-md); padding: 12px 18px;
    }
    .rc-meta span { display: inline-flex; align-items: center; gap: 6px; }

    .rc-proof-box { margin-bottom: 4px; }
    .rc-proof-link {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 16px; border-radius: var(--radius-md); background: #f1f5f9;
        color: #334155; font-size: 13px; font-weight: 600;
        text-decoration: none; transition: var(--transition); border: 1px solid #cbd5e1;
    }
    .rc-proof-link:hover { background: #e2e8f0; color: var(--text-main); }
    .rc-proof-link svg { width: 16px; height: 16px; }

    /* Action Form Layout */
    .action-form { border-top: 1px solid #f1f5f9; padding-top: 20px; }
    .action-row  { display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; }
    .note-group  { flex: 1; min-width: 280px; }
    .note-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: .8px; }
    .note-group textarea {
        width: 100%; padding: 12px; border-radius: var(--radius-sm); border: 1.5px solid #cbd5e1;
        font-family: inherit; font-size: 13px; color: var(--text-main);
        resize: vertical; min-height: 52px; outline: none; transition: var(--transition);
        background: #ffffff;
    }
    .note-group textarea:focus { border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(59,130,246,.15); }

    .actions-buttons-container { display: flex; gap: 12px; }
    .btn-confirm, .btn-reject {
        padding: 12px 24px; border-radius: var(--radius-sm); border: none;
        font-family: inherit; font-size: 13px; font-weight: 600;
        cursor: pointer; display: flex; align-items: center; gap: 8px; white-space: nowrap;
        transition: var(--transition); box-shadow: var(--shadow-sm);
    }
    .btn-confirm { background: var(--success); color: white; }
    .btn-confirm:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(16, 185, 129, 0.25); }
    .btn-reject  { background: #fee2e2; color: var(--danger); border: 1px solid #fecaca; }
    .btn-reject:hover { background: var(--danger); color: white; border-color: var(--danger); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(239, 68, 110, 0.25); }

    /* Reviewed History Table Styling */
    .table-container { background: var(--bg-card); border-radius: var(--radius-lg); padding: 24px; box-shadow: var(--shadow); border: 1px solid var(--border-color); overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    th, td { padding: 16px 20px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: .8px; color: var(--text-muted); font-weight: 600; background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafbfc; }

    .badge { padding: 6px 12px; border-radius: 30px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
    .badge.pending   { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .badge.confirmed { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .badge.rejected  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .badge.partial   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .badge.full      { background: #faf5ff; color: #6d28d9; border: 1px solid #e9d5ff; }

    .empty-state { text-align: center; padding: 48px 24px; color: var(--text-muted); }
    .empty-state svg { width: 44px; height: 44px; margin-bottom: 16px; opacity: .5; color: var(--text-muted); }

    @media (max-width: 992px) {
        .repayment-card { padding: 20px; }
        .rc-amounts { grid-template-columns: 1fr 1fr; }
        .rc-amounts div:last-child { grid-column: span 2; }
    }
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .rc-top { flex-direction: column; align-items: flex-start; }
        .rc-amounts { grid-template-columns: 1fr; }
        .rc-amounts div:last-child { grid-column: auto; }
        .action-row { flex-direction: column; align-items: stretch; }
        .actions-buttons-container { width: 100%; justify-content: flex-end; }
        .stats-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
        <h2>AgroLoan Agent</h2>
    </div>
    <nav class="nav">
        <a href="agent_dashboard.php" class="nav-link">
            <i data-feather="home"></i><span>Dashboard</span>
        </a>
        <a href="farmer_vetting.php" class="nav-link">
            <i data-feather="users"></i><span>Farmer Vetting</span>
        </a>
        <a href="proof_verification.php" class="nav-link">
            <i data-feather="check-square"></i><span>Proof Verify</span>
        </a>
        <a href="agent_repayments.php" class="nav-link active">
            <i data-feather="credit-card"></i><span>Repayments</span>
        </a>
        <a href="dispute_center.php" class="nav-link">
            <i data-feather="alert-triangle"></i><span>Dispute Center</span>
        </a>
        <a href="agent_profile.php" class="nav-link">
            <i data-feather="user"></i><span>My Profile</span>
        </a>
    </nav>
    <form action="logout.php" method="POST">
        <?php if (isset($_SESSION['csrf_token'])): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <?php endif; ?>
        <button class="logout-btn">
            <i data-feather="log-out"></i><span>Logout</span>
        </button>
    </form>
</aside>

<main class="main">
    <header class="topbar">
        <button id="toggleBtn" class="toggle-btn"><i data-feather="menu"></i></button>
        <div class="user-profile">
            <div style="text-align:right;margin-right:8px;">
                <div style="font-size:14px;font-weight:600;"><?= htmlspecialchars($username) ?></div>
                <div style="font-size:12px;color:var(--text-muted);">Agent</div>
            </div>
            <div class="user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
        </div>
    </header>

    <div class="content">
        <h1 class="page-title">Repayment Review</h1>
        <p class="page-subtitle">Review and confirm Stage 3 repayments submitted by your farmers. Confirming updates their outstanding loan balance.</p>

        <!-- Alerts -->
        <?php if (!empty($successMsg)): ?>
        <div class="alert success">
            <i data-feather="check-circle"></i>
            <span><?= htmlspecialchars($successMsg) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($errorMsg)): ?>
        <div class="alert error">
            <i data-feather="alert-circle"></i>
            <span><?= htmlspecialchars($errorMsg) ?></span>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon-box theme-orange"><i data-feather="clock"></i></div>
                <div class="stat-info">
                    <h3>Pending Review</h3>
                    <p><?= $total_pending ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon-box theme-green"><i data-feather="check-circle"></i></div>
                <div class="stat-info">
                    <h3>Confirmed</h3>
                    <p><?= $total_confirmed ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon-box theme-red"><i data-feather="x-circle"></i></div>
                <div class="stat-info">
                    <h3>Rejected</h3>
                    <p><?= $total_rejected ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon-box theme-blue"><i data-feather="trending-up"></i></div>
                <div class="stat-info">
                    <h3>Total Collected</h3>
                    <p style="font-size:18px;">GHc <?= number_format($total_confirmed_amt, 2) ?></p>
                </div>
            </div>
        </div>

        <!-- ── PENDING REPAYMENTS ── -->
        <div class="section-header">
            <h2 class="section-title">Pending Repayments (Stage 3 Loans)</h2>
            <span class="count-badge <?= $total_pending == 0 ? 'zero' : '' ?>">
                <?= $total_pending ?>
            </span>
        </div>

        <?php if (empty($pending_repayments)): ?>
        <div class="table-container" style="margin-bottom:36px;">
            <div class="empty-state">
                <i data-feather="inbox"></i>
                <p style="font-weight:600; margin-top:8px;">No pending repayments</p>
                <p style="font-size:13px; margin: 4px 0 0;">When farmers submit repayments for eligible loans (Stage 3 completed), they will appear here.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="repayment-cards">
            <?php foreach ($pending_repayments as $r):
                $principal    = (float) $r['loan_principal'];
                $interestRate = (float) ($r['interest_rate'] ?? 0);
                $totalRepayable = $principal + ($principal * $interestRate / 100);
                $outstanding   = $r['outstanding_balance'] !== null
                    ? (float) $r['outstanding_balance']
                    : $totalRepayable;
                $proofPath = "../uploads/repayments/loan_{$r['loan_id']}/" . basename($r['proof_filename'] ?? '');
            ?>
            <div class="repayment-card">
                <div>
                    <div class="rc-top">
                        <div class="rc-farmer-details">
                            <div class="rc-farmer-name"><?= htmlspecialchars($r['farmer_name']) ?></div>
                            <div class="rc-farmer-email"><?= htmlspecialchars($r['farmer_email']) ?></div>
                            <div class="rc-loan-title"><i data-feather="file-text" style="width:14px; height:14px;"></i> <?= htmlspecialchars($r['loan_title'] ?? 'Loan #' . $r['loan_id']) ?></div>
                        </div>
                        <span class="rc-type-badge <?= $r['repayment_type'] ?>">
                            <?= ucfirst($r['repayment_type']) ?> Repayment
                        </span>
                    </div>

                    <div class="rc-amounts" style="margin-top: 18px; margin-bottom: 18px;">
                        <div class="rc-amt-item">
                            <label>Amount Paid</label>
                            <div class="val paid">GHc <?= number_format($r['amount_paid'], 2) ?></div>
                        </div>
                        <div class="rc-amt-item">
                            <label>Balance Before</label>
                            <div class="val">GHc <?= number_format($r['balance_before'], 2) ?></div>
                        </div>
                        <div class="rc-amt-item">
                            <label>Balance After</label>
                            <div class="val balance">GHc <?= number_format($r['balance_after'], 2) ?></div>
                        </div>
                    </div>

                    <div class="rc-meta" style="margin-bottom: 20px;">
                        <span><i data-feather="briefcase" style="width:14px; height:14px; color:var(--text-muted);"></i> Principal: GHc <?= number_format($principal, 2) ?></span>
                        <span><i data-feather="percent" style="width:14px; height:14px; color:var(--text-muted);"></i> Interest: <?= $interestRate ?>%</span>
                        <span><i data-feather="dollar-sign" style="width:14px; height:14px; color:var(--text-muted);"></i> Repayable: GHc <?= number_format($totalRepayable, 2) ?></span>
                        <span><i data-feather="calendar" style="width:14px; height:14px; color:var(--text-muted);"></i> Submitted: <?= date('M d, Y H:i', strtotime($r['submitted_at'])) ?></span>
                    </div>

                    <div class="rc-proof-box" style="margin-bottom: 24px;">
                        <?php if (!empty($r['proof_filename'])): ?>
                        <a href="<?= htmlspecialchars($proofPath) ?>" target="_blank" class="rc-proof-link">
                            <i data-feather="image"></i>
                            View Payment Proof (<?= strtoupper(pathinfo($r['proof_filename'], PATHINFO_EXTENSION)) ?>)
                        </a>
                        <?php else: ?>
                        <div style="font-size:13px;color:var(--warning);display:flex;align-items:center;gap:6px;">
                            <i data-feather="alert-circle" style="width:16px; height:16px;"></i> No proof file uploaded.
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="action-form">
                        <div class="action-row">
                            <div class="note-group">
                                <label>Agent Note (optional)</label>
                                <textarea form="form-<?= $r['id'] ?>" name="agent_note"
                                          placeholder="Add an internal note or message for this farmer…"></textarea>
                            </div>

                            <div class="actions-buttons-container">
                                <form method="POST" id="form-<?= $r['id'] ?>" style="display:flex;gap:12px;align-items:center;">
                                    <?php if (isset($_SESSION['csrf_token'])): ?>
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="repayment_id" value="<?= $r['id'] ?>">

                                    <button type="submit" name="action" value="confirmed"
                                            class="btn-confirm"
                                            onclick="return confirm('Confirm this repayment of GHc <?= number_format($r['amount_paid'], 2) ?>?\n\nThis will update the farmer\'s outstanding balance.')">
                                        <i data-feather="check"></i> Confirm
                                    </button>

                                    <button type="submit" name="action" value="rejected"
                                            class="btn-reject"
                                            onclick="return confirm('Reject this repayment?\n\nThe farmer\'s balance will NOT be updated.')">
                                        <i data-feather="x"></i> Reject
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── REVIEWED HISTORY ── -->
        <div class="section-header">
            <h2 class="section-title">Review History</h2>
        </div>
        <div class="table-container">
            <?php if (empty($reviewed_repayments)): ?>
            <div class="empty-state">
                <i data-feather="list"></i>
                <p style="font-weight:600; margin-top:8px;">No reviewed repayments yet.</p>
            </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Farmer</th>
                        <th>Loan</th>
                        <th>Type</th>
                        <th>Amount Paid</th>
                        <th>Balance After</th>
                        <th>Status</th>
                        <th>Reviewed</th>
                        <th>Note</th>
                        <th>Proof</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reviewed_repayments as $r): ?>
                <tr>
                    <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($r['farmer_name']) ?></td>
                    <td><?= htmlspecialchars($r['loan_title'] ?? 'Loan #' . $r['loan_id']) ?></td>
                    <td><span class="badge <?= $r['repayment_type'] ?>"><?= ucfirst($r['repayment_type']) ?></span></td>
                    <td style="font-weight:700;color:var(--success);">GHc <?= number_format($r['amount_paid'], 2) ?></td>
                    <td style="font-weight:600;">GHc <?= number_format($r['balance_after'], 2) ?></td>
                    <td><span class="badge <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td style="color:var(--text-muted); font-size:12px;"><?= $r['reviewed_at'] ? date('M d, Y H:i', strtotime($r['reviewed_at'])) : '—' ?></td>
                    <td style="font-size:12px;color:var(--text-muted);max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($r['agent_note'] ?? '') ?>">
                        <?= htmlspecialchars($r['agent_note'] ?? '—') ?>
                    </td>
                    <td>
                        <?php if (!empty($r['proof_filename'])): ?>
                        <a href="../uploads/repayments/loan_<?= $r['loan_id'] ?>/<?= htmlspecialchars($r['proof_filename']) ?>"
                           target="_blank"
                           style="color:var(--secondary);font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                            <i data-feather="eye" style="width:14px;height:14px;"></i> View
                        </a>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
feather.replace();

const toggleBtn = document.getElementById('toggleBtn');
const sidebar   = document.getElementById('sidebar');
toggleBtn.addEventListener('click', () => {
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
    }
    setTimeout(() => feather.replace(), 310);
});
</script>
</body>
</html>