<?php
// public/forgot_password.php
require_once __DIR__ . '/../src/db.php';
$pdo = getPDO(); 

$message = '';
$msgType = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email) {
        // 1. Generate Token
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expiry = date("Y-m-d H:i:s", time() + 60 * 60);

        // 2. Identify the correct table (users or buyers)
        $target_table = '';
        
        // Check the users table first
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $target_table = 'users';
        } else {
            // Check the buyers table if not found in users
            $stmt = $pdo->prepare("SELECT id FROM buyers WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $target_table = 'buyers';
            }
        }

        // If the email belongs to an existing account
        if ($target_table !== '') {
            $stmt = $pdo->prepare("UPDATE {$target_table} SET reset_token_hash = ?, reset_token_expires_at = ? WHERE email = ?");
            $stmt->execute([$token_hash, $expiry, $email]);

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            
            // Appending a 'type' parameter allows reset_password.php to know which table to query
            $type_param = ($target_table === 'buyers') ? 'buyer' : 'user';
            $resetLink = "$protocol://$host$dir/reset_password.php?token=" . $token . "&type=" . $type_param;

            // Demo Link for testing
            $demoLink = $resetLink; 
        }

        // Standard security practice: display the confirmation message regardless of whether the email was found
        $message = "We have sent a password reset link to <b>" . htmlspecialchars($email) . "</b> if it is registered in our system.";
        $msgType = 'success';
        
    } else {
        $message = "Please enter your email address.";
        $msgType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password | Agro Loan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Fonts: Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Icons: Remix Icon -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <style>
        /* --- CSS Reset & Variables --- */
        :root {
            --primary: #16a34a;        
            --primary-hover: #15803d; 
            --bg-overlay: rgba(21, 128, 61, 0.90);
            --glass-bg: rgba(255, 255, 255, 0.95);
            --text-heading: #0f172a;
            --text-body: #64748b;
            --border-color: #e2e8f0;
            --focus-ring: rgba(22, 163, 74, 0.3);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: 
                linear-gradient(135deg, rgba(6, 78, 59, 0.8), rgba(20, 83, 45, 0.85)),
                url('https://images.unsplash.com/photo-1625246333195-78d9c38ad449?q=80&w=2070&auto=format&fit=crop') center/cover no-repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--text-heading);
            padding: 20px;
        }

        /* --- Card Design --- */
        .auth-card {
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(12px);           
            -webkit-backdrop-filter: blur(12px);
            padding: 48px 40px;
            border-radius: 24px;
            width: 100%;
            max-width: 440px;
            box-shadow: 
                0 4px 6px -1px rgba(0, 0, 0, 0.1), 
                0 20px 25px -5px rgba(0, 0, 0, 0.1),
                inset 0 0 0 1px rgba(255, 255, 255, 0.5); 
            text-align: center;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- Header Section --- */
        .icon-wrapper {
            width: 64px;
            height: 64px;
            background: #dcfce7; 
            color: var(--primary);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            font-size: 32px;
            box-shadow: 0 0 0 8px #f0fdf4; 
        }

        h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 8px;
            letter-spacing: -0.025em;
        }

        .subtitle {
            color: var(--text-body);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 32px;
        }

        /* --- Form Elements --- */
        .input-group {
            position: relative;
            margin-bottom: 20px;
            text-align: left;
        }

        .input-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.2rem;
            transition: color 0.3s;
        }

        input {
            width: 100%;
            padding: 14px 16px 14px 48px; /* Padding left accounts for icon */
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        input::placeholder { color: #cbd5e1; }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--focus-ring);
        }

        input:focus + i, .input-group:focus-within i {
            color: var(--primary);
        }

        button {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
        }

        button:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(22, 163, 74, 0.35);
        }

        button:active {
            transform: translateY(0);
        }

        /* --- Links --- */
        .back-link {
            display: inline-flex;
            align-items: center;
            margin-top: 24px;
            color: var(--text-body);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color 0.2s;
        }

        .back-link i { margin-right: 6px; }
        .back-link:hover { color: var(--text-heading); }

        /* --- Alerts --- */
        .alert {
            padding: 14px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.4;
        }

        .alert.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        
        /* --- Demo Box (Dev Only) --- */
        .dev-box {
            margin-top: 25px;
            background: #fffbeb;
            border: 1px dashed #fbbf24;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.8rem;
            color: #92400e;
            text-align: left;
        }
        .dev-box strong { display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-size: 0.7rem; }
        .dev-box a { color: #b45309; text-decoration: underline; word-break: break-all; }

    </style>
</head>
<body>

    <div class="auth-card">
        <!-- Header Icon -->
        <div class="icon-wrapper">
            <i class="ri-lock-password-fill"></i>
        </div>

        <h2>Forgot Password?</h2>
        <p class="subtitle">No worries! Enter the email associated with your account and we’ll send you a reset link.</p>

        <!-- Feedback Messages -->
        <?php if ($message): ?>
            <div class="alert <?= $msgType ?>">
                <i class="<?= $msgType === 'success' ? 'ri-checkbox-circle-fill' : 'ri-error-warning-fill' ?>"></i>
                <span><?= $message ?></span>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST">
            <div class="input-group">
                <i class="ri-mail-line"></i>
                <input type="email" name="email" placeholder="name@company.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            
            <button type="submit">Send Reset Instructions</button>
        </form>

        <!-- Back to Login -->
        <a href="login.php" class="back-link">
            <i class="ri-arrow-left-line"></i> Back to Login
        </a>

        <?php if (isset($demoLink)): ?>
            <div class="dev-box">
                <strong><i class="ri-code-s-slash-line"></i> Developer Demo</strong>
                Email simulation (Localhost):<br>
                <a href="<?= $demoLink ?>">Click here to reset password</a>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>