<?php
session_start();

// 1. Redirect if already logged in
if (isset($_SESSION['id'])) {
    header('Location: shop.php');
    exit;
}

require_once '../src/db.php';

$error = '';
$email_value = ''; // To keep email in input if error occurs

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();
    
    // Sanitize input
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $pass  = $_POST['password'] ?? '';
    $email_value = htmlspecialchars($email);

    if (empty($email) || empty($pass)) {
        $error = "Please fill in all fields.";
    } else {
        // Fetch user (including status field to check approval)
        $stmt = $pdo->prepare("SELECT * FROM buyers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password'])) {
            // Check verification status
            if ($user['status'] !== 'approved') {
                if ($user['status'] === 'pending' || $user['status'] === 'submitted' || $user['status'] === 'unverified') {
                    $error = "Your profile is awaiting administrator verification. Access is restricted until approved.";
                } elseif ($user['status'] === 'denied' || $user['status'] === 'rejected') {
                    $error = "Your account application has been declined. Please contact administration support.";
                } else {
                    $error = "Your account is unverified. Please check back later.";
                }
            } else {
                session_regenerate_id(true); 
                $_SESSION['id'] = $user['id'];
                $_SESSION['name'] = $user['name']; 
                $_SESSION['role'] = 'buyer';      // Explicitly set role
                
                // Redirect to shop
                header('Location: shop.php');
                exit;
            }
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Agro Market</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        agro: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' }
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    
    <style>
        body { background-color: #f8fafc; }
        /* Smooth fade in */
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-[url('https://images.unsplash.com/photo-1500937386664-56d1dfef3854?q=80&w=2070&auto=format&fit=crop')] bg-cover bg-center relative">
    
    <!-- Dark Overlay -->
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm z-0"></div>

    <!-- Login Card -->
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden z-10 fade-in relative">
        
        <!-- Decoration Header -->
        <div class="h-2 bg-gradient-to-r from-agro-500 to-agro-700"></div>

        <div class="p-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <a href="index.php" class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-agro-50 text-agro-600 mb-4">
                    <i class="ri-plant-fill text-2xl"></i>
                </a>
                <h1 class="text-2xl font-bold text-gray-900">Welcome Back</h1>
                <p class="text-sm text-gray-500 mt-1">Login to continue shopping</p>
            </div>

            <!-- Registration Success Alert -->
            <?php if (isset($_GET['registered']) && $_GET['registered'] == 1): ?>
                <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center gap-3 text-emerald-700 text-sm font-medium">
                    <i class="ri-checkbox-circle-fill text-lg"></i>
                    Registration complete. Your profile has been sent to the administrator for verification.
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-100 flex items-start gap-3 text-red-600 text-sm font-medium">
                    <i class="ri-error-warning-fill text-lg mt-0.5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="" class="space-y-5" onsubmit="document.getElementById('submitBtn').disabled = true; document.getElementById('btnText').classList.add('hidden'); document.getElementById('btnSpinner').classList.remove('hidden');">
                
                <!-- Email Field -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                            <i class="ri-mail-line"></i>
                        </div>
                        <input type="email" name="email" required 
                               value="<?= $email_value ?>"
                               class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-agro-500 focus:bg-white focus:border-transparent outline-none transition text-gray-800 placeholder-gray-400"
                               placeholder="you@example.com">
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <label class="block text-sm font-semibold text-gray-700">Password</label>
                        <a href="forgot_password.php" class="text-xs font-semibold text-agro-600 hover:text-agro-700 hover:underline">Forgot password?</a>
                    </div>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                            <i class="ri-lock-2-line"></i>
                        </div>
                        <input type="password" name="password" id="passwordInput" required 
                               class="w-full pl-10 pr-12 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-agro-500 focus:bg-white focus:border-transparent outline-none transition text-gray-800 placeholder-gray-400"
                               placeholder="••••••••">
                        
                        <!-- Toggle Password Visibility -->
                        <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 transition cursor-pointer outline-none">
                            <i class="ri-eye-off-line" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="submitBtn" class="w-full bg-agro-600 hover:bg-agro-700 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-agro-600/30 transition transform active:scale-[0.98] flex items-center justify-center gap-2">
                    <span id="btnText">Sign In</span>
                    <!-- Spinner (Hidden by default) -->
                    <span id="btnSpinner" class="hidden">
                        <i class="ri-loader-4-line animate-spin text-xl"></i> Processing...
                    </span>
                </button>
            </form>

            <!-- Footer Links -->
            <div class="mt-8 text-center border-t border-gray-100 pt-6">
                <p class="text-sm text-gray-600">
                    Don't have an account? 
                    <a href="buyers_registration.php" class="font-bold text-agro-600 hover:text-agro-700 hover:underline">Register now</a>
                </p>
                <div class="mt-4">
                    <a href="shop.php" class="text-xs text-gray-400 hover:text-gray-600 flex items-center justify-center gap-1">
                        <i class="ri-arrow-left-line"></i> Back to Shop
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon = document.getElementById('eyeIcon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('ri-eye-off-line');
                icon.classList.add('ri-eye-line');
                icon.classList.add('text-agro-600');
            } else {
                input.type = 'password';
                icon.classList.remove('ri-eye-line');
                icon.classList.remove('text-agro-600');
                icon.classList.add('ri-eye-off-line');
            }
        }
    </script>
</body>
</html>