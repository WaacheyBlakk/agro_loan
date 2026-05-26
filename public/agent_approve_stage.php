<?php
session_start();
require_once __DIR__ . '/../src/db.php';
$pdo = getPDO();

// Ensure logged-in agent
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'agent') {
    header('Location: login.php');
    exit;
}

$agent_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Agent';

// Handle Flash Messages from Redirects
$message = $_SESSION['flash_msg'] ?? '';
$msgType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

/* ✅ Handle Disbursement */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['stage_id'])) {
    $stage_id = (int) $_POST['stage_id'];
    $action_input = $_POST['action'];
    
    // Determine status
    $new_status = ($action_input === 'approve') ? 'disbursed' : 'rejected';

    try {
        $pdo->beginTransaction();

        // 1. Verify stage is actually waiting for disbursement
        $checkStmt = $pdo->prepare("
            SELECT id, application_id, stage_number, status 
            FROM loan_stages 
            WHERE id = ? AND status = 'awaiting_disbursement'
        ");
        $checkStmt->execute([$stage_id]);
        $targetStage = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetStage) {
            throw new Exception("Stage is not ready for disbursement or already processed.");
        }

        // 2. Update Stage Status
        $stmt = $pdo->prepare("UPDATE loan_stages SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $stage_id]);

        // 3. Log Disbursement
        $dstmt = $pdo->prepare("
            INSERT INTO disbursements (stage_id, approved_by, status, date_approved) 
            VALUES (?, ?, ?, NOW())
        ");
        $dstmt->execute([$stage_id, $agent_id, $new_status]);

        // 4. Unlock Next Stage (if approved)
        if ($new_status === 'disbursed') {
            $unlockStmt = $pdo->prepare("
                UPDATE loan_stages 
                SET status = 'pending' 
                WHERE application_id = ? 
                AND stage_number = ? 
                AND status = 'locked'
            ");
            $unlockStmt->execute([$targetStage['application_id'], $targetStage['stage_number'] + 1]);
        }

        $pdo->commit();
        
        // Set Flash Message
        $_SESSION['flash_msg'] = ($new_status === 'disbursed') ? "Funds released successfully." : "Disbursement rejected.";
        $_SESSION['flash_type'] = ($new_status === 'disbursed') ? "success" : "error";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_msg'] = "Error: " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
    }

    // Redirect to prevent form resubmission
    header("Location: agent_approve_stage.php");
    exit;
}

/* ✅ Fetch Data - Get Stages ONLY (No Joins on proofs to prevent duplicates) */
$query = "
    SELECT 
        ls.id AS stage_id,
        ls.stage_number,
        ls.status AS stage_status,
        la.id AS application_id,
        la.title AS loan_title,
        u.name AS farmer_name
    FROM loan_stages ls
    INNER JOIN loan_applications la ON la.id = ls.application_id
    INNER JOIN users u ON u.id = la.farmer_id
    WHERE ls.status = 'awaiting_disbursement'
    ORDER BY la.id DESC, ls.stage_number ASC
";
$stages = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursement Approval</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root {
        --primary: #1e40af;       
        --primary-dark: #172554;  
        --secondary: #3b82f6;    
        
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #1f2937;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --success: #10b981;
        
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Poppins', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        display: flex;
        height: 100vh;
        overflow: hidden;
    }

    /* --- SIDEBAR --- */
    .sidebar {
        width: var(--sidebar-width);
        background: var(--primary-dark);
        color: #fff;
        display: flex;
        flex-direction: column;
        padding: 20px;
        transition: width 0.3s ease;
        z-index: 100;
        box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        flex-shrink: 0;
    }

    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 40px;
        padding-left: 5px;
        overflow: hidden;
    }

    .brand img {
        width: 40px; height: 40px; border-radius: 8px;
        object-fit: cover; border: 2px solid rgba(255,255,255,0.2);
    }

    .brand h2 {
        font-size: 20px; font-weight: 600; white-space: nowrap;
        opacity: 1; transition: opacity 0.2s; margin: 0;
    }

    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .nav-link {
        display: flex; align-items: center; gap: 14px;
        padding: 12px 15px; color: #dbeafe;
        text-decoration: none; border-radius: 10px;
        transition: all 0.2s ease; white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1); color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 12px; border-radius: 10px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 10px; font-family: inherit; font-weight: 600;
        transition: 0.2s; width: 100%;
    }

    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
        width: 100%;
    }

    /* TOPBAR */
    .topbar {
        background: var(--bg-card); padding: 15px 30px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50;
    }

    .toggle-btn {
        background: transparent; border: none; color: var(--text-muted);
        cursor: pointer; padding: 5px;
    }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary);
        color: white; border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* CONTENT */
    .content-container { padding: 30px; }
    
    .card {
        background: #fff; 
        border-radius: 16px; 
        padding: 24px; 
        box-shadow: var(--shadow);
        border: 1px solid #f0f0f0;
        display: flex;
        flex-direction: column;
        transition: transform 0.2s;
    }
    .card:hover { transform: translateY(-4px); }

    .badge-verified { 
        background: #dcfce7; color: #166534; 
        padding: 4px 10px; border-radius: 20px; 
        font-size: 12px; font-weight: 700; text-transform: uppercase;
    }

    /* Proof Section */
    .proof-box {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        padding: 10px;
        margin-top: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .proof-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin: 15px 0;
    }

    /* Action Buttons */
    .btn-approve {
        flex: 1; background: #1e40af; color: white; border: none; 
        padding: 12px; border-radius: 8px; cursor: pointer; 
        font-weight: 600; transition: background 0.2s;
    }
    .btn-approve:hover { background: #1e3a8a; }

    .btn-reject {
        width: 44px; background: #fee2e2; color: #ef4444; border: none; 
        border-radius: 8px; cursor: pointer; display: flex; 
        align-items: center; justify-content: center; transition: background 0.2s;
    }
    .btn-reject:hover { background: #fecaca; }

    /* RESPONSIVE */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
    }
    </style>
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Agent</h2>
        </div>

        <nav class="nav">
            <a href="agent_dashboard.php" class="nav-link">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            <a href="farmer_vetting.php" class="nav-link">
                <i data-feather="users"></i>
                <span>Farmer Vetting</span>
            </a>
            <a href="proof_verification.php" class="nav-link">
                <i data-feather="check-square"></i>
                <span>Proof Verify</span>
            </a>
            <a href="agent_approve_stage.php" class="nav-link active">
                <i data-feather="dollar-sign"></i>
                <span>Disbursement</span>
            </a>
            <a href="agent_repayments.php" class="nav-link">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="agent_profile.php" class="nav-link">
                <i data-feather="user"></i>
                <span>My Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <main class="main">
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Agent</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <div class="content-container">
            <h1 style="margin-top:0;">Pending Disbursement</h1>
            <p style="color:var(--text-muted); margin-bottom:20px;">Release funds for stages that have been successfully verified.</p>
            <hr style="border:0; border-top:1px solid #e5e7eb; margin-bottom:25px;">
            
            <?php if(empty($stages)): ?>
                <div style="text-align:center; padding: 50px; background: white; border-radius: 16px; box-shadow: var(--shadow);">
                    <i data-feather="dollar-sign" style="width:40px; height:40px; color:var(--text-muted); margin-bottom:10px;"></i>
                    <p style="color:var(--text-muted);">No stages awaiting disbursement. Verify proofs first.</p>
                </div>
            <?php else: ?>
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap:24px;">
                    <?php foreach ($stages as $stage): ?>
                    <div class="card">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <div>
                                <h3 style="margin:0; font-size:18px; color:var(--primary);"><?= htmlspecialchars($stage['loan_title']) ?></h3>
                                <div style="font-size:14px; margin-top:5px; color:var(--text-muted);">
                                    Farmer: <strong style="color:var(--text-main);"><?= htmlspecialchars($stage['farmer_name']) ?></strong>
                                </div>
                            </div>
                            <span class="badge-verified">Verified</span>
                        </div>
                        
                        <div style="margin-top:15px; font-size:15px; font-weight:500;">
                            Stage <?= $stage['stage_number'] ?> Approval
                        </div>

                        <!-- Proof List (Fetched per stage to handle multiples) -->
                        <div class="proof-list">
                            <?php 
                            $stmtProofs = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ? AND status = 'verified'");
                            $stmtProofs->execute([$stage['stage_id']]);
                            $proofs = $stmtProofs->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php foreach($proofs as $proof): 
                                $encodedFilename = rawurlencode($proof['filename']);
                                $filePath = "../uploads/app_{$stage['application_id']}/stage_{$stage['stage_id']}/{$encodedFilename}";
                                $isImage = str_starts_with($proof['file_type'], 'image');
                            ?>
                                <div class="proof-box">
                                    <i data-feather="<?= $isImage ? 'image' : 'file-text' ?>" style="color:var(--secondary);"></i>
                                    <div style="flex:1; overflow:hidden;">
                                        <div style="font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <?= htmlspecialchars($proof['filename']) ?>
                                        </div>
                                        <a href="<?= $filePath ?>" target="_blank" style="font-size:12px; color:var(--secondary); text-decoration:none; font-weight:600;">
                                            View Original
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ACTION: Disburse or Reject -->
                        <div style="margin-top:auto;">
                            <form method="POST" style="display:flex; gap:10px;">
                                <input type="hidden" name="stage_id" value="<?= $stage['stage_id'] ?>">
                                <button type="submit" name="action" value="approve" class="btn-approve">
                                    <i data-feather="check-circle" style="width:16px; display:inline; vertical-align:text-bottom; margin-right:5px;"></i>
                                    Release Funds
                                </button>
                                <button type="submit" name="action" value="reject" class="btn-reject" title="Reject">
                                    <i data-feather="x"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Sidebar Toggle Logic
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        // SweetAlert Logic
        <?php if (!empty($message)): ?>
            Swal.fire({
                icon: '<?= $msgType ?>',
                title: '<?= ($msgType === "success") ? "Success" : "Error" ?>',
                text: '<?= addslashes($message) ?>',
                confirmButtonColor: '<?= ($msgType === "success") ? "#10b981" : "#ef4444" ?>'
            });
        <?php endif; ?>
    </script>
</body>
</html>