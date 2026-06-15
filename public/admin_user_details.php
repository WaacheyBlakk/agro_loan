<?php
session_start();
require_once '../src/db.php';
require_once '../src/sessions.php';

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// 2. Auth Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("Invalid user ID.");
}
$user_id = intval($_GET['id']);
$role = $_GET['role'] ?? '';

// Fetch base user from the appropriate table based on role
if ($role === 'buyer') {
    $stmt = $pdo->prepare("SELECT *, 'buyer' AS role FROM buyers WHERE id=? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user) { 
    die("User not found."); 
}

// Fetch extended profiles (only applicable for farmers and agents)
$profile = null;
if ($user['role'] === 'farmer') {
    $q = $pdo->prepare("SELECT * FROM farmer_profiles WHERE user_id=?");
    $q->execute([$user_id]);
    $profile = $q->fetch(PDO::FETCH_ASSOC);
} elseif ($user['role'] === 'agent') {
    $q = $pdo->prepare("SELECT * FROM agent_profiles WHERE user_id=?");
    $q->execute([$user_id]);
    $profile = $q->fetch(PDO::FETCH_ASSOC);
}

// Merge data for document checking
$profileData = [];
if (is_array($profile)) {
    foreach ($profile as $k => $v) {
        $profileData[$k] = $v;
    }
}
$fullData = array_merge($user, $profileData);

// Handle Form Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine status change based on table type
    if ($user['role'] === 'buyer') {
        $status = ($_POST['action'] === 'approve') ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE buyers SET status=? WHERE id=?");
    } else {
        $status = ($_POST['action'] === 'approve') ? 'verified' : 'rejected';
        $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=?");
    }
    
    if ($stmt->execute([$status, $user_id])) {
        $msg = "Account status has been updated to '" . $status . "' successfully.";
        header("Location: admin_verifications.php?msg=" . urlencode($msg));
        exit;
    }
}

// Helper for status colors
function getStatusColor($status) {
    switch(strtolower($status)) {
        case 'verified':
        case 'approved':
            return 'success';
        case 'rejected':
        case 'denied':
            return 'danger';
        default: 
            return 'warning';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Verification | Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Fonts & Icons -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    :root {
        --primary: #4f46e5;
        --primary-hover: #4338ca;
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border: #e2e8f0;
        
        --success: #10b981;
        --success-bg: #ecfdf5;
        --warning: #f59e0b;
        --warning-bg: #fffbeb;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
        
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Inter', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        padding-bottom: 60px;
        -webkit-font-smoothing: antialiased;
    }

    /* --- Top Navigation --- */
    .topbar {
        background: var(--bg-card);
        padding: 14px 32px;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 50;
        box-shadow: var(--shadow-sm);
    }

    .topbar-left {
        display: flex;
        justify-content: flex-start;
    }

    .topbar-center {
        text-align: center;
    }

    .topbar-right {
        display: flex;
        justify-content: flex-end;
    }

    .back-link {
        text-decoration: none;
        color: var(--text-muted);
        font-weight: 500;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background-color: var(--bg-card);
        transition: all 0.2s ease;
    }
    
    .back-link i {
        font-size: 12px;
        transition: transform 0.2s ease;
    }

    .back-link:hover {
        color: var(--text-main);
        background-color: #f1f5f9;
        border-color: #cbd5e1;
    }

    .back-link:hover i {
        transform: translateX(-3px);
    }

    .page-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-main);
        margin: 0;
    }

    /* --- Layout Grid --- */
    .container {
        max-width: 1200px;
        margin: 32px auto;
        padding: 0 24px;
        display: grid;
        grid-template-columns: 340px 1fr;
        gap: 32px;
    }

    /* --- Sidebar Card --- */
    .card {
        background: var(--bg-card);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .profile-sidebar {
        padding: 32px 24px;
        text-align: center;
    }

    .avatar-circle {
        width: 88px;
        height: 88px;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 36px;
        font-weight: 600;
        margin: 0 auto 16px;
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);
    }

    .user-name { 
        font-size: 20px; 
        font-weight: 700; 
        margin-bottom: 6px; 
        color: var(--text-main);
    }
    
    .user-role { 
        font-size: 13px; 
        color: var(--text-muted); 
        text-transform: uppercase; 
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-bottom: 20px; 
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 14px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-success { background: var(--success-bg); color: var(--success); }
    .status-warning { background: var(--warning-bg); color: var(--warning); }
    .status-danger { background: var(--danger-bg); color: var(--danger); }

    .info-list {
        margin-top: 28px;
        text-align: left;
        border-top: 1px solid var(--border);
        padding-top: 24px;
    }
    .info-item {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        font-size: 14px;
        color: var(--text-main);
    }
    .info-item i { color: var(--text-muted); width: 16px; font-size: 15px; }

    /* --- Main Content --- */
    .section-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
        background: #f8fafc;
    }
    .section-header h3 { 
        margin: 0; 
        font-size: 15px; 
        font-weight: 600; 
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-main);
    }

    .content-padding { padding: 28px; }

    .data-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 24px;
    }

    .data-box label {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 600;
        margin-bottom: 6px;
        letter-spacing: 0.5px;
    }
    .data-box span {
        font-size: 15px;
        font-weight: 500;
        color: var(--text-main);
    }

    /* --- Documents --- */
    .docs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 20px;
    }

    .doc-card {
        border: 1px solid var(--border);
        border-radius: 10px;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        cursor: pointer;
        background: var(--bg-card);
    }
    .doc-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }

    .doc-thumb {
        height: 140px;
        width: 100%;
        object-fit: cover;
        background: #f1f5f9;
    }
    .doc-pdf {
        height: 140px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff1f2;
        color: #e11d48;
        font-size: 42px;
    }
    .doc-footer {
        padding: 12px;
        background: white;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-main);
        border-top: 1px solid var(--border);
        text-align: center;
    }

    /* --- Action Buttons --- */
    .action-bar {
        margin-top: 28px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .btn {
        padding: 12px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    
    .btn-approve { 
        background: var(--success); 
        color: white; 
        box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
    }
    .btn-approve:hover { 
        background: #059669; 
    }
    
    .btn-reject { 
        background: var(--danger); 
        color: white; 
        box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
    }
    .btn-reject:hover { 
        background: #dc2626; 
    }

    /* --- Lightbox --- */
    #lightbox {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.9);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 24px;
        backdrop-filter: blur(4px);
    }
    #lightbox img {
        max-width: 90%;
        max-height: 85vh;
        border-radius: 8px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    }
    #lightbox .close-lb {
        position: absolute;
        top: 24px; right: 32px;
        color: white;
        font-size: 36px;
        cursor: pointer;
        opacity: 0.8;
        transition: opacity 0.2s;
    }
    #lightbox .close-lb:hover { opacity: 1; }

    /* Responsive */
    @media (max-width: 768px) {
        .topbar {
            grid-template-columns: auto 1fr;
            padding: 12px 16px;
            gap: 12px;
        }
        .topbar-right {
            display: none; /* Hide duplicate badge view on small screens */
        }
        .container { 
            grid-template-columns: 1fr; 
            margin: 16px auto;
            padding: 0 16px;
            gap: 20px;
        }
    }
</style>
</head>

<body>

<!-- TOP NAV -->
<div class="topbar">
    <div class="topbar-left">
        <a href="admin_verifications.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Verification Center
        </a>
    </div>
    <div class="topbar-center">
        <h1 class="page-title">User Account Verification</h1>
    </div>
    <div class="topbar-right">
        <span class="status-badge status-<?= getStatusColor($user['status']) ?>">
            <?= ucfirst($user['status']) ?>
        </span>
    </div>
</div>

<div class="container">

    <!-- LEFT SIDEBAR: USER SUMMARY -->
    <div class="card">
        <div class="profile-sidebar">
            <div class="avatar-circle">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
            <div class="user-role"><?= htmlspecialchars($user['role']) ?> account</div>
            
            <div class="status-badge status-<?= getStatusColor($user['status']) ?>">
                <?= ucfirst($user['status']) ?>
            </div>

            <div class="info-list">
                <div class="info-item">
                    <i class="fas fa-envelope"></i>
                    <span><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-phone"></i>
                    <span><?= htmlspecialchars($user['phone'] ?? "No phone") ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Joined: <?= date('M d, Y', strtotime($user['created_at'] ?? 'now')) ?></span>
                </div>
            </div>

            <form method="POST" class="action-bar">
                <button name="action" value="approve" class="btn btn-approve" onclick="return confirm('Verify this user?');">
                    <i class="fas fa-check-circle"></i> Approve
                </button>
                <button name="action" value="reject" class="btn btn-reject" onclick="return confirm('Reject this user?');">
                    <i class="fas fa-ban"></i> Reject
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT CONTENT: DETAILED INFO -->
    <div style="display:flex; flex-direction:column; gap:32px;">
        
        <!-- Extended Profile Info -->
        <div class="card">
            <div class="section-header">
                <h3><i class="fas fa-user-tag" style="margin-right:8px; color:var(--primary);"></i> Profile Information</h3>
            </div>
            <div class="content-padding">
                <?php if ($profile): ?>
                    <div class="data-grid">
                        <?php foreach ($profile as $key => $value): ?>
                            <?php 
                                if ($key === 'id' || $key === 'user_id' || empty($value)) continue; 
                                $label = str_replace("_", " ", $key);
                            ?>
                            <div class="data-box">
                                <label><?= htmlspecialchars($label) ?></label>
                                <span><?= htmlspecialchars($value) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-style:italic; margin:0;">No extended profile information submitted yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Documents Section -->
        <div class="card">
            <div class="section-header">
                <h3><i class="fas fa-folder-open" style="margin-right:8px; color:var(--primary);"></i> Submitted Documents</h3>
            </div>
            <div class="content-padding">
                <div class="docs-grid">
                    <?php
                    $docFields = ['id_card', 'passport_photo', 'farmland_photos', 'certificate_photo'];
                    $hasDocs = false;

                    foreach ($docFields as $field):
                        if (!empty($fullData[$field])):
                            $files = explode(',', $fullData[$field]);
                            foreach ($files as $single):
                                $single = trim($single);
                                if ($single === '') continue;
                                $filePath = "../" . $single;
                                if (!file_exists($filePath)) continue; // Skip if file missing

                                $hasDocs = true;
                                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                $label = ucfirst(str_replace("_", " ", $field));
                    ?>
                                <div class="doc-card" onclick="<?= (in_array($ext, ['jpg','jpeg','png'])) ? "openLightbox('$filePath')" : "window.open('$filePath', '_blank')" ?>">
                                    <?php if (in_array($ext, ['jpg','jpeg','png'])): ?>
                                        <img src="<?= htmlspecialchars($filePath) ?>" class="doc-thumb" alt="Doc">
                                    <?php else: ?>
                                        <div class="doc-pdf"><i class="fas fa-file-pdf"></i></div>
                                    <?php endif; ?>
                                    
                                    <div class="doc-footer">
                                        <?= $label ?>
                                        <?php if($ext === 'pdf') echo '<i class="fas fa-external-link-alt" style="margin-left:4px"></i>'; ?>
                                    </div>
                                </div>
                    <?php 
                            endforeach; 
                        endif;
                    endforeach; 
                    ?>
                </div>
                
                <?php if(!$hasDocs): ?>
                    <div style="text-align:center; padding:20px; color:var(--text-muted);">
                        <i class="fas fa-file-excel" style="font-size:30px; margin-bottom:10px; display:block;"></i>
                        No documents found for this user.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div> <!-- End Right Content -->

</div>

<!-- Lightbox Modal -->
<div id="lightbox" onclick="closeLightbox()">
    <span class="close-lb">&times;</span>
    <img id="lightbox-img" src="">
</div>

<script>
    function openLightbox(src) {
        document.getElementById('lightbox-img').src = src;
        document.getElementById('lightbox').style.display = 'flex';
    }

    function closeLightbox() {
        document.getElementById('lightbox').style.display = 'none';
    }

    // Close on Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === "Escape") {
            closeLightbox();
        }
    });
</script>

</body>
</html>