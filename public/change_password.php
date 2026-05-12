<?php
session_start();
require_once '../src/db.php';

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");

// 2. Auth Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// 3. CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getPDO();
$admin_id = $_SESSION['user_id'];
$success = "";
$error = "";

// 4. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // CSRF Check
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $current = $_POST["current_password"];
    $new     = $_POST["new_password"];
    $confirm = $_POST["confirm_password"];

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        $error = "User account not found.";
    } elseif (!password_verify($current, $admin["password"])) {
        $error = "The current password provided is incorrect.";
    } elseif ($new !== $confirm) {
        $error = "New passwords do not match.";
    } elseif (strlen($new) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[\W]/', $new)) {
        $error = "Password must contain 1 uppercase, 1 number, and 1 symbol.";
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
        
        if ($update->execute([$hashed, $admin_id])) {
            $success = "Password updated successfully.";
        } else {
            $error = "An error occurred. Please try again.";
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
            /* Palette: Modern Indigo/Violet */
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #e0e7ff;
            
            --bg-gradient-start: #f3f4f6;
            --bg-gradient-end: #e5e7eb;
            
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
            
            --focus-ring: rgba(99, 102, 241, 0.25);
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
            padding: 20px;
            color: var(--text-main);
        }

        /* Animated Background Mesh (Optional effect) */
        body::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 15% 50%, rgba(255, 255, 255, 0.4), transparent 25%),
                        radial-gradient(circle at 85% 30%, rgba(255, 255, 255, 0.4), transparent 25%);
            z-index: -1;
        }

        .container {
            width: 100%;
            max-width: 440px;
            perspective: 1000px;
        }

        .card {
            background: var(--surface);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 40px;
            position: relative;
            overflow: hidden;
            animation: cardEnter 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Decorative top bar */
        .card::after {
            content: "";
            position: absolute;
            top: 0; left: 0; width: 100%; height: 6px;
            background: linear-gradient(90deg, var(--primary), #a855f7);
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .icon-circle {
            width: 64px;
            height: 64px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 24px;
            position: relative;
        }
        
        /* Subtle glow behind icon */
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
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.025em;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 6px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .input-group {
            position: relative;
            transition: transform 0.2s;
        }

        .input-group i.field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 16px;
            transition: color 0.3s;
        }

        .input-group input {
            width: 100%;
            padding: 14px 45px 14px 45px;
            background: #f9fafb;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 15px;
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

        /* Password Toggle Eye */
        .toggle-password {
            position: absolute;
            right: 16px;
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
            display: none; /* Hidden until typing */
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            background: var(--primary);
            transition: width 0.3s, background 0.3s;
        }

        /* Buttons */
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
            margin-top: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
        }

        .btn-primary:active { transform: translateY(0); }

        .btn-back {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            margin-top: 20px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            transition: background 0.2s, color 0.2s;
        }

        .btn-back:hover {
            background: #f3f4f6;
            color: var(--text-main);
        }

        /* Alerts */
        .alert {
            padding: 14px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            line-height: 1.5;
            animation: slideDown 0.3s ease;
        }
        
        .alert-success { background: var(--success-bg); color: var(--success-text); border: 1px solid #a7f3d0; }
        .alert-error { background: var(--error-bg); color: var(--error-text); border: 1px solid #fecaca; }

        @keyframes cardEnter {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
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
            <p>Create a strong password to protect your data</p>
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
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label class="form-label" for="current_password">Current Password</label>
                <div class="input-group">
                    <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required>
                    <i class="fas fa-key field-icon"></i>
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('current_password', this)"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-group">
                    <input type="password" id="new_password" name="new_password" placeholder="Create new password" required oninput="checkStrength(this.value)">
                    <i class="fas fa-lock field-icon"></i>
                    <i class="fas fa-eye toggle-password" onclick="toggleVisibility('new_password', this)"></i>
                </div>
                <!-- Visual Strength Meter -->
                <div class="strength-meter" id="strength-meter">
                    <div class="strength-bar" id="strength-bar"></div>
                </div>
            </div>

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
    // Toggle Password Visibility
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

    // Password Strength Visualizer (Visual Only)
    function checkStrength(password) {
        const meter = document.getElementById('strength-meter');
        const bar = document.getElementById('strength-bar');
        
        if(password.length > 0) {
            meter.style.display = 'block';
        } else {
            meter.style.display = 'none';
        }

        let strength = 0;
        if (password.length >= 8) strength += 25;
        if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 25;
        if (password.match(/\d/)) strength += 25;
        if (password.match(/[^a-zA-Z\d]/)) strength += 25;

        bar.style.width = strength + '%';

        if (strength <= 25) {
            bar.style.backgroundColor = '#ef4444'; 
        } else if (strength <= 50) {
            bar.style.backgroundColor = '#f59e0b'; 
        } else if (strength <= 75) {
            bar.style.backgroundColor = '#3b82f6'; 
        } else {
            bar.style.backgroundColor = '#10b981'; 
        }
    }
</script>

</body>
</html>