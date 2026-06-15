<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/sessions.php';

// Ensure only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// DB connection
$conn = new mysqli("localhost", "root", "", "agro_loan");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch users and buyers awaiting verification using a UNION query
$query = "
    SELECT id, name, email, phone, 'buyer' AS role, status, created_at 
    FROM buyers 
    WHERE status IN ('pending', 'unverified', 'submitted')
    UNION ALL
    SELECT id, name, email, phone, role, status, created_at 
    FROM users 
    WHERE status IN ('pending', 'unverified', 'submitted')
    ORDER BY created_at DESC
";
$result = $conn->query($query);

$username = $_SESSION['name'] ?? "Admin";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verification Center | AgroLoan Admin</title>
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
        color: #d1fae5;
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.2s ease;
        white-space: nowrap;
        font-weight: 500;
    }

    .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
    .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }

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

    .toggle-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary); color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* PAGE SPECIFIC */
    .content { padding: 30px; }
    .page-header { margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* TABLE CARD */
    .card {
        background: var(--bg-card);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
        border: 1px solid var(--border-color);
    }

    .table-responsive { width: 100%; overflow-x: auto; }
    
    .table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .table thead { background: #f9fafb; border-bottom: 2px solid var(--border-color); }
    .table th { padding: 16px 24px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .table td { padding: 16px 24px; border-bottom: 1px solid var(--border-color); vertical-align: middle; font-size: 14px; }
    .table tr:last-child td { border-bottom: none; }
    .table tbody tr:hover { background: #f8fafc; }

    /* USER CELL */
    .user-cell { display: flex; align-items: center; gap: 12px; }
    .user-cell-avatar { width: 32px; height: 32px; background: #e0e7ff; color: #4338ca; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
    .user-info div { line-height: 1.2; }
    .user-email { font-size: 12px; color: var(--text-muted); }

    /* STATUS BADGES */
    .badge { padding: 4px 10px; border-radius: 50px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
    .badge-pending { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
    .badge-submitted { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
    .badge-unverified { background: #f3f4f6; color: #4b5563; border: 1px solid #d1d5db; }

    /* ACTION BUTTON */
    .btn-action {
        text-decoration: none;
        padding: 6px 12px;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        color: var(--text-main);
        font-size: 13px;
        font-weight: 500;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-action:hover { border-color: var(--primary); color: var(--primary); background: #f0fdfa; }

    .empty-state { text-align: center; padding: 50px; color: var(--text-muted); }

    /* MESSAGES */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #10b981; }

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
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Verification Center</h1>
                    <p class="page-subtitle">Review and approve new user applications.</p>
                </div>
            </div>

            <?php if (isset($_GET['msg'])): ?>
                <div class="alert alert-success">
                    <i data-feather="check-circle" style="width:18px"></i>
                    <?= htmlspecialchars($_GET['msg']); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Registered On</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-cell-avatar">
                                            <?= strtoupper(substr($row['name'], 0, 1)) ?>
                                        </div>
                                        <div class="user-info">
                                            <div style="font-weight:600; color:var(--text-main)"><?= htmlspecialchars($row['name']); ?></div>
                                            <div class="user-email"><?= htmlspecialchars($row['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                                <td><?= ucfirst($row['role']); ?></td>
                                <td>
                                    <?php 
                                        $badgeClass = 'badge-unverified';
                                        if($row['status'] === 'pending') $badgeClass = 'badge-pending';
                                        if($row['status'] === 'submitted') $badgeClass = 'badge-submitted';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                <td>
                                    <a href="admin_user_details.php?id=<?= $row['id']; ?>&role=<?= urlencode($row['role']); ?>" class="btn-action">
                                        <i data-feather="eye" style="width:14px;"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>

                            <?php if ($result->num_rows === 0): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i data-feather="inbox" style="width:40px; height:40px; margin-bottom:10px; opacity:0.5;"></i>
                                        <p>No users currently waiting for verification.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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