<?php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$agent_id = $_SESSION['user_id'];

$pdo = getPDO();

$stmt = $pdo->prepare("
    SELECT l.*, u.name AS farmer_name, u.email
    FROM loan_applications l 
    LEFT JOIN users u ON l.farmer_id = u.id 
    WHERE l.agent_id = ? AND l.status = 'pending'
    ORDER BY l.created_at DESC
");
$stmt->execute([$agent_id]);
$pending_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt2 = $pdo->prepare("
    SELECT sp.*, u.name AS farmer_name, ls.stage_number, l.id as app_id
    FROM stage_proofs sp
    JOIN loan_stages ls ON sp.stage_id = ls.id
    JOIN loan_applications l ON ls.application_id = l.id
    JOIN users u ON sp.farmer_id = u.id
    WHERE l.agent_id = ?
    ORDER BY sp.uploaded_at DESC
    LIMIT 5
");
$stmt2->execute([$agent_id]);
$proofs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Calculate Totals
$total_pending = count($pending_loans);
$total_proofs = count($proofs); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Dashboard | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

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
        padding: 12px 15px; color: #dbeafe; /* Light Blue Text */
        text-decoration: none; border-radius: 10px;
        transition: all 0.2s ease; white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
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

    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    .stats-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 24px; margin-bottom: 30px;
    }

    .stat-card {
        background: var(--bg-card); padding: 24px; border-radius: 16px;
        box-shadow: var(--shadow); display: flex; align-items: center;
        gap: 20px; transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid #f0f0f0;
    }

    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }

    .icon-box {
        width: 56px; height: 56px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }

    .stat-info h3 { margin: 0; font-size: 14px; color: var(--text-muted); font-weight: 500; }
    .stat-info p { margin: 5px 0 0; font-size: 28px; font-weight: 700; color: var(--text-main); }

    .theme-blue { background: #eff6ff; color: var(--primary); }
    .theme-orange { background: #fff7ed; color: var(--warning); }
    .theme-indigo { background: #eef2ff; color: #4338ca; }

    .table-container {
        background: var(--bg-card); border-radius: 16px;
        padding: 24px; box-shadow: var(--shadow);
        overflow-x: auto; margin-bottom: 30px;
    }
    
    .table-header { 
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 20px; 
    }
    .table-title { font-size: 18px; font-weight: 600; color: var(--text-main); }

    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    
    th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f3f4f6; }
    
    th {
        font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--text-muted); font-weight: 600; background: #f9fafb;
    }
    
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f9fafb; }

    .btn-sm {
        padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 500;
        text-decoration: none; display: inline-block; transition: all 0.2s;
        background: var(--primary); color: white;
    }
    .btn-sm:hover { background: var(--primary-dark); }
    
    .badge {
        padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;
    }
    .badge-pending { background: #fff7ed; color: #b45309; }
    
    .file-type {
        font-family: monospace; background: #f3f4f6; 
        padding: 2px 6px; border-radius: 4px; font-size: 12px;
    }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
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
            <a href="agent_approve_stage.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_approve_stage.php' ? 'active' : '' ?>">
                <i data-feather="dollar-sign"></i>
                <span>Disbursement</span>
            </a>
             <a href="agent_repayments.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_repayments.php' ? 'active' : '' ?>">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="agent_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_profile.php' ? 'active' : '' ?>">
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
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Agent</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- DASHBOARD CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Agent Overview</h1>
                <p class="page-subtitle">Manage loan applications and verify farmer proofs.</p>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <!-- Pending Loans -->
                <div class="stat-card">
                    <div class="icon-box theme-orange">
                        <i data-feather="clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Loans</h3>
                        <p><?= number_format($total_pending); ?></p>
                    </div>
                </div>

                <!-- Proofs to Review -->
                <div class="stat-card">
                    <div class="icon-box theme-blue">
                        <i data-feather="image"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Proofs to Review</h3>
                        <p><?= number_format($total_proofs); ?></p>
                    </div>
                </div>
                
                 <div class="stat-card">
                    <div class="icon-box theme-indigo">
                        <i data-feather="activity"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Activity Level</h3>
                        <p>High</p>
                    </div>
                </div>
            </div>

            <!-- 1. Pending Loans Table -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-title">Recent Loan Applications</div>
                    <a href="farmer_vetting.php" style="font-size:13px; color:var(--primary); text-decoration:none;">View All &rarr;</a>
                </div>
                
                <?php if (empty($pending_loans)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:10px;">No pending applications found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Farmer</th>
                                <th>Loan Purpose</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending_loans as $loan): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($loan['farmer_name']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($loan['email']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($loan['title']) ?></td>
                                <td style="font-weight:600;">GHS <?= number_format((float)$loan['amount'], 2) ?></td>
                                <td><?= date('M d, Y', strtotime($loan['created_at'])) ?></td>
                                <td><span class="badge badge-pending">Pending Review</span></td>
                                <td>
                                    <a href="view_application.php?id=<?= $loan['id'] ?>" class="btn-sm">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- 2. Recent Proofs Table -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-title">Recent Stage Proofs</div>
                    <a href="proof_verification.php" style="font-size:13px; color:var(--primary); text-decoration:none;">Verify All &rarr;</a>
                </div>

                <?php if (empty($proofs)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:10px;">No recent proofs uploaded.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Farmer</th>
                                <th>Stage Detail</th>
                                <th>File Type</th>
                                <th>Uploaded At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($proofs as $p): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($p['farmer_name']) ?></td>
                                <td>Stage <?= htmlspecialchars($p['stage_number']) ?></td>
                                <td>
                                    <span class="file-type">
                                        <?= htmlspecialchars(strtoupper(pathinfo($p['file_type'], PATHINFO_EXTENSION) ?: $p['file_type'])) ?>
                                    </span>
                                </td>
                                <td><?= date('M d, H:i', strtotime($p['uploaded_at'])) ?></td>
                                <td>
                                    <?php $filePath = "../uploads/app_{$p['app_id']}/stage_{$p['stage_id']}/" . basename($p['filename']); ?>
                                    <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" class="btn-sm">View File</a>
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