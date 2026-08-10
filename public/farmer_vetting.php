<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../src/db.php';

$pdo = getPDO();

// Automatically ensure necessary database tables & columns exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_farm_inspections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        farmer_id INT NOT NULL,
        agent_id INT NOT NULL,
        stage_number INT DEFAULT NULL,
        comments TEXT NOT NULL,
        photo_path VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Database table creation failover
}

try {
    $pdo->query("SELECT rejection_reason, duration_months, repayment_due_date FROM loan_applications LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE loan_applications 
            ADD COLUMN rejection_reason TEXT NULL,
            ADD COLUMN duration_months INT NULL,
            ADD COLUMN repayment_due_date DATETIME NULL");
    } catch (PDOException $ex) {
        // Migration failover
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
 * Sends an SMS notification.
 */
function send_sms_notification($phone, $message) {
    error_log("SMS dispatched to {$phone}: {$message}");
    return true;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'agent') {
    header("Location: login.php");
    exit;
}

$agent_id = (int)$_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Agent';
$successMessage = '';
$errorMessage = '';

// 1. HANDLE FIELD INSPECTION ADDITION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_inspection') {
    csrf_verify();
    $app_id = (int)($_POST['application_id'] ?? 0);
    $farmer_id = (int)($_POST['farmer_id'] ?? 0);
    $stage_num = !empty($_POST['stage_number']) ? (int)$_POST['stage_number'] : NULL;
    $comments = trim($_POST['comments'] ?? '');

    $photo_path = null;
    if (isset($_FILES['inspection_photo']) && $_FILES['inspection_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/inspections/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['inspection_photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (in_array($ext, $allowed)) {
            $filename = 'insp_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['inspection_photo']['tmp_name'], $upload_dir . $filename)) {
                $photo_path = 'uploads/inspections/' . $filename;
            }
        }
    }

    if (!empty($comments) && $app_id > 0 && $farmer_id > 0) {
        $stmt = $pdo->prepare("INSERT INTO agent_farm_inspections (application_id, farmer_id, agent_id, stage_number, comments, photo_path) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$app_id, $farmer_id, $agent_id, $stage_num, $comments, $photo_path])) {
            $successMessage = "Farm inspection check added successfully!";
        } else {
            $errorMessage = "Failed to record inspection details.";
        }
    } else {
        $errorMessage = "Please enter valid inspection comments.";
    }
}

// 2. HANDLE FIELD INSPECTION EDIT / ALTER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_inspection') {
    csrf_verify();
    $inspection_id = (int)($_POST['inspection_id'] ?? 0);
    $stage_num = !empty($_POST['stage_number']) ? (int)$_POST['stage_number'] : NULL;
    $comments = trim($_POST['comments'] ?? '');

    // Verify ownership
    $checkStmt = $pdo->prepare("SELECT * FROM agent_farm_inspections WHERE id = ? AND agent_id = ?");
    $checkStmt->execute([$inspection_id, $agent_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && !empty($comments)) {
        $photo_path = $existing['photo_path'];

        // Check if user selected photo removal
        if (!empty($_POST['remove_photo']) && $photo_path) {
            $full_path = __DIR__ . '/../' . $photo_path;
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
            $photo_path = null;
        }

        // Handle replacement photo upload if provided
        if (isset($_FILES['inspection_photo']) && $_FILES['inspection_photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/inspections/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['inspection_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            if (in_array($ext, $allowed)) {
                // Delete old file
                if ($existing['photo_path'] && file_exists(__DIR__ . '/../' . $existing['photo_path'])) {
                    @unlink(__DIR__ . '/../' . $existing['photo_path']);
                }
                $filename = 'insp_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['inspection_photo']['tmp_name'], $upload_dir . $filename)) {
                    $photo_path = 'uploads/inspections/' . $filename;
                }
            }
        }

        $stmt = $pdo->prepare("UPDATE agent_farm_inspections SET stage_number = ?, comments = ?, photo_path = ? WHERE id = ? AND agent_id = ?");
        if ($stmt->execute([$stage_num, $comments, $photo_path, $inspection_id, $agent_id])) {
            $successMessage = "Inspection check updated successfully!";
        } else {
            $errorMessage = "Failed to update inspection details.";
        }
    } else {
        $errorMessage = "Inspection record not found or comments missing.";
    }
}

// 3. HANDLE FIELD INSPECTION DELETION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_inspection') {
    csrf_verify();
    $inspection_id = (int)($_POST['inspection_id'] ?? 0);

    // Verify ownership & get photo path to delete file from disk
    $checkStmt = $pdo->prepare("SELECT photo_path FROM agent_farm_inspections WHERE id = ? AND agent_id = ?");
    $checkStmt->execute([$inspection_id, $agent_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if (!empty($existing['photo_path'])) {
            $full_path = __DIR__ . '/../' . $existing['photo_path'];
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
        }

        $stmt = $pdo->prepare("DELETE FROM agent_farm_inspections WHERE id = ? AND agent_id = ?");
        if ($stmt->execute([$inspection_id, $agent_id])) {
            $successMessage = "Inspection record deleted successfully!";
        } else {
            $errorMessage = "Failed to delete inspection record.";
        }
    } else {
        $errorMessage = "Inspection record not found or access denied.";
    }
}

// 4. HANDLE APPROVAL / REJECTION ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id'], $_POST['action']) && !in_array($_POST['action'], ['add_inspection', 'edit_inspection', 'delete_inspection'])) {
    csrf_verify();
    $application_id = (int)$_POST['application_id'];
    $action = ($_POST['action'] === 'approve') ? 'approved' : 'rejected';
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    $duration_months = isset($_POST['duration_months']) ? (int)$_POST['duration_months'] : null;

    if ($action === 'rejected') {
        $stmt = $pdo->prepare("UPDATE loan_applications SET status = ?, rejection_reason = ? WHERE id = ? AND agent_id = ?");
        $params = [$action, $rejection_reason, $application_id, $agent_id];
    } else {
        if (!$duration_months || $duration_months <= 0) {
            $duration_months = 1;
        }
        $repayment_due_date = date('Y-m-d H:i:s', strtotime("+{$duration_months} months"));
        $stmt = $pdo->prepare("UPDATE loan_applications SET status = ?, duration_months = ?, repayment_due_date = ?, rejection_reason = NULL WHERE id = ? AND agent_id = ?");
        $params = [$action, $duration_months, $repayment_due_date, $application_id, $agent_id];
    }

    if ($stmt->execute($params)) {
        $successMessage = ($action === 'approved')
            ? 'Application Approved Successfully with ' . $duration_months . ' month(s) repayment duration!'
            : 'Application Rejected!';

        $infoStmt = $pdo->prepare("
            SELECT la.title, la.amount, la.repayment_due_date, u.name AS farmer_name, u.email AS farmer_email, u.phone AS farmer_phone
            FROM loan_applications la
            JOIN users u ON la.farmer_id = u.id
            WHERE la.id = ?
        ");
        $infoStmt->execute([$application_id]);
        $loanInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

        if ($loanInfo) {
            $f_name = $loanInfo['farmer_name'];
            $f_email = $loanInfo['farmer_email'];
            $f_phone = $loanInfo['farmer_phone'];
            $l_title = $loanInfo['title'];
            $l_amount = number_format((float)$loanInfo['amount'], 2);
            $due_date_formatted = !empty($loanInfo['repayment_due_date']) ? date('M d, Y', strtotime($loanInfo['repayment_due_date'])) : 'N/A';

            if ($action === 'approved') {
                $subject = "AgroLoan Application Approved: " . $l_title;
                $email_body = "
                    <h2>Congratulations, {$f_name}!</h2>
                    <p>Your loan application for <strong>{$l_title}</strong> of amount <strong>GHS {$l_amount}</strong> has been <strong>approved</strong>.</p>
                    <p><strong>Loan Duration:</strong> {$duration_months} Month(s)<br>
                    <strong>Repayment Due Date:</strong> {$due_date_formatted}</p>
                    <p>Repayments will open automatically when this duration elapses or when stage disbursements finish. Log in to view progress.</p>
                    <br>
                    <p>Best regards,<br>AgroLoan Team</p>
                ";
                $sms_text = "Hello {$f_name}, your AgroLoan application '{$l_title}' of GHS {$l_amount} has been approved! Duration: {$duration_months} month(s). Due Date: {$due_date_formatted}.";
            } else {
                $subject = "AgroLoan Application Update: " . $l_title;
                $reason_snippet = !empty($rejection_reason) ? $rejection_reason : "Please check the portal for feedback.";
                $email_body = "
                    <h2>Hello, {$f_name}</h2>
                    <p>We regret to inform you that your loan application for <strong>{$l_title}</strong> of amount <strong>GHS {$l_amount}</strong> was not approved at this time.</p>
                    <p><strong>Reason provided by the agent:</strong><br><em>{$reason_snippet}</em></p>
                    <p>You can re-evaluate, address the agent's concerns, and resubmit your application through your dashboard.</p>
                    <br>
                    <p>Best regards,<br>AgroLoan Team</p>
                ";
                $sms_text = "Hello {$f_name}, your application '{$l_title}' was rejected. Reason: '{$reason_snippet}'. You can modify and resubmit it now.";
            }

            if (!empty($f_email)) send_email_notification($f_email, $subject, $email_body);
            if (!empty($f_phone)) send_sms_notification($f_phone, $sms_text);
        }
    }
}

// Fetch loan applications assigned to current agent
$stmt = $pdo->prepare("
    SELECT 
        la.id AS application_id,
        la.farmer_id,
        la.title,
        la.amount,
        la.purpose,
        la.status,
        la.duration_months,
        la.repayment_due_date,
        la.rejection_reason,
        la.created_at,
        u.name AS farmer_name,
        u.email AS farmer_email,
        u.phone,
        fp.house_address AS farmer_address,
        fp.id_card AS farmer_id_card,
        fp.farm_type AS farmer_farm_type,
        fp.acreage AS farmer_acreage,
        fp.passport_photo AS farmer_photo,
        fp.id_card_number AS farmer_id_number
    FROM loan_applications la
    INNER JOIN users u ON la.farmer_id = u.id
    LEFT JOIN farmer_profiles fp ON u.id = fp.user_id
    WHERE la.agent_id = ?
    ORDER BY la.created_at DESC
");
$stmt->execute([$agent_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all field inspection histories per farmer (includes field checks conducted by ALL agents)
$inspections_by_farmer = [];
foreach ($applications as $app) {
    $f_id = (int)$app['farmer_id'];
    if (!isset($inspections_by_farmer[$f_id])) {
        $insp_stmt = $pdo->prepare("
            SELECT afi.*, ag.name AS agent_name, la.title AS loan_title
            FROM agent_farm_inspections afi
            LEFT JOIN users ag ON afi.agent_id = ag.id
            LEFT JOIN loan_applications la ON afi.application_id = la.id
            WHERE afi.farmer_id = ?
            ORDER BY afi.created_at DESC
        ");
        $insp_stmt->execute([$f_id]);
        $inspections_by_farmer[$f_id] = $insp_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Farmer Vetting | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/feather-icons"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root {
        --primary: #1e40af;      
        --primary-dark: #172554;  
        --secondary: #3b82f6;   
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #1f2937;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --success: #10b981;
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Poppins', sans-serif; background: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

    .sidebar { width: var(--sidebar-width); background: var(--primary-dark); color: #fff; display: flex; flex-direction: column; padding: 20px; transition: width 0.3s ease; z-index: 100; box-shadow: 4px 0 10px rgba(0,0,0,0.1); }
    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }
    .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding-left: 5px; overflow: hidden; }
    .brand img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 2px solid rgba(255,255,255,0.2); }
    .brand h2 { font-size: 20px; font-weight: 600; white-space: nowrap; opacity: 1; transition: opacity 0.2s; margin: 0; }
    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 15px; color: #dbeafe; text-decoration: none; border-radius: 10px; transition: all 0.2s ease; white-space: nowrap; font-weight: 500; }
    .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
    .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4); }
    .nav-link svg { width: 20px; height: 20px; }
    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

    .logout-btn { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.2); padding: 12px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; font-family: inherit; font-weight: 600; transition: 0.2s; width: 100%; }
    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    .main { flex: 1; display: flex; flex-direction: column; overflow-y: auto; position: relative; }
    .topbar { background: var(--bg-card); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50; }
    .toggle-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; }
    .toggle-btn:hover { color: var(--primary); }
    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar { width: 35px; height: 35px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; }

    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    .table-container { background: var(--bg-card); border-radius: 16px; padding: 24px; box-shadow: var(--shadow); overflow-x: auto; margin-bottom: 30px; }
    .table-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .table-title { font-size: 18px; font-weight: 600; color: var(--text-main); }

    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f3f4f6; }
    th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); font-weight: 600; background: #f9fafb; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f9fafb; }

    .btn-sm { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s; border: none; cursor: pointer; color: white; margin-right: 4px; }
    .btn-approve { background: #dcfce7; color: #16a34a; }
    .btn-approve:hover { background: #16a34a; color: white; }
    .btn-reject { background: #fee2e2; color: #dc2626; }
    .btn-reject:hover { background: #dc2626; color: white; }
    .btn-view { background: #e0f2fe; color: #0284c7; }
    .btn-view:hover { background: #0284c7; color: white; }
    .btn-check { background: #fef3c7; color: #d97706; }
    .btn-check:hover { background: #d97706; color: white; }
    .btn-edit-sm { background: #e0e7ff; color: #4338ca; padding: 3px 8px; font-size: 11px; border-radius: 6px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:4px; }
    .btn-edit-sm:hover { background: #4338ca; color: white; }
    .btn-delete-sm { background: #fee2e2; color: #dc2626; padding: 3px 8px; font-size: 11px; border-radius: 6px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:4px; }
    .btn-delete-sm:hover { background: #dc2626; color: white; }
    
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .badge-pending { background: #fff7ed; color: #b45309; }
    .badge-approved { background: #ecfdf5; color: #047857; }
    .badge-rejected { background: #fef2f2; color: #b91c1c; }

    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(2px); overflow-y: auto; }
    .modal-content { background-color: var(--bg-card); margin: 3% auto; padding: 0; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 90%; max-width: 850px; animation: slideIn 0.3s ease-out; }
    @keyframes slideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

    .modal-header { padding: 20px 24px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { margin: 0; font-size: 18px; color: var(--primary-dark); }
    .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); }
    .modal-close:hover { color: var(--danger); }
    .modal-body { padding: 24px; }

    .detail-row { margin-bottom: 15px; }
    .detail-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 4px; }
    .detail-value { font-size: 15px; color: var(--text-main); font-weight: 500; line-height: 1.5; }
    .detail-full-text { background: #f9fafb; padding: 12px; border-radius: 8px; font-size: 14px; border: 1px solid #e5e7eb; margin-top: 5px; white-space: pre-wrap; }

    .inspection-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px; margin-bottom: 12px; position: relative; }
    .inspection-header { display: flex; justify-content: space-between; font-size: 12px; color: #475569; font-weight: 600; margin-bottom: 8px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 6px; }

    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: var(--text-main); }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #d1d5db; font-family: inherit; font-size: 14px; }

    .doc-preview-wrapper { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
    .doc-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 15px; text-align: center; }
    .doc-card h4 { margin: 0 0 10px 0; font-size: 13px; text-transform: uppercase; color: var(--text-muted); }
    .doc-img { max-width: 100%; height: 160px; object-fit: contain; border-radius: 6px; border: 1px solid #e5e7eb; background: #fff; }
    .doc-placeholder { height: 160px; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 13px; background: #f3f4f6; border-radius: 6px; border: 1px dashed #d1d5db; }
    .view-link { display: inline-block; margin-top: 8px; font-size: 12px; color: var(--primary); text-decoration: none; font-weight: 500; }
    .view-link:hover { text-decoration: underline; }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .doc-preview-wrapper { grid-template-columns: 1fr; }
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
            <a href="agent_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="home"></i><span>Dashboard</span>
            </a>
            <a href="farmer_vetting.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_vetting.php' ? 'active' : '' ?>">
                <i data-feather="users"></i><span>Farmer Vetting</span>
            </a>
            <a href="proof_verification.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'proof_verification.php' ? 'active' : '' ?>">
                <i data-feather="check-square"></i><span>Proof Verify</span>
            </a>
            <a href="agent_repayments.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_repayments.php' ? 'active' : '' ?>">
                <i data-feather="credit-card"></i><span>Repayments</span>
            </a>
            <a href="dispute_center.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dispute_center.php' ? 'active' : '' ?>">
                <i data-feather="alert-triangle"></i><span>Dispute Center</span>
            </a>
            <a href="agent_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_profile.php' ? 'active' : '' ?>">
                <i data-feather="user"></i><span>My Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
            <?php if (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php endif; ?>
            <button class="logout-btn"><i data-feather="log-out"></i><span>Logout</span></button>
        </form>
    </aside>

    <main class="main">
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn"><i data-feather="menu"></i></button>
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Agent</div>
                </div>
                <div class="user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
            </div>
        </header>

        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Farmer Vetting & Field Inspections</h1>
                <p class="page-subtitle">Review applications, conduct farm field checks, manage inspection notes/photos, and approve or reject requests.</p>
            </div>

            <div class="table-container">
                <div class="table-header">
                    <div class="table-title">Loan Applications</div>
                </div>
                
                <?php if (empty($applications)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:10px;">No loan applications found assigned to you.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Farmer Details</th>
                                <th>Project & Purpose</th>
                                <th>Amount</th>
                                <th>Duration / Due Date</th>
                                <th>Status</th>
                                <th style="width: 310px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($applications as $app): ?>
                            <?php 
                            $farmer_insps = $inspections_by_farmer[$app['farmer_id']] ?? []; 
                            $encoded_inspections = htmlspecialchars(json_encode($farmer_insps, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($app['farmer_name']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($app['farmer_email']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?= htmlspecialchars($app['title'] ?: 'Untitled Project') ?></div>
                                    <div style="font-size:12px; color:var(--text-muted); max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($app['purpose']) ?>">
                                        <?= htmlspecialchars($app['purpose']) ?>
                                    </div>
                                </td>
                                <td style="font-weight:600;">GHS <?= number_format((float)$app['amount'], 2) ?></td>
                                <td>
                                    <?php if ($app['duration_months']): ?>
                                        <div style="font-size:13px; font-weight:600; color:var(--primary);"><?= $app['duration_months'] ?> Months</div>
                                        <div style="font-size:11px; color:var(--text-muted);">Due: <?= date('M d, Y', strtotime($app['repayment_due_date'])) ?></div>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-muted); font-style:italic;">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars($app['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($app['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <!-- View Details Trigger -->
                                    <button type="button" class="btn-sm btn-view open-modal-btn"
                                            data-name="<?= htmlspecialchars($app['farmer_name']) ?>"
                                            data-email="<?= htmlspecialchars($app['farmer_email']) ?>"
                                            data-phone="<?= htmlspecialchars($app['phone']) ?>"
                                            data-address="<?= htmlspecialchars($app['farmer_address'] ?? 'No Address Provided') ?>"
                                            data-nationalid="<?= htmlspecialchars($app['farmer_id_card'] ?? '') ?>"
                                            data-idcardnumber="<?= htmlspecialchars($app['farmer_id_number'] ?? '') ?>"
                                            data-farmtype="<?= htmlspecialchars($app['farmer_farm_type'] ?? '') ?>"
                                            data-acreage="<?= htmlspecialchars($app['farmer_acreage'] ?? '') ?>"
                                            data-photo="<?= htmlspecialchars($app['farmer_photo'] ?? '') ?>"
                                            data-amount="<?= number_format((float)$app['amount'], 2) ?>"
                                            data-title="<?= htmlspecialchars($app['title']) ?>"
                                            data-duration="<?= htmlspecialchars($app['duration_months'] ? $app['duration_months'] . ' Months' : 'N/A') ?>"
                                            data-duedate="<?= htmlspecialchars($app['repayment_due_date'] ? date('M d, Y', strtotime($app['repayment_due_date'])) : 'N/A') ?>"
                                            data-date="<?= date('F d, Y', strtotime($app['created_at'])) ?>"
                                            data-status="<?= htmlspecialchars($app['status']) ?>"
                                            data-rejection-reason="<?= htmlspecialchars($app['rejection_reason'] ?? '') ?>"
                                            data-purpose="<?= htmlspecialchars($app['purpose']) ?>"
                                            data-inspections="<?= $encoded_inspections ?>">
                                        <i data-feather="eye" style="width:13px; height:13px;"></i> View
                                    </button>

                                    <!-- Add Field Check Trigger -->
                                    <button type="button" class="btn-sm btn-check open-check-btn"
                                            data-app-id="<?= $app['application_id'] ?>"
                                            data-farmer-id="<?= $app['farmer_id'] ?>"
                                            data-farmer-name="<?= htmlspecialchars($app['farmer_name']) ?>"
                                            data-title="<?= htmlspecialchars($app['title']) ?>">
                                        <i data-feather="camera" style="width:13px; height:13px;"></i> Add Check
                                    </button>

                                    <?php if ($app['status'] === 'pending'): ?>
                                        <form method="POST" class="action-form approve-form" style="display:inline-block;">
                                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                            <button type="button" class="btn-sm btn-approve trigger-approve-btn" title="Approve with Duration">
                                                <i data-feather="check" style="width:13px; height:13px;"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="action-form reject-form" style="display:inline-block;">
                                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                            <button type="button" class="btn-sm btn-reject trigger-reject-btn" title="Reject Application">
                                                <i data-feather="x" style="width:13px; height:13px;"></i>
                                            </button>
                                        </form>
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

    <!-- MODAL 1: APPLICATION DETAILS & CROSS-AGENT INSPECTION HISTORY -->
    <div id="vettingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Application Details & Field Check History</h3>
                <button class="modal-close" id="closeVettingModal">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <div class="detail-row"><span class="detail-label">Farmer Name</span><span class="detail-value" id="m_name">...</span></div>
                        <div class="detail-row"><span class="detail-label">Email / Phone</span><span class="detail-value" id="m_contact">...</span></div>
                        <div class="detail-row"><span class="detail-label">House Address</span><span class="detail-value" id="m_address">...</span></div>
                        <div class="detail-row"><span class="detail-label">National ID Card Number</span><span class="detail-value" id="m_id_number">...</span></div>
                        <div class="detail-row"><span class="detail-label">Farm Type & Acreage</span><span class="detail-value" id="m_farm_info">...</span></div>
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <div class="detail-row"><span class="detail-label">Project Title</span><span class="detail-value" id="m_title">...</span></div>
                        <div class="detail-row"><span class="detail-label">Requested Amount</span><span class="detail-value" style="color:var(--primary); font-weight:700;">GHS <span id="m_amount">0.00</span></span></div>
                        <div class="detail-row"><span class="detail-label">Loan Duration / Due Date</span><span class="detail-value" id="m_duration_due">...</span></div>
                        <div class="detail-row"><span class="detail-label">Applied Date</span><span class="detail-value" id="m_date">...</span></div>
                        <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value" id="m_status">...</span></div>
                    </div>
                </div>

                <div class="detail-row" id="m_rejection_reason_container" style="display: none; margin-top: 10px;">
                    <span class="detail-label" style="color: var(--danger);">Rejection Reason</span>
                    <div class="detail-full-text" id="m_rejection_reason" style="border-color: #fecaca; background: #fef2f2; color: #991b1b;">...</div>
                </div>

                <div class="detail-row" style="margin-top: 10px;">
                    <span class="detail-label">Full Project Purpose</span>
                    <div class="detail-full-text" id="m_purpose">...</div>
                </div>

                <!-- CROSS-AGENT FIELD CHECK HISTORY SECTION -->
                <div style="margin-top: 25px; border-top: 2px dashed #e2e8f0; padding-top: 20px;">
                    <h4 style="margin: 0 0 15px 0; color: var(--primary-dark); font-size: 15px; display: flex; align-items: center; gap: 8px;">
                        <i data-feather="shield"></i> Farmer's Cross-Agent Field Check History
                    </h4>
                    <div id="m_inspection_history_list">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <div class="doc-preview-wrapper">
                    <div class="doc-card">
                        <h4>Farmer Photo</h4>
                        <img id="img_photo" class="doc-img" src="" alt="Farmer Photo" style="display:none;">
                        <div id="no_photo" class="doc-placeholder">No photo uploaded</div>
                        <a id="lnk_photo" href="#" target="_blank" class="view-link" style="display:none;">View full resolution</a>
                    </div>
                    <div class="doc-card">
                        <h4>ID Card</h4>
                        <img id="img_idcard" class="doc-img" src="" alt="ID Card" style="display:none;">
                        <div id="no_idcard" class="doc-placeholder">No ID Card uploaded</div>
                        <a id="lnk_idcard" href="#" target="_blank" class="view-link" style="display:none;">View full resolution</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2: ADD FIELD CHECK / INSPECTION REPORT -->
    <div id="inspectionModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3>Record Farm Field Check</h3>
                <button class="modal-close" id="closeInspModal">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="modal-body">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="add_inspection">
                <input type="hidden" name="application_id" id="insp_app_id">
                <input type="hidden" name="farmer_id" id="insp_farmer_id">

                <div class="detail-row">
                    <span class="detail-label">Farmer & Loan Project</span>
                    <div style="font-weight: 600;" id="insp_farmer_title">...</div>
                </div>

                <div class="form-group">
                    <label for="stage_number">Loan Stage (Optional)</label>
                    <select name="stage_number" id="stage_number">
                        <option value="">-- General / Initial Field Check --</option>
                        <option value="1">Stage 1 Inspection</option>
                        <option value="2">Stage 2 Inspection</option>
                        <option value="3">Stage 3 Inspection</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="comments">Agent Inspection Notes & Comments *</label>
                    <textarea name="comments" id="comments" rows="4" placeholder="Enter findings from field visit (crop condition, land readiness, stage progress)..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="inspection_photo">Attach Field Photo / Proof Document (Optional)</label>
                    <input type="file" name="inspection_photo" id="inspection_photo" accept="image/*,.pdf">
                </div>

                <button type="submit" class="btn-sm btn-approve" style="width: 100%; padding: 12px; font-size: 14px; margin-top: 10px;">
                    <i data-feather="save"></i> Save Inspection Check Record
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: EDIT FIELD CHECK / INSPECTION REPORT -->
    <div id="editInspectionModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3>Edit Farm Field Check</h3>
                <button class="modal-close" id="closeEditInspModal">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="modal-body">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="edit_inspection">
                <input type="hidden" name="inspection_id" id="edit_insp_id">

                <div class="form-group">
                    <label for="edit_stage_number">Loan Stage (Optional)</label>
                    <select name="stage_number" id="edit_stage_number">
                        <option value="">-- General / Initial Field Check --</option>
                        <option value="1">Stage 1 Inspection</option>
                        <option value="2">Stage 2 Inspection</option>
                        <option value="3">Stage 3 Inspection</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_comments">Agent Inspection Notes & Comments *</label>
                    <textarea name="comments" id="edit_comments" rows="4" required></textarea>
                </div>

                <div id="edit_existing_photo_container" style="display:none; margin-bottom:15px; padding:10px; background:#f1f5f9; border-radius:8px;">
                    <span class="detail-label">Current Attached File</span>
                    <a id="edit_existing_photo_link" href="#" target="_blank" class="view-link" style="margin-top:2px;">View Existing File</a>
                    <div style="margin-top:6px;">
                        <label style="font-size:12px; font-weight:500; cursor:pointer; color:var(--danger);">
                            <input type="checkbox" name="remove_photo" value="1"> Remove current photo/file
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_inspection_photo">Upload Replacement Photo / Proof File (Optional)</label>
                    <input type="file" name="inspection_photo" id="edit_inspection_photo" accept="image/*,.pdf">
                </div>

                <button type="submit" class="btn-sm btn-approve" style="width: 100%; padding: 12px; font-size: 14px; margin-top: 10px;">
                    <i data-feather="check-circle"></i> Update Inspection Record
                </button>
            </form>
        </div>
    </div>

    <!-- HIDDEN FORM FOR DELETING INSPECTION RECORDS -->
    <form id="deleteInspectionForm" method="POST" style="display:none;">
        <?php if (isset($_SESSION['csrf_token'])): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <?php endif; ?>
        <input type="hidden" name="action" value="delete_inspection">
        <input type="hidden" name="inspection_id" id="delete_insp_id">
    </form>

    <script>
        feather.replace();

        const currentAgentId = <?= (int)$agent_id ?>;

        // Sidebar Navigation Toggle
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");
        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) sidebar.classList.toggle("active");
            else sidebar.classList.toggle("collapsed");
        });

        // Path Resolver Helper Function
        function resolveImagePath(path) {
            if (!path || path.trim() === "" || path === "N/A") return "";
            if (path.startsWith("http://") || path.startsWith("https://") || path.startsWith("../") || path.startsWith("/")) return path;
            return "../uploads/farmers/" + path;
        }

        // Modal 1: Application Details & Field Check History
        const vettingModal = document.getElementById("vettingModal");
        const closeVettingModal = document.getElementById("closeVettingModal");
        const openModalBtns = document.querySelectorAll(".open-modal-btn");

        openModalBtns.forEach(btn => {
            btn.addEventListener("click", function() {
                document.getElementById("m_name").textContent = this.getAttribute("data-name") || "N/A";
                document.getElementById("m_contact").textContent = (this.getAttribute("data-email") || "") + " | " + (this.getAttribute("data-phone") || "");
                document.getElementById("m_address").textContent = this.getAttribute("data-address") || "N/A";
                document.getElementById("m_id_number").textContent = this.getAttribute("data-idcardnumber") || "Not recorded";
                document.getElementById("m_farm_info").textContent = (this.getAttribute("data-farmtype") || "N/A") + " (" + (this.getAttribute("data-acreage") || "N/A") + " Acres)";
                document.getElementById("m_title").textContent = this.getAttribute("data-title") || "Untitled Project";
                document.getElementById("m_amount").textContent = this.getAttribute("data-amount") || "0.00";
                
                const duration = this.getAttribute("data-duration") || "N/A";
                const duedate = this.getAttribute("data-duedate") || "N/A";
                document.getElementById("m_duration_due").textContent = duration + " (Due: " + duedate + ")";
                
                document.getElementById("m_date").textContent = this.getAttribute("data-date") || "";
                
                const status = (this.getAttribute("data-status") || "").toLowerCase();
                const m_status = document.getElementById("m_status");
                m_status.textContent = status.toUpperCase();
                if (status === 'pending') m_status.style.color = 'var(--warning)';
                else if (status === 'approved') m_status.style.color = 'var(--success)';
                else m_status.style.color = 'var(--danger)';

                document.getElementById("m_purpose").textContent = this.getAttribute("data-purpose") || "No details provided.";

                // Rejection Reason Handling
                const rejectionReason = this.getAttribute("data-rejection-reason");
                const rejectionContainer = document.getElementById("m_rejection_reason_container");
                if (status === 'rejected' && rejectionReason && rejectionReason.trim() !== '') {
                    document.getElementById("m_rejection_reason").textContent = rejectionReason;
                    rejectionContainer.style.display = "block";
                } else {
                    rejectionContainer.style.display = "none";
                }

                // Cross-Agent Inspection History Population with Edit & Delete Actions for Current Agent
                let inspections = [];
                try {
                    inspections = JSON.parse(this.getAttribute("data-inspections") || "[]");
                } catch(e) { inspections = []; }

                const historyContainer = document.getElementById("m_inspection_history_list");
                historyContainer.innerHTML = "";

                if (inspections.length === 0) {
                    historyContainer.innerHTML = `<p style="font-size:13px; color:var(--text-muted); font-style:italic;">No recorded field checks present for this farmer from any agent yet.</p>`;
                } else {
                    inspections.forEach(insp => {
                        const stageLabel = insp.stage_number ? `Stage ${insp.stage_number}` : 'General Visit';
                        const agentName = insp.agent_name ? insp.agent_name : 'Unknown Agent';
                        const loanTitle = insp.loan_title ? insp.loan_title : 'N/A';
                        const isOwner = parseInt(insp.agent_id) === currentAgentId;

                        const photoMarkup = insp.photo_path 
                            ? `<div style="margin-top:8px;"><a href="../${insp.photo_path}" target="_blank" class="view-link"><i data-feather="image" style="width:12px;height:12px;"></i> View Attached Field Document</a></div>` 
                            : '';
                        
                        // Edit & Delete Action Buttons for records owned by logged-in agent
                        let ownerActions = '';
                        if (isOwner) {
                            const safeComments = encodeURIComponent(insp.comments || '');
                            const safePhoto = encodeURIComponent(insp.photo_path || '');
                            ownerActions = `
                                <div style="display:flex; gap:6px; margin-top:8px;">
                                    <button type="button" class="btn-edit-sm edit-insp-btn" 
                                            data-id="${insp.id}" 
                                            data-stage="${insp.stage_number || ''}" 
                                            data-comments="${safeComments}" 
                                            data-photo="${safePhoto}">
                                        <i data-feather="edit-2" style="width:11px;height:11px;"></i> Edit / Alter
                                    </button>
                                    <button type="button" class="btn-delete-sm delete-insp-btn" data-id="${insp.id}">
                                        <i data-feather="trash-2" style="width:11px;height:11px;"></i> Delete
                                    </button>
                                </div>
                            `;
                        }

                        const inspCard = document.createElement("div");
                        inspCard.className = "inspection-card";
                        inspCard.innerHTML = `
                            <div class="inspection-header">
                                <span>Agent: ${agentName} ${isOwner ? '<strong>(You)</strong>' : ''} • (${loanTitle})</span>
                                <span>${stageLabel} • ${new Date(insp.created_at).toLocaleDateString()}</span>
                            </div>
                            <div style="font-size:13px; color:var(--text-main); line-height:1.4;">${insp.comments}</div>
                            ${photoMarkup}
                            ${ownerActions}
                        `;
                        historyContainer.appendChild(inspCard);
                    });
                    feather.replace();
                    bindInspectionActions();
                }

                // Farmer Photo Preview Handling
                const img_photo = document.getElementById("img_photo");
                const no_photo = document.getElementById("no_photo");
                const lnk_photo = document.getElementById("lnk_photo");
                const resolvedPhoto = resolveImagePath(this.getAttribute("data-photo"));
                
                if (resolvedPhoto) {
                    img_photo.src = resolvedPhoto;
                    img_photo.style.display = "inline-block";
                    lnk_photo.href = resolvedPhoto;
                    lnk_photo.style.display = "inline-block";
                    no_photo.style.display = "none";
                } else {
                    img_photo.style.display = "none";
                    lnk_photo.style.display = "none";
                    no_photo.style.display = "flex";
                }

                // Farmer ID Card Preview Handling
                const img_idcard = document.getElementById("img_idcard");
                const no_idcard = document.getElementById("no_idcard");
                const lnk_idcard = document.getElementById("lnk_idcard");
                const resolvedIdCard = resolveImagePath(this.getAttribute("data-nationalid"));
                
                if (resolvedIdCard) {
                    img_idcard.src = resolvedIdCard;
                    img_idcard.style.display = "inline-block";
                    lnk_idcard.href = resolvedIdCard;
                    lnk_idcard.style.display = "inline-block";
                    no_idcard.style.display = "none";
                } else {
                    img_idcard.style.display = "none";
                    lnk_idcard.style.display = "none";
                    no_idcard.style.display = "flex";
                }

                vettingModal.style.display = "block";
            });
        });

        closeVettingModal.addEventListener("click", () => vettingModal.style.display = "none");

        // Modal 2: Record New Field Check
        const inspModal = document.getElementById("inspectionModal");
        const closeInspModal = document.getElementById("closeInspModal");
        const openCheckBtns = document.querySelectorAll(".open-check-btn");

        openCheckBtns.forEach(btn => {
            btn.addEventListener("click", function() {
                document.getElementById("insp_app_id").value = this.getAttribute("data-app-id");
                document.getElementById("insp_farmer_id").value = this.getAttribute("data-farmer-id");
                document.getElementById("insp_farmer_title").textContent = this.getAttribute("data-farmer-name") + " — " + this.getAttribute("data-title");
                inspModal.style.display = "block";
            });
        });

        closeInspModal.addEventListener("click", () => inspModal.style.display = "none");

        // Modal 3: Edit/Alter Field Check Setup
        const editInspModal = document.getElementById("editInspectionModal");
        const closeEditInspModal = document.getElementById("closeEditInspModal");
        closeEditInspModal.addEventListener("click", () => editInspModal.style.display = "none");

        function bindInspectionActions() {
            // Edit Inspection Triggers
            document.querySelectorAll(".edit-insp-btn").forEach(btn => {
                btn.addEventListener("click", function() {
                    const id = this.getAttribute("data-id");
                    const stage = this.getAttribute("data-stage");
                    const comments = decodeURIComponent(this.getAttribute("data-comments") || "");
                    const photo = decodeURIComponent(this.getAttribute("data-photo") || "");

                    document.getElementById("edit_insp_id").value = id;
                    document.getElementById("edit_stage_number").value = stage || "";
                    document.getElementById("edit_comments").value = comments;

                    const photoContainer = document.getElementById("edit_existing_photo_container");
                    const photoLink = document.getElementById("edit_existing_photo_link");

                    if (photo && photo.trim() !== "") {
                        photoLink.href = "../" + photo;
                        photoContainer.style.display = "block";
                    } else {
                        photoContainer.style.display = "none";
                    }

                    editInspModal.style.display = "block";
                });
            });

            // Delete Inspection Triggers
            document.querySelectorAll(".delete-insp-btn").forEach(btn => {
                btn.addEventListener("click", function() {
                    const id = this.getAttribute("data-id");
                    Swal.fire({
                        title: 'Delete Inspection Record?',
                        text: 'This action cannot be undone and will remove the inspection notes & attached photo.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Yes, Delete Record'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            document.getElementById("delete_insp_id").value = id;
                            document.getElementById("deleteInspectionForm").submit();
                        }
                    });
                });
            });
        }

        // Close Modals on Outside Backdrop Click
        window.addEventListener("click", (e) => {
            if (e.target === vettingModal) vettingModal.style.display = "none";
            if (e.target === inspModal) inspModal.style.display = "none";
            if (e.target === editInspModal) editInspModal.style.display = "none";
        });

        // SweetAlert Prompts for Approval & Rejection Actions
        document.querySelectorAll(".trigger-approve-btn").forEach(btn => {
            btn.addEventListener("click", function() {
                const form = this.closest("form");
                Swal.fire({
                    title: 'Approve Loan Application',
                    text: 'Set the loan duration until repayment is due (in months):',
                    input: 'number',
                    inputAttributes: { min: 1, max: 120, step: 1, placeholder: 'e.g. 6' },
                    showCancelButton: true,
                    confirmButtonColor: '#16a34a',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Approve & Set Due Date',
                    preConfirm: (value) => {
                        if (!value || parseInt(value) < 1) {
                            Swal.showValidationMessage('Please enter a valid duration (minimum 1 month).');
                        }
                        return value;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const aIn = document.createElement("input"); aIn.type = "hidden"; aIn.name = "action"; aIn.value = "approve"; form.appendChild(aIn);
                        const dIn = document.createElement("input"); dIn.type = "hidden"; dIn.name = "duration_months"; dIn.value = result.value; form.appendChild(dIn);
                        form.submit();
                    }
                });
            });
        });

        document.querySelectorAll(".trigger-reject-btn").forEach(btn => {
            btn.addEventListener("click", function() {
                const form = this.closest("form");
                Swal.fire({
                    title: 'Reject Loan Application',
                    text: 'Please provide a clear explanation for rejecting this loan application:',
                    input: 'textarea',
                    inputPlaceholder: 'Enter rejection reason here...',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Submit Rejection',
                    preConfirm: (value) => {
                        if (!value || value.trim() === '') {
                            Swal.showValidationMessage('A rejection reason is required.');
                        }
                        return value;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const aIn = document.createElement("input"); aIn.type = "hidden"; aIn.name = "action"; aIn.value = "reject"; form.appendChild(aIn);
                        const rIn = document.createElement("input"); rIn.type = "hidden"; rIn.name = "rejection_reason"; rIn.value = result.value; form.appendChild(rIn);
                        form.submit();
                    }
                });
            });
        });

        // Server Message Feedback Alerts
        <?php if (!empty($successMessage)): ?>
            Swal.fire({ icon: 'success', title: 'Success', text: <?= json_encode($successMessage) ?>, confirmButtonColor: '#1e40af' });
        <?php elseif (!empty($errorMessage)): ?>
            Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($errorMessage) ?>, confirmButtonColor: '#ef4444' });
        <?php endif; ?>
    </script>
</body>
</html>