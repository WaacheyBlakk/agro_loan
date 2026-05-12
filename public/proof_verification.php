<?php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/loan.php';
require_once __DIR__ . '/../src/upload.php';

$pdo = getPDO();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'agent') {
    header('Location: login.php');
    exit;
}

$agent_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Agent';

/* Handle proof verification */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proof_id'], $_POST['action'])) {
    $proof_id = (int) $_POST['proof_id'];
    $action_input = $_POST['action']; 

    // 1. Determine statuses
    $new_proof_status = ($action_input === 'approve') ? 'verified' : 'rejected';

    try {
        $pdo->beginTransaction();

        // 2. Update the Proof Status
        $stmt = $pdo->prepare("UPDATE stage_proofs SET status = ? WHERE id = ?");
        $stmt->execute([$new_proof_status, $proof_id]);

        // 3. Check Stage Status
        if ($new_proof_status === 'verified') {
            // Find the associated stage ID
            $stageStmt = $pdo->prepare("SELECT stage_id FROM stage_proofs WHERE id = ?");
            $stageStmt->execute([$proof_id]);
            $stage_id = $stageStmt->fetchColumn();

            if ($stage_id) {
                // Check if there are any *other* pending proofs for this stage.
                $checkPending = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM stage_proofs 
                    WHERE stage_id = ? AND status = 'pending'
                ");
                $checkPending->execute([$stage_id]);
                $pendingCount = $checkPending->fetchColumn();

                if ($pendingCount == 0) {
                    $updateStage = $pdo->prepare("UPDATE loan_stages SET status = 'awaiting_disbursement' WHERE id = ?");
                    $updateStage->execute([$stage_id]);
                    $_SESSION['flash_success'] = 'Proof verified! Stage moved to Disbursement queue.';
                } else {
                    $_SESSION['flash_success'] = 'Proof verified! ' . $pendingCount . ' proofs remaining for this stage.';
                }
            }
        } else {
            $_SESSION['flash_success'] = 'Proof rejected.';
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_error'] = 'Database update failed: ' . $e->getMessage();
    }

    // Redirect to same page to refresh list
    header("Location: proof_verification.php");
    exit;
}

/* Flash messages */
$successMessage = $_SESSION['flash_success'] ?? '';
$errorMessage = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$stmt = $pdo->prepare("
    SELECT la.*, u.name AS farmer_name, u.id AS farmer_user_id
    FROM loan_applications la
    INNER JOIN users u ON la.farmer_id = u.id
    WHERE la.agent_id = ?
    AND EXISTS (
        SELECT 1 FROM loan_stages ls 
        JOIN stage_proofs sp ON sp.stage_id = ls.id 
        WHERE ls.application_id = la.id AND sp.status = 'pending'
    )
    ORDER BY la.id DESC
");
$stmt->execute([$agent_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proof Verification</title>
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

    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
        width: 100%;
    }

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

    .content-container { padding: 30px; }
    
    .proof-card {
        background: white;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 16px;
        box-shadow: var(--shadow);
        border: 1px solid #e5e7eb;
    }

    .proof-grid {
        display: grid; 
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); 
        gap: 15px; 
        margin-top: 15px;
    }

    .proof-item {
        border: 1px solid #e5e7eb; 
        padding: 15px; 
        border-radius: 12px; 
        text-align: center;
        background: #fff;
        position: relative;
        transition: all 0.2s;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        height: 100%;
    }
    
    .proof-item.pending {
        border-color: var(--secondary);
        box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.1);
    }

    .proof-item.processed {
        background: #f9fafb;
        border-color: #f3f4f6;
        opacity: 0.9;
    }

    .proof-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .proof-preview {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px 0;
        margin-bottom: 10px;
    }
    
    .proof-preview a {
        display: flex; 
        align-items: center; 
        gap: 8px; 
        color: var(--primary); 
        text-decoration: none; 
        font-weight: 500;
        padding: 8px 16px;
        background: #eff6ff;
        border-radius: 8px;
        transition: 0.2s;
    }
    .proof-preview a:hover { background: #dbeafe; }

    .proof-actions {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: auto;
        padding-top: 10px;
    }

    .action-btn {
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        color: white;
        transition: background 0.2s;
        flex: 1;
    }
    
    .btn-verify { background: var(--success); }
    .btn-verify:hover { background: #059669; }
    
    .btn-reject { background: var(--danger); }
    .btn-reject:hover { background: #dc2626; }

    .proof-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        width: 100%;
    }
    .badge-verified { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .badge-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    .history-section {
        margin-top: 25px;
        border-top: 1px solid #e5e7eb;
        padding-top: 15px;
    }

    details summary {
        cursor: pointer;
        font-weight: 600;
        color: var(--text-muted);
        list-style: none; 
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 10px;
        background: #f8fafc;
        border-radius: 8px;
        transition: background 0.2s;
    }
    
    details summary:hover { background: #e2e8f0; color: var(--text-main); }
    details summary::after { content: '+'; margin-left: auto; font-weight: bold; font-size: 18px; }
    details[open] summary::after { content: '-'; }

    .history-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
    .history-table th, .history-table td { text-align: left; padding: 10px; border-bottom: 1px solid #f3f4f6; }
    .history-table th { color: var(--text-muted); font-weight: 500; }

    .status-badge { padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
    .st-verified { background: #d1fae5; color: #065f46; }
    .st-rejected { background: #fee2e2; color: #991b1b; }

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
            <a href="proof_verification.php" class="nav-link active">
                <i data-feather="check-square"></i>
                <span>Proof Verify</span>
            </a>
            <a href="agent_approve_stage.php" class="nav-link">
                <i data-feather="dollar-sign"></i>
                <span>Disbursement</span>
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
            <h1 style="margin-top:0;">Proof Verification</h1>
            <p style="color:var(--text-muted);">Review pending proofs. Processed proofs for active stages are also shown below.</p>
            <hr style="border:0; border-top:1px solid #e5e7eb; margin: 20px 0;">

            <?php if (empty($applications)): ?>
                <div style="text-align:center; padding: 40px; color: var(--text-muted);">
                    <i data-feather="check-circle" style="width:40px; height:40px; margin-bottom:10px;"></i>
                    <p>No pending proofs found. Great job!</p>
                </div>
            <?php else: ?>
                <?php foreach ($applications as $app): ?>
                    <div class="proof-card">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div>
                                <h3 style="margin:0; font-size:18px; color:var(--primary);"><?= htmlspecialchars($app['title']) ?></h3>
                                <div style="font-size:14px; margin-top:4px;">
                                    <span style="color:var(--text-muted);">Farmer:</span> 
                                    <strong><?= htmlspecialchars($app['farmer_name']) ?></strong>
                                </div>
                            </div>
                            <span style="background:#eff6ff; color:#1d4ed8; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600;">
                                App ID: #<?= $app['id'] ?>
                            </span>
                        </div>

                        <?php
                        $s_sql = "
                            SELECT ls.* 
                            FROM loan_stages ls 
                            JOIN stage_proofs sp ON sp.stage_id = ls.id
                            WHERE ls.application_id = ?
                            GROUP BY ls.id
                            ORDER BY ls.stage_number ASC
                        ";
                        $st = $pdo->prepare($s_sql);
                        $st->execute([$app['id']]);
                        $stages = $st->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php foreach ($stages as $stage): ?>
                            <div style="margin-top:20px; padding-top:15px; border-top:1px dashed #e5e7eb;">
                                <div style="font-weight:600; color:var(--text-main); margin-bottom:10px;">
                                    Stage <?= htmlspecialchars($stage['stage_number']) ?> Documents
                                </div>

                                <?php
                                // Fetch ALL proofs for this stage, not just pending
                                // ORDER BY: Pending first, then by ID
                                $p_stmt = $pdo->prepare("
                                    SELECT * FROM stage_proofs 
                                    WHERE stage_id = ? 
                                    ORDER BY (status = 'pending') DESC, id DESC
                                ");
                                $p_stmt->execute([$stage['id']]);
                                $proofs = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>

                                <div class="proof-grid">
                                    <?php foreach ($proofs as $proof): ?>
                                        <?php 
                                        $encodedFilename = rawurlencode($proof['filename']);
                                        $filePath = "../uploads/app_{$app['id']}/stage_{$stage['id']}/{$encodedFilename}"; 
                                        $isImage = str_starts_with($proof['file_type'], 'image');
                                        
                                        // Styling classes based on status
                                        $isPending = ($proof['status'] === 'pending');
                                        $cardClass = $isPending ? 'pending' : 'processed';
                                        ?>

                                        <div class="proof-item <?= $cardClass ?>">
                                            <!-- File Preview Link -->
                                            <div class="proof-preview">
                                                <a href="<?= $filePath ?>" target="_blank">
                                                    <i data-feather="<?= $isImage ? 'image' : 'file-text' ?>" style="width:16px;"></i>
                                                    View <?= $isImage ? 'Image' : 'File' ?>
                                                </a>
                                            </div>

                                            <div style="font-size:12px; color:#6b7280; margin-bottom:10px;">
                                                Uploaded: <?= date('M d, H:i', strtotime($proof['created_at'])) ?>
                                            </div>

                                            <?php if ($isPending): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="proof_id" value="<?= $proof['id'] ?>">
                                                    <div class="proof-actions">
                                                        <button type="submit" name="action" value="approve" class="action-btn btn-verify">
                                                            Verify
                                                        </button>
                                                        <button type="submit" name="action" value="reject" class="action-btn btn-reject">
                                                            Reject
                                                        </button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <div class="proof-actions">
                                                    <?php if ($proof['status'] === 'verified'): ?>
                                                        <span class="proof-badge badge-verified">
                                                            <i data-feather="check" style="width:12px; height:12px;"></i> Verified
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="proof-badge badge-rejected">
                                                            <i data-feather="x" style="width:12px; height:12px;"></i> Rejected
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php
                            $h_sql = "
                                SELECT sp.*, ls.stage_number, ls.id as stage_id, la.title as loan_title, la.id as app_id
                                FROM stage_proofs sp
                                JOIN loan_stages ls ON sp.stage_id = ls.id
                                JOIN loan_applications la ON ls.application_id = la.id
                                WHERE la.farmer_id = ? 
                                AND la.id != ?  /* Exclude current app since we show it above */
                                AND sp.status != 'pending' 
                                ORDER BY la.id DESC, ls.stage_number DESC
                            ";
                            $hst = $pdo->prepare($h_sql);
                            $hst->execute([$app['farmer_user_id'], $app['id']]);
                            $historyProofs = $hst->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if(!empty($historyProofs)): ?>
                        <div class="history-section">
                            <details>
                                <summary>
                                    <span>
                                        <i data-feather="archive" style="width:18px; height:18px; vertical-align:middle; margin-right:5px;"></i>
                                        View Proofs from Previous Loans
                                    </span>
                                </summary>
                                <table class="history-table">
                                    <thead>
                                        <tr>
                                            <th>Loan Application</th>
                                            <th>Stage</th>
                                            <th>File</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historyProofs as $hp): ?>
                                            <?php
                                                $hpEncodedName = rawurlencode($hp['filename']);
                                                $hpPath = "../uploads/app_{$hp['app_id']}/stage_{$hp['stage_id']}/{$hpEncodedName}";
                                                $statusClass = ($hp['status'] === 'verified') ? 'st-verified' : 'st-rejected';
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($hp['loan_title']) ?> (#<?= $hp['app_id'] ?>)</td>
                                                <td>Stage <?= htmlspecialchars($hp['stage_number']) ?></td>
                                                <td><a href="<?= $hpPath ?>" target="_blank">View File</a></td>
                                                <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($hp['status']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        feather.replace();

        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        <?php if (!empty($successMessage)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?= addslashes($successMessage) ?>',
                showConfirmButton: false,
                timer: 2000,
                confirmButtonColor: '#1e40af'
            });
        <?php elseif (!empty($errorMessage)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?= addslashes($errorMessage) ?>',
                confirmButtonColor: '#ef4444'
            });
        <?php endif; ?>
    </script>
</body>
</html>