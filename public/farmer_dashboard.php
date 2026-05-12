<?php
// public/farmer_dashboard.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];

$pdo = getPDO();

// Fetch all loans belonging to this farmer
$stmt = $pdo->prepare("SELECT 
                             l.*, 
                             u.name AS agent_name, 
                             ap.interest_rate, 
                             ap.loan_terms
                        FROM loan_applications l
                        LEFT JOIN users u ON l.agent_id = u.id
                        LEFT JOIN agent_profiles ap ON u.id = ap.user_id
                        WHERE l.farmer_id = ?
                        ORDER BY l.created_at DESC");
$stmt->execute([$farmer_id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary metrics
$total_loans = count($loans);
$approved_loans = count(array_filter($loans, function($l){ return isset($l['status']) && $l['status'] === 'approved'; }));
$pending_loans = count(array_filter($loans, function($l){ return !isset($l['status']) || $l['status'] === 'pending'; }));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Farmer Dashboard | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    /* --- COPIED & ADAPTED FROM ADMIN DASHBOARD --- */
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

    /* DASHBOARD CONTENT */
    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* STATS GRID */
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

    .theme-blue { background: #eff6ff; color: #2563eb; }
    .theme-green { background: #ecfdf5; color: #059669; }
    .theme-orange { background: #fff7ed; color: #ea580c; }

    /* --- TABLE STYLES (Added for Farmer Dashboard) --- */
    .table-container {
        background: var(--bg-card); border-radius: 16px;
        padding: 24px; box-shadow: var(--shadow);
        overflow-x: auto;
    }
    
    .table-header { margin-bottom: 20px; font-size: 18px; font-weight: 600; color: var(--text-main); }

    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    
    th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f3f4f6; }
    
    th {
        font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--text-muted); font-weight: 600; background: #f9fafb;
    }
    
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f9fafb; }

    /* BADGES */
    .badge {
        padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
        text-transform: capitalize; display: inline-block;
    }
    .badge.approved { background: #d1fae5; color: #065f46; }
    .badge.pending { background: #fef3c7; color: #92400e; }
    .badge.rejected { background: #fee2e2; color: #991b1b; }

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
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <!-- Using the same logo path as the original request -->
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
            <a href="upload_proof.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'upload_proof.php' ? 'active' : '' ?>">
                <i data-feather="upload-cloud"></i>
                <span>Upload Proof</span>
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

        <!-- DASHBOARD CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Dashboard Overview</h1>
                <p class="page-subtitle">Track your loan applications and status.</p>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <!-- Total Applications -->
                <div class="stat-card">
                    <div class="icon-box theme-blue">
                        <i data-feather="layers"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Applications</h3>
                        <p><?= number_format($total_loans); ?></p>
                    </div>
                </div>

                <!-- Approved Loans -->
                <div class="stat-card">
                    <div class="icon-box theme-green">
                        <i data-feather="check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Approved Loans</h3>
                        <p><?= number_format($approved_loans); ?></p>
                    </div>
                </div>

                <!-- Pending Review -->
                <div class="stat-card">
                    <div class="icon-box theme-orange">
                        <i data-feather="clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Review</h3>
                        <p><?= number_format($pending_loans); ?></p>
                    </div>
                </div>
            </div>

            <!-- Recent Loans Table -->
            <div class="table-container">
                <div class="table-header">Recent Loan Applications</div>
                
                <?php if (empty($loans)): ?>
                    <div style="text-align:center; padding: 20px; color: var(--text-muted);">
                        <i data-feather="inbox" style="width:40px; height:40px; margin-bottom:10px;"></i>
                        <p>You have no active loan applications yet.</p>
                        <a href="apply_loan.php" style="color:var(--primary); font-weight:600; text-decoration:none;">Apply now &rarr;</a>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Purpose</th>
                                <th>Amount</th>
                                <th>Interest</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($loans as $loan): 
                            $status = htmlspecialchars($loan['status'] ?? 'pending');
                            // Determine badge class
                            $badgeClass = 'pending';
                            if($status === 'approved') $badgeClass = 'approved';
                            if($status === 'rejected') $badgeClass = 'rejected';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($loan['agent_name'] ?? '—'); ?></td>
                                <td><?= htmlspecialchars($loan['title'] ?? '-'); ?></td>
                                <td style="font-weight:600;">GHc<?= number_format($loan['amount'] ?? 0, 2); ?></td>
                                <td><?= isset($loan['interest_rate']) ? htmlspecialchars($loan['interest_rate'] . '%') : '-'; ?></td>
                                <td><?= isset($loan['repayment_period']) ? htmlspecialchars($loan['repayment_period'] . ' months') : '-'; ?></td>
                                <td>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= ucfirst($status); ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($loan['created_at'])); ?></td>
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
                sidebar.classList.toggle("active"); // Mobile behavior
            } else {
                sidebar.classList.toggle("collapsed"); // Desktop behavior
            }
        });
    </script>
</body>
</html>