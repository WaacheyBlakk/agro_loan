<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/sessions.php';

// Ensure only admin can access
if ($_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// DB connection
$conn = new mysqli("localhost", "root", "", "agro_loan");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch users awaiting verification
$query = "
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

<style>
/* GLOBAL THEME VARIABLES (match dashboard) */
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


/* MAIN WRAPPER */
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
    border-bottom:1px solid rgba(0,0,0,0.05);
    background:white;
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

/* PAGE CONTENT */
.page-content{
    padding:32px 40px;
}

/* PAGE TITLE */
.title{
    font-size:28px;
    font-weight:700;
    margin-bottom:20px;
}

/* CARD CONTAINER */
.card{
    background:var(--card-bg);
    padding:25px;
    border-radius:14px;
    box-shadow:0 4px 16px rgba(0,0,0,0.07);
    animation: fadeIn 0.3s ease;
}

/* TABLE */
.table-wrapper{
    overflow-x:auto;
    margin-top:20px;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:900px;
}

table thead{
    background:#eef2f4;
}

table th{
    padding:14px;
    font-size:15px;
    text-align:left;
    font-weight:600;
    color:#374151;
    border-bottom:2px solid #e5e7eb;
}

table td{
    padding:14px;
    border-bottom:1px solid #e5e7eb;
    font-size:15px;
}

tr:hover{
    background:#f0f7f6;
    transition:0.15s;
}

/* STATUS BADGES */
.status{
    padding:6px 12px;
    border-radius:6px;
    color:white;
    font-size:13px;
    font-weight:600;
}

.pending   { background:#f59e0b; }
.submitted { background:#0ea5e9; }
.unverified{ background:#6b7280; }

/* ACTION BUTTON */
.btn{
    padding:8px 14px;
    background:var(--brand);
    color:white;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    transition:0.15s;
}

.btn:hover{
    background:var(--brand-dark);
}

/* SUCCESS MESSAGE */
.success-msg{
    padding:14px 18px;
    background:#d4edda;
    color:#155724;
    border-left:5px solid #28a745;
    border-radius:6px;
    margin-bottom:18px;
}

/* ANIMATION */
@keyframes fadeIn{
    from{opacity:0; transform:translateY(10px);}
    to{opacity:1; transform:translateY(0);}
}
</style>
</head>

<body>
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


<main class="main">
    
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn">☰</button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 👨‍💼</div>
    </div>

    <div class="page-content">

        <h1 class="title">Users Awaiting Verification</h1>

        <div class="card">

            <?php if (isset($_GET['msg'])): ?>
                <div class="success-msg">
                    <?= htmlspecialchars($_GET['msg']); ?>
                </div>
            <?php endif; ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id']; ?></td>
                            <td><?= htmlspecialchars($row['name']); ?></td>
                            <td><?= htmlspecialchars($row['email']); ?></td>
                            <td><?= htmlspecialchars($row['phone'] ?? 'N/A'); ?></td>
                            <td><?= ucfirst($row['role']); ?></td>

                            <td>
                                <span class="status <?= $row['status'] ?>">
                                    <?= ucfirst($row['status']); ?>
                                </span>
                            </td>

                            <td><?= $row['created_at']; ?></td>

                            <td>
                                <a href="admin_user_details.php?id=<?= $row['id']; ?>" class="btn">View Details</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>

                        <?php if ($result->num_rows === 0): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:20px; color:#777;">
                                No users waiting for verification.
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
document.getElementById("toggleBtn").addEventListener("click", function () {
    document.getElementById("adminSidebar").classList.toggle("collapsed");
});
</script>

</body>
</html>
