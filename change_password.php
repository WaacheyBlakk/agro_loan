<?php
session_start();
require_once '../src/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$admin_id = $_SESSION['user_id'];

// Fetch admin
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die("Admin not found.");
}

$success = "";
$error = "";

// Handle password change
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $current = $_POST["current_password"];
    $new = $_POST["new_password"];
    $confirm = $_POST["confirm_password"];

    if (!password_verify($current, $admin["password"])) {
        $error = "Current password is incorrect.";
    } elseif (strlen($new) < 6) {
        $error = "New password must be at least 6 characters long.";
    } elseif ($new !== $confirm) {
        $error = "New passwords do not match.";
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);

        $update = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
        $update->execute([$hashed, $admin_id]);

        $success = "Password updated successfully!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Change Password | Admin</title>

<style>
/* ===== LAYOUT ===== */
body {
    font-family: "Segoe UI", sans-serif;
    background: #eef2f3;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 15px;
}

/* ===== CARD CONTAINER ===== */
.card {
    background: #ffffff;
    padding: 40px 35px;
    border-radius: 16px;
    width: 420px;
    max-width: 92%;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    animation: fadeIn 0.3s ease;
    
    display: flex;
    flex-direction: column;
    align-items: center;      /* center horizontally */
    text-align: center;       /* center text */
}

.card h2 {
    font-size: 26px;
    font-weight: 700;
    color: #003049;
    margin-bottom: 25px;
}

/* ===== INPUT FIELDS ===== */
.input-group {
    width: 100%;
    display: flex;
    justify-content: center;
}

.input-group input {
    width: 100%;
    padding: 13px;
    border-radius: 10px;
    border: 1px solid #c7c9cc;
    font-size: 15px;
    transition: border 0.2s ease;
    outline: none;
    margin-bottom: 10px;
}

.input-group input:focus {
    border-color: #0077b6;
}

/* ===== BUTTONS ===== */
.btn {
    width: 100%;
    padding: 13px;
    background: #0077b6;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 17px;
    cursor: pointer;
    margin-top: 5px;
    transition: 0.2s ease;
}

.btn:hover {
    background: #005f8d;
    transform: translateY(-1px);
}

.back-btn {
    width: 100%;
    display: block;
    padding: 11px;
    margin-top: 18px;
    border-radius: 10px;
    background: #6c757d;
    color: #fff;
    text-decoration: none;
    font-size: 15px;
    transition: 0.2s ease;
    text-align: center;
}

.back-btn:hover {
    background: #545b62;
}

/* ===== STATUS MESSAGES ===== */
.success, .error {
    width: 100%;
    padding: 14px;
    border-radius: 8px;
    font-size: 15px;
    margin-bottom: 18px;
    text-align: center;   /* center message text */
}

.success {
    background: #d4edda;
    color: #155724;
    border-left: 5px solid #28a745;
}

.error {
    background: #f8d7da;
    color: #721c24;
    border-left: 5px solid #dc3545;
}

/* ===== ANIMATION ===== */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
</head>

<body>

<div class="card">

    <h2>Change Password</h2>

    <?php if ($success): ?>
        <div class="success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" style="width:100%;">

        <div class="input-group">
            <input type="password" name="current_password" placeholder="Current Password" required>
        </div>

        <div class="input-group">
            <input type="password" name="new_password" placeholder="New Password" required>
        </div>

        <div class="input-group">
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
        </div>

        <button class="btn">Update Password</button>
    </form>

    <a href="admin_profile.php" class="back-btn">← Back to Profile</a>

</div>

</body>
</html>
