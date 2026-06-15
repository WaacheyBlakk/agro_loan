<?php
session_start();
require_once '../src/db.php';

// Ensure admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();

// Count aggregated system data
$stats = [
    'farmers'  => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn(),
    'agents'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='agent'")->fetchColumn(),
    'buyers'   => (int)$pdo->query("SELECT COUNT(*) FROM buyers")->fetchColumn(),
    
    // Aggregates pending profiles from both users and buyers tables
    'pending'  => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status IN ('pending', 'unverified', 'submitted')")->fetchColumn() +
                  (int)$pdo->query("SELECT COUNT(*) FROM buyers WHERE status IN ('pending', 'unverified', 'submitted')")->fetchColumn(),
                  
    // Aggregates verified farmers/agents and approved buyers
    'verified' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='verified'")->fetchColumn() +
                  (int)$pdo->query("SELECT COUNT(*) FROM buyers WHERE status='approved'")->fetchColumn(),
                  
    // Aggregates rejected accounts from both tables
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='rejected'")->fetchColumn() +
                  (int)$pdo->query("SELECT COUNT(*) FROM buyers WHERE status='rejected'")->fetchColumn(),
];

$username = $_SESSION['name'] ?? "Admin";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons (Lightweight SVGs) -->
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

    .sidebar.collapsed {
        width: var(--sidebar-collapsed);
        padding: 20px 10px;
    }

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
        color: #d1fae5; /* Light emerald */
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.2s ease;
        white-space: nowrap;
        font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1);
        color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary);
        color: #fff;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

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

    /* TOPBAR */
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

    .toggle-btn {
        background: transparent;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 5px;
    }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .user-avatar {
        width: 35px;
        height: 35px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }

    /* DASHBOARD CONTENT */
    .content { padding: 30px; }

    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* STATS GRID */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 24px;
    }

    .stat-card {
        background: var(--bg-card);
        padding: 24px;
        border-radius: 16px;
        box-shadow: var(--shadow);
        display: flex;
        align-items: center;
        gap: 20px;
        transition: transform 0.2s, box-shadow 0.2s;
        border: 1px solid #f0f0f0;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .icon-box {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .stat-info h3 { margin: 0; font-size: 14px; color: var(--text-muted); font-weight: 500; }
    .stat-info p { margin: 5px 0 0; font-size: 28px; font-weight: 700; color: var(--text-main); }

    /* Card Themes */
    .theme-blue { background: #eff6ff; color: #2563eb; }
    .theme-green { background: #ecfdf5; color: #059669; }
    .theme-purple { background: #f5f3ff; color: #7c3aed; }
    .theme-orange { background: #fff7ed; color: #ea580c; }
    .theme-teal { background: #f0fdfa; color: #0d9488; }
    .theme-red { background: #fef2f2; color: #dc2626; }

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
            <!-- Placeholder for Logo if image fails -->
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Administrator</h2>
        </div>

        <nav class="nav">
            <a href="admin_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="pie-chart"></i>
                <span>Dashboard</span>
            </a>
            <a href="admin_verifications.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_verifications.php' ? 'active' : '' ?>">
                <i data-feather="check-square"></i>
                <span>Verifications</span>
            </a>
            <a href="admin_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_profile.php' ? 'active' : '' ?>">
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
                    <div style="font-size:12px; color:var(--text-muted);">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- DASHBOARD CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Overview</h1>
                <p class="page-subtitle">Welcome back! Here's what's happening with AgroLoan today.</p>
            </div>

            <div class="stats-grid">
                <!-- Farmers -->
                <div class="stat-card">
                    <div class="icon-box theme-blue">
                        <i data-feather="users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Farmers</h3>
                        <p><?= number_format($stats['farmers']); ?></p>
                    </div>
                </div>

                <!-- Agents -->
                <div class="stat-card">
                    <div class="icon-box theme-green">
                        <i data-feather="briefcase"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Active Agents</h3>
                        <p><?= number_format($stats['agents']); ?></p>
                    </div>
                </div>

                <!-- Buyers -->
                <div class="stat-card">
                    <div class="icon-box theme-purple">
                        <i data-feather="shopping-bag"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Buyers</h3>
                        <p><?= number_format($stats['buyers']); ?></p>
                    </div>
                </div>

                <!-- Pending -->
                <div class="stat-card">
                    <div class="icon-box theme-orange">
                        <i data-feather="clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending Apps</h3>
                        <p><?= number_format($stats['pending']); ?></p>
                    </div>
                </div>

                <!-- Verified -->
                <div class="stat-card">
                    <div class="icon-box theme-teal">
                        <i data-feather="check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Verified Users</h3>
                        <p><?= number_format($stats['verified']); ?></p>
                    </div>
                </div>

                <!-- Rejected -->
                <div class="stat-card">
                    <div class="icon-box theme-red">
                        <i data-feather="x-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Rejected</h3>
                        <p><?= number_format($stats['rejected']); ?></p>
                    </div>
                </div>
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