<?php
// public/admin_disputes.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

// Administration Authentication Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? "Admin";
$pdo = getPDO();

$successMessage = '';
$errorMessage = '';

/**
 * Sends an HTML email notification.
 */
function send_email_notification($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: AgroLoan Admin Compliance <disputes@agroloan.com>" . "\r\n";
    return @mail($to, $subject, $message, $headers);
}

/**
 * Sends an SMS notification (Configurable with standard SMS Gateways).
 */
function send_sms_notification($phone, $message) {
    error_log("SMS dispatched to {$phone}: {$message}");
    return true;
}

// Handle Admin Resolution Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_dispute') {
    try {
        $dispute_id = intval($_POST['dispute_id'] ?? 0);
        $decision = trim($_POST['decision'] ?? '');
        $status = $_POST['status'] ?? 'resolved'; // 'resolved' or 'dismissed'

        if ($dispute_id <= 0 || empty($decision)) {
            throw new Exception("Please provide a decision explanation.");
        }

        if (!in_array($status, ['resolved', 'dismissed'])) {
            $status = 'resolved';
        }

        $pdo->beginTransaction();

        $upd = $pdo->prepare("UPDATE disputes SET status = ?, admin_decision = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$status, $decision, $dispute_id]);

        $pdo->commit();
        $successMessage = "The dispute has been updated, and the parties have been notified.";

        // Retrieve Participant details for Notifications
        $detailsStmt = $pdo->prepare("
            SELECT d.title, d.description,
                   uc.name AS creator_name, uc.email AS creator_email, uc.phone AS creator_phone,
                   ud.name AS defendant_name, ud.email AS defendant_email, ud.phone AS defendant_phone
            FROM disputes d
            JOIN users uc ON d.creator_id = uc.id
            JOIN users ud ON d.defendant_id = ud.id
            WHERE d.id = ?
        ");
        $detailsStmt->execute([$dispute_id]);
        $party = $detailsStmt->fetch(PDO::FETCH_ASSOC);

        if ($party) {
            $subject = "Update: AgroLoan Dispute Case Resolution";
            $email_body = "
                <h2>AgroLoan Dispute Resolution Alert</h2>
                <p>An administrative decision has been finalized regarding the dispute: '<strong>{$party['title']}</strong>'.</p>
                <p><strong>Administrative Determination:</strong><br>
                <em>{$decision}</em></p>
                <p><strong>Case Status Update:</strong> " . strtoupper($status) . "</p>
                <p>If you have any questions, please contact support.</p>
                <br>
                <p>Best regards,<br>AgroLoan Admin Team</p>
            ";

            $sms_text = "AgroLoan Notice: Dispute '{$party['title']}' status updated to '" . strtoupper($status) . "'. Decision: '{$decision}'.";

            // Notify Creator
            if (!empty($party['creator_email'])) {
                send_email_notification($party['creator_email'], $subject, $email_body);
            }
            if (!empty($party['creator_phone'])) {
                send_sms_notification($party['creator_phone'], $sms_text);
            }

            // Notify Defendant
            if (!empty($party['defendant_email'])) {
                send_email_notification($party['defendant_email'], $subject, $email_body);
            }
            if (!empty($party['defendant_phone'])) {
                send_sms_notification($party['defendant_phone'], $sms_text);
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = $e->getMessage();
    }
}

// Fetch all registered disputes
$disputesQuery = $pdo->query("
    SELECT d.*, 
           la.title AS loan_title, 
           uc.name AS creator_name, uc.role AS creator_role,
           ud.name AS defendant_name, ud.role AS defendant_role,
           de.filename AS evidence_filename, de.file_type AS evidence_type
    FROM disputes d
    JOIN loan_applications la ON d.loan_id = la.id
    JOIN users uc ON d.creator_id = uc.id
    JOIN users ud ON d.defendant_id = ud.id
    LEFT JOIN dispute_evidence de ON d.id = de.dispute_id
    ORDER BY d.created_at DESC
");
$all_disputes = $disputesQuery->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Disputes Audit Portal | AgroLoan Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    :root {
        --primary: #059669; /* Emerald 600 */
        --primary-dark: #576868ff;
        --secondary: #10b981; /* Emerald 500 */
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #111827;
        --text-muted: #6b7280;
        --danger: #ef4444;
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
    }

    .sidebar.collapsed {
        width: var(--sidebar-collapsed);
        padding: 20px 10px;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 40px;
        padding-left: 5px;
        overflow: hidden;
    }

    .brand img {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        object-fit: cover;
        border: 2px solid rgba(255,255,255,0.2);
    }

    .brand h2 {
        font-size: 20px;
        font-weight: 600;
        white-space: nowrap;
        opacity: 1;
        transition: opacity 0.2s;
        margin: 0;
    }

    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .nav-link {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 15px;
        color: #d1fae5; /* Light emerald */
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.2s ease;
        white-space: nowrap;
        font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1);
        color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary);
        color: #fff;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1);
        color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 12px;
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        font-family: inherit;
        font-weight: 600;
        transition: 0.2s;
        width: 100%;
    }

    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        position: relative;
    }

    /* TOPBAR */
    .topbar {
        background: var(--bg-card);
        padding: 15px 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: var(--shadow);
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .toggle-btn {
        background: transparent;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 5px;
    }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .user-avatar {
        width: 35px;
        height: 35px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }

    /* CONTENT STYLING */
    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    .card {
        background: var(--bg-card); padding: 25px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #e2e8f0; margin-bottom: 30px;
    }

    /* Tables */
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
    th {
        font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--text-muted); font-weight: 600; background: #f9fafb;
    }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f9fafb; }

    .badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; text-transform: capitalize; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-resolved { background: #d1fae5; color: #065f46; }
    .badge-dismissed { background: #f3f4f6; color: #374151; }

    /* Action Form components */
    .action-panel { background: #fafafa; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 20px; margin-top: 15px; }
    textarea { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 14px; margin-bottom: 15px; resize: vertical; }
    select { padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 14px; margin-right: 15px; background: white; }
    
    .btn-action {
        background: #1e293b; color: white; border: none; padding: 10px 20px;
        border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;
        display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;
    }
    .btn-action:hover { background: var(--primary); }

    .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-success { background: #ecfdf5; color: #064e3b; border: 1px solid #a7f3d0; }
    
    .link { color: #2563eb; text-decoration: none; font-weight: 500; }
    .link:hover { text-decoration: underline; }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
    }
</style>
</head>
<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Administrator</h2>
        </div>

        <nav class="nav">
            <a href="admin_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="pie-chart"></i>
                <span>Dashboard</span>
            </a>
            <a href="admin_user_management.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_user_management.php' ? 'active' : '' ?>">
                <i data-feather="users"></i>
                <span>User Management</span>
            </a>
            <a href="admin_verifications.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_verifications.php' ? 'active' : '' ?>">
                <i data-feather="check-square"></i>
                <span>Verifications</span>
            </a>
            <a href="admin_loan_oversight.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_loan_oversight.php' ? 'active' : '' ?>">
                <i data-feather="shield"></i>
                <span>Loan Oversight</span>
            </a>
            <a href="admin_disputes.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_disputes.php' ? 'active' : '' ?>">
                <i data-feather="alert-triangle"></i>
                <span>Dispute Center</span>
            </a>
            <a href="admin_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_profile.php' ? 'active' : '' ?>">
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

    <!-- MAIN AREA -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- DISPUTES CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Disputes Audit Portal</h1>
                <p class="page-subtitle">Verify transactional disputes, evaluate uploaded evidence files, and issue formal system determinations.</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i data-feather="alert-circle"></i> <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i data-feather="check-circle"></i> <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>

            <div class="card" style="overflow-x:auto;">
                <h2 style="margin-top:0; font-size:18px; margin-bottom:15px;">Active Dispute Log Files</h2>
                <?php if (empty($all_disputes)): ?>
                    <p style="color: var(--text-muted); text-align:center; padding: 20px; font-size:14px;">No active dispute transactions are logged currently.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Case ID</th>
                                <th>Transaction Ref</th>
                                <th>Plaintiff (Uploader)</th>
                                <th>Defendant (Accused)</th>
                                <th>Dispute Case File</th>
                                <th>Evidence Doc</th>
                                <th>Case Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_disputes as $dis): ?>
                                <tr style="border-bottom: 2px solid #f1f5f9;">
                                    <td>#<?= $dis['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($dis['loan_title']) ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($dis['creator_name']) ?></strong><br>
                                        <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted);"><?= $dis['creator_role'] ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($dis['defendant_name']) ?></strong><br>
                                        <span style="font-size:11px; text-transform:uppercase; color:var(--text-muted);"><?= $dis['defendant_role'] ?></span>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:var(--primary);"><?= htmlspecialchars($dis['title']) ?></div>
                                        <div style="font-size:13px; color: #334155; margin-top:5px; max-width: 400px; line-height:1.4;">
                                            <?= htmlspecialchars($dis['description']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($dis['evidence_filename'])): ?>
                                            <?php 
                                            $filePath = "../uploads/disputes/dispute_{$dis['id']}/" . rawurlencode($dis['evidence_filename']); 
                                            ?>
                                            <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" class="link" style="display:inline-flex; align-items:center; gap:5px; font-size:13px;">
                                                <i data-feather="file" style="width:14px;"></i> View File
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted); font-size:13px;">No files provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $dis['status'] ?>">
                                            <?= str_replace('_', ' ', ucfirst($dis['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                                
                                <!-- Resolution Row: Active inputs when case is pending -->
                                <?php if ($dis['status'] === 'pending'): ?>
                                    <tr style="background:#fffcfc;">
                                        <td colspan="7">
                                            <div class="action-panel">
                                                <h4 style="margin:0 0 10px 0; font-size:14px; color:var(--primary);">Issue Final Administrative Resolution (#<?= $dis['id'] ?>)</h4>
                                                <form method="POST">
                                                    <input type="hidden" name="dispute_id" value="<?= $dis['id'] ?>">
                                                    <input type="hidden" name="action" value="resolve_dispute">
                                                    
                                                    <textarea name="decision" rows="3" placeholder="Provide the administrative findings and decisions here. Both parties will be alerted immediately..." required></textarea>
                                                    
                                                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                                                        <select name="status" required>
                                                            <option value="resolved">Mark Resolved</option>
                                                            <option value="dismissed">Dismiss Case File</option>
                                                        </select>
                                                        <button type="submit" class="btn-action">
                                                            <i data-feather="check-square"></i> Issue Resolution Order
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <!-- Logged decision history row -->
                                    <tr style="background:#fafafa;">
                                        <td colspan="7">
                                            <div style="padding:15px; border-left:4px solid var(--text-muted); font-size:13px;">
                                                <strong>Case History Decision:</strong><br>
                                                <span style="color:#334155; line-height:1.5;"><?= htmlspecialchars($dis['admin_decision']) ?></span><br>
                                                <small style="color:var(--text-muted); display:block; margin-top:5px;">Archived on: <?= date('M d, Y H:i', strtotime($dis['updated_at'])) ?></small>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
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
                sidebar.classList.toggle("active"); // Mobile behavior
            } else {
                sidebar.classList.toggle("collapsed"); // Desktop behavior
            }
        });
    </script>
</body>
</html>