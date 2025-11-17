<?php
session_start();
require_once '../src/db.php';
require_once '../src/sessions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
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

$fullData = array_merge($user, $profile ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = ($_POST['action'] === 'approve') ? 'verified' : 'rejected';
    $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=?");
    $stmt->execute([$status, $user_id]);

    header("Location: admin_verifications.php?msg=User updated successfully.");
    exit;
}

$username = $_SESSION['name'] ?? "Admin";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Details | AgroLoan Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root{
    --brand: #0f766e;
    --brand-dark: #0d9488;
    --danger: #dc3545;
    --success: #28a745;
    --bg: #f6f8fa;
    --text: #1f2937;
    --card-bg: #ffffff;
}

/* PAGE LAYOUT */
body{
    margin:0;
    font-family:"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
}

/* TOP BAR */
.topbar{
    background:white;
    padding:18px 28px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:1px solid rgba(0,0,0,0.06);
    position:sticky;
    top:0;
    z-index:50;
}

.topbar-title{
    font-size:22px;
    font-weight:600;
}

/* BACK BUTTON */
.back-btn{
    background:var(--brand);
    color:white;
    padding:10px 18px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    box-shadow:0 2px 6px rgba(0,0,0,0.15);
    transition:0.2s;
}
.back-btn:hover{
    background:var(--brand-dark);
    transform:translateY(-2px);
}

/* PAGE CONTENT */
.page-content{
    padding:32px 40px;
    max-width:1100px;
    margin:auto;
}

/* CARD */
.card{
    background:var(--card-bg);
    padding:25px;
    border-radius:14px;
    box-shadow:0 4px 16px rgba(0,0,0,0.08);
}

/* SECTION BOX */
.section-box{
    background:#f9f9f9;
    padding:15px;
    border-radius:8px;
    border-left:4px solid var(--brand);
    margin-top:12px;
}

/* BUTTONS */
.btn{
    padding:10px 16px;
    border:none;
    color:white;
    border-radius:6px;
    cursor:pointer;
    margin-right:10px;
}
.approve{ background:var(--success); }
.reject{ background:var(--danger); }

/* DOCUMENT PREVIEW */
.id-preview{
    margin-top:15px;
    display:flex;
    gap:20px;
    flex-wrap:wrap;
}

.id-box{
    background:#fff;
    padding:12px;
    width:220px;
    border-radius:8px;
    box-shadow:0 2px 8px rgba(0,0,0,0.1);
    text-align:center;
}

.id-box img{
    width:100%;
    border-radius:6px;
}

.id-label{
    font-weight:bold;
    margin-bottom:8px;
    display:block;
}

</style>
</head>

<body>

<!-- TOP BAR WITH BACK BUTTON -->
<div class="topbar">
    <a href="admin_verifications.php" class="back-btn">
        ← Back to Verification Center
    </a>
</div>


<div class="page-content">

    <div class="card">

        <h2>User Details</h2>

        <div class="section-box">
            <p><b>Name:</b> <?= htmlspecialchars($user['name']); ?></p>
            <p><b>Email:</b> <?= htmlspecialchars($user['email']); ?></p>
            <p><b>Phone:</b> <?= htmlspecialchars($user['phone'] ?? "N/A"); ?></p>
            <p><b>Role:</b> <?= ucfirst($user['role']); ?></p>
            <p><b>Status:</b> <?= ucfirst($user['status']); ?></p>
        </div>

        <h3>Full Profile Information</h3>
        <div class="section-box">
            <?php if ($profile): ?>
                <?php foreach ($profile as $key => $value): ?>
                    <?php if ($key !== 'id' && $key !== 'user_id'): ?>
                        <p><b><?= ucfirst(str_replace("_", " ", $key)); ?>:</b>
                            <?= htmlspecialchars($value); ?>
                        </p>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No extra data available.</p>
            <?php endif; ?>
        </div>

        <h3>ID Documents</h3>
        <div class="section-box">

            <?php
            $docFields = ['id_card','business_certificate','passport_photo'];
            ?>

            <div class="id-preview">
                <?php foreach ($docFields as $field): ?>
                    <?php if (!empty($fullData[$field])): 
                        $file = "../uploads/" . basename($fullData[$field]);
                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    ?>
                        <div class="id-box">
                            <span class="id-label"><?= ucfirst(str_replace("_", " ", $field)); ?></span>

                            <?php if (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                                <img src="<?= htmlspecialchars($file) ?>" alt="Document">
                            <?php elseif ($ext === "pdf"): ?>
                                <a href="<?= htmlspecialchars($file) ?>" target="_blank">View PDF Document</a>
                            <?php else: ?>
                                <p>Unsupported file format</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

        </div>

        <form method="POST" style="margin-top:20px;">
            <button name="action" value="approve" class="btn approve">Approve</button>
            <button name="action" value="reject" class="btn reject">Reject</button>
        </form>

    </div>

</div>

</body>
</html>
