<?php
session_start();
require_once '../src/db.php';

// Ensure admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();

// Count data
$stats = [
    'farmers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn(),
    'agents' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='agent'")->fetchColumn(),
    'pending' => $pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn(),
    'verified' => $pdo->query("SELECT COUNT(*) FROM users WHERE status='verified'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM users WHERE status='rejected'")->fetchColumn(),
];

$username = $_SESSION['name'] ?? "Admin";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root{
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #0f766e;     
    --brand-dark: #0d9488;
    --danger: #e53e3e;
    --bg: #f6f8fa;
    --text: #1f2937;
    --card-bg: #fff;
}
*{box-sizing:border-box;}
body{
    margin:0;
    font-family:"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
    height:100vh;
    display:flex;
    overflow:hidden;
}

/* SIDEBAR */
.sidebar{
    width:var(--sidebar-width);
    background:var(--brand);
    color:#fff;
    min-height:100vh;
    display:flex;
    flex-direction:column;
    padding:18px;
    gap:10px;
    transition:width .28s ease, padding .2s ease;
}

.sidebar.collapsed{
    width:var(--sidebar-collapsed-width);
    padding-left:10px;
    padding-right:10px;
}

.brand{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:10px;
}

.brand .logo{
    width:44px;
    height:44px;
    border-radius:8px;
    object-fit:cover;
}

.brand h2{
    font-size:18px;
    margin:0;
    font-weight:600;
}

.sidebar.collapsed .brand h2{
    opacity:0;
    width:0;
}

/* Navigation Links */
.nav{
    display:flex;
    flex-direction:column;
    gap:6px;
}

.nav a{
    display:flex;
    align-items:center;
    gap:12px;
    padding:10px;
    border-radius:8px;
    color:#fff;
    text-decoration:none;
    font-weight:500;
    transition:background .15s, transform .08s;
}

.nav a .icon{
    width:34px;
    height:34px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    background:rgba(255,255,255,0.09);
    border-radius:6px;
}

.nav a:hover{background:var(--brand-dark);transform:translateY(-1px);}
.nav a.active{background:rgba(0,0,0,0.12);}

.sidebar.collapsed .nav a{
    justify-content:center;
    padding:8px;
}

.sidebar.collapsed .nav a .label{
    display:none;
}

.sidebar .spacer{
    flex:1;
}

/* Logout Button */
.logout-btn{
    background:var(--danger);
    border:none;
    color:#fff;
    padding:10px;
    border-radius:8px;
    cursor:pointer;
    width:100%;
    font-weight:600;
}
.sidebar.collapsed .logout-btn{
    padding:8px;
    width:48px;
    height:48px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.sidebar.collapsed .logout-btn span.label{display:none;}

/* MAIN */
.main{
    flex:1;
    display:flex;
    flex-direction:column;
    overflow:auto;
}
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:20px;
    border-bottom:1px solid rgba(0,0,0,0.05);
}
.user-greet{
    font-size:18px;
    font-weight:600;
}
.toggle-btn{
    background:var(--brand);
    color:#fff;
    border:none;
    padding:8px 10px;
    border-radius:8px;
    cursor:pointer;
    font-size:18px;
}

/* CONTENT AREA */
.dashboard-content{
    padding:32px 40px;
    display:flex;
    flex-direction:column;
    gap:30px;
}

.title{
    font-size:28px;
    font-weight:700;
    margin-bottom:5px;
}

/* GRID CARDS */
.stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(230px, 1fr));
    gap:24px;
}

.stat-card{
    background:var(--card-bg);
    border-radius:14px;
    padding:18px;
    box-shadow:0 4px 16px rgba(0,0,0,0.06);
    text-align:center;
    transition:0.2s ease;
}
.stat-card:hover{
    transform:translateY(-5px);
    box-shadow:0 6px 22px rgba(0,0,0,0.10);
}

.stat-card h3{
    font-size:16px;
    margin:0;
}
.stat-card p{
    font-size:34px;
    margin-top:10px;
    font-weight:bold;
    color:#fff;
}

/* COLORS */
.blue  { background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; }
.green { background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; }
.orange{ background:linear-gradient(135deg,#ea580c,#c2410c); color:#fff; }
.teal  { background:linear-gradient(135deg,#0d9488,#0f766e); color:#fff; }
.red   { background:linear-gradient(135deg,#dc2626,#b91c1c); color:#fff; }

</style>
</head>

<body>
  <aside class="sidebar" id="adminSidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo">
        <h2>AgroLoan Admin</h2>
    </div>

    <nav class="nav">
        <a href="admin_dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
            <span class="icon">📊</span>
            <span class="label">Dashboard</span>
        </a>

        <a href="admin_verifications.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_verifications.php' ? 'active' : '' ?>">
            <span class="icon">📝</span>
            <span class="label">Verification Center</span>
        </a>

        <a href="admin_profile.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_profile.php' ? 'active' : '' ?>">
            <span class="icon">⚙️</span>
            <span class="label">Profile</span>
        </a>
    </nav>

    <div class="spacer"></div>

    <form action="logout.php" method="POST">
        <button class="logout-btn">
            <span class="icon">🚪</span>
            <span class="label">Logout</span>
        </button>
    </form>
</aside>


<main class="main" id="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn">☰</button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 👨‍💼</div>
    </div>

    <div class="dashboard-content">

        <h1 class="title">Admin Dashboard Overview</h1>

        <div class="stats-grid">

            <div class="stat-card blue">
                <h3>Farmers</h3>
                <p><?= $stats['farmers']; ?></p>
            </div>

            <div class="stat-card green">
                <h3>Agents</h3>
                <p><?= $stats['agents']; ?></p>
            </div>  

            <div class="stat-card orange">
                <h3>Pending</h3>
                <p><?= $stats['pending']; ?></p>
            </div>

            <div class="stat-card teal">
                <h3>Verified</h3>
                <p><?= $stats['verified']; ?></p>
            </div>

            <div class="stat-card red">
                <h3>Rejected</h3>
                <p><?= $stats['rejected']; ?></p>
            </div>

        </div>
    </div>
</main>

<script>
document.getElementById("toggleBtn").addEventListener("click", function () {
    document.getElementById("adminSidebar").classList.toggle("collapsed");
});
</script>



</body>
</html>
