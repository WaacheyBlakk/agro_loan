<?php
// public/agent_dashboard.php
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

// Get pending loans for this agent
$stmt = $pdo->prepare("SELECT l.*, u.name AS farmer_name 
                       FROM loan_applications l 
                       LEFT JOIN users u ON l.farmer_id = u.id 
                       WHERE l.agent_id = ? AND l.status = 'pending'");
$stmt->execute([$agent_id]);
$pending_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stage proofs awaiting review
$proofs = $pdo->query("SELECT sp.*, u.name AS farmer_name, ls.stage_number 
                       FROM stage_proofs sp
                       JOIN loan_stages ls ON sp.stage_id = ls.id
                       JOIN users u ON sp.farmer_id = u.id
                       ORDER BY sp.uploaded_at DESC
                       LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Dashboard | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #1e40af;         /* Agent Blue Theme */
    --brand-dark: #1d4ed8;
    --danger: #e53e3e;
    --bg: #f6f8fa;
    --text: #1f2937;
    --muted: #6b7280;
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
    min-width:var(--sidebar-width);
    background:var(--brand);
    color:#fff;
    display:flex;
    flex-direction:column;
    padding:18px;
    gap:8px;
    transition:width .28s ease, padding .2s ease;
}
.sidebar.collapsed{
    width:var(--sidebar-collapsed-width);
    min-width:var(--sidebar-collapsed-width);
    padding-left:10px;
    padding-right:10px;
}
.brand{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:6px;
}
.brand .logo{
    width:44px;
    height:44px;
    background:rgba(255,255,255,0.12);
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:18px;
}
.brand h2{
    font-size:18px;
    margin:0;
    line-height:1;
    font-weight:600;
    transition:opacity .18s ease;
}
.sidebar.collapsed .brand h2{opacity:0;width:0;margin:0;}

/* NAV */
.nav{
    display:flex;
    flex-direction:column;
    gap:6px;
    margin-top:8px;
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
    white-space:nowrap;
}
.nav a .icon{
    width:36px;
    height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    border-radius:6px;
    background:rgba(255,255,255,0.06);
}
.nav a:hover{background:var(--brand-dark);transform:translateY(-1px);}
.nav a.active{background:rgba(0,0,0,0.12);}
.sidebar.collapsed .nav a{justify-content:center;padding:8px;}
.sidebar.collapsed .nav a .label{display:none;}
.sidebar.collapsed .nav a .icon{margin:0;}
.sidebar .spacer{flex:1 1 auto;}
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

/* CONTENT */
.main{
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    height:100vh;
    overflow:auto;
}
.topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:20px;
    border-bottom:1px solid rgba(15,23,42,0.04);
}

.toggle-btn{
    background:var(--brand);
    color:#fff;
    border:none;
    padding:8px 10px;
    border-radius:8px;
    font-size:18px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.card-summary{display:flex;gap:18px;}
.card{
    background:var(--card-bg);
    padding:18px;
    border-radius:12px;
    box-shadow:0 4px 18px rgba(16,24,40,0.06);
    flex:1;text-align:center;
}
.card h3{margin:0;color:var(--brand);font-size:14px;font-weight:700;}
.card p{margin-top:10px;font-size:20px;font-weight:700;}
.table-wrap{
    background:var(--card-bg);
    border-radius:12px;
    padding:18px;
    box-shadow:0 4px 18px rgba(16,24,40,0.06);
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:14px;
}
th,td{
    padding:12px 10px;
    border-bottom:1px solid #eef2f3;
    text-align:left;
}
th{
    color:var(--brand);
    text-transform:uppercase;
    font-size:12px;
}
tr:hover td{background:rgba(15,23,42,0.02);}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo"></a>
        <h2>AgroLoan Agent</h2>
    </div>

    <nav class="nav">
        <a href="agent_dashboard.php" class="active"><span class="icon">🏠</span><span class="label">Dashboard</span></a>
        <a href="farmer_vetting.php"><span class="icon">👨‍🌾</span><span class="label">Farmer Vetting</span></a>
        <a href="proof_verification.php"><span class="icon">📸</span><span class="label">Proof Verification</span></a>
        <a href="agent_approve_stage.php"><span class="icon">💰</span><span class="label">Disbursement</span></a>
        <a href="agent_profile.php"><span class="icon">⚙️</span><span class="label">Profile</span></a>
    </nav>

    <div class="spacer"></div>

    <form method="POST" action="logout.php">
        <button class="logout-btn"><span class="icon">🚪</span><span class="label">Logout</span></button>
    </form>
</aside>

<main class="main" id="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 💼</div>
    </div>

    <div class="content-area">
        <div class="card-summary">
            <div class="card">
                <h3>Pending Loans</h3>
                <p><?= count($pending_loans) ?></p>
            </div>
            <div class="card">
                <h3>Proofs to Review</h3>
                <p><?= count($proofs) ?></p>
            </div>
        </div>

        <div class="table-wrap">
            <h3>Pending Loan Applications</h3>
            <?php if (empty($pending_loans)): ?>
                <p>No pending loan applications at the moment.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Loan ID</th>
                        <th>Farmer</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Submitted On</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending_loans as $loan): ?>
                    <tr>
                        <td><?= htmlspecialchars($loan['id']) ?></td>
                        <td><?= htmlspecialchars($loan['farmer_name']) ?></td>
                        <td><?= htmlspecialchars($loan['title']) ?></td>
                        <td>$<?= number_format($loan['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($loan['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <h3>Recent Stage Proofs</h3>
            <?php if (empty($proofs)): ?>
                <p>No recent uploads to review.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Farmer</th>
                        <th>Stage</th>
                        <th>File</th>
                        <th>Type</th>
                        <th>Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($proofs as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['farmer_name']) ?></td>
                        <td>Stage <?= htmlspecialchars($p['stage_number']) ?></td>
                        <td><a href="../uploads/<?= htmlspecialchars($p['filename']) ?>" target="_blank">View</a></td>
                        <td><?= htmlspecialchars($p['file_type']) ?></td>
                        <td><?= htmlspecialchars($p['uploaded_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
(function(){
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    toggleBtn.addEventListener('click',()=>{
        sidebar.classList.toggle('collapsed');
    });
})();
</script>
</body>
</html>
