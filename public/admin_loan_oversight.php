<?php
// public/admin_loan_oversight.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

// Role Verification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Administrator';
$pdo = getPDO();

$successMessage = '';
$errorMessage = '';

/**
 * Dispatches an HTML email notification from the administration desk.
 */
function send_admin_email_notification($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: AgroLoan Admin Portal <admin-support@agroloan.com>" . "\r\n";
    return @mail($to, $subject, $message, $headers);
}

/**
 * Logs SMS dispatches for local administrative record keeping.
 */
function log_admin_sms_notification($phone, $message) {
    error_log("ADMIN SMS Dispatch to {$phone}: {$message}");
    return true;
}

/* ==========================================
   ADMINISTRATIVE INTERVENTION PROCESSING
   ========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intervention_type'])) {
    $intervention = $_POST['intervention_type'];
    $loan_id = isset($_POST['loan_id']) ? intval($_POST['loan_id']) : 0;

    try {
        $pdo->beginTransaction();

        // 1. Manually Override Dispute Case
        if ($intervention === 'resolve_dispute') {
            $dispute_id = intval($_POST['dispute_id'] ?? 0);
            $decision = trim($_POST['admin_decision'] ?? '');
            $status = $_POST['status'] ?? 'resolved'; // resolved or dismissed

            if ($dispute_id <= 0 || empty($decision)) {
                throw new Exception("Please specify a dispute reference and provide a decision message.");
            }

            $stmt = $pdo->prepare("UPDATE disputes SET status = ?, admin_decision = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $decision, $dispute_id]);

            // Notify disputing parties
            $dispStmt = $pdo->prepare("
                SELECT d.title, uc.name AS creator_name, uc.email AS creator_email, ud.name AS defendant_name, ud.email AS defendant_email
                FROM disputes d
                JOIN users uc ON d.creator_id = uc.id
                JOIN users ud ON d.defendant_id = ud.id
                WHERE d.id = ?
            ");
            $dispStmt->execute([$dispute_id]);
            $parties = $dispStmt->fetch(PDO::FETCH_ASSOC);

            if ($parties) {
                $subject = "Administrative Decision Issued: " . $parties['title'];
                $email_body = "
                    <h2>Administrative Review Action Notice</h2>
                    <p>The system administration team has formally closed the dispute file regarding '<strong>{$parties['title']}</strong>'.</p>
                    <p><strong>Official Directive/Decision:</strong></p>
                    <blockquote style='background:#f3f4f6; padding:15px; border-left:4px solid #4f46e5;'>{$decision}</blockquote>
                    <p>Status set to: <strong>" . ucfirst($status) . "</strong></p>
                    <p>Please log in to review any updated transaction statuses or repayments related to this override.</p>
                ";

                if (!empty($parties['creator_email'])) {
                    send_admin_email_notification($parties['creator_email'], $subject, $email_body);
                }
                if (!empty($parties['defendant_email'])) {
                    send_admin_email_notification($parties['defendant_email'], $subject, $email_body);
                }
            }

            $successMessage = "Dispute reference #{$dispute_id} updated. Decision registered and parties notified.";
        }

        // 2. Override Stage Status (Unblocking Stalls)
        elseif ($intervention === 'override_stage') {
            $stage_id = intval($_POST['stage_id'] ?? 0);
            $new_stage_status = $_POST['stage_status'] ?? '';
            $disbursed_flag = isset($_POST['disbursed']) ? intval($_POST['disbursed']) : 0;

            if ($stage_id <= 0 || empty($new_stage_status)) {
                throw new Exception("Missing required stage identifier or status override value.");
            }

            // Get original stage details prior to manual override
            $origStmt = $pdo->prepare("SELECT application_id, required_amount, disbursed, stage_number FROM loan_stages WHERE id = ?");
            $origStmt->execute([$stage_id]);
            $origStage = $origStmt->fetch(PDO::FETCH_ASSOC);

            if (!$origStage) {
                throw new Exception("Stage record not identified.");
            }

            // Update individual stage status
            $upd = $pdo->prepare("UPDATE loan_stages SET status = ?, disbursed = ? WHERE id = ?");
            $upd->execute([$new_stage_status, $disbursed_flag, $stage_id]);

            // Adjust disbursement amount tracker on main application if flag flipped
            if ($disbursed_flag == 1 && $origStage['disbursed'] == 0) {
                $updApp = $pdo->prepare("UPDATE loan_applications SET disbursed_amount = disbursed_amount + ? WHERE id = ?");
                $updApp->execute([$origStage['required_amount'], $origStage['application_id']]);
            } elseif ($disbursed_flag == 0 && $origStage['disbursed'] == 1) {
                $updApp = $pdo->prepare("UPDATE loan_applications SET disbursed_amount = GREATEST(0, disbursed_amount - ?) WHERE id = ?");
                $updApp->execute([$origStage['required_amount'], $origStage['application_id']]);
            }

            // Check if stage is marked completed and we need to increment active stage indexes
            if ($new_stage_status === 'completed') {
                $nextStage = $origStage['stage_number'] + 1;
                $updIndex = $pdo->prepare("UPDATE loan_applications SET current_stage = ? WHERE id = ?");
                $updIndex->execute([$nextStage, $origStage['application_id']]);

                // Check overall application complete logic
                $checkRem = $pdo->prepare("SELECT COUNT(*) FROM loan_stages WHERE application_id = ? AND status != 'completed'");
                $checkRem->execute([$origStage['application_id']]);
                if ($checkRem->fetchColumn() == 0) {
                    $pdo->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?")->execute([$origStage['application_id']]);
                }
            }

            $successMessage = "Stage reference #{$stage_id} manually adjusted to '{$new_stage_status}'. Disbursement limits recalculated.";
        }

        // 3. Override Specific Evidence Proof
        elseif ($intervention === 'override_proof') {
            $proof_id = intval($_POST['proof_id'] ?? 0);
            $new_proof_status = $_POST['proof_status'] ?? ''; // Approved, Rejected

            if ($proof_id <= 0 || empty($new_proof_status)) {
                throw new Exception("Specify a proof file and verification action.");
            }

            $upd = $pdo->prepare("UPDATE stage_proofs SET status = ? WHERE id = ?");
            $upd->execute([$new_proof_status, $proof_id]);

            $successMessage = "Proof document status manually overridden to '{$new_proof_status}'.";
        }

        // 4. Force Update Main Application Lifecycle Status
        elseif ($intervention === 'override_application') {
            $new_app_status = $_POST['app_status'] ?? '';
            $current_stage = intval($_POST['current_stage'] ?? 1);
            $outstanding = floatval($_POST['outstanding_balance'] ?? null);

            if (empty($new_app_status) || $loan_id <= 0) {
                throw new Exception("Select a clean application state reference.");
            }

            $upd = $pdo->prepare("
                UPDATE loan_applications 
                SET status = ?, current_stage = ?, outstanding_balance = ? 
                WHERE id = ?
            ");
            $upd->execute([$new_app_status, $current_stage, $outstanding, $loan_id]);

            $successMessage = "Main loan profile status overridden. Active stage state tracking restructured.";
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Intervention Aborted: " . $e->getMessage();
    }
}

// Read-only single drill-down load evaluation
$selected_loan_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$selected_loan = null;
$stages = [];
$disputes = [];
$repayments = [];

if ($selected_loan_id) {
    // Fetch loan parameters
    $stmt = $pdo->prepare("
        SELECT la.*, 
               uf.name AS farmer_name, uf.email AS farmer_email, uf.phone AS farmer_phone,
               ua.name AS agent_name, ua.email AS agent_email, ua.phone AS agent_phone,
               ap.interest_rate
        FROM loan_applications la
        LEFT JOIN users uf ON la.farmer_id = uf.id
        LEFT JOIN users ua ON la.agent_id = ua.id
        LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
        WHERE la.id = ?
    ");
    $stmt->execute([$selected_loan_id]);
    $selected_loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_loan) {
        // Fetch stages
        $stStmt = $pdo->prepare("SELECT * FROM loan_stages WHERE application_id = ? ORDER BY stage_number ASC");
        $stStmt->execute([$selected_loan_id]);
        $stages = $stStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch related dispute entries
        $dispStmt = $pdo->prepare("
            SELECT d.*, uc.name AS creator_name, ud.name AS defendant_name
            FROM disputes d
            JOIN users uc ON d.creator_id = uc.id
            JOIN users ud ON d.defendant_id = ud.id
            WHERE d.loan_id = ?
            ORDER BY d.created_at DESC
        ");
        $dispStmt->execute([$selected_loan_id]);
        $disputes = $dispStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch repayment files
        $repStmt = $pdo->prepare("SELECT * FROM loan_repayments WHERE loan_id = ? ORDER BY submitted_at DESC");
        $repStmt->execute([$selected_loan_id]);
        $repayments = $repStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch global list of loans for dashboard overview
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$dispute_filter = $_GET['has_dispute'] ?? '';

$query_str = "
    SELECT la.*, 
           uf.name AS farmer_name, 
           ua.name AS agent_name,
           (SELECT COUNT(*) FROM disputes d WHERE d.loan_id = la.id AND d.status = 'pending') AS active_disputes_count
    FROM loan_applications la
    LEFT JOIN users uf ON la.farmer_id = uf.id
    LEFT JOIN users ua ON la.agent_id = ua.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query_str .= " AND (la.title LIKE ? OR uf.name LIKE ? OR ua.name LIKE ?)";
    $bind = "%{$search}%";
    $params[] = $bind;
    $params[] = $bind;
    $params[] = $bind;
}

if (!empty($status_filter)) {
    $query_str .= " AND la.status = ?";
    $params[] = $status_filter;
}

if ($dispute_filter === '1') {
    $query_str .= " AND la.id IN (SELECT DISTINCT loan_id FROM disputes WHERE status = 'pending')";
}

$query_str .= " ORDER BY active_disputes_count DESC, la.created_at DESC";
$stmtAll = $pdo->prepare($query_str);
$stmtAll->execute($params);
$all_loans = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// Total overview metrics
$metrics = [
    'total_active' => 0,
    'total_disbursed' => 0.0,
    'open_disputes' => 0
];
$m_stmt = $pdo->query("SELECT status, disbursed_amount FROM loan_applications");
while ($row = $m_stmt->fetch(PDO::FETCH_ASSOC)) {
    if (in_array($row['status'], ['approved', 'disbursed', 'pending'])) $metrics['total_active']++;
    $metrics['total_disbursed'] += (float)$row['disbursed_amount'];
}
$metrics['open_disputes'] = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status = 'pending'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Oversight Desk | AgroLoan Administration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        :root {
            --primary: #059669;
            --primary-dark: #576868ff;
            --secondary: #10b981;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --sidebar-width: 260px;
            --sidebar-collapsed: 80px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
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

        .sidebar {
            width: var(--sidebar-width);
            background: var(--primary-dark);
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 20px;
            transition: width 0.3s ease;
            z-index: 100;
            box-shadow: 4px 0 10px rgba(0,0,0,0.05);
            flex-shrink: 0;
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
            width: 40px; height: 40px; border-radius: 8px;
            object-fit: cover; border: 2px solid rgba(255,255,255,0.2);
        }
        .brand h2 {
            font-size: 20px; font-weight: 600; white-space: nowrap;
            opacity: 1; transition: opacity 0.2s; margin: 0;
        }
        .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
        .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .nav-link {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 15px; color: #d1fae5; text-decoration: none;
            border-radius: 10px; transition: all 0.2s ease;
            white-space: nowrap; font-weight: 500;
        }
        .nav-link:hover {
            background: rgba(255,255,255,0.1); color: #fff;
            transform: translateX(4px);
        }
        .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .nav-link svg { width: 20px; height: 20px; }
        .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
        .sidebar.collapsed .nav-link span { display: none; }
        
        .logout-btn {
            background: rgba(239, 68, 68, 0.1); color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.15);
            padding: 12px; border-radius: 10px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            gap: 10px; font-family: inherit; font-weight: 600;
            transition: 0.2s; width: 100%;
        }
        .logout-btn:hover { background: var(--danger); color: white; }
        .sidebar.collapsed .logout-btn span { display: none; }

        .main {
            flex: 1; display: flex; flex-direction: column;
            overflow-y: auto; position: relative;
        }

        .topbar {
            background: var(--bg-card); padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50;
        }
        .toggle-btn {
            background: transparent; border: none; color: var(--text-muted);
            cursor: pointer; padding: 5px;
        }
        .toggle-btn:hover { color: var(--primary); }

        .user-profile { display: flex; align-items: center; gap: 10px; }
        .user-avatar {
        width: 35px; height: 35px; background: var(--primary); color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
         }

         
        .content { padding: 30px; max-width: 1400px; margin: 0 auto; width: 100%; }

        /* Metric Widgets */
        .metrics-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: var(--bg-card); border-radius: 12px; padding: 20px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: var(--shadow); border: 1px solid #e2e8f0;
        }
        .metric-info h4 { margin: 0; font-size: 13px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; }
        .metric-info p { margin: 5px 0 0; font-size: 24px; font-weight: 700; color: var(--text-main); }
        .metric-icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .icon-blue { background: #e0e7ff; color: #4f46e5; }
        .icon-green { background: #d1fae5; color: #059669; }
        .icon-red { background: #fee2e2; color: #ef4444; }

        /* General layout blocks */
        .card {
            background: var(--bg-card); padding: 25px; border-radius: 12px;
            box-shadow: var(--shadow); border: 1px solid #e2e8f0; margin-bottom: 25px;
        }
        .flex-header {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;
        }
        .card-title { font-size: 18px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 8px; }

        /* Form Filter Panel */
        .filter-panel {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; margin-bottom: 20px;
        }
        .filter-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        select, input[type="text"], textarea {
            padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px;
            font-family: inherit; width: 100%; outline: none; background: #fff;
        }
        select:focus, input[type="text"]:focus, textarea:focus { border-color: var(--primary); }

        /* Table structures */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        th { background: #f8fafc; font-weight: 600; color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
        tr:hover td { background: #fcfdfe; }

        /* Status badges */
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-approved { background: #ecfdf5; color: #059669; }
        .badge-rejected { background: #fef2f2; color: #dc2626; }
        .badge-completed { background: #eff6ff; color: #2563eb; }
        .badge-disbursed { background: #f5f3ff; color: #7c3aed; }

        /* Action buttons */
        .btn {
            padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #f1f5f9; color: var(--text-main); border: 1px solid #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }

        /* Intervention Block Styles */
        .intervention-box {
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 20px; margin-top: 25px;
        }
        .intervention-box h3 { margin: 0 0 10px 0; color: #b45309; font-size: 16px; display: flex; align-items: center; gap: 8px; }

        .stage-box {
            background: #faf9ff; border: 1px solid #e0e7ff; border-radius: 10px; padding: 15px; margin-bottom: 15px;
        }
        .stage-box.stalled { border-left: 4px solid var(--warning); }

        .alert { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        @media (max-width: 992px) {
            .split-view { display: flex; flex-direction: column; gap: 20px; }
        }
        .split-view { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start; }
    </style>
</head>
<body>
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
            <a href="admin_user_management.php" class="nav-link">
                <i data-feather="users"></i>
                <span>User Management</span>
            </a>
            <a href="admin_verifications.php" class="nav-link">
                <i data-feather="check-square"></i>
                <span>Verifications</span>
            </a>
            <a href="admin_loan_oversight.php" class="nav-link active">
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

    <main class="main">
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn"><i data-feather="menu"></i></button>
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username,0,1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            <div class="flex-header">
                <div>
                    <h1 style="margin: 0; font-size: 24px; font-weight: 700;">Global System Loan Registry</h1>
                    <p style="color: var(--text-muted); margin: 5px 0 0 0;">System audits, manual overrides, and transactional dispute resolution controls.</p>
                </div>
                <?php if ($selected_loan_id): ?>
                    <a href="admin_loan_oversight.php" class="btn btn-secondary">&larr; Back to Loan List</a>
                <?php endif; ?>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i data-feather="check-circle"></i> <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error"><i data-feather="alert-circle"></i> <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Metric Row on Dashboard Main State -->
            <?php if (!$selected_loan_id): ?>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Active Loan Accounts</h4>
                            <p><?= $metrics['total_active'] ?></p>
                        </div>
                        <div class="metric-icon icon-blue"><i data-feather="activity"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Total Disbursed Volume</h4>
                            <p>GHS <?= number_format($metrics['total_disbursed'], 2) ?></p>
                        </div>
                        <div class="metric-icon icon-green"><i data-feather="dollar-sign"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Flagged Stalled Disputes</h4>
                            <p><?= $metrics['open_disputes'] ?></p>
                        </div>
                        <div class="metric-icon icon-red"><i data-feather="alert-triangle"></i></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SINGLE LOAN AUDITING DRILL-DOWN VIEW -->
            <?php if ($selected_loan_id && $selected_loan): ?>
                <div class="split-view">
                    
                    <!-- Left Segment: Stages, Progression, Proof Files -->
                    <div>
                        <div class="card">
                            <div class="flex-header">
                                <h2 class="card-title"><i data-feather="file-text"></i> Profile Details: #<?= $selected_loan['id'] ?></h2>
                                <span class="badge badge-<?= $selected_loan['status'] ?>"><?= $selected_loan['status'] ?></span>
                            </div>

                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Farmer Profile</strong>
                                    <div style="font-size:14px; font-weight:600; margin-top:3px;"><?= htmlspecialchars($selected_loan['farmer_name']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_loan['farmer_email']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_loan['farmer_phone'] ?? 'No Phone') ?></div>
                                </div>
                                <div>
                                    <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Vetting Agent</strong>
                                    <div style="font-size:14px; font-weight:600; margin-top:3px;"><?= htmlspecialchars($selected_loan['agent_name'] ?? 'Unassigned') ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_loan['agent_email'] ?? '') ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_loan['agent_phone'] ?? '') ?></div>
                                </div>
                                <div>
                                    <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Amounts & Progress</strong>
                                    <div style="font-size:14px; font-weight:600; margin-top:3px;">Total Limit: GHS <?= number_format($selected_loan['amount'], 2) ?></div>
                                    <div style="font-size:13px; color:var(--success); font-weight:600;">Disbursed: GHS <?= number_format($selected_loan['disbursed_amount'], 2) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);">Current Active Index: Stage <?= $selected_loan['current_stage'] ?></div>
                                </div>
                            </div>

                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border:1px solid #e2e8f0;">
                                <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:5px;">Purpose of Loan</strong>
                                <p style="font-size:13px; margin: 0; line-height:1.5;"><?= htmlspecialchars($selected_loan['purpose']) ?></p>
                            </div>
                        </div>

                        <!-- Stages Progression Trace -->
                        <div class="card">
                            <h2 class="card-title" style="margin-bottom:15px;"><i data-feather="git-commit"></i> Verification Timeline Stages</h2>
                            
                            <?php foreach ($stages as $stg): ?>
                                <div class="stage-box <?= (in_array($stg['status'], ['awaiting_before_approval','awaiting_after_approval','awaiting_payment_approval'])) ? 'stalled' : '' ?>">
                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-bottom: 1px dashed #cbd5e1; padding-bottom:8px; margin-bottom:12px;">
                                        <div>
                                            <strong style="font-size:14px;">Stage <?= $stg['stage_number'] ?></strong>
                                            <span style="font-size:12px; color:var(--text-muted); margin-left:10px;">Cost Limit: GHS <?= number_format($stg['required_amount'], 2) ?></span>
                                        </div>
                                        <div>
                                            <span class="badge badge-<?= $stg['status'] ?>"><?= str_replace('_', ' ', $stg['status']) ?></span>
                                            <span style="font-size:12px; color:var(--text-muted); margin-left:10px;">Disbursed: <strong><?= $stg['disbursed'] ? 'Yes' : 'No' ?></strong></span>
                                        </div>
                                    </div>

                                    <!-- Retrieve proofs linked directly to this stage block -->
                                    <?php
                                    $p_stmt = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ? ORDER BY uploaded_at DESC");
                                    $p_stmt->execute([$stg['id']]);
                                    $proof_files = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    ?>

                                    <?php if (empty($proof_files)): ?>
                                        <p style="font-size:12px; color:var(--text-muted); font-style:italic; margin: 0;">No proof documents uploaded for this stage.</p>
                                    <?php else: ?>
                                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-top: 10px;">
                                            <?php foreach ($proof_files as $pf): 
                                                $pf_filename = !empty($pf['filename']) ? $pf['filename'] : (!empty($pf['file_path']) ? $pf['file_path'] : '');
                                                $path = "../uploads/app_{$selected_loan['id']}/stage_{$stg['id']}/" . rawurlencode($pf_filename);
                                                $is_img = str_contains($pf['file_type'] ?? '', 'image');
                                            ?>
                                                <div style="border: 1px solid #cbd5e1; padding: 10px; border-radius: 8px; background: #fff; display:flex; flex-direction:column; justify-content:space-between;">
                                                    <div>
                                                        <div style="font-size: 11px; text-transform: uppercase; font-weight:700; color:var(--text-muted);"><?= $pf['proof_type'] ?> Proof</div>
                                                        <a href="<?= htmlspecialchars($path) ?>" target="_blank" style="font-size:13px; color:var(--primary); font-weight:500; display:block; margin: 5px 0;">
                                                            <i data-feather="<?= $is_img ? 'image' : 'file-text' ?>" style="width:14px; height:14px; vertical-align:middle; margin-right:3px;"></i> View File
                                                        </a>
                                                    </div>
                                                    <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center;">
                                                        <span style="font-size:11px; color:var(--text-muted);">Verified: <strong><?= $pf['status'] ?></strong></span>
                                                        
                                                        <!-- Admin Direct Verification Override form -->
                                                        <form method="POST" style="display:inline-block;">
                                                            <input type="hidden" name="intervention_type" value="override_proof">
                                                            <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                                            <input type="hidden" name="proof_id" value="<?= $pf['id'] ?>">
                                                            <button type="submit" name="proof_status" value="Approved" title="Force Approve" style="background:none; border:none; cursor:pointer; color:var(--success); margin-right:4px;"><i data-feather="check-circle" style="width:16px;"></i></button>
                                                            <button type="submit" name="proof_status" value="Rejected" title="Force Reject" style="background:none; border:none; cursor:pointer; color:var(--danger);"><i data-feather="x-circle" style="width:16px;"></i></button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Right Segment: Intervention, Overrides & Flagged Disputes -->
                    <div>
                        <!-- Active disputes panel -->
                        <div class="card" style="border-color:#fecaca; background:#fffdfd;">
                            <h2 class="card-title" style="color:var(--danger);"><i data-feather="alert-triangle"></i> System Dispute Folder</h2>
                            <p style="font-size:12px; color:var(--text-muted); margin-bottom:15px;">Historical and active disputes linked to this loan transaction record.</p>

                            <?php if (empty($disputes)): ?>
                                <p style="font-size:13px; color:var(--text-muted); text-align:center; padding: 15px 0;">No disputes reported for this transaction.</p>
                            <?php else: ?>
                                <?php foreach ($disputes as $disp): ?>
                                    <div style="border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-bottom:12px; background:#fff;">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <strong style="font-size:13px;"><?= htmlspecialchars($disp['title']) ?></strong>
                                            <span class="badge" style="background:<?= ($disp['status'] === 'pending') ? '#fee2e2; color:#ef4444;' : '#e2e8f0; color:#475569;' ?>"><?= $disp['status'] ?></span>
                                        </div>
                                        <div style="font-size:12px; color:var(--text-muted); margin-top:3px;">Filed by: <?= htmlspecialchars($disp['creator_name']) ?> | Target: <?= htmlspecialchars($disp['defendant_name']) ?></div>
                                        <p style="font-size:13px; margin: 8px 0; background:#fefefe; border: 1px solid #f3f4f6; padding: 8px; border-radius:4px; font-style:italic;">
                                            "<?= htmlspecialchars($disp['description']) ?>"
                                        </p>

                                        <?php if ($disp['admin_decision']): ?>
                                            <div style="font-size:12px; border-top:1px dashed #cbd5e1; padding-top:8px; margin-top:8px;">
                                                <strong>Admin Directive:</strong> <em><?= htmlspecialchars($disp['admin_decision']) ?></em>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($disp['status'] === 'pending'): ?>
                                            <!-- Resolve Dispute Form inside file -->
                                            <form method="POST" style="margin-top:10px; border-top:1px dashed #e2e8f0; padding-top:10px;">
                                                <input type="hidden" name="intervention_type" value="resolve_dispute">
                                                <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                                <input type="hidden" name="dispute_id" value="<?= $disp['id'] ?>">
                                                
                                                <div style="margin-bottom:8px;">
                                                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:3px;">Write Admin Directive Decision</label>
                                                    <textarea name="admin_decision" rows="3" placeholder="Enter binding resolution message..." required style="font-size:12px;"></textarea>
                                                </div>
                                                <div style="display:flex; gap:8px;">
                                                    <button type="submit" name="status" value="resolved" class="btn btn-primary" style="font-size:11px; padding:6px 12px;">Resolve Decision</button>
                                                    <button type="submit" name="status" value="dismissed" class="btn btn-secondary" style="font-size:11px; padding:6px 12px;">Dismiss Dispute</button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Manual Stage Intervention Override Box -->
                        <div class="intervention-box">
                            <h3><i data-feather="settings"></i> Force Stage Overrides</h3>
                            <p style="font-size:12px; color:#92400e; margin-bottom:15px; line-height:1.4;">Use to forcefully advance stalled stage transitions, manually trigger disbursements, or set final completion indexes.</p>
                            
                            <form method="POST">
                                <input type="hidden" name="intervention_type" value="override_stage">
                                <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                
                                <div style="margin-bottom:12px;">
                                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Select Stage Target</label>
                                    <select name="stage_id" required style="font-size:12px;">
                                        <option value="">-- Choose Stage --</option>
                                        <?php foreach($stages as $stg): ?>
                                            <option value="<?= $stg['id'] ?>">Stage <?= $stg['stage_number'] ?> (Current Status: <?= $stg['status'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div style="margin-bottom:12px;">
                                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Manual Stage Status Override</label>
                                    <select name="stage_status" required style="font-size:12px;">
                                        <option value="pending">Pending Fund Release</option>
                                        <option value="disbursed">Funds Disbursed / Work Ongoing</option>
                                        <option value="completed">Completed (Stage Satisfied)</option>
                                        <option value="awaiting_before_approval">Awaiting Before-Work Approval</option>
                                        <option value="awaiting_after_approval">Awaiting After-Work Approval</option>
                                        <option value="awaiting_payment_approval">Awaiting Payment/Receipt Approval</option>
                                    </select>
                                </div>

                                <div style="margin-bottom:15px;">
                                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Disbursement Status Force</label>
                                    <select name="disbursed" style="font-size:12px;">
                                        <option value="0">Not Disbursed</option>
                                        <option value="1">Mark Disbursed (Increments Application Disbursed Amount)</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary" style="font-size:12px; width:100%; display:flex; justify-content:center;">Apply Stage Intervention</button>
                            </form>
                        </div>

                        <!-- Manual Loan Profile Override -->
                        <div class="card" style="margin-top:20px; background:#fafafa;">
                            <h3 style="font-size:15px; margin: 0 0 10px 0;"><i data-feather="shield"></i> Manual Lifecycle Reset</h3>
                            <p style="font-size:11px; color:var(--text-muted); margin-bottom:15px;">Direct override of parent application state tracking parameters.</p>
                            
                            <form method="POST">
                                <input type="hidden" name="intervention_type" value="override_application">
                                <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">

                                <div style="margin-bottom:10px;">
                                    <label style="font-size:11px; font-weight:600; display:block; margin-bottom:4px;">Overall App Status</label>
                                    <select name="app_status" required style="font-size:12px; padding:6px 10px;">
                                        <option value="pending" <?= ($selected_loan['status'] === 'pending') ? 'selected' : '' ?>>Pending Review</option>
                                        <option value="approved" <?= ($selected_loan['status'] === 'approved') ? 'selected' : '' ?>>Approved</option>
                                        <option value="rejected" <?= ($selected_loan['status'] === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                        <option value="completed" <?= ($selected_loan['status'] === 'completed') ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= ($selected_loan['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div style="margin-bottom:10px;">
                                    <label style="font-size:11px; font-weight:600; display:block; margin-bottom:4px;">Current Active Stage Index</label>
                                    <input type="number" name="current_stage" value="<?= $selected_loan['current_stage'] ?>" min="1" max="4" required style="font-size:12px; padding:6px 10px;">
                                </div>

                                <div style="margin-bottom:15px;">
                                    <label style="font-size:11px; font-weight:600; display:block; margin-bottom:4px;">Outstanding Balance (GHS)</label>
                                    <input type="text" name="outstanding_balance" value="<?= $selected_loan['outstanding_balance'] ?>" placeholder="0.00" style="font-size:12px; padding:6px 10px;">
                                </div>

                                <button type="submit" class="btn btn-secondary" style="font-size:12px; width:100%; display:flex; justify-content:center; background:none; border:1px solid var(--primary); color:var(--primary);">Override Parent Profile</button>
                            </form>
                        </div>
                    </div>
                </div>

            <!-- GLOBAL LIST REGISTRY DASHBOARD -->
            <?php else: ?>
                <div class="card">
                    <div class="flex-header">
                        <h2 class="card-title"><i data-feather="list"></i> Active System Portfolios</h2>
                    </div>

                    <!-- Filtering Form panel -->
                    <div class="filter-panel">
                        <form method="GET">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label for="search">Keyword Search</label>
                                    <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="Title, Farmer or Agent Name...">
                                </div>

                                <div class="filter-group">
                                    <label for="status">Loan Lifecycle State</label>
                                    <select name="status" id="status">
                                        <option value="">-- All Active & Archived --</option>
                                        <option value="pending" <?= ($status_filter === 'pending') ? 'selected' : '' ?>>Pending Review</option>
                                        <option value="approved" <?= ($status_filter === 'approved') ? 'selected' : '' ?>>Approved / Disbursing</option>
                                        <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                        <option value="completed" <?= ($status_filter === 'completed') ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= ($status_filter === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label for="has_dispute">Dispute State</label>
                                    <select name="has_dispute" id="has_dispute">
                                        <option value="">-- All Accounts --</option>
                                        <option value="1" <?= ($dispute_filter === '1') ? 'selected' : '' ?>>Flagged Disputes Only</option>
                                    </select>
                                </div>

                                <div class="filter-group" style="display:flex; flex-direction:row; gap:10px;">
                                    <button type="submit" class="btn btn-primary" style="flex:1; justify-content:center; height:41px;"><i data-feather="search"></i> Search</button>
                                    <a href="admin_loan_oversight.php" class="btn btn-secondary" style="flex:1; justify-content:center; height:41px; align-items:center;">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Loans List Table -->
                    <div class="table-wrap">
                        <?php if (empty($all_loans)): ?>
                            <p style="text-align:center; color:var(--text-muted); padding:30px; font-style:italic;">No transaction profiles found matching search filters.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Loan Title</th>
                                        <th>Farmer</th>
                                        <th>Vetting Agent</th>
                                        <th>Stage</th>
                                        <th>Limit</th>
                                        <th>Disbursed</th>
                                        <th>Status</th>
                                        <th>Disputes</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_loans as $ln): ?>
                                        <tr style="<?= ($ln['active_disputes_count'] > 0) ? 'background:#fff5f5;' : '' ?>">
                                            <td>#<?= $ln['id'] ?></td>
                                            <td>
                                                <strong style="color:var(--text-main); font-size:14px;"><?= htmlspecialchars($ln['title']) ?></strong>
                                                <div style="font-size:11px; color:var(--text-muted);"><?= date('M d, Y', strtotime($ln['created_at'])) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($ln['farmer_name'] ?? 'System User') ?></td>
                                            <td><?= htmlspecialchars($ln['agent_name'] ?? 'Unassigned') ?></td>
                                            <td>Stage <?= $ln['current_stage'] ?></td>
                                            <td style="font-weight:600;">GHS <?= number_format($ln['amount'], 2) ?></td>
                                            <td style="color:var(--success); font-weight:600;">GHS <?= number_format($ln['disbursed_amount'], 2) ?></td>
                                            <td><span class="badge badge-<?= $ln['status'] ?>"><?= $ln['status'] ?></span></td>
                                            <td>
                                                <?php if ($ln['active_disputes_count'] > 0): ?>
                                                    <span class="badge badge-rejected" style="animation: pulse 2s infinite;"><i data-feather="alert-triangle" style="width:11px; height:11px; vertical-align:middle; margin-right:3px;"></i> <?= $ln['active_disputes_count'] ?> FLAGGED</span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted); font-size:12px;">Up to date</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="admin_loan_oversight.php?id=<?= $ln['id'] ?>" class="btn btn-secondary" style="font-size:11px; padding:5px 10px;"><i data-feather="eye" style="width:12px;"></i> Audit</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize simple vector icons
        feather.replace();

        // Mobile responsive sidebar collapses
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            sidebar.classList.toggle("collapsed");
        });
    </script>
</body>
</html>