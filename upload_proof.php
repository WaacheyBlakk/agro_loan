<?php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];

$pdo = getPDO();

// Handle file upload
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    $loan_id = $_POST['loan_id'] ?? null;
    $file = $_FILES['proof'];

    if ($loan_id && $file['error'] === UPLOAD_ERR_OK) {
        $target_dir = __DIR__ . "/../uploads/proofs/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $filename = time() . "_" . basename($file['name']);
        $target_path = $target_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $stmt = $pdo->prepare("INSERT INTO stage_proofs (stage_id, farmer_id, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$loan_id, $farmer_id, $filename]);
            $message = "✅ Proof uploaded successfully!";
        } else {
            $message = "❌ Failed to upload file.";
        }
    } else {
        $message = "⚠️ Please select a loan and a valid file.";
    }
}

// Fetch farmer's loans for dropdown
$stmt = $pdo->prepare("SELECT id, title FROM loan_applications WHERE farmer_id = ?");
$stmt->execute([$farmer_id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upload Proof - Agro Loan</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
/* --- Include same style from farmer_dashboard --- */
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

/* SIDEBAR (same as dashboard) */
.sidebar { width: var(--sidebar-width); min-width: var(--sidebar-width); background: var(--brand); color:#fff; display:flex; flex-direction:column; padding:18px; gap:8px; transition: width .28s ease, padding .2s ease; overflow:hidden; }
.sidebar.collapsed { width: var(--sidebar-collapsed-width); min-width: var(--sidebar-collapsed-width); padding-left:10px; padding-right:10px; }
.brand { display:flex; align-items:center; gap:12px; margin-bottom:6px; }
.brand .logo { width:44px; height:44px; background:rgba(255,255,255,0.12); border-radius:8px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:18px; }
.brand h2 { font-size:18px; margin:0; font-weight:600; transition:opacity .18s ease; }
.sidebar.collapsed .brand h2 { opacity:0; width:0; margin:0; }
.nav { display:flex; flex-direction:column; gap:6px; margin-top:8px; }
.nav a { display:flex; align-items:center; gap:12px; padding:10px; border-radius:8px; color:#fff; text-decoration:none; font-weight:500; transition: background .15s, transform .08s; white-space:nowrap; overflow:hidden; }
.nav a .icon { width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; font-size:18px; border-radius:6px; background:rgba(255,255,255,0.06); }
.nav a:hover { background: var(--brand-dark); transform: translateY(-1px); }
.nav a.active { background: rgba(0,0,0,0.12); }
.sidebar.collapsed .nav a { justify-content:center; padding:8px; }
.sidebar.collapsed .nav a .label { display:none; }
.sidebar .spacer { flex:1 1 auto; }
.logout-btn { background: var(--danger); border:none; color:#fff; padding:10px; border-radius:8px; cursor:pointer; width:100%; font-weight:600; }
.sidebar.collapsed .logout-btn { padding:8px; width:48px; height:48px; border-radius:8px; display:flex; align-items:center; justify-content:center; }
.sidebar.collapsed .logout-btn span.label { display:none; }

/* MAIN AREA */
.main { flex:1 1 auto; display:flex; flex-direction:column; height:100vh; overflow:auto; }
.topbar { display:flex; align-items:center; justify-content:space-between; padding:20px; border-bottom:1px solid rgba(15,23,42,0.04); }
.left-controls { display:flex; align-items:center; gap:12px; }
.toggle-btn { background: var(--brand); color:white; border:none; padding:8px 10px; border-radius:8px; font-size:18px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
.user-greet { font-size:18px; font-weight:600; }
.content-area { padding:28px 40px; display:flex; flex-direction:column; align-items:center; gap:22px; }

/* Upload form */
.upload-card {
    background: var(--card-bg);
    padding:15px;
    border-radius:12px;
    box-shadow: 0 4px 18px rgba(16,24,40,0.06);
    width:100%;
    max-width:600px;
    text-align:center;
}
.upload-card h2 { color:var(--brand); margin-bottom:20px; }
.upload-card form { display:flex; flex-direction:column; gap:16px; }
.upload-card select, .upload-card input[type="file"], .upload-card button {
    padding:10px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
}
.upload-card button {
    background: var(--brand);
    color:white;
    font-weight:600;
    cursor:pointer;
    border:none;
    transition: background .2s;
}
.upload-card button:hover { background: var(--brand-dark); }
.message { font-weight:600; color:var(--brand-dark); }
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
        <div class="left-controls">
            <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
            <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 👩‍🌾</div>
        </div>
    </div>

    <div class="content-area" id="contentArea">
        <div class="upload-card">
            <h2>Upload Payment Proof</h2>
            <?php if ($message): ?>
                <p class="message"><?= htmlspecialchars($message) ?></p>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <label for="loan_id">Select Loan:</label>
                <select name="loan_id" id="loan_id" required>
                    <option value="">-- Choose Loan --</option>
                    <?php foreach ($loans as $loan): ?>
                        <option value="<?= $loan['id'] ?>"><?= htmlspecialchars($loan['title']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="proof">Select File:</label>
                <input type="file" name="proof" id="proof" accept=".jpg,.jpeg,.png,.pdf" required>

                <button type="submit">Upload Proof</button>
            </form>
        </div>
    </div>
</main>

<script>
const sidebar = document.getElementById('sidebar');
const toggleBtn = document.getElementById('toggleBtn');
const contentArea = document.getElementById('contentArea');

toggleBtn.addEventListener('click', function(){
    sidebar.classList.toggle('collapsed');
    const collapsed = sidebar.classList.contains('collapsed');
    toggleBtn.setAttribute('aria-pressed', collapsed);
});
</script>
</body>
</html>
