<?php
// public/apply_loan.php
session_start();
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/users.php';
require_once __DIR__ . '/../src/loan.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];

$pdo = getPDO();

// Automatically verify and add the rejection_reason column if it does not exist
try {
    $pdo->query("SELECT rejection_reason FROM loan_applications LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE loan_applications ADD COLUMN rejection_reason TEXT NULL");
    } catch (PDOException $ex) {
        // Fallback in case of database permission restrictions
    }
}

/**
 * Sends an HTML email notification.
 */
function send_email_notification($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: AgroLoan Notifications <no-reply@agroloan.com>" . "\r\n";
    
    return @mail($to, $subject, $message, $headers);
}

/**
 * Sends an SMS notification (Configurable with standard SMS Gateways).
 */
function send_sms_notification($phone, $message) {
    // Write SMS details to php error log for local tracking/development
    error_log("SMS dispatched to {$phone}: {$message}");
    
    // To connect to a live gateway (e.g. Twilio, Arkesel, Hubtel), configure the API endpoints below:
    /*
    $apiKey = "YOUR_GATEWAY_API_KEY";
    $senderId = "AgroLoan";
    $url = "https://api.example.com/sms/send?key=" . urlencode($apiKey) . "&to=" . urlencode($phone) . "&msg=" . urlencode($message) . "&sender=" . urlencode($senderId);
    @file_get_contents($url);
    */
    
    return true;
}

$agents = $pdo->query("
    SELECT u.id, u.name, ap.interest_rate, ap.loan_terms 
    FROM users u 
    JOIN agent_profiles ap ON u.id = ap.user_id
")->fetchAll();

$errorMessage = ''; 

// Reapplication checks
$reapply_id = isset($_GET['reapply_id']) ? intval($_GET['reapply_id']) : 0;
$reapply_data = null;
$reapply_stages = [];

if ($reapply_id > 0) {
    // Retrieve the rejected application and verify it belongs to the current farmer
    $stmt = $pdo->prepare("SELECT * FROM loan_applications WHERE id = ? AND farmer_id = ? AND status = 'rejected'");
    $stmt->execute([$reapply_id, $farmer_id]);
    $reapply_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reapply_data) {
        $stmt_stages = $pdo->prepare("SELECT stage_number, required_amount FROM loan_stages WHERE application_id = ? ORDER BY stage_number ASC");
        $stmt_stages->execute([$reapply_id]);
        $reapply_stages = $stmt_stages->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $totalAmount = floatval($_POST['amount']);
    $stageSum = 0;

    $stages = [];
    for ($i = 1; $i <= 3; $i++) {
        $amt = floatval($_POST["stage_{$i}_amount"] ?? 0);
        $stageSum += $amt;
        $stages[] = ['stage_number' => $i, 'required_amount' => $amt];
    }

    // Backend Validation
    if ($stageSum !== $totalAmount) {
        $errorMessage = "The total of all stage amounts (GHS {$stageSum}) must equal the loan amount (GHS {$totalAmount}).";
    } else {
        $success = false;
        $targetId = 0;

        if ($reapply_id > 0 && $reapply_data) {
            // Update existing rejected application to pending, clearing old rejection reason
            $stmt = $pdo->prepare("UPDATE loan_applications SET agent_id = ?, title = ?, amount = ?, purpose = ?, status = 'pending', rejection_reason = NULL WHERE id = ? AND farmer_id = ?");
            if ($stmt->execute([$_POST['agent_id'], $_POST['title'], $totalAmount, $_POST['purpose'], $reapply_id, $farmer_id])) {
                
                // Recreate stage entries
                $stmt = $pdo->prepare("DELETE FROM loan_stages WHERE application_id = ?");
                $stmt->execute([$reapply_id]);
                
                $stmt = $pdo->prepare("INSERT INTO loan_stages (application_id, stage_number, required_amount) VALUES (?, ?, ?)");
                foreach ($stages as $stage) {
                    $stmt->execute([$reapply_id, $stage['stage_number'], $stage['required_amount']]);
                }
                $targetId = $reapply_id;
                $success = true;
            }
        } else {
            // Create a brand new application
            $appId = create_application(
                $farmer_id,
                $_POST['agent_id'],
                $_POST['title'],
                $totalAmount,
                $_POST['purpose'],
                $stages
            );
            if ($appId) {
                $targetId = $appId;
                $success = true;
            }
        }

        if ($success && $targetId > 0) {
            // Retrieve Agent details for notification dispatch
            $agentStmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
            $agentStmt->execute([$_POST['agent_id']]);
            $agentInfo = $agentStmt->fetch(PDO::FETCH_ASSOC);

            if ($agentInfo) {
                $a_name = $agentInfo['name'];
                $a_email = $agentInfo['email'];
                $a_phone = $agentInfo['phone'];
                $formatted_amount = number_format($totalAmount, 2);
                
                $subject = "Loan Application Review Request: " . $_POST['title'];
                $email_body = "
                    <h2>Hello Agent {$a_name},</h2>
                    <p>Farmer <strong>{$username}</strong> has submitted a loan application for <strong>{$_POST['title']}</strong> (Amount: <strong>GHS {$formatted_amount}</strong>) and assigned you as their vetting agent.</p>
                    <p>Please log in to your agent portal to review the applicant's profile and make an approval decision.</p>
                    <br>
                    <p>Best regards,<br>AgroLoan Portal</p>
                ";
                $sms_text = "Hello Agent {$a_name}, farmer {$username} has submitted a loan application '{$_POST['title']}' (GHS {$formatted_amount}) to you. Please review it on the portal.";

                if (!empty($a_email)) {
                    send_email_notification($a_email, $subject, $email_body);
                }
                if (!empty($a_phone)) {
                    send_sms_notification($a_phone, $sms_text);
                }
            }
            
            header("Location: view_application.php?id={$targetId}");
            exit;
        }
    }
}

// Fetch rejected applications to show at the bottom
$rejected_stmt = $pdo->prepare("
    SELECT la.*, u.name AS agent_name
    FROM loan_applications la
    LEFT JOIN users u ON la.agent_id = u.id
    WHERE la.farmer_id = ? AND la.status = 'rejected'
    ORDER BY la.created_at DESC
");
$rejected_stmt->execute([$farmer_id]);
$rejected_list = $rejected_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Apply for Loan | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    /* --- CORE THEME (From Dashboard) --- */
    :root {
        --primary: #059669; /* Emerald 600 */
        --primary-dark: #064e3b; /* Emerald 900 */
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

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1); color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 12px; border-radius: 10px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 10px; font-family: inherit; font-weight: 600;
        transition: 0.2s; width: 100%;
    }

    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
    }

    /* TOPBAR */
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
        width: 35px; height: 35px; background: var(--primary);
        color: white; border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* --- FORM & PAGE CONTENT --- */
    .content { padding: 30px; max-width: 1200px; margin: 0 auto; width: 100%; }
    
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* Layout Grid for Form + Info */
    .layout-grid {
        display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start;
    }
    @media (max-width: 992px) { .layout-grid { grid-template-columns: 1fr; } }

    /* Cards */
    .card {
        background: var(--bg-card); padding: 30px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #f0f0f0;
    }

    /* Form Elements */
    .form-group { margin-bottom: 20px; }
    
    label {
        display: block; font-weight: 500; margin-bottom: 8px;
        font-size: 14px; color: var(--text-main);
    }

    input[type="text"],
    input[type="number"],
    textarea,
    select {
        width: 100%; padding: 12px 16px; border-radius: 10px;
        border: 1px solid #e5e7eb; font-size: 14px;
        font-family: inherit; background: #fff;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    input:focus, textarea:focus, select:focus {
        border-color: var(--primary); outline: none;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
    }

    textarea { resize: vertical; }

    /* Stage Inputs Grid */
    .stages-container {
        background: #f9fafb; padding: 20px; border-radius: 12px;
        border: 1px solid #e5e7eb;
    }
    .stages-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;
    }
    @media (max-width: 600px) { .stages-grid { grid-template-columns: 1fr; } }

    .stage-item label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }

    /* Buttons */
    .btn-submit {
        background: var(--primary); color: white; border: none;
        padding: 14px 24px; border-radius: 10px; cursor: pointer;
        width: 100%; font-weight: 600; font-size: 16px;
        transition: background 0.2s; margin-top: 10px;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover { background: var(--primary-dark); }

    /* Info Card */
    .info-card {
        background: #ecfdf5; border: 1px solid #d1fae5;
    }
    .info-card h3 {
        margin: 0 0 15px 0; font-size: 18px; color: var(--primary-dark);
        display: flex; align-items: center; gap: 8px;
    }
    .info-card ul { padding-left: 20px; margin: 0; color: var(--primary-dark); }
    .info-card li { margin-bottom: 12px; font-size: 14px; line-height: 1.6; }

    /* Alerts */
    .alert {
        padding: 15px; border-radius: 10px; margin-bottom: 25px;
        font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;
    }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #b45309; }
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
    }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Farmer</h2>
        </div>

        <nav class="nav">
            <a href="farmer_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            <a href="add_product.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'add_product.php' ? 'active' : '' ?>">
                <i data-feather="shopping-bag"></i>
                <span>Add Produce</span>
            </a>
            <a href="apply_loan.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'apply_loan.php' ? 'active' : '' ?>">
                <i data-feather="dollar-sign"></i>
                <span>Apply for Loan</span>
            </a>
            <a href="view_application.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'view_application.php' ? 'active' : '' ?>">
                <i data-feather="file-text"></i>
                <span>Applications</span>
            </a>
            <a href="farmer_repayment.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_repayment.php' ? 'active' : '' ?>">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="dispute_center.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dispute_center.php' ? 'active' : '' ?>">
                <i data-feather="alert-triangle"></i>
                <span>Dispute Center</span>
            </a>
            <a href="farmer_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_profile.php' ? 'active' : '' ?>">
                <i data-feather="user"></i>
                <span>Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <!-- MAIN AREA -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Farmer</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">
                    <?= ($reapply_id > 0 && $reapply_data) ? 'Update & Resubmit Application' : 'New Application' ?>
                </h1>
                <p class="page-subtitle">Fill in the details below to request financing.</p>
            </div>

            <!-- Reapplication Warning Alert showing previous rejection details -->
            <?php if ($reapply_id > 0 && $reapply_data): ?>
                <div class="alert alert-warning">
                    <i data-feather="alert-triangle"></i>
                    <div>
                        <strong>You are modifying a rejected application: "<?= htmlspecialchars($reapply_data['title']) ?>"</strong>
                        <div style="margin-top: 5px; font-size: 13px;">
                            <strong>Rejection Reason:</strong> <em><?= htmlspecialchars($reapply_data['rejection_reason'] ?? 'No explanation provided.') ?></em>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="layout-grid">
                
                <!-- Left Column: Form -->
                <div class="card">
                    
                    <!-- PHP Error Message -->
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger">
                            <i data-feather="alert-circle"></i>
                            <?= htmlspecialchars($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <!-- JS Error Message -->
                    <div id="stage-error" class="alert alert-danger" style="display:none;"></div>

                    <form method="POST">
                        <div class="form-group">
                            <label for="title">Loan Title</label>
                            <input type="text" id="title" name="title" 
                                   value="<?= htmlspecialchars($reapply_data['title'] ?? '') ?>" 
                                   placeholder="e.g., Maize Season 2025" required>
                        </div>

                        <div class="form-group">
                            <label for="agent">Select Agent</label>
                            <select name="agent_id" id="agent" required>
                                <option value="">-- Choose an Agent --</option>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?= $a['id'] ?>" <?= (isset($reapply_data['agent_id']) && $reapply_data['agent_id'] == $a['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['name']) ?> (<?= $a['interest_rate'] ?>% Interest)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="amount">Total Loan Amount (GHS)</label>
                            <input type="number" id="amount" name="amount" 
                                   value="<?= htmlspecialchars($reapply_data['amount'] ?? '') ?>" 
                                   placeholder="0.00" step="1" required>
                        </div>

                        <div class="form-group">
                            <label for="purpose">Purpose of Loan</label>
                            <textarea name="purpose" id="purpose" rows="4" placeholder="Describe what you need the funds for..." required><?= htmlspecialchars($reapply_data['purpose'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Distribution Stages (Must equal Total Amount)</label>
                            <div class="stages-container">
                                <?php
                                $stage_vals = [1 => '', 2 => '', 3 => ''];
                                foreach ($reapply_stages as $rs) {
                                    $stage_vals[$rs['stage_number']] = $rs['required_amount'];
                                }
                                ?>
                                <div class="stages-grid">
                                    <div class="stage-item">
                                        <label>Stage 1 (GHS)</label>
                                        <input type="number" name="stage_1_amount" value="<?= htmlspecialchars($stage_vals[1]) ?>" placeholder="0.00" step="1">
                                    </div>
                                    <div class="stage-item">
                                        <label>Stage 2 (GHS)</label>
                                        <input type="number" name="stage_2_amount" value="<?= htmlspecialchars($stage_vals[2]) ?>" placeholder="0.00" step="1">
                                    </div>
                                    <div class="stage-item">
                                        <label>Stage 3 (GHS)</label>
                                        <input type="number" name="stage_3_amount" value="<?= htmlspecialchars($stage_vals[3]) ?>" placeholder="0.00" step="1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">
                            <?= ($reapply_id > 0 && $reapply_data) ? 'Resubmit Updated Application' : 'Submit Application' ?>
                        </button>
                    </form>
                </div>

                <!-- Right Column: Info -->
                <div class="card info-card">
                    <h3><i data-feather="help-circle"></i> Instructions</h3>
                    <ul>
                        <li><strong>Title:</strong> Give your loan a short, clear name (e.g., "Fertilizer Purchase").</li>
                        <li><strong>Agent:</strong> Choose an agent based on their interest rates.</li>
                        <li><strong>Amount:</strong> Enter the total amount you need in GHS.</li>
                        <li><strong>Purpose:</strong> Explain how the money will be used to help the agent approve your request.</li>
                        <li><strong>Stages:</strong> You can split the loan into up to 3 installments. The sum of these stages must equal the <strong>Total Loan Amount</strong>.</li>
                    </ul>
                </div>

            </div>

            <!-- Rejected Applications List -->
            <?php if (!empty($rejected_list)): ?>
                <div class="card" style="margin-top: 40px; border-color: #fca5a5; background: #fffdfd; overflow-x: auto;">
                    <h3 style="color: #b91c1c; display: flex; align-items: center; gap: 8px; margin-top: 0;">
                        <i data-feather="x-circle"></i> Rejected Applications
                    </h3>
                    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                        The following application entries require revision. Review the rejection reasons, update details, and resubmit them.
                    </p>
                    <table style="width: 100%; border-collapse: collapse; min-width: 600px;">
                        <thead>
                            <tr style="background: #fef2f2;">
                                <th style="padding: 12px; color: #b91c1c;">Title</th>
                                <th style="padding: 12px; color: #b91c1c;">Agent</th>
                                <th style="padding: 12px; color: #b91c1c;">Amount</th>
                                <th style="padding: 12px; color: #b91c1c;">Date Rejected</th>
                                <th style="padding: 12px; color: #b91c1c;">Reason for Rejection</th>
                                <th style="padding: 12px; color: #b91c1c; text-align: center; width: 180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejected_list as $rej): ?>
                                <tr style="border-bottom: 1px solid #fee2e2;">
                                    <td style="padding: 12px; font-weight: 600;"><?= htmlspecialchars($rej['title']) ?></td>
                                    <td style="padding: 12px;"><?= htmlspecialchars($rej['agent_name'] ?? 'Unassigned') ?></td>
                                    <td style="padding: 12px; font-weight: 600;">GHS <?= number_format($rej['amount'], 2) ?></td>
                                    <td style="padding: 12px; font-size: 13px;"><?= date('M d, Y', strtotime($rej['created_at'])) ?></td>
                                    <td style="padding: 12px; color: #b91c1c; font-style: italic; font-size: 13px; max-width: 300px; word-wrap: break-word;">
                                        <?= htmlspecialchars($rej['rejection_reason'] ?? 'No specific reason recorded.') ?>
                                    </td>
                                    <td style="padding: 12px; text-align: center;">
                                        <a href="apply_loan.php?reapply_id=<?= $rej['id'] ?>" class="btn-sm" style="background: #dc2626; color: white; text-decoration: none; border-radius: 8px; padding: 8px 12px; display: inline-flex; align-items: center; gap: 6px;">
                                            <i data-feather="edit-2" style="width:14px; height:14px;"></i> Edit & Resubmit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Sidebar Toggle Logic
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        const amountField = document.getElementById("amount");
        const stageFields = document.querySelectorAll("input[name^='stage_'][name$='_amount']");
        const msgBox = document.getElementById("stage-error");

        function validateStageAmounts() {
            let total = parseFloat(amountField.value) || 0;
            let sum = 0;

            stageFields.forEach(f => {
                sum += parseFloat(f.value) || 0;
            });

            if (total > 0 && sum > 0) {
                if (sum !== total) {
                    msgBox.innerHTML = `<i data-feather="alert-triangle" style="width:16px;height:16px;"></i> &nbsp; Stage total (GHS ${sum}) must equal the loan amount (GHS ${total}).`;
                    msgBox.style.display = "flex";
                    feather.replace(); 
                } else {
                    msgBox.style.display = "none";
                }
            } else {
                msgBox.style.display = "none";
            }
        }

        amountField.addEventListener("input", validateStageAmounts);
        stageFields.forEach(f => f.addEventListener("input", validateStageAmounts));
    </script>
</body>
</html>