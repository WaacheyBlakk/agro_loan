<?php
require_once __DIR__ . '/../src/db.php'; // Make sure this connects via PDO to your DB

$pdo = getPDO();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $new_password = trim($_POST['password'] ?? '');

    if ($email && $new_password) {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Update password securely (hash it!)
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $update->execute([$hashed_password, $email]);

            $success = true;
        } else {
            $error = "No account found with that email.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password - Agro Loan</title>
<style>
    body {
        font-family: "Poppins", sans-serif;
        background: linear-gradient(135deg, #2e8b57, #3cb371);
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        margin: 0;
    }

    .container {
        background: #fff;
        padding: 40px;
        border-radius: 12px;
        width: 380px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        position: relative;
    }

    h2 {
        text-align: center;
        color: #2e8b57;
        margin-bottom: 25px;
    }

    input[type="email"], input[type="password"] {
        width: 100%;
        padding: 12px;
        margin: 10px 0;
        border: 1px solid #ccc;
        border-radius: 8px;
        font-size: 15px;
        outline: none;
    }

    .password-wrapper {
        position: relative;
    }

    .toggle-password {
        position: absolute;
        right: 10px;
        top: 35%;
        cursor: pointer;
        color: #888;
    }

    button {
        width: 100%;
        padding: 12px;
        background: #2e8b57;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        cursor: pointer;
        transition: background 0.3s;
        margin-top: 10px;
    }

    button:hover {
        background: #246b45;
    }

    .error {
        color: red;
        text-align: center;
        margin-top: 10px;
    }

    .success-popup {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0);
        background: #fff;
        padding: 30px 50px;
        border-radius: 15px;
        text-align: center;
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        transition: transform 0.3s ease-in-out;
        z-index: 10;
    }

    .success-popup.active {
        transform: translate(-50%, -50%) scale(1);
    }

    .success-popup .checkmark {
        color: #2e8b57;
        font-size: 40px;
        margin-bottom: 10px;
    }

    .link {
        display: block;
        text-align: center;
        margin-top: 20px;
        color: #2e8b57;
        text-decoration: none;
    }

    .link:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>

<div class="container">
    <h2>Reset Password</h2>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="email" name="email" placeholder="Enter your registered email" required>

        <div class="password-wrapper">
            <input type="password" name="password" id="password" placeholder="Enter new password" required>
            <span class="toggle-password" onclick="togglePassword()">👁</span>
        </div>

        <button type="submit">Reset Password</button>
    </form>

    <a href="login.php" class="link">← Back to Login</a>
</div>

<div id="successPopup" class="success-popup">
    <div class="checkmark">✔</div>
    <h3>Password Reset Successful!</h3>
    <p>You can now log in with your new password.</p>
</div>

<script>
function togglePassword() {
    const passwordField = document.getElementById('password');
    passwordField.type = passwordField.type === 'password' ? 'text' : 'password';
}

// Show success popup
<?php if ($success): ?>
    document.getElementById('successPopup').classList.add('active');
    setTimeout(() => {
        window.location.href = 'login.php'; // Redirect to login after 3s
    }, 3000);
<?php endif; ?>
</script>

</body>
</html>
