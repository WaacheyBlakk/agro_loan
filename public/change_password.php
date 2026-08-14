<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../src/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$admin_id = $_SESSION['user_id'] ?? null;
$success = "";
$error = "";

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (function_exists('csrf_verify')) {
        csrf_verify();
    }

    $current = $_POST["current_password"] ?? '';
    $new     = $_POST["new_password"] ?? '';
    $confirm = $_POST["confirm_password"] ?? '';

    // Fetch user record
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        $error = "User account not found.";
    } else {
        // Detect the password column name in database (e.g. password_hash, password, pass_hash)
        $password_col = null;
        foreach (['password_hash', 'password', 'pass_hash', 'pass'] as $col) {
            if (array_key_exists($col, $admin)) {
                $password_col = $col;
                break;
            }
        }

        if (!$password_col) {
            $error = "Unable to identify the password column in the database.";
        } elseif (!password_verify($current, $admin[$password_col])) {
            $error = "The current password provided is incorrect.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } elseif (strlen($new) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[\W_]/', $new)) {
            $error = "Password must contain uppercase, lowercase, a number, and a special character.";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET {$password_col}=? WHERE id=?");
            
            if ($update->execute([$hashed, $admin_id])) {
                $success = "Password updated successfully.";
            } else {
                $error = "An error occurred while updating the password. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | Admin</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #059669;
            --primary-dark: #047857;
            --primary-light: #d1fae5;
            
            --surface: #ffffff;
            --surface-hover: #f9fafb;
            
            --text-main: #111827;
            --text-secondary: #6b7280;
            --border: #e5e7eb;
            
            /* Feedback Colors */
            --success-bg: #ecfdf5;
            --success-text: #047857;
            --error-bg: #fef2f2;
            --error-text: #b91c1c;
            
            --focus-ring: rgba(5, 150, 105, 0.25);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 25px 15px;
            color: var(--text-main);
        }

        .container {
            width: 100%;
            max-width: 480px;
        }

        .card {
            background: var(--surface);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 35px 28px;
            position: relative;
            overflow: hidden;
            animation: cardEnter 0.4s ease-out;
        }

        .card::after {
            content: "";
            position: absolute;
            top: 0; left: 0; width: 100%; height: 6px;
            background: linear-gradient(90deg, var(--primary), #10b981);
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .icon-circle {
            width: 60px;
            height: 60px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 22px;
            position: relative;
        }
        
        .icon-circle::before {
            content: '';
            position: absolute;
            width: 100%; height: 100%;
            border-radius: 50%;
            background: var(--primary);
            opacity: 0.2;
            filter: blur(10px);
            z-index: -1;
        }

        .header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 13.5px;
            margin-top: 5px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .input-group {
            position: relative;
        }

        .input-group i.field-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 15px;
            transition: color 0.3s;
        }

        .input-group input {
            width: 100%;
            padding: 13px 42px 13px 42px;
            background: #f9fafb;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 14.5px;
            color: var(--text-main);
            transition: all 0.3s ease;
        }

        .input-group input::placeholder { color: #d1d5db; }

        .input-group input:focus {
            background: #fff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--focus-ring);
            outline: none;
        }

        .input-group input:focus + i.field-icon {
            color: var(--primary);
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            padding: 5px;
            transition: color 0.2s;
        }
        .toggle-password:hover { color: var(--text-main); }

        /* Strength Meter */
        .strength-meter {
            height: 4px;
            width: 100%;
            background: #e5e7eb;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
            display: none;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            background: var(--primary);
            transition: width 0.3s, background 0.3s;
        }

        /* --- Password Requirements Box --- */
        .requirements-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-top: 10px;
            font-size: 12.5px;
        }

        .requirements-title {
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .requirements-list {
            list-style: none;
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
        }

        .req-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            transition: color 0.2s ease;
        }

        .req-item i {
            font-size: 12px;
            width: 14px;
            text-align: center;
            color: #cbd5e1;
            transition: color 0.2s ease;
        }

        .req-item.valid {
            color: #059669;
            font-weight: 500;
        }

        .req-item.valid i {
            color: #059669;
        }

        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
            box-shadow: 0 4px 6px -1px rgba(5, 150, 105, 0.2);
            margin-top: 12px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(5, 150, 105, 0.3);
        }

        .btn-primary:active { transform: translateY(0); }

        .btn-back {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px;
            margin-top: 14px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            border-radius: 8px;
            transition: background 0.2s, color 0.2s;
        }

        .btn-back:hover {
            background: #f3f4f6;
            color: var(--text-main);
        }

        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.4;
            animation: slideDown 0.3s ease;
        }
        
        .alert-success { background: var(--success-bg); color: var(--success-text); border: 1px solid #a7f3d0; }
        .alert-error { background: var(--error-bg); color: var(--error-text); border: 1px solid #fecaca; }

        @keyframes cardEnter {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <div class="icon-circle">
                <i class="fas fa-shield-halved"></i>
            </div>
            <h2>Secure Your Account</h2>
            <p>Create a strong password following the rules below</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle" style="margin-top: 3px;"></i> 
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-triangle-exclamation" style="margin-top: 3px;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php if (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php endif; ?>

            <!-- Current Password -->
            <div class="form-group">
                <label class="form-label" for="current_password">Current Password</label>
                <div class="input-group">
                    <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required>
                    <i class="fas fa-key field-icon"></i>
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('current_password', this)"></i>
                </div>
            </div>

            <!-- New Password -->
            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-group">
                    <input type="password" id="new_password" name="new_password" placeholder="Create new password" required oninput="validatePassword(this.value)">
                    <i class="fas fa-lock field-icon"></i>
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('new_password', this)"></i>
                </div>
                
                <div class="strength-meter" id="strength-meter">
                    <div class="strength-bar" id="strength-bar"></div>
                </div>

                <!-- Explicit Requirements Guidance -->
                <div class="requirements-card">
                    <div class="requirements-title">
                        <i class="fas fa-circle-info"></i> Password Instructions:
                    </div>
                    <ul class="requirements-list">
                        <li class="req-item" id="req-len">
                            <i class="fas fa-circle" id="icon-len"></i> Minimum 8 characters
                        </li>
                        <li class="req-item" id="req-upper">
                            <i class="fas fa-circle" id="icon-upper"></i> At least 1 uppercase letter (A-Z)
                        </li>
                        <li class="req-item" id="req-lower">
                            <i class="fas fa-circle" id="icon-lower"></i> At least 1 lowercase letter (a-z)
                        </li>
                        <li class="req-item" id="req-num">
                            <i class="fas fa-circle" id="icon-num"></i> At least 1 number (0-9)
                        </li>
                        <li class="req-item" id="req-sym">
                            <i class="fas fa-circle" id="icon-sym"></i> At least 1 special symbol (e.g. !@#$%^&*)
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <div class="input-group">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                    <i class="fas fa-check-double field-icon"></i>
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('confirm_password', this)"></i>
                </div>
            </div>

            <button type="submit" class="btn-primary">
                Update Password <i class="fas fa-arrow-right" style="margin-left: 8px; font-size: 14px;"></i>
            </button>
        </form>

        <a href="admin_profile.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Profile
        </a>
    </div>
</div>

<script>
    function toggleVisibility(inputId, icon) {
        const input = document.getElementById(inputId);
        if (input.type === "password") {
            input.type = "text";
            icon.classList.replace("fa-eye", "fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.replace("fa-eye-slash", "fa-eye");
        }
    }

    function setReqStatus(elementId, iconId, isValid) {
        const item = document.getElementById(elementId);
        const icon = document.getElementById(iconId);

        if (isValid) {
            item.classList.add("valid");
            icon.classList.remove("fa-circle");
            icon.classList.add("fa-check-circle");
        } else {
            item.classList.remove("valid");
            icon.classList.remove("fa-check-circle");
            icon.classList.add("fa-circle");
        }
    }

    function validatePassword(password) {
        const meter = document.getElementById('strength-meter');
        const bar = document.getElementById('strength-bar');
        
        if (password.length > 0) {
            meter.style.display = 'block';
        } else {
            meter.style.display = 'none';
        }

        // Check Individual Rules
        const hasLength = password.length >= 8;
        const hasUpper  = /[A-Z]/.test(password);
        const hasLower  = /[a-z]/.test(password);
        const hasNum    = /[0-9]/.test(password);
        const hasSym    = /[\W_]/.test(password);

        setReqStatus('req-len', 'icon-len', hasLength);
        setReqStatus('req-upper', 'icon-upper', hasUpper);
        setReqStatus('req-lower', 'icon-lower', hasLower);
        setReqStatus('req-num', 'icon-num', hasNum);
        setReqStatus('req-sym', 'icon-sym', hasSym);

        // Calculate score
        let score = 0;
        if (hasLength) score += 20;
        if (hasUpper)  score += 20;
        if (hasLower)  score += 20;
        if (hasNum)    score += 20;
        if (hasSym)    score += 20;

        bar.style.width = score + '%';

        if (score <= 40) {
            bar.style.backgroundColor = '#ef4444'; 
        } else if (score <= 80) {
            bar.style.backgroundColor = '#f59e0b'; 
        } else {
            bar.style.backgroundColor = '#10b981'; 
        }
    }
</script>

</body>
</html>