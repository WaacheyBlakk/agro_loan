<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
// public/dispute_center.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';
require_once __DIR__ . '/../src/mailer.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['farmer', 'agent'])) {
    header("Location: login.php");
    exit;
}

$my_id = $_SESSION['user_id'];
$my_role = $_SESSION['role'];
$username = $_SESSION['name'];
$pdo = getPDO();

// Automatically verify and build tables if they do not exist
try {
    $pdo->query("SELECT 1 FROM disputes LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS disputes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                loan_id INT NOT NULL,
                creator_id INT NOT NULL,
                defendant_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                admin_decision TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    } catch (PDOException $ex) {
        // Fallback in case of database permission restrictions
    }
}

try {
    $pdo->query("SELECT 1 FROM dispute_evidence LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dispute_evidence (
                id INT AUTO_INCREMENT PRIMARY KEY,
                dispute_id INT NOT NULL,
                uploader_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                file_type VARCHAR(50) NOT NULL,
                notes TEXT NULL,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (PDOException $ex) {
        // Fallback in case of database permission restrictions
    }
}

// Verify dynamic addition of the notes column to the database if not present
try {
    $pdo->query("SELECT notes FROM dispute_evidence LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE dispute_evidence ADD COLUMN notes TEXT NULL");
    } catch (PDOException $ex) {
        // Fallback in case of column addition restrictions
    }
}

/**
 * Sends an SMS notification (Configurable with standard SMS Gateways).
 */
function send_sms_notification($phone, $message) {
    error_log("SMS dispatched to {$phone}: {$message}");
    return true;
}

$errorMessage = '';
$successMessage = '';

// Handle Dispute Creation Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'launch_dispute') {
    csrf_verify();
    try {
        $loan_id = intval($_POST['loan_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($loan_id <= 0 || empty($title) || empty($description)) {
            throw new Exception("Please complete all required fields.");
        }

        // Verify that the relationship is valid: creator must be on the loan, and identify the defendant
        $loanStmt = $pdo->prepare("SELECT id, farmer_id, agent_id, title AS loan_title FROM loan_applications WHERE id = ?");
        $loanStmt->execute([$loan_id]);
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

        if (!$loan) {
            throw new Exception("Invalid transaction reference.");
        }

        if ($my_role === 'farmer') {
            if ($loan['farmer_id'] != $my_id) {
                throw new Exception("Unauthorized transaction reference.");
            }
            $defendant_id = $loan['agent_id'];
        } else { // agent
            if ($loan['agent_id'] != $my_id) {
                throw new Exception("Unauthorized transaction reference.");
            }
            $defendant_id = $loan['farmer_id'];
        }

        $pdo->beginTransaction();

        // Save Dispute
        $insStmt = $pdo->prepare("INSERT INTO disputes (loan_id, creator_id, defendant_id, title, description, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $insStmt->execute([$loan_id, $my_id, $defendant_id, $title, $description]);
        $dispute_id = $pdo->lastInsertId();

        // Handle Multiple Evidence Uploads if present
        if (!empty($_FILES['evidence']['name'][0])) {
            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'txt'];
            $target_dir = __DIR__ . "/../uploads/disputes/dispute_{$dispute_id}/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            foreach ($_FILES['evidence']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['evidence']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_name = $_FILES['evidence']['name'][$key];
                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                    if (in_array($ext, $allowed_exts)) {
                        $filename = "evidence_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                        if (move_uploaded_file($tmpName, $target_dir . $filename)) {
                            $file_type = str_contains($_FILES['evidence']['type'][$key], 'image') ? 'image' : 'document';
                            $evStmt = $pdo->prepare("INSERT INTO dispute_evidence (dispute_id, uploader_id, filename, file_type, notes) VALUES (?, ?, ?, ?, NULL)");
                            $evStmt->execute([$dispute_id, $my_id, $filename, $file_type]);
                        }
                    } else {
                        throw new Exception("Unsupported file format included in uploads.");
                    }
                }
            }
        }

        $pdo->commit();
        $successMessage = "Dispute registered successfully. It has been routed to administration for review.";

        // Retrieve Defendant info for Notification
        $defStmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $defStmt->execute([$defendant_id]);
        $def = $defStmt->fetch(PDO::FETCH_ASSOC);

        if ($def) {
            $subject = "Alert: Dispute Registered Against You";
            $email_body = "
                <h2>Hello {$def['name']},</h2>
                <p>A dispute has been formally launched against you by <strong>{$username}</strong> in relation to loan transaction '<strong>{$loan['loan_title']}</strong>'.</p>
                <p><strong>Dispute Title:</strong> {$title}<br>
                <strong>Details:</strong> {$description}</p>
                <p>The system administrators are currently reviewing the dispute. You can log in to your dashboard to review files or submit clarification.</p>
                <br>
                <p>Best regards,<br>AgroLoan Support</p>
            ";
            $sms_text = "Hello {$def['name']}, a dispute has been filed against you by {$username} regarding '{$loan['loan_title']}'. It is currently under administrative review.";

            if (!empty($def['email'])) {
                send_mail($def['email'], $subject, email_template($subject, $email_body), $def['name']);
            }
            if (!empty($def['phone'])) {
                send_sms_notification($def['phone'], $sms_text);
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = $e->getMessage();
    }
}

// Handle Supplemental Evidence Submission on Active Cases
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_evidence') {
    csrf_verify();
    try {
        $dispute_id = intval($_POST['dispute_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        // Verify authorization: current user is creator or defendant of this dispute
        $check = $pdo->prepare("SELECT id, status FROM disputes WHERE id = ? AND (creator_id = ? OR defendant_id = ?)");
        $check->execute([$dispute_id, $my_id, $my_id]);
        $dis = $check->fetch();

        if (!$dis) {
            throw new Exception("Unauthorized dispute reference.");
        }

        if (!in_array($dis['status'], ['pending', 'under_review', 'open'])) {
            throw new Exception("This case context is locked and cannot accept updates.");
        }

        if (!empty($_FILES['evidence']['name'][0])) {
            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'txt'];
            $target_dir = __DIR__ . "/../uploads/disputes/dispute_{$dispute_id}/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            foreach ($_FILES['evidence']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['evidence']['error'][$key] === UPLOAD_ERR_OK) {
                    $original_name = $_FILES['evidence']['name'][$key];
                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                    if (in_array($ext, $allowed_exts)) {
                        $filename = "evidence_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                        if (move_uploaded_file($tmpName, $target_dir . $filename)) {
                            $file_type = str_contains($_FILES['evidence']['type'][$key], 'image') ? 'image' : 'document';
                            $evStmt = $pdo->prepare("INSERT INTO dispute_evidence (dispute_id, uploader_id, filename, file_type, notes) VALUES (?, ?, ?, ?, ?)");
                            $evStmt->execute([$dispute_id, $my_id, $filename, $file_type, $notes]);
                        }
                    } else {
                        throw new Exception("Unsupported file format included in supplemental uploads.");
                    }
                }
            }
            $successMessage = "Additional evidence files have been attached successfully.";
        } else {
            throw new Exception("Please select valid files to attach.");
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Handle Uploaded Evidence Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_evidence') {
    csrf_verify();
    try {
        $evidence_id = intval($_POST['evidence_id'] ?? 0);

        // Fetch evidence record and check ownership constraints
        $stmt = $pdo->prepare("SELECT * FROM dispute_evidence WHERE id = ?");
        $stmt->execute([$evidence_id]);
        $evidence = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($evidence) {
            if ((int)$evidence['uploader_id'] === (int)$my_id) {
                $filePath = __DIR__ . "/../uploads/disputes/dispute_{$evidence['dispute_id']}/" . $evidence['filename'];
                if (file_exists($filePath) && is_file($filePath)) {
                    unlink($filePath);
                }
                
                $del = $pdo->prepare("DELETE FROM dispute_evidence WHERE id = ?");
                $del->execute([$evidence_id]);
                $successMessage = "Selected evidence has been deleted.";
            } else {
                throw new Exception("You are not authorized to delete this file.");
            }
        } else {
            throw new Exception("Evidence record not found.");
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Fetch list of users worked with currently/recently (via loans)
if ($my_role === 'farmer') {
    $eligibleStmt = $pdo->prepare("
        SELECT la.id AS loan_id, la.title AS loan_title, u.name AS counterpart_name 
        FROM loan_applications la
        JOIN users u ON la.agent_id = u.id
        WHERE la.farmer_id = ?
        ORDER BY la.created_at DESC
    ");
} else { // agent
    $eligibleStmt = $pdo->prepare("
        SELECT la.id AS loan_id, la.title AS loan_title, u.name AS counterpart_name 
        FROM loan_applications la
        JOIN users u ON la.farmer_id = u.id
        WHERE la.agent_id = ?
        ORDER BY la.created_at DESC
    ");
}
$eligibleStmt->execute([$my_id]);
$eligible_transactions = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing disputes involving this user
$disputesStmt = $pdo->prepare("
    SELECT d.*, 
           la.title AS loan_title, 
           uc.name AS creator_name, uc.role AS creator_role,
           ud.name AS defendant_name, ud.role AS defendant_role
    FROM disputes d
    JOIN loan_applications la ON d.loan_id = la.id
    JOIN users uc ON d.creator_id = uc.id
    JOIN users ud ON d.defendant_id = ud.id
    WHERE d.creator_id = ? OR d.defendant_id = ?
    ORDER BY d.created_at DESC
");
$disputesStmt->execute([$my_id, $my_id]);
$my_disputes = $disputesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dispute Center | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    :root {
        --primary: <?= ($my_role === 'farmer') ? '#059669' : '#1e40af' ?>; 
        --primary-dark: <?= ($my_role === 'farmer') ? '#064e3b' : '#172554' ?>;
        --secondary: <?= ($my_role === 'farmer') ? '#10b981' : '#3b82f6' ?>;
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: <?= ($my_role === 'farmer') ? '#111827' : '#1f2937' ?>;
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
        padding: 12px 15px; 
        color: <?= ($my_role === 'farmer') ? '#d1fae5' : '#dbeafe' ?>; 
        text-decoration: none;
        border-radius: 10px; transition: all 0.2s ease;
        white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px <?= ($my_role === 'farmer') ? 'rgba(16, 185, 129, 0.3)' : 'rgba(59, 130, 246, 0.4)' ?>;
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
        flex: 1; display: flex; flex-direction: column; min-width: 0;
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

    /* --- DISPUTE CENTER CONTENT --- */
    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    .layout-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start; }
    @media (max-width: 992px) { .layout-grid { grid-template-columns: 1fr; } }

    .card {
        background: var(--bg-card); padding: 30px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #f0f0f0; margin-bottom: 30px;
    }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-weight: 500; margin-bottom: 8px; font-size: 14px; }
    input[type="text"], textarea, select {
        width: 100%; padding: 12px 16px; border-radius: 10px;
        border: 1px solid #e5e7eb; font-size: 14px; font-family: inherit;
    }
    input:focus, textarea:focus, select:focus { border-color: var(--primary); outline: none; }
    
    .btn-submit {
        background: var(--primary); color: white; border: none;
        padding: 14px 24px; border-radius: 10px; cursor: pointer;
        width: 100%; font-weight: 600; font-size: 16px; display: flex;
        align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover { background: var(--primary-dark); }

    /* Alerts */
    .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-success { background: #ecfdf5; color: #064e3b; border: 1px solid #a7f3d0; }

    /* Badges */
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: capitalize; display: inline-block; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-under_review { background: #e0f2fe; color: #0369a1; }
    .badge-resolved { background: #d1fae5; color: #065f46; }
    .badge-dismissed { background: #f3f4f6; color: #374151; }

    /* Case Columns Layout */
    .dispute-item { border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; background: #fff; margin-bottom: 25px; }
    .split-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-top: 15px; }
    .split-column { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; }
    .split-col-title { font-size: 12px; font-weight: 700; margin: 0 0 10px 0; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }

    /* Dynamic Evidence Grid from Admin Disputes */
    .evidence-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: 10px;
        margin-top: 10px;
    }
    .evidence-item {
        position: relative;
        width: 100%;
        height: 90px;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #cbd5e1;
        background: #f1f5f9;
    }
    .evidence-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .evidence-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.75);
        opacity: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 10px;
        transition: opacity 0.2s;
        text-align: center;
        padding: 4px;
    }
    .evidence-item:hover .evidence-overlay {
        opacity: 1;
    }
    .evidence-note {
        font-size: 11px;
        background: #ffffff;
        padding: 6px 10px;
        border-radius: 6px;
        border-left: 3px solid #cbd5e1;
        color: #475569;
        margin-top: 6px;
        line-height: 1.4;
    }

    /* RESPONSIVE */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
    }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <?php if ($my_role === 'farmer'): ?>
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
                <h2>Farmer</h2>
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
                <a href="dispute_center.php" class="nav-link active">
                    <i data-feather="alert-triangle"></i>
                    <span>Dispute Center</span>
                </a>
                <a href="farmer_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_profile.php' ? 'active' : '' ?>">
                    <i data-feather="user"></i>
                    <span>Profile</span>
                </a>
            </nav>

            <form action="logout.php" method="POST">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php endif; ?>
                <button class="logout-btn">
                    <i data-feather="log-out"></i>
                    <span>Logout</span>
                </button>
            </form>
        </aside>
    <?php else: /* agent */ ?>
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
                <h2>Agent</h2>
            </div>

            <nav class="nav">
                <a href="agent_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_dashboard.php' ? 'active' : '' ?>">
                    <i data-feather="home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="farmer_vetting.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_vetting.php' ? 'active' : '' ?>">
                    <i data-feather="users"></i>
                    <span>Farmer Vetting</span>
                </a>
                <a href="proof_verification.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'proof_verification.php' ? 'active' : '' ?>">
                    <i data-feather="check-square"></i>
                    <span>Proof Verify</span>
                </a>
                <a href="agent_repayments.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_repayments.php' ? 'active' : '' ?>">
                    <i data-feather="credit-card"></i>
                    <span>Repayments</span>
                </a>
                <a href="dispute_center.php" class="nav-link active">
                    <i data-feather="alert-triangle"></i>
                    <span>Dispute Center</span>
                </a>
                <a href="agent_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_profile.php' ? 'active' : '' ?>">
                    <i data-feather="user"></i>
                    <span>My Profile</span>
                </a>
            </nav>

            <form action="logout.php" method="POST">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php endif; ?>
                <button class="logout-btn">
                    <i data-feather="log-out"></i>
                    <span>Logout</span>
                </button>
            </form>
        </aside>
    <?php endif; ?>

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
                    <div style="font-size:12px; color:var(--text-muted);"><?= ($my_role === 'farmer') ? 'Farmer' : 'Agent' ?></div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- DISPUTE CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Dispute Center</h1>
                <p class="page-subtitle">Resolve transactional issues, submit evidence, and track administrative decisions.</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i data-feather="alert-circle"></i> <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i data-feather="check-circle"></i> <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>

            <div class="layout-grid">
                <!-- Left Column: New Dispute Form -->
                <div class="card">
                    <h2 style="margin-top:0; font-size:18px;">Submit New Dispute File</h2>
                    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
                        You may file disputes exclusively against counterparts whom you have collaborated with on system loan records.
                    </p>
                    <form method="POST" enctype="multipart/form-data">
                        <?php if (isset($_SESSION['csrf_token'])): ?>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <?php endif; ?>
                        <input type="hidden" name="action" value="launch_dispute">
                        
                        <div class="form-group">
                            <label for="loan_id">Select Affiliated Loan Transaction</label>
                            <select name="loan_id" id="loan_id" required>
                                <option value="">-- Choose Transaction --</option>
                                <?php foreach ($eligible_transactions as $et): ?>
                                    <option value="<?= $et['loan_id'] ?>">
                                        <?= htmlspecialchars($et['loan_title']) ?> (Counterpart: <?= htmlspecialchars($et['counterpart_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="title">Dispute Topic / Title</label>
                            <input type="text" id="title" name="title" placeholder="Brief statement summarizing the core issue..." required>
                        </div>

                        <div class="form-group">
                            <label for="description">Explanation & Supporting Facts</label>
                            <textarea name="description" id="description" rows="5" placeholder="List key events, milestones, or breaches clearly..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label for="evidence">Upload Evidence Files (Multiple Allowed)</label>
                            <input type="file" id="evidence" name="evidence[]" multiple style="font-size:13px;">
                            <p style="font-size:11px; color: var(--text-muted); margin-top:5px;">Allowed types: JPG, PNG, PDF, DOCX, TXT. Max size: 5MB.</p>
                        </div>

                        <button type="submit" class="btn-submit">
                            <i data-feather="send"></i> Submit Dispute File
                        </button>
                    </form>
                </div>

                <!-- Right Column: Support & Instructions -->
                <div class="card" style="background:#fefbf3; border-color:#fde68a;">
                    <h3 style="margin:0 0 15px 0; font-size:16px; color:#b45309; display:flex; align-items:center; gap:8px;">
                        <i data-feather="help-circle"></i> Vetting Rules
                    </h3>
                    <ul style="padding-left:18px; color:#92400e; font-size:13px; line-height:1.6; margin:0;">
                        <li style="margin-bottom:8px;"><strong>Counterpart Check:</strong> You can only log disputes if there is an active or completed loan connecting you to the other user.</li>
                        <li style="margin-bottom:8px;"><strong>Evidence files:</strong> Keep evidence files brief. Documents are immediately routed to administrative compliance staff.</li>
                        <li><strong>Audit trail:</strong> Unjust disputes are subject to terms of service audits and account status review.</li>
                    </ul>
                </div>
            </div>

            <!-- Bottom: Dispute History Section (Cards with Split Columns and Notes) -->
            <div class="card">
                <h3 style="margin-top:0; font-size:18px; margin-bottom:15px;">Historical Dispute Folder</h3>
                <?php if (empty($my_disputes)): ?>
                    <p style="color: var(--text-muted); text-align:center; padding: 20px; font-size:14px;">No dispute cases recorded for your profile.</p>
                <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:25px;">
                        <?php foreach ($my_disputes as $d): ?>
                            <?php
                            // Fetch evidence for this specific dispute case
                            $evStmt = $pdo->prepare("
                                SELECT de.*, u.role AS uploader_role, u.name AS uploader_name 
                                FROM dispute_evidence de
                                JOIN users u ON de.uploader_id = u.id
                                WHERE de.dispute_id = ?
                                ORDER BY de.uploaded_at ASC
                            ");
                            $evStmt->execute([$d['id']]);
                            $evidences = $evStmt->fetchAll(PDO::FETCH_ASSOC);

                            $farmer_evidences = [];
                            $agent_evidences = [];
                            foreach ($evidences as $ev) {
                                if ($ev['uploader_role'] === 'farmer') {
                                    $farmer_evidences[] = $ev;
                                } else {
                                    $agent_evidences[] = $ev;
                                }
                            }
                            ?>
                            <div class="dispute-item">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:15px; border-bottom:1px solid #f3f4f6; padding-bottom:12px;">
                                    <div>
                                        <h4 style="margin:0; font-size:16px; color:var(--text-main);">
                                            Case #<?= $d['id'] ?>: <?= htmlspecialchars($d['title']) ?>
                                        </h4>
                                        <p style="margin:4px 0 0 0; font-size:12px; color:var(--text-muted);">
                                            Transaction: <strong><?= htmlspecialchars($d['loan_title']) ?></strong> &middot; Opened <?= date('d M Y, h:i A', strtotime($d['created_at'])) ?>
                                        </p>
                                    </div>
                                    <div>
                                        <span class="badge badge-<?= $d['status'] ?>">
                                            <?= str_replace('_', ' ', ucfirst($d['status'])) ?>
                                        </span>
                                    </div>
                                </div>

                                <div style="font-size:12px; color:var(--text-muted); margin-bottom:15px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                    <div>Initiated By: <strong style="color:var(--text-main);"><?= ($d['creator_id'] == $my_id) ? 'You' : htmlspecialchars($d['creator_name']) ?> (<?= ucfirst($d['creator_role']) ?>)</strong></div>
                                    <div>Defendant: <strong style="color:var(--text-main);"><?= ($d['defendant_id'] == $my_id) ? 'You' : htmlspecialchars($d['defendant_name']) ?> (<?= ucfirst($d['defendant_role']) ?>)</strong></div>
                                </div>

                                <!-- Segmented Statements & Supporting Evidence List -->
                                <div class="split-grid">
                                    <!-- Farmer Column -->
                                    <div class="split-column">
                                        <h5 class="split-col-title" style="color: #065f46;">
                                            <i data-feather="user" style="width:14px;"></i> Farmer's Case & Evidence
                                        </h5>
                                        <?php if ($d['creator_role'] === 'farmer'): ?>
                                            <div style="font-size:12px; color:#475569; font-style:italic; margin-bottom:10px; background:#fff; border:1px solid #e5e7eb; padding:8px; border-radius:6px; line-height:1.4;">
                                                <strong>Statement:</strong> "<?= htmlspecialchars($d['description']) ?>"
                                            </div>
                                        <?php endif; ?>

                                        <?php if (empty($farmer_evidences)): ?>
                                            <p style="font-size:11px; color:var(--text-muted); font-style:italic; margin:0;">No evidence files attached.</p>
                                        <?php else: ?>
                                            <div class="evidence-grid">
                                                <?php foreach ($farmer_evidences as $ev): ?>
                                                    <?php $filePath = "../uploads/disputes/dispute_{$d['id']}/" . rawurlencode($ev['filename']); ?>
                                                    <div>
                                                        <div class="evidence-item">
                                                            <img src="<?= $filePath ?>" alt="Farmer Evidence" onerror="this.src='https://via.placeholder.com/90?text=File';">
                                                            <div class="evidence-overlay">
                                                                <a href="<?= $filePath ?>" target="_blank" style="color:#34d399; text-decoration:none; font-weight:bold; margin-bottom:8px;">Open File</a>
                                                                <?php if ((int)$ev['uploader_id'] === (int)$my_id): ?>
                                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this file?');" style="margin:0;">
                                                                        <?php if (isset($_SESSION['csrf_token'])): ?>
                                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                        <?php endif; ?>
                                                                        <input type="hidden" name="action" value="delete_evidence">
                                                                        <input type="hidden" name="evidence_id" value="<?= $ev['id'] ?>">
                                                                        <button type="submit" style="background:none; border:none; color:#fca5a5; cursor:pointer; font-size:10px; font-weight:600; padding:0; text-decoration:underline;">Delete</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php if (!empty($ev['notes'])): ?>
                                                            <div class="evidence-note" style="border-left-color: #059669;">
                                                                <strong>Note:</strong> <?= htmlspecialchars($ev['notes']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Agent Column -->
                                    <div class="split-column">
                                        <h5 class="split-col-title" style="color: #1e40af;">
                                            <i data-feather="user" style="width:14px;"></i> Agent's Case & Evidence
                                        </h5>
                                        <?php if ($d['creator_role'] === 'agent'): ?>
                                            <div style="font-size:12px; color:#475569; font-style:italic; margin-bottom:10px; background:#fff; border:1px solid #e5e7eb; padding:8px; border-radius:6px; line-height:1.4;">
                                                <strong>Statement:</strong> "<?= htmlspecialchars($d['description']) ?>"
                                            </div>
                                        <?php endif; ?>

                                        <?php if (empty($agent_evidences)): ?>
                                            <p style="font-size:11px; color:var(--text-muted); font-style:italic; margin:0;">No evidence files attached.</p>
                                        <?php else: ?>
                                            <div class="evidence-grid">
                                                <?php foreach ($agent_evidences as $ev): ?>
                                                    <?php $filePath = "../uploads/disputes/dispute_{$d['id']}/" . rawurlencode($ev['filename']); ?>
                                                    <div>
                                                        <div class="evidence-item">
                                                            <img src="<?= $filePath ?>" alt="Agent Evidence" onerror="this.src='https://via.placeholder.com/90?text=File';">
                                                            <div class="evidence-overlay">
                                                                <a href="<?= $filePath ?>" target="_blank" style="color:#34d399; text-decoration:none; font-weight:bold; margin-bottom:8px;">Open File</a>
                                                                <?php if ((int)$ev['uploader_id'] === (int)$my_id): ?>
                                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this file?');" style="margin:0;">
                                                                        <?php if (isset($_SESSION['csrf_token'])): ?>
                                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                        <?php endif; ?>
                                                                        <input type="hidden" name="action" value="delete_evidence">
                                                                        <input type="hidden" name="evidence_id" value="<?= $ev['id'] ?>">
                                                                        <button type="submit" style="background:none; border:none; color:#fca5a5; cursor:pointer; font-size:10px; font-weight:600; padding:0; text-decoration:underline;">Delete</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <?php if (!empty($ev['notes'])): ?>
                                                            <div class="evidence-note" style="border-left-color: #1e40af;">
                                                                <strong>Note:</strong> <?= htmlspecialchars($ev['notes']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Supplemental Evidence Form (Active Cases Only) -->
                                <?php if (in_array($d['status'], ['pending', 'under_review', 'open'])): ?>
                                    <details style="margin-top:15px; border-top:1px solid #f3f4f6; padding-top:12px;">
                                        <summary style="font-size:12px; font-weight:600; color:var(--primary); cursor:pointer; display:flex; align-items:center; gap:4px; list-style:none;">
                                            <i data-feather="plus-circle" style="width:14px;"></i> Submit Additional Evidence
                                        </summary>
                                        <form method="POST" enctype="multipart/form-data" style="margin-top:12px; display:flex; flex-direction:column; gap:10px; max-width:400px;">
                                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="action" value="add_evidence">
                                            <input type="hidden" name="dispute_id" value="<?= $d['id'] ?>">
                                            
                                            <div>
                                                <label style="font-size:11px; font-weight:600; color:var(--text-muted); display:block; margin-bottom:3px;">Select Files *</label>
                                                <input type="file" name="evidence[]" multiple required style="font-size:12px;">
                                            </div>
                                            <div>
                                                <label style="font-size:11px; font-weight:600; color:var(--text-muted); display:block; margin-bottom:3px;">Short Clarification Note</label>
                                                <input type="text" name="notes" placeholder="Reason or clarification for this attachment..." style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;">
                                            </div>
                                            <button type="submit" class="btn-submit" style="padding:6px 12px; font-size:12px; width:auto; align-self:flex-start; border-radius:6px;">
                                                Upload Evidences
                                            </button>
                                        </form>
                                    </details>
                                <?php endif; ?>

                                <!-- Decision Resolution Panel -->
                                <?php if ($d['status'] === 'resolved' || $d['status'] === 'dismissed'): ?>
                                    <div style="margin-top:15px; padding:12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; font-size:13px;">
                                        <strong>Administrative Resolution Details:</strong>
                                        <div style="font-style:italic; color:#166534; margin-top:4px; line-height:1.4;">
                                            "<?= htmlspecialchars($d['admin_decision'] ?? 'No formal statement submitted.') ?>"
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
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
                sidebar.classList.toggle("active"); // Mobile behavior
            } else {
                sidebar.classList.toggle("collapsed"); // Desktop behavior
            }
        });
    </script>
</body>
</html>