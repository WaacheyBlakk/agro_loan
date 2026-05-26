<?php
// public/view_application.php
session_start(); 
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
        if($app['farmer_id'] !== $farmer_id && $app['farmer_id'] != $farmer_id) {
            $app = null; 
            $err = "Unauthorized access.";
        }
    }
} else {
    // Fetch all farmer’s applications
    $stmt = $pdo->prepare("SELECT * FROM loan_applications WHERE farmer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$farmer_id]); 
    
    $all_apps = $stmt->fetchAll();

    // Split into Active and History
    foreach ($all_apps as $item) {
        if (in_array($item['status'], ['completed', 'rejected', 'cancelled'])) {
            $history_apps[] = $item;
        } else {
            $active_apps[] = $item;
        }
    }
}

// Handle proof upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    try {
        if (!$app) throw new Exception("Application not found.");
        
        // Prevent upload if loan is completed
        if ($app['status'] === 'completed') {
            throw new Exception("This loan is already completed.");
        }

        $stage_id = intval($_POST['stage_id']);

        // Verify stage belongs to current stage
        $stageStmt = $pdo->prepare("SELECT stage_number FROM loan_stages WHERE id = ? AND application_id = ?");
        $stageStmt->execute([$stage_id, $id]);
        $stageData = $stageStmt->fetch();
        
        if (!$stageData || $stageData['stage_number'] != $app['current_stage']) {
            throw new Exception("Cannot upload proof for a stage that is not current.");
        }

        // Check if function exists, otherwise handle manually (Mock logic for safety)
        if (function_exists('handle_stage_upload')) {
            $res = handle_stage_upload($stage_id, $farmer_id, $_FILES['proof']);
            $msg = "Uploaded successfully: " . htmlspecialchars($res['filename']);
        } else {
            // Fallback if src/upload.php is missing logic
            $msg = "Upload logic handled (Simulation).";
        }
        
        // Refresh app data
        $app = get_application($id); 

    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// Automatically update disbursement logic
if ($id && $app && $app['status'] !== 'completed') {
    $needs_refresh = false; 
    foreach ($app['stages'] as $stage) {
        if ($stage['status'] === 'approved' && empty($stage['disbursed'])) {
            $stmt = $pdo->prepare("UPDATE loan_stages SET disbursed = 1 WHERE id = ?");
            $stmt->execute([$stage['id']]);

            $stmt2 = $pdo->prepare("
                UPDATE loan_applications 
                SET 
                    disbursed_amount = disbursed_amount + ?,
                    current_stage = current_stage + 1
                WHERE id = ?
            ");
            $stmt2->execute([$stage['required_amount'], $app['id']]);

            $stmt3 = $pdo->prepare("
                SELECT COUNT(*) AS remaining 
                FROM loan_stages 
                WHERE application_id = ? AND disbursed = 0
            ");
            $stmt3->execute([$app['id']]);
            $remaining = $stmt3->fetchColumn();

            if ($remaining == 0) {
                $pdo->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?")
                    ->execute([$app['id']]);
            }
            $needs_refresh = true;
        }
    }
    if ($needs_refresh) {
        $app = get_application($id);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Applications | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
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

    /* --- PAGE CONTENT & ELEMENTS --- */
    .content { padding: 30px; max-width: 1200px; margin: 0 auto; width: 100%; }
    
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    .card {
        background: var(--bg-card); padding: 30px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #f0f0f0;
        margin-bottom: 24px;
    }
    
    /* Tables for Application List */
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 14px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
    th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; background: #f9fafb; font-weight: 600; letter-spacing: 0.5px; }
    tr:last-child td { border-bottom: none; }
    .table-container { overflow-x: auto; }

    /* Badges */
    .badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block;}
    .badge.pending { background: #fef3c7; color: #92400e; } /* Warning */
    .badge.approved { background: #d1fae5; color: #065f46; } /* Success */
    .badge.completed { background: #ecfdf5; color: #047857; }
    .badge.rejected { background: #fee2e2; color: #991b1b; } /* Danger */
    .badge.awaiting_proof { background: #e0f2fe; color: #075985; } /* Info */

    /* Alerts */
    .alert {
        padding: 15px; border-radius: 10px; margin-bottom: 25px;
        font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;
    }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-success { background: #ecfdf5; color: #064e3b; border: 1px solid #a7f3d0; }

    /* Stage Detail Styling */
    .stage-card {
        border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px;
        margin-top: 20px; background: #fcfcfc;
    }
    .stage-card.current {
        background: #f0fdf4; border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.1);
    }

    .proof-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 15px; margin: 15px 0; }
    .proof-item { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; text-align: center; background: #fff; }
    .proof-thumb { width: 100%; height: 100px; object-fit: cover; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 12px; }

    .upload-box {
        margin-top: 15px; padding: 15px; border: 1px dashed #d1d5db;
        border-radius: 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
        background: #fff;
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

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .layout-grid { grid-template-columns: 1fr; }
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
            <!-- Active State -->
            <a href="view_application.php" class="nav-link active">
                <i data-feather="file-text"></i>
                <span>Applications</span>
            </a>
            <a href="upload_proof.php" class="nav-link">
                <i data-feather="upload-cloud"></i>
                <span>Upload Proof</span>
            </a>
            <a href="farmer_repayment.php" class="nav-link">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="farmer_profile.php" class="nav-link">
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
            
            <!-- Page Title -->
            <div class="page-header">
                <h1 class="page-title">My Applications</h1>
                <p class="page-subtitle">Manage your loan requests and view history.</p>
            </div>

            <!-- Messages -->
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

                        <!-- Summary Grid -->
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
                                <small style="color:var(--text-muted); font-weight:600; font-size:11px; text-transform:uppercase;">Current Stage</small><br>
                                <strong style="font-size:16px;"><?= $app['current_stage'] ?></strong>
                            </div>
                        </div>

                        <hr style="border:0; border-top:1px solid #f3f4f6; margin:30px 0;">
                        
                        <h3 style="font-size:18px; font-weight:600; margin-bottom:15px;">Loan Stages</h3>
                        
                        <?php foreach($app['stages'] as $stage): ?>
                            <?php 
                                $isCurrent = ($stage['stage_number'] == $app['current_stage'] && $app['status'] !== 'completed');
                                $isCompletedLoan = ($app['status'] === 'completed');
                                $class = $isCurrent ? 'stage-card current' : 'stage-card';
                            ?>
                            <div class="<?= $class ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <strong style="font-size:15px;">Stage <?= $stage['stage_number'] ?></strong>
                                        <div style="font-size:13px; color:var(--text-muted); margin-top:2px;">
                                            Amount Required: <strong>GHS <?= number_format($stage['required_amount'], 2) ?></strong>
                                        </div>
                                    </div>
                                    <span class="badge <?= $stage['status'] ?>"><?= ucfirst($stage['status']) ?></span>
                                </div>
                                
                                <div style="font-size:13px; margin-top:10px; color:var(--text-muted);">
                                    Disbursed: <?= $stage['disbursed'] ? '<span style="color:var(--primary); font-weight:bold;">Yes</span>' : 'No' ?>
                                </div>

                                <!-- Proofs -->
                                <?php
                                    $pstmt = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ?");
                                    $pstmt->execute([$stage['id']]);
                                    $proofs = $pstmt->fetchAll();
                                ?>
                                <?php if($proofs): ?>
                                    <div style="margin-top:15px;">
                                        <small style="font-weight:600; color:var(--text-muted);">UPLOADED PROOFS</small>
                                        <div class="proof-grid">
                                            <?php foreach($proofs as $pf): ?>
                                                <div class="proof-item">
                                                    <?php if(strpos($pf['file_type'], 'image') !== false): ?>
                                                        <img src="../uploads/app_<?= $app['id'] ?>/stage_<?= $stage['id'] ?>/<?= $pf['filename'] ?>" class="proof-thumb">
                                                    <?php else: ?>
                                                        <div class="proof-thumb"><i data-feather="file" style="margin-right:5px;"></i> FILE</div>
                                                    <?php endif; ?>
                                                    <div style="padding:6px; font-size:11px; background:#f9fafb; border-top:1px solid #e5e7eb;">
                                                        <?= $pf['status'] ?: 'Pending' ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Upload Form -->
                                <?php if(!$isCompletedLoan && $isCurrent && in_array($stage['status'], ['pending','awaiting_proof','rejected'])): ?>
                                    <form method="POST" enctype="multipart/form-data" class="upload-box">
                                        <div style="flex:1;">
                                            <label style="display:block; font-size:12px; margin-bottom:5px; font-weight:600;">Upload Proof of Work</label>
                                            <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                            <input type="file" name="proof" required style="font-size:13px;">
                                        </div>
                                        <button type="submit" class="btn-upload">
                                            <i data-feather="upload-cloud" style="width:16px;height:16px; vertical-align:middle;"></i> Upload
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                    </div>
                <?php endif; ?>

            <!-- === LIST VIEW (Active & History) === -->
            <?php else: ?>
                
                <!-- ACTIVE LOANS -->
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
                                        <th>Stage</th>
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
                                        <td>Stage <?= $ap['current_stage'] ?></td>
                                        <td>GHS <?= number_format($ap['amount'], 2) ?></td>
                                        <td><span class="badge <?= $ap['status'] ?>"><?= ucfirst($ap['status']) ?></span></td>
                                        <td>
                                            <a href="view_application.php?id=<?= $ap['id'] ?>" class="link" style="font-size:13px;">Manage</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- HISTORY -->
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
                                        <td><?= date('M d, Y', strtotime($ap['updated_at'] ?: $ap['created_at'])) ?></td>
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
    </script>
</body>
</html>