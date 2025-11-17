<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/loan.php';
require_once __DIR__ . '/../src/upload.php';

$user = current_user();
if (!$user || $user['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$app = null;
$apps = [];

// If ID is provided, fetch single loan
if ($id) {
    $app = get_application($id);
    if (!$app) {
        echo "<p style='text-align:center;color:red;'>Application not found (ID: {$id}).</p>";
        exit;
    }
} else {
    // Fetch all farmer’s applications
    $stmt = $pdo->prepare("SELECT * FROM loan_applications WHERE farmer_id = ?");
    $stmt->execute([$user['id']]);
    $apps = $stmt->fetchAll();
}

// Handle proof upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    try {
        $stage_id = intval($_POST['stage_id']);
        $res = handle_stage_upload($stage_id, $user['id'], $_FILES['proof']);
        $msg = "Uploaded: " . htmlspecialchars($res['filename']);
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// Automatically update disbursement after agent approval
if ($id && $app) {
    foreach ($app['stages'] as $stage) {
        // Check if the agent approved and not yet disbursed
        if ($stage['status'] === 'approved' && empty($stage['disbursed'])) {

            // Mark the stage as disbursed
            $stmt = $pdo->prepare("
                UPDATE loan_stages 
                SET disbursed = 1 
                WHERE id = ?
            ");
            $stmt->execute([$stage['id']]);

            // Update total disbursed amount and advance current stage
            $stmt2 = $pdo->prepare("
                UPDATE loan_applications 
                SET 
                    disbursed_amount = disbursed_amount + ?,
                    current_stage = current_stage + 1
                WHERE id = ?
            ");
            $stmt2->execute([$stage['required_amount'], $app['id']]);

            // Update application status if all stages are completed
            $stmt3 = $pdo->prepare("
                SELECT COUNT(*) AS remaining 
                FROM loan_stages 
                WHERE application_id = ? AND disbursed = 0
            ");
            $stmt3->execute([$app['id']]);
            $remaining = $stmt3->fetchColumn();

            if ($remaining == 0) {
                $pdo->prepare("
                    UPDATE loan_applications 
                    SET status = 'completed'
                    WHERE id = ?
                ")->execute([$app['id']]);
            }
        }
    }

    // Refresh application data after update
    $app = get_application($id);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Applications | AgroLoan</title>
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
    border-radius:8px;
    object-fit:cover;
}
.brand h2 {
    font-size:18px;
    margin:0;
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
.content-area {
    padding:28px 40px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:22px;
}

/* PROOF GALLERY STYLES */
.proof-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
    margin-top: 12px;
}
.proof-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.proof-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.proof-thumb {
    width: 100%;
    height: 120px;
    object-fit: cover;
    display: block;
    background: #f9fafb;
}
.proof-info {
    padding: 8px 10px;
    font-size: 13px;
    color: #333;
}
.proof-name {
    font-weight: 600;
    margin-bottom: 4px;
    overflow-wrap: anywhere;
}
.proof-status {
    display: inline-block;
    font-size: 12px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 6px;
    color: white;
}
.proof-status.verified { background: #16a34a; }
.proof-status.rejected { background: #dc2626; }
.proof-status.pending,
.proof-status[""] { background: #f59e0b; }
.proof-date {
    color: #6b7280;
    font-size: 12px;
    margin-top: 3px;
}
.proof-link {
    display: block;
    text-align: center;
    padding: 40px 0;
    color: #2563eb;
    font-weight: 600;
    text-decoration: none;
}
.proof-link:hover {
    text-decoration: underline;
}

/* Cards and Tables */
.app-card {
    background: var(--card-bg);
    padding: 20px;
    border-radius:12px;
    box-shadow: 0 4px 18px rgba(16,24,40,0.06);
    width:100%;
    max-width:900px;
}
.app-card h2 {
    color: var(--brand);
    margin-bottom:15px;
}
.stage {
    border-top:1px solid #e5e7eb;
    padding-top:12px;
    margin-top:12px;
}
.stage h4 {
    color: var(--brand-dark);
}
form {
    display:flex;
    flex-direction:column;
    gap:10px;
    margin-top:10px;
}
form input, form textarea, form select {
    padding:10px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
}
form button {
    background: var(--brand);
    color:white;
    font-weight:600;
    border:none;
    padding:10px;
    border-radius:8px;
    cursor:pointer;
    transition: background .2s;
}
form button:hover { background: var(--brand-dark); }

table {
    width:100%;
    border-collapse:collapse;
}
table th, table td {
    border:1px solid #ddd;
    padding:10px;
}
table th {
    background:#f1f5f9;
    color:#1f2937;
}
table tr:hover { background:#f9fafb; }
a {
    color: var(--brand-dark);
    text-decoration:none;
}
.table a:hover { text-decoration:underline; }
.message.success {
    color:green;
}
.message.error {
    color:red;
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

<main class="main">
    <div class="topbar">
        <div class="left-controls">
            <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
            <div class="user-greet"></div>
        </div>
    </div>
    <div class="content-area">
        <h2>My Loan Applications</h2>
        <?php if ($id && $app): ?>
            <div class="app-card">
                <h3><?= htmlspecialchars($app['title']) ?></h3>
                <p><strong>Status:</strong> <?= htmlspecialchars($app['status']) ?><br>
                <strong>Current Stage:</strong> <?= htmlspecialchars($app['current_stage']) ?><br>
                <strong>Total Disbursed:</strong> ₵<?= htmlspecialchars($app['disbursed_amount']) ?></p>

                <?php foreach($app['stages'] as $stage): ?>
                    <div class="stage">
                        <h4>Stage <?= $stage['stage_number'] ?> : ₵<?= $stage['required_amount'] ?> — <?= ucfirst($stage['status']) ?></h4>
                        <p>Disbursed: <?= $stage['disbursed'] ? "✅ Yes" : "❌ No" ?></p>

                        <?php
                            $pstmt = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ?");
                            $pstmt->execute([$stage['id']]);
                            $proofs = $pstmt->fetchAll();
                        ?>
                        <?php if ($proofs): ?>
                            <div class="proof-gallery">
                                <?php foreach($proofs as $pf): ?>
                                    <div class="proof-card">
                                        <?php
                                            $filePath = "../uploads/app_{$app['id']}/stage_{$stage['id']}/" . htmlspecialchars($pf['filename']);
                                            $fileType = $pf['file_type'];
                                        ?>
                                        <?php if ($fileType === 'image'): ?>
                                            <img src="<?= $filePath ?>" alt="Proof Image" class="proof-thumb">
                                        <?php elseif ($fileType === 'video'): ?>
                                            <video controls class="proof-thumb">
                                                <source src="<?= $filePath ?>" type="video/mp4">
                                                Your browser does not support the video tag.
                                            </video>
                                        <?php else: ?>
                                            <a href="<?= $filePath ?>" target="_blank" class="proof-link">📄 View File</a>
                                        <?php endif; ?>
                                        <div class="proof-info">
                                            <p class="proof-name"><?= htmlspecialchars($pf['filename']) ?></p>
                                            <span class="proof-status <?= htmlspecialchars($pf['status']) ?>">
                                                <?= ucfirst($pf['status'] ?: 'Pending Review') ?>
                                            </span>
                                            <p class="proof-date">Uploaded: <?= date('M d, Y', strtotime($pf['uploaded_at'])) ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (in_array($stage['status'], ['awaiting_proof','pending'])): ?>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>"/>
                                <input type="file" name="proof" required/>
                                <button>Upload Proof</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($msg)): ?><p class="message success"><?= $msg ?></p><?php endif; ?>
                <?php if (!empty($err)): ?><p class="message error"><?= $err ?></p><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="app-card">
                <?php if (empty($apps)): ?>
                    <p>No applications found.</p>
                <?php else: ?>
                    <table border="1" cellspacing="0" cellpadding="8" width="100%">
                        <tr><th>ID</th><th>Title</th><th>Status</th><th>Current Stage</th><th>Action</th></tr>
                        <?php foreach ($apps as $ap): ?>
                        <tr>
                            <td><?= $ap['id'] ?></td>
                            <td><?= htmlspecialchars($ap['title']) ?></td>
                            <td><?= htmlspecialchars($ap['status']) ?></td>
                            <td><?= htmlspecialchars($ap['current_stage']) ?></td>
                            <td><a href="view_application.php?id=<?= $ap['id'] ?>">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
