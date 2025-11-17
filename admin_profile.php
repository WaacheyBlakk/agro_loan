<?php
session_start();
require_once '../src/db.php';

// Ensure admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$admin_id = $_SESSION['user_id']; 

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("Admin record not found.");
}

$username = $admin['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Profile | AgroLoan</title>

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

* { box-sizing:border-box; }

body{
    margin:0;
    font-family:"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
    height:100vh;
    display:flex;
    overflow:hidden;
}

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

/* Navigation */
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

.nav a:hover{background:var(--brand-dark);transform:translateY(-1px);}
.nav a.active{background:rgba(0,0,0,0.12);}

.nav a .icon{
    width:34px;
    height:34px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,0.10);
    border-radius:6px;
    font-size:18px;
}

.sidebar.collapsed .nav a{
    justify-content:center;
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


/* ========== MAIN WRAPPER ========== */
.main{
    flex:1;
    display:flex;
    flex-direction:column;
    overflow:auto;
}

/* TOP BAR */
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:20px;
    background:white;
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

/* ========== PROFILE CONTENT ========== */
.page-content {
    padding: 20px;
    display: flex;
    justify-content: center;     
    align-items: center;         
    min-height: calc(100vh - 80px);
}

.profile-card {
    background: #ffffff;
    padding: 30px;
    border-radius: 18px;
    max-width: 550px;
    width: 100%;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    animation: fadeIn 0.4s ease;
}


.profile-card h2 {
    margin-bottom: 20px;
    font-size: 26px;
    font-weight: 700;
    color: #003049;
}

.profile-row {
    margin: 15px 0;
    font-size: 18px;
}

.profile-row b {
    color: #003049;
}

.change-pass-btn {
    display: inline-block;
    margin-top: 20px;
    background: #0077b6;
    color: white;
    padding: 10px 18px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 16px;
    transition: 0.2s ease;
}

.change-pass-btn:hover {
    background: #005f8d;
}

/* Fade Animation */
@keyframes fadeIn {
    from { opacity:0; transform: translateY(10px); }
    to { opacity:1; transform: translateY(0); }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="adminSidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo">
        <h2>AgroLoan Admin</h2>
    </div>

    <nav class="nav">
        <a href="admin_dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
            <span class="icon">📊</span> <span class="label">Dashboard</span>
        </a>

        <a href="admin_verifications.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_verifications.php' ? 'active' : '' ?>">
            <span class="icon">📝</span> <span class="label">Verification Center</span>
        </a>

        <a href="admin_profile.php" class="active">
            <span class="icon">⚙️</span> <span class="label">Profile</span>
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

<!-- MAIN -->
<main class="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn">☰</button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 👨‍💼</div>
    </div>

    <div class="page-content">

        <div class="profile-card">
            <h2>Admin Profile</h2>

            <div class="profile-row">
                <b>Name:</b> <?= htmlspecialchars($admin['name']); ?>
            </div>

            <div class="profile-row">
                <b>Email:</b> <?= htmlspecialchars($admin['email']); ?>
            </div>

            <div class="profile-row">
                <b>Role:</b> Administrator
            </div>

            <a href="change_password.php" class="change-pass-btn">Change Password</a>
        </div>

    </div>
</main>

<script>
document.getElementById("toggleBtn").addEventListener("click", () => {
    document.getElementById("adminSidebar").classList.toggle("collapsed");
});
</script>

</body>
</html>
