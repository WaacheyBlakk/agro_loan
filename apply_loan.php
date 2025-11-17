<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/users.php';
require_once __DIR__ . '/../src/loan.php';

$user = current_user();
if (!$user || !isset($user['id'])) {
    header('Location: login.php');
    exit;
}


$pdo = getPDO();
$agents = $pdo->query("
    SELECT u.id, u.name, ap.interest_rate, ap.loan_terms 
    FROM users u 
    JOIN agent_profiles ap ON u.id = ap.user_id
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stages = [];
    for ($i = 1; $i <= 3; $i++) {
        $amt = floatval($_POST["stage_{$i}_amount"] ?? 0);
        $stages[] = ['stage_number' => $i, 'required_amount' => $amt];
    }
    $appId = create_application($user['id'], $_POST['agent_id'], $_POST['title'], $_POST['amount'], $_POST['purpose'], $stages);
    header("Location: view_application.php?id={$appId}");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Apply for Loan - AgroLoan</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root {
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #2f855a;
    --brand-dark: #276749;
    --danger: #e53e3e;
    --bg: #f6f8fa;
    --text: #1f2937;
    --muted: #6b7280;
    --card-bg: #fff;
}
* { box-sizing: border-box; }
body {
    margin:0;
    font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    height:100vh;
    overflow:hidden;
    display:flex;
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
    overflow: hidden;
}
.sidebar.collapsed {
    width: var(--sidebar-collapsed-width);
    min-width: var(--sidebar-collapsed-width);
    padding-left: 10px;
    padding-right: 10px;
}

.brand {
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:6px;
}
.brand .logo {
    width:44px;
    height:44px;
    background: rgba(255,255,255,0.12);
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:18px;
}
.brand h2 {
    font-size:18px;
    margin:0;
    line-height:1;
    font-weight:600;
    transition:opacity .18s ease;
}
.sidebar.collapsed .brand h2 { opacity:0; width:0; margin:0; }

.nav {
    display:flex;
    flex-direction:column;
    gap:6px;
    margin-top:8px;
}
.nav a {
    display:flex;
    align-items:center;
    gap:12px;
    padding:10px;
    border-radius:8px;
    color:#fff;
    text-decoration:none;
    font-weight:500;
    transition: background .15s, transform .08s;
    white-space:nowrap;
    overflow:hidden;
}
.nav a .icon {
    width:36px;
    height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    border-radius:6px;
    background: rgba(255,255,255,0.06);
}
.nav a:hover { background: var(--brand-dark); transform: translateY(-1px); }
.nav a.active { background: rgba(0,0,0,0.12); }

.sidebar.collapsed .nav a { justify-content:center; padding:8px; }
.sidebar.collapsed .nav a .label { display:none; }

.sidebar .spacer { flex:1 1 auto; }

.logout-btn {
    background: var(--danger);
    border: none;
    color:#fff;
    padding:10px;
    border-radius:8px;
    cursor:pointer;
    width:100%;
    font-weight:600;
}
.sidebar.collapsed .logout-btn {
    padding:8px;
    width:48px;
    height:48px;
    border-radius:8px;
    align-self:center;
    display:flex;
    justify-content:center;
    align-items:center;
}
.sidebar.collapsed .logout-btn span.label { display:none; }

/* MAIN AREA */
.main {
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    height:100vh;
    overflow:auto;
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
    color:white;
    border:none;
    padding:8px 10px;
    border-radius:8px;
    font-size:18px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.user-greet {
    font-size:18px;
    font-weight:600;
}
.content-area {
    padding:28px 40px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:22px;
}

/* Form Card */
.form-card {
    background: var(--card-bg);
    padding: 15px;
    border-radius:12px;
    box-shadow: 0 4px 18px rgba(16,24,40,0.06);
    width:100%;
    max-width:700px;
    text-align:left;
}
.form-card h2 {
    color: var(--brand);
    margin-bottom:20px;
    text-align: center;
}
.form-card form {
    display:flex;
    flex-direction:column;
    gap:8px;
}
.form-card input, .form-card select, .form-card textarea {
    padding:10px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
}
.form-card button {
    background: var(--brand);
    color:white;
    font-weight:600;
    cursor:pointer;
    border:none;
    padding:12px;
    border-radius:8px;
    transition: background .2s;
}
.form-card button:hover {
    background: var(--brand-dark);
}
</style>
</head>

<body>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo"></a>
        <h2>AgroLoan Farmer</h2>
    </div>

    <nav class="nav">
        <a href="farmer_dashboard.php" class="<?= basename($_SERVER['PHP_SELF'])==='farmer_dashboard.php'?'active':'' ?>">
            <span class="icon">🏠</span><span class="label">Dashboard</span>
        </a>
        <a href="apply_loan.php" class="<?= basename($_SERVER['PHP_SELF'])==='apply_loan.php'?'active':'' ?>">
            <span class="icon">💰</span><span class="label">Apply for Loan</span>
        </a>
        <a href="view_application.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_application.php' ? 'active' : '' ?>">
            <span class="icon">📄</span>
            <span class="label">View Applications</span>
        </a>
        <a href="upload_proof.php" class="<?= basename($_SERVER['PHP_SELF'])==='upload_proof.php'?'active':'' ?>">
            <span class="icon">📸</span><span class="label">Upload Proof</span>
        </a>
        <a href="farmer_profile.php" class="<?= basename($_SERVER['PHP_SELF'])==='farmer_profile.php'?'active':'' ?>">
            <span class="icon">⚙️</span><span class="label">Profile</span>
        </a>
    </nav>

    <div class="spacer"></div>
    <form method="POST" action="logout.php">
        <button class="logout-btn"><span class="icon">🚪</span><span class="label">Logout</span></button>
    </form>
</aside>

<main class="main" id="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
    </div>

    <div class="content-area">
        <div class="form-card">
            <h2>Apply for a New Loan</h2>
            <form method="POST">
                <input type="text" name="title" placeholder="Loan Title" required>

                <select name="agent_id" placeholder="Agent" required>
                    <option value="">Select Agent</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['id'] ?>">
                            <?= htmlspecialchars($a['name']) ?> (<?= $a['interest_rate'] ?>% interest)
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="number" name="amount" placeholder="Total Loan Amount" step="1000" required>

                <textarea name="purpose" rows="5" placeholder="Purpose" required></textarea>

                <?php for ($i = 1; $i <= 3; $i++): ?>
                    <input type="number" name="stage_<?= $i ?>_amount" placeholder="Stage <?= $i ?> Amount" step="1000">
                <?php endfor; ?>

                <button type="submit">Submit Application</button>
            </form>
        </div>
    </div>
</main>

<script>
const sidebar = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');
toggleBtn.addEventListener('click', function(){
    sidebar.classList.toggle('collapsed');
    const collapsed = sidebar.classList.contains('collapsed');
    toggleBtn.setAttribute('aria-pressed', collapsed);
});
</script>
</body>
</html>
