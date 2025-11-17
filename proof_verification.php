<?php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/loan.php';
require_once __DIR__ . '/../src/upload.php';

$pdo = getPDO();

// Ensure logged-in agent
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'agent') {
    header("Location: login.php");
    exit;
}

$agent_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Agent';

/* ✅ Handle proof verification */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proof_id'], $_POST['action']) && !isset($_POST['disbursement_action'])) {
    $proof_id = (int) $_POST['proof_id'];
    $action = ($_POST['action'] === 'approve') ? 'verified' : 'rejected';

    $stmt = $pdo->prepare("
        UPDATE stage_proofs 
        SET status = ? 
        WHERE id = ? 
          AND stage_id IN (
            SELECT id FROM loan_stages 
            WHERE application_id IN (
                SELECT id FROM loan_applications WHERE agent_id = ?
            )
        )
    ");

    if ($stmt->execute([$action, $proof_id, $agent_id])) {
        if ($action === 'verified') {
            $stageStmt = $pdo->prepare("SELECT stage_id FROM stage_proofs WHERE id = ?");
            $stageStmt->execute([$proof_id]);
            $stage = $stageStmt->fetchColumn();
            if ($stage) {
                $pdo->prepare("UPDATE loan_stages SET status = 'awaiting_disbursement' WHERE id = ?")
                    ->execute([$stage]);
            }
        }
        $_SESSION['flash_success'] = ($action === 'verified')
            ? 'Proof verified successfully! Disbursement now awaits approval.'
            : 'Proof rejected successfully.';
    } else {
        $_SESSION['flash_error'] = 'Failed to update proof status.';
    }

    header("Location: proof_verification.php");
    exit;
}

/* ✅ Flash messages */
$successMessage = $_SESSION['flash_success'] ?? '';
$errorMessage = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* ✅ Fetch applications assigned to this agent */
$stmt = $pdo->prepare("
    SELECT la.*, u.name AS farmer_name
    FROM loan_applications la
    INNER JOIN users u ON la.farmer_id = u.id
    WHERE la.agent_id = ?
    ORDER BY la.id DESC
");
$stmt->execute([$agent_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Proof Verification | AgroLoan</title>
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

/* SIDEBAR (unchanged) */
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
    transition: opacity .18s ease;
}
.sidebar.collapsed .brand h2 {opacity:0;width:0;margin:0;}

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
.toggle-btn {
    background: var(--brand);
    color: #fff;
    border: none;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 18px;
    cursor: pointer;
}
.user-greet {
    font-weight: 600;
}

/* CONTENT AREA - NEW DESIGN */
.content-area {
    padding: 30px 50px;
    background: var(--bg);
    display: flex;
    flex-direction: column;
    gap: 24px;
}

h2 {
    font-size: 24px;
    font-weight: 700;
    color: var(--brand);
    margin-bottom: 10px;
}

/* APPLICATION CARD */
.app-card {
    background: var(--card-bg);
    border-radius: 14px;
    padding: 22px 26px;
    box-shadow: 0 6px 16px rgba(0,0,0,0.05);
    transition: all 0.25s ease;
    border: 1px solid rgba(0,0,0,0.04);
}
.app-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.07);
}

.app-card h3 {
    font-size: 18px;
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.app-card p {
    margin: 6px 0 10px;
    color: var(--muted);
}

/* STAGE CARD */
.stage {
    background: #f9fafb;
    border-radius: 10px;
    padding: 14px 18px;
    margin-top: 14px;
    border: 1px solid #e5e7eb;
    transition: all 0.25s ease;
}
.stage:hover {
    background: #f3f6ff;
    border-color: #c7d2fe;
}
.stage h4 {
    margin: 0 0 10px;
    color: #1e3a8a;
    font-size: 16px;
}

/* STATUS COLORS */
.status {
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 13px;
}
.verified { background: #dcfce7; color: #166534; }
.rejected { background: #fee2e2; color: #b91c1c; }
.pending { background: #fef9c3; color: #92400e; }
.awaiting_disbursement { background: #e0f2fe; color: #075985; }
.disbursed { background: #bbf7d0; color: #065f46; }
.disbursement_rejected { background: #fee2e2; color: #7f1d1d; }

/* BUTTONS */
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
.approve-btn { background-color: #22c55e; }
.reject-btn { background-color: #ef4444; }
.disburse-btn { background-color: var(--accent); }

button:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

/* Proof list */
ul { list-style: none; padding: 0; margin: 0; }
li {
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}
li img {
    border-radius: 6px;
    border: 1px solid #ddd;
    transition: all 0.2s;
}
li img:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
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
        <a href="proof_verification.php" class="active"><span class="icon">📸</span><span class="label">Proof Verification</span></a>
        <a href="agent_approve_stage.php"><span class="icon">💰</span><span class="label">Disbursement</span></a>
        <a href="agent_profile.php"><span class="icon">⚙️</span><span class="label">Profile</span></a>
    </nav>
    <div class="spacer"></div>
    <form method="POST" action="logout.php">
        <button class="logout-btn" style="background:var(--danger);padding:10px;border-radius:8px;width:100%;">🚪 Logout</button>
    </form>
</aside>

<!-- MAIN -->
<main class="main" id="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 💼</div>
    </div>

    <div class="content-area">
        <h2>Proof Verification</h2>

        <?php if (empty($applications)): ?>
            <p>No loan applications found for your account.</p>
        <?php else: ?>
            <?php foreach ($applications as $app): ?>
                <div class="app-card">
                    <h3><?= htmlspecialchars($app['title'] ?? 'Untitled Loan') ?>  
                        <span class="status <?= htmlspecialchars($app['status']) ?>">
                            <?= htmlspecialchars($app['status']) ?>
                        </span>
                    </h3>
                    <p><strong>Farmer:</strong> <?= htmlspecialchars($app['farmer_name']) ?></p>

                    <?php
                    $st = $pdo->prepare("SELECT * FROM loan_stages WHERE application_id = ?");
                    $st->execute([$app['id']]);
                    $stages = $st->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($stages as $stage):
                        $stage_id = $stage['id'];
                        $stage_status = htmlspecialchars($stage['status']);
                    ?>
                    <div class="stage">
                        <h4>Stage <?= htmlspecialchars($stage['stage_number']) ?> :
                            <span class="status <?= $stage_status ?>"><?= $stage_status ?></span>
                        </h4>
                        <?php
                        $pstmt = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ?");
                        $pstmt->execute([$stage_id]);
                        $proofs = $pstmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php if ($proofs): ?>
                            <ul>
                                <?php foreach ($proofs as $proof): 
                                    $proof_id = (int)$proof['id'];
                                    $status = htmlspecialchars($proof['status']);
                                    $file_type = $proof['file_type'];
                                    $file_name = htmlspecialchars($proof['filename']);
                                    $file_path = "../uploads/app_{$app['id']}/stage_{$stage_id}/{$file_name}";
                                ?>
                                <li>
                                    <?php if (str_starts_with($file_type, 'image')): ?>
                                        <a href="<?= $file_path ?>" target="_blank"><img src="<?= $file_path ?>" style="max-width:80px;max-height:60px;border-radius:4px;"></a>
                                    <?php elseif (str_starts_with($file_type, 'video')): ?>
                                        <a href="<?= $file_path ?>" target="_blank">🎥 View Video</a>
                                    <?php else: ?>
                                        <a href="<?= $file_path ?>" target="_blank">📄 View File</a>
                                    <?php endif; ?>
                                     <span class="status <?= $status ?>"><?= $status ?></span>
                                    <?php if ($status === 'pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="proof_id" value="<?= $proof_id ?>">
                                            <button class="approve-btn" name="action" value="approve">Verify</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="proof_id" value="<?= $proof_id ?>">
                                            <button class="reject-btn" name="action" value="reject">Reject</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>No proofs uploaded yet for this stage.</p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
const sidebar = document.getElementById('sidebar');
document.getElementById('toggleBtn').addEventListener('click',()=> sidebar.classList.toggle('collapsed'));
<?php if (!empty($successMessage)): ?>
Swal.fire({icon:'success',title:'Success!',text:'<?= addslashes($successMessage) ?>',timer:2000,showConfirmButton:false});
<?php elseif (!empty($errorMessage)): ?>
Swal.fire({icon:'error',title:'Error',text:'<?= addslashes($errorMessage) ?>'});
<?php endif; ?>
</script>
</body>
</html>
