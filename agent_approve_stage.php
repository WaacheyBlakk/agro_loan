<?php
require_once '../src/db.php';
session_start();

// Ensure logged-in agent
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$username = $_SESSION['name'] ?? 'Agent';
$message = '';

/* -------------------------------
    Handle Disbursement Actions
--------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['stage_id'])) {
    $stage_id = (int) $_POST['stage_id'];
    $agent_id = $_SESSION['user_id'] ?? 0;
    $action = ($_POST['action'] === 'approve') ? 'disbursed' : 'rejected';

    try {
        $pdo->beginTransaction();

        // Update loan_stages table
        $stmt = $pdo->prepare("UPDATE loan_stages SET status = ? WHERE id = ?");
        $stmt->execute([$action, $stage_id]);

        // Record disbursement in separate table
        $dstmt = $pdo->prepare("
            INSERT INTO disbursements (stage_id, approved_by, status, date_approved)
            VALUES (?, ?, ?, NOW())
        ");
        $dstmt->execute([$stage_id, $agent_id, $action]);

        $pdo->commit();
        $message = ($action === 'disbursed')
            ? "✅ Stage funds disbursed successfully."
            : "❌ Disbursement rejected.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "⚠️ Error: " . $e->getMessage();
    }
}

/* -------------------------------
    Fetch Undisbursed Stages
--------------------------------*/
$query = "
    SELECT 
        ls.id AS stage_id,
        ls.stage_number,
        ls.status,
        la.id AS application_id,
        la.title AS loan_title,
        u.name AS farmer_name,
        sp.filename,
        sp.file_type
    FROM loan_stages ls
    INNER JOIN loan_applications la ON la.id = ls.application_id
    INNER JOIN users u ON u.id = la.farmer_id
    LEFT JOIN stage_proofs sp ON sp.stage_id = ls.id
    WHERE ls.status IN ('verified', 'approved') 
      AND ls.id NOT IN (SELECT stage_id FROM disbursements WHERE status = 'disbursed')
    ORDER BY la.id DESC, ls.stage_number ASC
";
$stages = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agent Disbursement | AgroLoan</title>
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
    --card-bg: #fff;
    --accent: #2563eb;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: "Segoe UI", Roboto, Arial, sans-serif;
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
}
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

/* MAIN AREA */
.main {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: auto;
}
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    background: #fff;
    box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    position: sticky;
    top: 0;
    z-index: 10;
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
.user-greet { font-weight: 600; }

/* CONTENT */
.content-area {
    padding: 28px 40px;
    display: flex;
    flex-direction: column;
    gap: 22px;
}
.stage-card {
    background: var(--card-bg);
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(16,24,40,0.06);
    padding: 20px;
}
.stage-card h3 { color: var(--brand); }
.proof-preview {
    margin: 10px 0;
    border: 1px solid #ddd;
    padding: 8px;
    background: #fafafa;
    border-radius: 8px;
}
.proof-preview img, .proof-preview video {
    max-width: 100%;
    border-radius: 8px;
}
.actions button {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    margin-right: 10px;
    color: white;
    font-weight: 600;
}
.approve-btn { background: #16a34a; }
.reject-btn { background: #dc2626; }
.message { text-align:center; font-weight:600; color:#333; margin-bottom: 20px; }
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo">
        <h2>AgroLoan Agent</h2>
    </div>
    <nav class="nav">
        <a href="agent_dashboard.php"><span class="icon">🏠</span><span class="label">Dashboard</span></a>
        <a href="farmer_vetting.php"><span class="icon">👨‍🌾</span><span class="label">Farmer Vetting</span></a>
        <a href="proof_verification.php"><span class="icon">📸</span><span class="label">Proof Verification</span></a>
        <a href="agent_approve_stage.php" class="active"><span class="icon">💰</span><span class="label">Disbursement</span></a>
        <a href="agent_profile.php"><span class="icon">⚙️</span><span class="label">Profile</span></a>
    </nav>
    <div class="spacer"></div>
    <form method="POST" action="logout.php">
        <button class="logout-btn"><span class="icon">🚪</span><span class="label">Logout</span></button>
    </form>
</aside>

<!-- MAIN -->
<main class="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn">☰</button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 💼</div>
    </div>

    <div class="content-area">
        <h2>Pending Disbursements</h2>

        <?php if (!empty($message)): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (empty($stages)): ?>
            <p>No undisbursed stages available at the moment.</p>
        <?php else: ?>
            <?php foreach ($stages as $stage): ?>
                <div class="stage-card">
                    <h3><?= htmlspecialchars($stage['loan_title'] ?? 'Loan Application') ?> — Stage <?= htmlspecialchars($stage['stage_number']) ?></h3>
                    <p><strong>Farmer:</strong> <?= htmlspecialchars($stage['farmer_name']) ?></p>
                    <p><strong>Status:</strong> <?= htmlspecialchars($stage['status']) ?></p>

                    <?php if ($stage['filename']): ?>
                        <div class="proof-preview">
                            <?php
                            $filePath = "../uploads/app_{$stage['application_id']}/stage_{$stage['stage_id']}/" . htmlspecialchars($stage['filename']);
                            if (str_starts_with($stage['file_type'], 'image')): ?>
                                <img src="<?= $filePath ?>" alt="Proof Image">
                            <?php elseif (str_starts_with($stage['file_type'], 'video')): ?>
                                <video controls><source src="<?= $filePath ?>" type="<?= htmlspecialchars($stage['file_type']) ?>"></video>
                            <?php else: ?>
                                <a href="<?= $filePath ?>" target="_blank">📄 View File</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="actions">
                        <input type="hidden" name="stage_id" value="<?= $stage['stage_id'] ?>">
                        <button type="submit" name="action" value="approve" class="approve-btn">Disburse</button>
                        <button type="submit" name="action" value="reject" class="reject-btn">Reject</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
const sidebar = document.getElementById('sidebar');
document.getElementById('toggleBtn').addEventListener('click',()=> sidebar.classList.toggle('collapsed'));
<?php if (!empty($message)): ?>
Swal.fire({
    icon: 'info',
    title: 'Update',
    text: '<?= addslashes($message) ?>',
    timer: 2500,
    showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>
