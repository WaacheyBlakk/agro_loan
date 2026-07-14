<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/loan.php';
require_once __DIR__ . '/../src/upload.php'; 

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];
$pdo = getPDO();

$id = isset($_GET['id']) ? intval($_GET['id']) : null;

$app = null;
$active_apps = [];
$history_apps = [];
$msg = "";
$err = "";

if ($id) {
    $app = get_application($id);
    if ($app && $app['farmer_id'] !== $farmer_id) { 
        $app = null; 
        $err = "Unauthorized access.";
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM loan_applications WHERE farmer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$farmer_id]); 
    $all_apps = $stmt->fetchAll();

    foreach ($all_apps as $item) {
        if (in_array($item['status'], ['completed', 'rejected', 'cancelled'])) {
            $history_apps[] = $item;
        } else {
            $active_apps[] = $item;
        }
    }
}

// Handle stage proof upload (Before Work, After Work, or Payment Proof)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    csrf_verify();
    try {
        if (!$app) throw new Exception("Application not found.");
        
        if ($app['status'] !== 'approved') {
            throw new Exception("You can only upload proofs for approved loan applications.");
        }

        $stage_id = intval($_POST['stage_id']);
        $proof_type = $_POST['proof_type']; 

        if (!in_array($proof_type, ['before', 'after', 'payment'])) {
            throw new Exception("Invalid upload verification target.");
        }

        $stageStmt = $pdo->prepare("SELECT stage_number, status, disbursed FROM loan_stages WHERE id = ? AND application_id = ?");
        $stageStmt->execute([$stage_id, $id]);
        $stageData = $stageStmt->fetch();
        
        if (!$stageData || $stageData['stage_number'] != $app['current_stage']) {
            throw new Exception("Cannot upload proof for a stage that is not active.");
        }

        if ($proof_type === 'before' && $stageData['disbursed'] == 1) {
            throw new Exception("Funds already disbursed. Please submit 'after work' photos or payment proofs.");
        }
        if (($proof_type === 'after' || $proof_type === 'payment') && $stageData['disbursed'] == 0) {
            throw new Exception("Funds must be disbursed for this stage before submitting photos or payment proofs.");
        }

        $file = $_FILES['proof'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed with error code: " . $file['error']);
        }

        $target_dir = __DIR__ . "/../uploads/app_{$app['id']}/stage_{$stage_id}/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'mp4', 'mov'];
        if (!in_array($ext, $allowed_exts)) {
            throw new Exception("Only JPG, JPEG, PNG, GIF, PDF, MP4 and MOV files are accepted.");
        }

        $filename = $proof_type . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $target_file = $target_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            $fileType = 'other';
            if (strpos($file['type'], 'image') !== false) {
                $fileType = 'image';
            } elseif (strpos($file['type'], 'video') !== false) {
                $fileType = 'video';
            }

            // Explicitly insert into stage_proofs strictly mapping database schemas
            $insert_sql = "INSERT INTO stage_proofs (stage_id, farmer_id, filename, file_type, status, proof_type, uploaded_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW())";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([$stage_id, $farmer_id, $filename, $fileType, $proof_type]);

            // Map stage statuses within strict enum boundaries
            if ($proof_type === 'before') {
                $new_stage_status = 'awaiting_disbursement';
            } else {
                $new_stage_status = 'disbursed'; 
            }

            $stmtUpdate = $pdo->prepare("UPDATE loan_stages SET status = ? WHERE id = ?");
            $stmtUpdate->execute([$new_stage_status, $stage_id]);

            $msg = ucfirst($proof_type) . " proof uploaded successfully. Awaiting verification from agent.";
        } else {
            throw new Exception("Could not save the uploaded file.");
        }
        
        $app = get_application($id); 

    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Applications | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/feather-icons"></script>
<style>
    :root {
        --primary: #059669; 
        --primary-dark: #064e3b; 
        --secondary: #10b981; 
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
        width: 35px; height: 35px; background: var(--primary);
        color: white; border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }
    .content { padding: 30px; max-width: 1200px; margin: 0 auto; width: 100%; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }
    .card {
        background: var(--bg-card); padding: 30px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #f0f0f0;
        margin-bottom: 24px;
    }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 14px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
    th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; background: #f9fafb; font-weight: 600; letter-spacing: 0.5px; }
    tr:last-child td { border-bottom: none; }
    .table-container { overflow-x: auto; }
    .badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block;}
    .badge.pending { background: #fef3c7; color: #92400e; }
    .badge.approved { background: #d1fae5; color: #065f46; }
    .badge.completed { background: #ecfdf5; color: #047857; }
    .badge.rejected { background: #fee2e2; color: #991b1b; }
    .badge.awaiting_before_approval { background: #e0f2fe; color: #075985; }
    .badge.awaiting_after_approval { background: #e0f2fe; color: #075985; }
    .badge.awaiting_payment_approval { background: #e0f2fe; color: #075985; }
    .badge.disbursed { background: #d1fae5; color: #065f46; }
    .stage-card {
        border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px;
        margin-top: 20px; background: #fcfcfc;
    }
    .stage-card.current {
        background: #f0fdf4; border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.1);
    }
    .verification-sub-section {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 15px;
        margin-top: 15px;
    }
    .proof-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 15px; margin: 15px 0; }
    .proof-item { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; text-align: center; background: #fff; }
    .proof-thumb { width: 100%; height: 100px; object-fit: cover; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 12px; }
    .upload-box {
        margin-top: 15px; padding: 15px; border: 1px dashed #d1d5db;
        border-radius: 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
        background: #fafafa;
    }
    .btn-upload {
        background: var(--primary); color: white; border: none;
        padding: 10px 18px; border-radius: 8px; cursor: pointer;
        font-weight: 500; font-family: inherit; transition: 0.2s;
    }
    .btn-upload:hover { background: var(--primary-dark); }
    .link { color: var(--primary); text-decoration: none; font-weight: 500; transition: 0.2s; }
    .link:hover { color: var(--primary-dark); text-decoration: underline; }
    .section-label { 
        font-size: 14px; color: var(--text-muted); font-weight: 700; 
        text-transform: uppercase; margin: 0 0 15px 0; display: block; 
        letter-spacing: 1px;
    }
    .alert {
        padding: 15px; border-radius: 10px; margin-bottom: 25px;
        font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;
    }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-success { background: #ecfdf5; color: #064e3b; border: 1px solid #a7f3d0; }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .layout-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Farmer</h2>
        </div>

        <nav class="nav">
            <a href="farmer_dashboard.php" class="nav-link">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            <a href="add_product.php" class="nav-link">
                <i data-feather="shopping-bag"></i>
                <span>Add Produce</span>
            </a>
            <a href="apply_loan.php" class="nav-link">
                <i data-feather="dollar-sign"></i>
                <span>Apply for Loan</span>
            </a>
            <a href="view_application.php" class="nav-link active">
                <i data-feather="file-text"></i>
                <span>Applications</span>
            </a>
            <a href="farmer_repayment.php" class="nav-link">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="dispute_center.php" class="nav-link">
                <i data-feather="alert-triangle"></i>
                <span>Dispute Center</span>
            </a>
            <a href="farmer_profile.php" class="nav-link">
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

    <main class="main">
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

        <div class="content">
            <div class="page-header">
                <h1 class="page-title">My Applications</h1>
                <p class="page-subtitle">Track disbursements, submit phase files, and check stage completion status.</p>
            </div>

            <?php if ($msg): ?>
                <div class="alert alert-success">
                    <i data-feather="check-circle"></i> <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($err): ?>
                <div class="alert alert-danger">
                    <i data-feather="alert-circle"></i> <?= htmlspecialchars($err) ?>
                </div>
            <?php endif; ?>

            <!-- === DETAIL VIEW (Single Loan) === -->
            <?php if ($id): ?>
                <?php if (!$app): ?>
                    <div class="card" style="text-align:center; padding:40px;">
                        <i data-feather="search" style="width:40px;height:40px;color:var(--text-muted);"></i>
                        <p style="margin-top:10px;">Application not found or unauthorized.</p>
                        <a href="view_application.php" class="link">Go Back</a>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px; gap:10px;">
                            <h2 style="margin:0; font-size:20px; font-weight:600;"><?= htmlspecialchars($app['title']) ?></h2>
                            <?php if($app['status'] == 'completed'): ?>
                                <span class="badge completed" style="font-size:14px; padding:6px 14px;">
                                    <i data-feather="check-circle" style="width:14px; vertical-align:middle;"></i> Loan Completed
                                </span>
                            <?php else: ?>
                                <a href="view_application.php" class="link" style="font-size:14px;">&larr; Back to List</a>
                            <?php endif; ?>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:20px; background:#f9fafb; padding:20px; border-radius:12px; border:1px solid #e5e7eb;">
                            <div>
                                <small style="color:var(--text-muted); font-weight:600; font-size:11px; text-transform:uppercase;">Status</small><br>
                                <span class="badge <?= $app['status'] ?>" style="margin-top:5px;"><?= ucfirst($app['status']) ?></span>
                            </div>
                            <div>
                                <small style="color:var(--text-muted); font-weight:600; font-size:11px; text-transform:uppercase;">Total Amount</small><br>
                                <strong style="font-size:16px;">GHS <?= number_format($app['amount'], 2) ?></strong>
                            </div>
                            <div>
                                <small style="color:var(--text-muted); font-weight:600; font-size:11px; text-transform:uppercase;">Disbursed</small><br>
                                <span style="color:var(--primary); font-weight:bold; font-size:16px;">GHS <?= number_format($app['disbursed_amount'], 2) ?></span>
                            </div>
                            <div>
                                <small style="color:var(--text-muted); font-weight:600; font-size:11px; text-transform:uppercase;">Current Active Stage</small><br>
                                <strong style="font-size:16px;">Stage <?= htmlspecialchars($app['current_stage']) ?></strong>
                            </div>
                        </div>

                        <hr style="border:0; border-top:1px solid #f3f4f6; margin:30px 0;">

                        <?php if ($app['status'] === 'pending'): ?>
                            <div class="alert" style="background-color: #fffbeb; border: 1px solid #fde68a; color: #b45309; margin-bottom: 25px;">
                                <i data-feather="lock"></i> This application is pending agent approval. Proof upload tools will become available once approved.
                            </div>
                        <?php endif; ?>
                        
                        <h3 style="font-size:18px; font-weight:600; margin-bottom:15px;">Stage Verification Timeline</h3>
                        
                        <?php foreach($app['stages'] as $stage): ?>
                            <?php 
                                $isCurrent = ($stage['stage_number'] == $app['current_stage'] && $app['status'] === 'approved');
                                $isCompletedLoan = ($app['status'] === 'completed');
                                $class = $isCurrent ? 'stage-card current' : 'stage-card';
                            ?>
                            <div class="<?= $class ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                    <div>
                                        <strong style="font-size:16px;">Stage <?= $stage['stage_number'] ?></strong>
                                        <div style="font-size:13px; color:var(--text-muted); margin-top:2px;">
                                            Disbursement Limit: <strong>GHS <?= number_format($stage['required_amount'], 2) ?></strong>
                                        </div>
                                    </div>
                                    <div>
                                        <?php if ($stage['status'] === 'verified'): ?>
                                            <span class="badge completed">Completed</span>
                                        <?php elseif ($stage['status'] === 'awaiting_disbursement'): ?>
                                            <span class="badge awaiting_before_approval">Awaiting Before-Work Approval</span>
                                        <?php elseif ($stage['status'] === 'disbursement_rejected'): ?>
                                            <span class="badge rejected">Disbursement Rejected</span>
                                        <?php elseif ($stage['status'] === 'rejected'): ?>
                                            <span class="badge rejected">Stage Proof Rejected</span>
                                        <?php elseif ($stage['disbursed'] == 1): ?>
                                            <span class="badge disbursed">Funds Disbursed / Work Ongoing</span>
                                        <?php else: ?>
                                            <span class="badge pending">Pending Fund Release</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div style="font-size:13px; margin-top:10px; color:var(--text-muted);">
                                    Disbursed: <?= $stage['disbursed'] ? '<span style="color:var(--primary); font-weight:bold;">Yes</span>' : 'No' ?>
                                </div>

                                <?php
                                    $pstmt = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ?");
                                    $pstmt->execute([$stage['id']]);
                                    $all_proofs = $pstmt->fetchAll();

                                    $before_proofs = array_filter($all_proofs, function($p) { return $p['proof_type'] === 'before'; });
                                    $after_proofs = array_filter($all_proofs, function($p) { return $p['proof_type'] === 'after'; });
                                    $payment_proofs = array_filter($all_proofs, function($p) { return $p['proof_type'] === 'payment'; });
                                ?>

                                <!-- PHASE 1: Farm State Prior to Work -->
                                <div class="verification-sub-section">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <h4 style="margin:0; font-size:13px; font-weight:600; color:var(--text-main);">
                                            PHASE 1: Farm Status (Before Work)
                                        </h4>
                                        <span style="font-size:11px; font-weight:600; text-transform:uppercase; color: var(--text-muted);">
                                            Required for Disbursement
                                        </span>
                                    </div>

                                    <?php if ($before_proofs): ?>
                                        <div class="proof-grid">
                                            <?php foreach($before_proofs as $pf): ?>
                                                <?php $pf_filename = !empty($pf['filename']) ? $pf['filename'] : ''; ?>
                                                <div class="proof-item">
                                                    <?php if(strpos($pf['file_type'] ?? '', 'image') !== false): ?>
                                                        <img src="../uploads/app_<?= $app['id'] ?>/stage_<?= $stage['id'] ?>/<?= htmlspecialchars($pf_filename) ?>" class="proof-thumb" alt="Before verification">
                                                    <?php else: ?>
                                                        <div class="proof-thumb"><i data-feather="file" style="margin-right:5px;"></i> DOCUMENT</div>
                                                    <?php endif; ?>
                                                    <div style="padding:6px; font-size:11px; background:#f9fafb; border-top:1px solid #e5e7eb; font-weight:600; text-align:center;">
                                                        Status: 
                                                        <?php 
                                                            $p_status = strtolower(trim($pf['status'] ?? 'pending'));
                                                            if ($p_status === 'verified') {
                                                                echo '<span class="badge approved" style="padding:2px 8px; font-size:10px;">Verified</span>';
                                                            } elseif ($p_status === 'rejected') {
                                                                echo '<span class="badge rejected" style="padding:2px 8px; font-size:10px;">Rejected</span>';
                                                            } else {
                                                                echo '<span class="badge pending" style="padding:2px 8px; font-size:10px;">Pending</span>';
                                                            }
                                                        ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="font-size:12px; color:var(--text-muted); margin:10px 0 0 0;">No 'before-work' photos uploaded yet.</p>
                                    <?php endif; ?>

                                    <?php if ($isCurrent && !$stage['disbursed']): ?>
                                        <?php 
                                            $has_pending_before = false;
                                            $has_approved_before = false;
                                            foreach($before_proofs as $bp) {
                                                $bp_status = strtolower(trim($bp['status'] ?? ''));
                                                if ($bp_status === 'pending') { 
                                                    $has_pending_before = true; 
                                                } elseif ($bp_status === 'verified') {
                                                    $has_approved_before = true;
                                                }
                                            }
                                        ?>
                                        <?php if ($has_approved_before): ?>
                                            <div style="margin-top:12px; font-size:13px; color: var(--primary); font-weight:600;">
                                                <i data-feather="check-circle" style="width:14px; height:14px; vertical-align:middle;"></i> Farm status prior to work verified and approved.
                                            </div>
                                        <?php elseif ($has_pending_before): ?>
                                            <div style="margin-top:12px; font-size:13px; color: var(--warning); font-weight:500;">
                                                <i data-feather="clock" style="width:14px; height:14px; vertical-align:middle;"></i> Photos sent to Agent. Verification in progress before releasing funds.
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" enctype="multipart/form-data" class="upload-box">
                                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                                <input type="hidden" name="proof_type" value="before">
                                                <div style="flex:1;">
                                                    <label style="display:block; font-size:12px; margin-bottom:5px; font-weight:600; color:var(--text-main);">
                                                        Upload current farm/plot photos to trigger stage disbursement:
                                                    </label>
                                                    <input type="file" name="proof" required style="font-size:13px;">
                                                </div>
                                                <button type="submit" class="btn-upload">
                                                    <i data-feather="upload-cloud" style="width:16px; height:16px; vertical-align:middle;"></i> Upload Photos
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- PHASE 2: Completed Work Verification -->
                                <div class="verification-sub-section" style="margin-top: 15px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <h4 style="margin:0; font-size:13px; font-weight:600; color:var(--text-main);">
                                            PHASE 2: Completed Work Status (After Work)
                                        </h4>
                                        <span style="font-size:11px; font-weight:600; text-transform:uppercase; color: var(--text-muted);">
                                            Required for Stage Completion
                                        </span>
                                    </div>

                                    <?php if ($after_proofs): ?>
                                        <div class="proof-grid">
                                            <?php foreach($after_proofs as $pf): ?>
                                                <?php $pf_filename = !empty($pf['filename']) ? $pf['filename'] : ''; ?>
                                                <div class="proof-item">
                                                    <?php if(strpos($pf['file_type'] ?? '', 'image') !== false): ?>
                                                        <img src="../uploads/app_<?= $app['id'] ?>/stage_<?= $stage['id'] ?>/<?= htmlspecialchars($pf_filename) ?>" class="proof-thumb" alt="After verification">
                                                    <?php else: ?>
                                                        <div class="proof-thumb"><i data-feather="file" style="margin-right:5px;"></i> DOCUMENT</div>
                                                    <?php endif; ?>
                                                    <div style="padding:6px; font-size:11px; background:#f9fafb; border-top:1px solid #e5e7eb; font-weight:600; text-align:center;">
                                                        Status: 
                                                        <?php 
                                                            $p_status = strtolower(trim($pf['status'] ?? 'pending'));
                                                            if ($p_status === 'verified') {
                                                                echo '<span class="badge approved" style="padding:2px 8px; font-size:10px;">Verified</span>';
                                                            } elseif ($p_status === 'rejected') {
                                                                echo '<span class="badge rejected" style="padding:2px 8px; font-size:10px;">Rejected</span>';
                                                            } else {
                                                                echo '<span class="badge pending" style="padding:2px 8px; font-size:10px;">Pending</span>';
                                                            }
                                                        ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="font-size:12px; color:var(--text-muted); margin:10px 0 0 0;">No 'after-work' completion photos uploaded yet.</p>
                                    <?php endif; ?>

                                    <?php if ($isCurrent && $stage['disbursed'] && $stage['status'] !== 'verified'): ?>
                                        <?php 
                                            $has_pending_after = false;
                                            $has_approved_after = false;
                                            foreach($after_proofs as $ap_proof) {
                                                $ap_status = strtolower(trim($ap_proof['status'] ?? ''));
                                                if ($ap_status === 'pending') { 
                                                    $has_pending_after = true; 
                                                } elseif ($ap_status === 'verified') {
                                                    $has_approved_after = true;
                                                }
                                            }
                                        ?>
                                        <?php if ($has_approved_after): ?>
                                            <div style="margin-top:12px; font-size:13px; color: var(--primary); font-weight:600;">
                                                <i data-feather="check-circle" style="width:14px; height:14px; vertical-align:middle;"></i> Work completion verified and approved.
                                            </div>
                                        <?php elseif ($has_pending_after): ?>
                                            <div style="margin-top:12px; font-size:13px; color: var(--warning); font-weight:500;">
                                                <i data-feather="clock" style="width:14px; height:14px; vertical-align:middle;"></i> Photos sent to Agent. Verification in progress to complete current stage.
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" enctype="multipart/form-data" class="upload-box">
                                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                                <input type="hidden" name="proof_type" value="after">
                                                <div style="flex:1;">
                                                    <label style="display:block; font-size:12px; margin-bottom:5px; font-weight:600; color:var(--text-main);">
                                                        Upload work completion photos to verify stage activity:
                                                    </label>
                                                    <input type="file" name="proof" required style="font-size:13px;">
                                                </div>
                                                <button type="submit" class="btn-upload">
                                                    <i data-feather="upload-cloud" style="width:16px; height:16px; vertical-align:middle;"></i> Upload Progress
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif (!$stage['disbursed'] && !$isCompletedLoan): ?>
                                        <div style="margin-top:12px; font-size:12px; color: var(--text-muted); font-style:italic;">
                                            Disbursement must occur before uploading completion proof.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- PHASE 3: Payment & Expenditure Proof -->
                                <div class="verification-sub-section" style="margin-top: 15px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <h4 style="margin:0; font-size:13px; font-weight:600; color:var(--text-main);">
                                            PHASE 3: Payment & Receipt Verification (Payment Proof)
                                        </h4>
                                        <span style="font-size:11px; font-weight:600; text-transform:uppercase; color: var(--text-muted);">
                                            Required for Stage Completion
                                        </span>
                                    </div>

                                    <?php if ($payment_proofs): ?>
                                        <div class="proof-grid">
                                            <?php foreach($payment_proofs as $pf): ?>
                                                <?php $pf_filename = !empty($pf['filename']) ? $pf['filename'] : ''; ?>
                                                <div class="proof-item">
                                                    <?php if(strpos($pf['file_type'] ?? '', 'image') !== false): ?>
                                                        <img src="../uploads/app_<?= $app['id'] ?>/stage_<?= $stage['id'] ?>/<?= htmlspecialchars($pf_filename) ?>" class="proof-thumb" alt="Payment verification">
                                                    <?php else: ?>
                                                        <div class="proof-thumb"><i data-feather="file" style="margin-right:5px;"></i> DOCUMENT</div>
                                                    <?php endif; ?>
                                                    <div style="padding:6px; font-size:11px; background:#f9fafb; border-top:1px solid #e5e7eb; font-weight:600; text-align:center;">
                                                        Status: 
                                                        <?php 
                                                            $p_status = strtolower(trim($pf['status'] ?? 'pending'));
                                                            if ($p_status === 'verified') {
                                                                echo '<span class="badge approved" style="padding:2px 8px; font-size:10px;">Verified</span>';
                                                            } elseif ($p_status === 'rejected') {
                                                                echo '<span class="badge rejected" style="padding:2px 8px; font-size:10px;">Rejected</span>';
                                                            } else {
                                                                echo '<span class="badge pending" style="padding:2px 8px; font-size:10px;">Pending</span>';
                                                            }
                                                        ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="font-size:12px; color:var(--text-muted); margin:10px 0 0 0;">No receipt or payment proofs uploaded yet.</p>
                                    <?php endif; ?>

                                    <?php if ($isCurrent && $stage['disbursed'] && $stage['status'] !== 'verified'): ?>
                                        <?php 
                                            $has_pending_payment = false;
                                            $has_approved_payment = false;
                                            foreach($payment_proofs as $pp_proof) {
                                                $pp_status = strtolower(trim($pp_proof['status'] ?? ''));
                                                if ($pp_status === 'pending') { 
                                                    $has_pending_payment = true; 
                                                } elseif ($pp_status === 'verified') {
                                                    $has_approved_payment = true;
                                                }
                                            }
                                        ?>
                                        <?php if ($has_approved_payment): ?>
                                            <div style="margin-top:12px; font-size:13px; color: var(--primary); font-weight:600;">
                                                <i data-feather="check-circle" style="width:14px; height:14px; vertical-align:middle;"></i> Payment receipts verified and approved.
                                            </div>
                                        <?php elseif ($has_pending_payment): ?>
                                            <div style="margin-top:12px; font-size:13px; color: var(--warning); font-weight:500;">
                                                <i data-feather="clock" style="width:14px; height:14px; vertical-align:middle;"></i> Receipts sent to Agent. Verification in progress to validate expenditures.
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" enctype="multipart/form-data" class="upload-box">
                                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                                <input type="hidden" name="proof_type" value="payment">
                                                <div style="flex:1;">
                                                    <label style="display:block; font-size:12px; margin-bottom:5px; font-weight:600; color:var(--text-main);">
                                                        Upload invoice/receipt/payment proof to verify stage disbursements:
                                                    </label>
                                                    <input type="file" name="proof" required style="font-size:13px;">
                                                </div>
                                                <button type="submit" class="btn-upload">
                                                    <i data-feather="upload-cloud" style="width:16px; height:16px; vertical-align:middle;"></i> Upload Receipt
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif (!$stage['disbursed'] && !$isCompletedLoan): ?>
                                        <div style="margin-top:12px; font-size:12px; color: var(--text-muted); font-style:italic;">
                                            Disbursement must occur before uploading payment proof.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <span class="section-label">Current Applications</span>
                <div class="card">
                    <?php if (empty($active_apps)): ?>
                        <div style="text-align:center; padding:20px 0;">
                            <i data-feather="inbox" style="width:40px;height:40px;color:#e5e7eb;margin-bottom:10px;"></i>
                            <p style="color:var(--text-muted); margin:0;">No active loan applications.</p>
                            <a href="apply_loan.php" class="link" style="margin-top:10px; display:inline-block;">Start a new Application</a>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Loan Title</th>
                                        <th>Active Stage</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_apps as $ap): ?>
                                    <tr>
                                        <td>
                                            <span style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($ap['title']) ?></span>
                                            <div style="font-size:11px; color:var(--text-muted);"><?= date('M d, Y', strtotime($ap['created_at'])) ?></div>
                                        </td>
                                        <td>Stage <?= htmlspecialchars($ap['current_stage']) ?></td>
                                        <td>GHS <?= number_format($ap['amount'], 2) ?></td>
                                        <td><span class="badge <?= $ap['status'] ?>"><?= ucfirst($ap['status']) ?></span></td>
                                        <td>
                                            <a href="view_application.php?id=<?= $ap['id'] ?>" class="link" style="font-size:13px;">Manage Uploads</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <span class="section-label" style="margin-top:40px;">Loan History</span>
                <div class="card">
                    <?php if (empty($history_apps)): ?>
                        <div style="text-align:center; padding:20px; color:var(--text-muted);">
                            <i data-feather="archive" style="width:30px; height:30px; margin-bottom:10px; opacity:0.3;"></i>
                            <p>No previous loan history found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Loan Title</th>
                                        <th>Last Updated</th>
                                        <th>Total Amount</th>
                                        <th>Final Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history_apps as $ap): ?>
                                    <tr style="opacity: 0.8;">
                                        <td><strong><?= htmlspecialchars($ap['title']) ?></strong></td>
                                        <td><?= date('M d, Y', strtotime($ap['updated_at'] ?? $ap['created_at'])) ?></td>
                                        <td>GHS <?= number_format($ap['amount'], 2) ?></td>
                                        <td>
                                            <span class="badge <?= $ap['status'] ?>"><?= ucfirst($ap['status']) ?></span>
                                        </td>
                                        <td>
                                            <a href="view_application.php?id=<?= $ap['id'] ?>" class="link" style="color:var(--text-muted); font-size:13px;">
                                                View Details
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        feather.replace();
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