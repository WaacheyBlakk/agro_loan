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

// Fetch base user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) { die("User not found."); }

// Fetch extended profiles
$profile = null;
if ($user['role'] === 'farmer') {
    $q = $pdo->prepare("SELECT * FROM farmer_profiles WHERE user_id=?");
    $q->execute([$user_id]);
    $profile = $q->fetch(PDO::FETCH_ASSOC);
}
if ($user['role'] === 'agent') {
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
    // CSRF protection could be added here
    $status = ($_POST['action'] === 'approve') ? 'verified' : 'rejected';
    
    $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=?");
    if($stmt->execute([$status, $user_id])){
        $msg = "User has been " . $status . " successfully.";
        header("Location: admin_verifications.php?msg=" . urlencode($msg));
        exit;
    }
}

// Helper for status colors
function getStatusColor($status) {
    switch(strtolower($status)) {
        case 'verified': return 'success';
        case 'rejected': return 'danger';
        default: return 'warning';
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
    :root{
        --primary: #4f46e5;
        --primary-hover: #4338ca;
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #111827;
        --text-muted: #6b7280;
        --border: #e5e7eb;
        
        --success: #10b981;
        --success-bg: #ecfdf5;
        --warning: #f59e0b;
        --warning-bg: #fffbeb;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Inter', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        padding-bottom: 40px;
    }

    /* --- Top Navigation --- */
    .topbar {
        background: var(--bg-card);
        padding: 16px 32px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        z-index: 50;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .back-link {
        text-decoration: none;
        color: var(--text-muted);
        font-weight: 500;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: color 0.2s;
    }
    .back-link:hover { color: var(--primary); }

    .page-title {
        margin-left: 20px;
        font-size: 18px;
        font-weight: 600;
        padding-left: 20px;
        border-left: 1px solid var(--border);
    }

    /* --- Layout Grid --- */
    .container {
        max-width: 1200px;
        margin: 30px auto;
        padding: 0 20px;
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 24px;
    }

    /* --- Sidebar Card --- */
    .card {
        background: var(--bg-card);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .profile-sidebar {
        padding: 24px;
        text-align: center;
    }

    .avatar-circle {
        width: 80px;
        height: 80px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        font-weight: 600;
        margin: 0 auto 16px;
    }

    .user-name { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
    .user-role { font-size: 14px; color: var(--text-muted); text-transform: capitalize; margin-bottom: 16px; }

    .status-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-success { background: var(--success-bg); color: var(--success); }
    .status-warning { background: var(--warning-bg); color: var(--warning); }
    .status-danger { background: var(--danger-bg); color: var(--danger); }

    .info-list {
        margin-top: 24px;
        text-align: left;
        border-top: 1px solid var(--border);
        padding-top: 20px;
    }
    .info-item {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        font-size: 14px;
    }
    .info-item i { color: var(--text-muted); width: 16px; }

    /* --- Main Content --- */
    .section-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
        background: #f9fafb;
    }
    .section-header h3 { margin: 0; font-size: 16px; font-weight: 600; }

    .content-padding { padding: 24px; }

    .data-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 20px;
    }

    .data-box label {
        display: block;
        font-size: 12px;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 600;
        margin-bottom: 5px;
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
        gap: 16px;
    }

    .doc-card {
        border: 1px solid var(--border);
        border-radius: 8px;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
        position: relative;
    }
    .doc-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .doc-thumb {
        height: 140px;
        width: 100%;
        object-fit: cover;
        background: #eee;
    }
    .doc-pdf {
        height: 140px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fdf2f8;
        color: #db2777;
        font-size: 40px;
    }
    .doc-footer {
        padding: 10px;
        background: white;
        font-size: 12px;
        font-weight: 600;
        border-top: 1px solid var(--border);
        text-align: center;
    }

    /* --- Action Buttons --- */
    .action-bar {
        margin-top: 24px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .btn {
        padding: 14px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: opacity 0.2s;
    }
    .btn:hover { opacity: 0.9; }

    .btn-approve { background: var(--success); color: white; }
    .btn-reject { background: var(--danger); color: white; }

    /* --- Lightbox --- */
    #lightbox {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.85);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 20px;
    }
    #lightbox img {
        max-width: 100%;
        max-height: 90vh;
        border-radius: 4px;
        box-shadow: 0 0 20px rgba(0,0,0,0.5);
    }
    #lightbox .close-lb {
        position: absolute;
        top: 20px; right: 30px;
        color: white;
        font-size: 40px;
        cursor: pointer;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .container { grid-template-columns: 1fr; }
    }
</style>
</head>

<body>

<!-- TOP NAV -->
<div class="topbar">
    <a href="admin_verifications.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Verification Center
    </a>
    <div class="page-title">User Details</div>
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
    <div style="display:flex; flex-direction:column; gap:24px;">
        
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
                    <p style="color:var(--text-muted); font-style:italic;">No extended profile information submitted yet.</p>
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