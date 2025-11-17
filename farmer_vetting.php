<?php
session_start();

// Load DB connection
require_once __DIR__ . '/../src/db.php';

// Get the PDO instance
$pdo = getPDO();

// Ensure agent is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit;
}

$agent_id = $_SESSION['user_id'];
$username = $_SESSION['name'];
$successMessage = '';

// Handle Approve / Reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id'], $_POST['action'])) {
    $application_id = $_POST['application_id'];
    $action = ($_POST['action'] === 'approve') ? 'approved' : 'rejected';

    $stmt = $pdo->prepare("UPDATE loan_applications SET status = ? WHERE id = ? AND agent_id = ?");
    if ($stmt->execute([$action, $application_id, $agent_id])) {
        $successMessage = ($action === 'approved')
            ? 'Application Approved Successfully!'
            : 'Application Rejected!';
    }
}

// Fetch loan applications assigned to this agent
$stmt = $pdo->prepare("
    SELECT 
        la.id AS application_id,
        la.title,
        la.amount,
        la.purpose,
        la.status,
        la.created_at,
        u.name AS farmer_name,
        u.email AS farmer_email
    FROM loan_applications la
    INNER JOIN users u ON la.farmer_id = u.id
    WHERE la.agent_id = ?
    ORDER BY la.created_at DESC
");
$stmt->execute([$agent_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Farmer Vetting | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #1e40af;
    --brand-dark: #1d4ed8;
    --danger: #e53e3e;
    --bg: #f6f8fa;
    --text: #1f2937;
    --muted: #6b7280;
    --card-bg: #fff;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: "Segoe UI", "Roboto", "Helvetica Neue", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    height: 100vh;
    display: flex;
    overflow: hidden;
}

/* SIDEBAR */
.sidebar {
    width: var(--sidebar-width);
    min-width: var(--sidebar-width);
    background: var(--brand);
    color: #fff;
    display: flex;
    flex-direction: column;
    padding: 18px;
    gap: 8px;
    transition: width .28s ease, padding .2s ease;
}
.sidebar.collapsed {
    width: var(--sidebar-collapsed-width);
    min-width: var(--sidebar-collapsed-width);
    padding-left: 10px;
    padding-right: 10px;
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 6px;
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
.brand img {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
}
.brand h2 {
    font-size: 18px;
    color: #fff;
    margin: 0;
    line-height: 1;
    font-weight: 600;
    transition: opacity .18s ease;
}
.sidebar.collapsed .brand h2 {opacity:0;width:0;margin:0;}

/* NAVIGATION */
.nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 8px;
}
.nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    border-radius: 8px;
    color: #fff;
    text-decoration: none;
    font-weight: 500;
    transition: background .15s, transform .08s;
    white-space: nowrap;
}
.nav a .icon {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    border-radius: 6px;
    background: rgba(255,255,255,0.06);
}
.nav a:hover { background: var(--brand-dark); transform: translateY(-1px); }
.nav a.active { background: rgba(0,0,0,0.12); }
.sidebar.collapsed .nav a { justify-content: center; padding: 8px; }
.sidebar.collapsed .nav a .label { display: none; }
.sidebar.collapsed .nav a .icon { margin: 0; }
.sidebar .spacer { flex: 1 1 auto; }
.logout-btn {
    background: var(--danger);
    border: none;
    color: #fff;
    padding: 10px;
    border-radius: 8px;
    cursor: pointer;
    width: 100%;
    font-weight: 600;
}
.sidebar.collapsed .logout-btn {
    padding: 8px;
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sidebar.collapsed .logout-btn span.label { display: none; }

/* MAIN CONTENT */
.main {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: auto;
}
.topbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:20px;
    border-bottom:1px solid rgba(15,23,42,0.04);
}
.toggle-btn {
    background: var(--brand);
    color: #fff;
    border:none;
    padding:8px 10px;
    border-radius:8px;
    font-size:18px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.user-greet { font-size: 18px; font-weight: 600; }

/* TABLE STYLING */
.content-area {
    padding: 28px 40px;
    display: flex;
    flex-direction: column;
    gap: 22px;
}
.table-wrap {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 4px 18px rgba(16,24,40,0.06);
}
h2 {
    color: var(--brand);
    margin-bottom: 15px;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 14px;
}
th, td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    vertical-align: top;
}
th {
    background-color: var(--brand);
    color: white;
    text-transform: uppercase;
    font-size: 12px;
}
tr:hover td { background: rgba(15,23,42,0.02); }
.status { font-weight: 600; text-transform: capitalize; }
.approved { color: green; }
.rejected { color: red; }
.pending { color: orange; }
form { display: inline; }
button {
    padding: 8px 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    color: white;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.15s ease;
}
button:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}
.approve-btn { background-color: #28a745; }
.reject-btn { background-color: #dc3545; }
.approve-btn:hover { background-color: #218838; }
.reject-btn:hover { background-color: #c82333; }
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo">
        <h2>AgroLoan Agent</h2>
    </div>

    <nav class="nav">
        <a href="agent_dashboard.php"><span class="icon">🏠</span><span class="label">Dashboard</span></a>
        <a href="farmer_vetting.php" class="active"><span class="icon">👨‍🌾</span><span class="label">Farmer Vetting</span></a>
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
        <div class="table-wrap">
            <h2>Review Farmer Loan Applications</h2>

            <?php if (empty($applications)): ?>
                <p>No loan applications found.</p>
            <?php else: ?>
            <table>
                <tr>
                    <th>Farmer</th>
                    <th>Project Title</th>
                    <th>Amount (GHS)</th>
                    <th>Purpose</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?= htmlspecialchars($app['farmer_name']) ?><br><small><?= htmlspecialchars($app['farmer_email']) ?></small></td>
                        <td><?= htmlspecialchars($app['title'] ?: 'Untitled') ?></td>
                        <td><?= number_format($app['amount'], 2) ?></td>
                        <td><?= nl2br(htmlspecialchars($app['purpose'])) ?></td>
                        <td class="status <?= htmlspecialchars($app['status']) ?>">
                            <?= htmlspecialchars($app['status']) ?>
                        </td>
                        <td>
                            <?php if ($app['status'] === 'pending'): ?>
                                <form method="POST">
                                    <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                    <button class="approve-btn" type="submit" name="action" value="approve">Approve</button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                    <button class="reject-btn" type="submit" name="action" value="reject">Reject</button>
                                </form>
                            <?php else: ?>
                                <em>No action available</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
(function(){
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    toggleBtn.addEventListener('click',()=> sidebar.classList.toggle('collapsed'));
})();
<?php if (!empty($successMessage)): ?>
Swal.fire({
    icon: 'success',
    title: 'Success!',
    text: '<?= $successMessage ?>',
    showConfirmButton: false,
    timer: 2000
}).then(() => {
    window.location.href = 'farmer_vetting.php';
});
<?php endif; ?>
</script>
</body>
</html>
