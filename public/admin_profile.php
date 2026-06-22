<?php
session_start();
require_once '../src/db.php';

// Ensure admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$admin_id = $_SESSION['user_id'] ?? 1; // Fallback for testing if session not set

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("Admin record not found.");
}

$username = $admin['name'];
// Extract initials for avatar
$initials = strtoupper(substr($username, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Profile | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
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
        --border-color: #e5e7eb;
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
        width: 40px; height: 40px; border-radius: 8px; object-fit: cover;
        border: 2px solid rgba(255,255,255,0.2);
    }

    .brand h2 {
        font-size: 20px; font-weight: 600; white-space: nowrap;
        opacity: 1; transition: opacity 0.2s; margin: 0;
    }

    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }

    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .nav-link {
        display: flex; align-items: center; gap: 14px; padding: 12px 15px;
        color: #d1fae5; text-decoration: none; border-radius: 10px;
        transition: all 0.2s ease; white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
    .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1); color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2); padding: 12px;
        border-radius: 10px; cursor: pointer; display: flex;
        align-items: center; justify-content: center; gap: 10px;
        font-family: inherit; font-weight: 600; transition: 0.2s; width: 100%;
    }
    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
    }

    .topbar {
        background: var(--bg-card); padding: 15px 30px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50;
    }

    .toggle-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar-mini {
        width: 35px; height: 35px; background: var(--primary); color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* --- PROFILE SPECIFIC --- */
    .page-content {
        padding: 40px;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: calc(100vh - 80px);
    }

    .profile-card {
        background: var(--bg-card);
        border-radius: 20px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 600px;
        overflow: hidden;
        animation: fadeIn 0.4s ease;
    }

    .profile-header-bg {
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
        height: 120px;
        position: relative;
    }

    .profile-body {
        padding: 0 40px 40px 40px;
        text-align: center;
        margin-top: 10px; /* Pulls content up over the banner */
    }

    .large-avatar {
        width: 120px;
        height: 120px;
        background: white;
        border-radius: 50%;
        padding: 5px;
        margin: 0 auto 20px auto;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .large-avatar-inner {
        width: 100%;
        height: 100%;
        background: #ecfdf5;
        color: var(--primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        font-weight: 700;
    }

    .profile-name { font-size: 28px; font-weight: 700; color: var(--text-main); margin: 0; }
    .profile-role-badge { 
        display: inline-block; 
        background: #f3f4f6; 
        color: var(--text-muted); 
        padding: 5px 15px; 
        border-radius: 50px; 
        font-size: 14px; 
        font-weight: 500; 
        margin-top: 8px;
        margin-bottom: 30px;
    }

    .info-grid {
        display: flex;
        flex-direction: column;
        gap: 15px;
        text-align: left;
    }

    .info-item {
        display: flex;
        align-items: center;
        padding: 15px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        transition: 0.2s;
    }

    .info-item:hover { border-color: var(--primary); background: #f0fdfa; }

    .info-icon {
        width: 40px; height: 40px;
        background: #e0e7ff; color: #4338ca;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        margin-right: 15px;
    }
    
    .info-content small { display: block; color: var(--text-muted); font-size: 12px; margin-bottom: 2px; }
    .info-content span { font-weight: 600; color: var(--text-main); font-size: 16px; }

    .btn-primary {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        margin-top: 30px;
        background: var(--primary);
        color: white;
        padding: 14px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 600;
        transition: 0.2s;
        box-shadow: 0 4px 6px rgba(5, 150, 105, 0.2);
    }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }

    @keyframes fadeIn {
        from { opacity:0; transform: translateY(15px); }
        to { opacity:1; transform: translateY(0); }
    }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .page-content { padding: 20px; }
    }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Administrator</h2>
        </div>

        <nav class="nav">
            <a href="admin_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="pie-chart"></i>
                <span>Dashboard</span>
            </a>
            <a href="admin_user_management.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_user_management.php' ? 'active' : '' ?>">
                <i data-feather="users"></i>
                <span>User Management</span>
            </a>
            <a href="admin_verifications.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_verifications.php' ? 'active' : '' ?>">
                <i data-feather="check-square"></i>
                <span>Verifications</span>
            </a>
            <a href="admin_loan_oversight.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_loan_oversight.php' ? 'active' : '' ?>">
                <i data-feather="shield"></i>
                <span>Loan Oversight</span>
            </a>
            <a href="admin_disputes.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_disputes.php' ? 'active' : '' ?>">
                <i data-feather="alert-triangle"></i>
                <span>Dispute Center</span>
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

    <!-- MAIN CONTENT -->
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
                <div class="user-avatar-mini">
                    <?= $initials ?>
                </div>
            </div>
        </header>

        <!-- PROFILE CONTENT -->
        <div class="page-content">
            <div class="profile-card">
                <!-- Decorative Header -->
                <div class="profile-header-bg"></div>

                <div class="profile-body">
                    <!-- Avatar -->
                    <div class="large-avatar">
                        <div class="large-avatar-inner">
                            <?= $initials ?>
                        </div>
                    </div>

                    <!-- Name & Role -->
                    <h1 class="profile-name"><?= htmlspecialchars($admin['name']); ?></h1>
                    <span class="profile-role-badge">System Administrator</span>

                    <!-- Info Grid -->
                    <div class="info-grid">
                        
                        <div class="info-item">
                            <div class="info-icon">
                                <i data-feather="mail"></i>
                            </div>
                            <div class="info-content">
                                <small>Email Address</small>
                                <span><?= htmlspecialchars($admin['email']); ?></span>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-icon" style="background:#fef3c7; color:#d97706;">
                                <i data-feather="shield"></i>
                            </div>
                            <div class="info-content">
                                <small>Security Level</small>
                                <span>Super Admin</span>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-icon" style="background:#dbeafe; color:#2563eb;">
                                <i data-feather="calendar"></i>
                            </div>
                            <div class="info-content">
                                <small>Account ID</small>
                                <span>#<?= htmlspecialchars($admin['id']); ?></span>
                            </div>
                        </div>

                    </div>

                    <!-- Action Button -->
                    <a href="change_password.php" class="btn-primary">
                        <i data-feather="lock"></i> Change Password
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Sidebar Logic
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