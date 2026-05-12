<?php
// public/reset_password.php
require_once __DIR__ . '/../src/db.php';
$pdo = getPDO();

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

// 1. Validate Token Presence
if (!$token) {
    $error = "Invalid request. No token provided.";
}

// 2. Validate Token in DB
if (!$error) {
    $token_hash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token_hash = ? AND reset_token_expires_at > NOW()");
    $stmt->execute([$token_hash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $error = "This password reset link is invalid or has expired.";
    }
}

// 3. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $new_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // 4. Update Password & Clear Token
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // --- FIXED SQL QUERY BELOW ---
        $update = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_token_expires_at = NULL WHERE id = ?");
        $update->execute([$hashed_password, $user['id']]);
        
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | Agro Loan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #16a34a;
            --primary-hover: #15803d;
            --bg-gradient: linear-gradient(135deg, rgba(6, 78, 59, 0.85), rgba(20, 83, 45, 0.9));
            --glass-bg: rgba(255, 255, 255, 0.9);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --success: #22c55e;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            /* Update this URL to your actual local image path if needed */
            background: var(--bg-gradient), url('../assets/images/farm-bg.jpg') center/cover no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 460px;
            perspective: 1000px;
        }

        .card {
            background: var(--glass-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 48px 40px;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.4);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Header */
        .header-icon {
            width: 72px;
            height: 72px;
            background: #dcfce7;
            color: var(--primary);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin-bottom: 24px;
            box-shadow: 0 0 0 8px rgba(220, 252, 231, 0.5);
        }

        h2 { color: var(--text-main); font-size: 1.75rem; font-weight: 700; margin-bottom: 8px; }
        p.subtitle { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 32px; line-height: 1.5; }

        /* Form */
        .form-group { margin-bottom: 24px; text-align: left; }
        
        .label-row { display: flex; justify-content: space-between; margin-bottom: 8px; }
        label { color: var(--text-main); font-weight: 600; font-size: 0.9rem; }
        
        .match-status { font-size: 0.8rem; font-weight: 600; transition: color 0.3s; }
        .match-status.match { color: var(--success); }
        .match-status.mismatch { color: var(--danger); }

        .input-wrap { position: relative; }
        
        input {
            width: 100%;
            padding: 14px 45px 14px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.2s;
            background: white;
            font-family: inherit;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.15);
        }

        .toggle-pw {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 4px;
        }
        .toggle-pw:hover { color: var(--primary); }

        /* Password Strength Bar */
        .strength-meter {
            height: 4px;
            background: #e2e8f0;
            margin-top: 8px;
            border-radius: 2px;
            overflow: hidden;
            display: flex;
        }
        .strength-fill {
            height: 100%;
            width: 0%;
            background: var(--danger);
            transition: width 0.3s, background 0.3s;
        }

        button {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        button:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(22, 163, 74, 0.3); }

        /* Messages */
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            text-align: left;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }

        .success-content { animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    </style>
</head>
<body>

<div class="container">
    <div class="card">
        
        <?php if ($success): ?>
            <!-- SUCCESS STATE -->
            <div class="success-content">
                <div class="header-icon" style="background: #ecfdf5; color: #16a34a;">
                    <i class="ri-check-line"></i>
                </div>
                <h2>Password Reset!</h2>
                <p class="subtitle">Your password has been successfully updated. You can now log in with your new credentials.</p>
                <a href="login.php" style="text-decoration:none;">
                    <button type="button">Back to Login</button>
                </a>
            </div>

        <?php elseif ($error): ?>
            <!-- ERROR STATE (Invalid Token) -->
            <div class="error-content">
                <div class="header-icon" style="background: #fef2f2; color: #ef4444; box-shadow: 0 0 0 8px rgba(254, 242, 242, 0.5);">
                    <i class="ri-link-unlink-m"></i>
                </div>
                <h2>Invalid Link</h2>
                <div class="alert error">
                    <i class="ri-error-warning-fill" style="font-size:1.1rem; margin-top:2px;"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
                <a href="forgot_password.php" style="text-decoration:none;">
                    <button type="button">Request New Link</button>
                </a>
            </div>

        <?php else: ?>
            <!-- FORM STATE -->
            <div class="header-icon">
                <i class="ri-shield-keyhole-line"></i>
            </div>
            <h2>Set New Password</h2>
            <p class="subtitle">Create a strong password to secure your account.</p>

            <form method="POST" id="resetForm">
                
                <!-- Password Field -->
                <div class="form-group">
                    <label>New Password</label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password" placeholder="Min 6 characters" required>
                        <i class="ri-eye-off-line toggle-pw" onclick="togglePassword('password', this)"></i>
                    </div>
                    <!-- Strength Bar -->
                    <div class="strength-meter">
                        <div class="strength-fill" id="strengthBar"></div>
                    </div>
                </div>

                <!-- Confirm Field -->
                <div class="form-group">
                    <div class="label-row">
                        <label>Confirm Password</label>
                        <span id="matchStatus" class="match-status"></span>
                    </div>
                    <div class="input-wrap">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Retype password" required>
                        <i class="ri-eye-off-line toggle-pw" onclick="togglePassword('confirm_password', this)"></i>
                    </div>
                </div>

                <button type="submit">
                    Reset Password <i class="ri-arrow-right-line"></i>
                </button>

            </form>
        <?php endif; ?>

    </div>
</div>

<script>
    // Toggle Visibility
    function togglePassword(id, icon) {
        const input = document.getElementById(id);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('ri-eye-off-line', 'ri-eye-line');
        } else {
            input.type = 'password';
            icon.classList.replace('ri-eye-line', 'ri-eye-off-line');
        }
    }

    // Real-time Validation
    const passInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const matchStatus = document.getElementById('matchStatus');

    if (passInput && confirmInput) {
        
        // Password Strength Logic
        passInput.addEventListener('input', function() {
            const val = this.value;
            let strength = 0;
            
            if (val.length > 0) strength += 20;
            if (val.length >= 6) strength += 20;
            if (val.length >= 10) strength += 20;
            if (/[A-Z]/.test(val)) strength += 20;
            if (/[0-9]/.test(val)) strength += 20;

            strengthBar.style.width = strength + '%';

            if (strength < 40) strengthBar.style.backgroundColor = '#ef4444'; // Red
            else if (strength < 80) strengthBar.style.backgroundColor = '#f59e0b'; // Orange
            else strengthBar.style.backgroundColor = '#22c55e'; // Green

            checkMatch();
        });

        // Match Logic
        confirmInput.addEventListener('input', checkMatch);

        function checkMatch() {
            const p1 = passInput.value;
            const p2 = confirmInput.value;

            if (p2.length === 0) {
                matchStatus.textContent = '';
                confirmInput.style.borderColor = 'var(--border)';
                return;
            }

            if (p1 === p2) {
                matchStatus.textContent = 'Passwords match';
                matchStatus.className = 'match-status match';
                confirmInput.style.borderColor = 'var(--success)';
            } else {
                matchStatus.textContent = 'Passwords do not match';
                matchStatus.className = 'match-status mismatch';
                confirmInput.style.borderColor = 'var(--danger)';
            }
        }
    }
</script>

</body>
</html>