<?php
session_start();

// 1. Redirect if already logged in
if (isset($_SESSION['id'])) {
    header('Location: shop.php');
    exit;
}

require_once '../src/db.php';

$errors = [];
$name_val = '';
$email_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPDO();

    // Sanitize and capture inputs
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Retain values for the form
    $name_val = htmlspecialchars($name);
    $email_val = htmlspecialchars($email);

    // 2. Validation Logic
    if (empty($name) || empty($email) || empty($password)) {
        $errors[] = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // 3. Database Interaction
    if (empty($errors)) {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM buyers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "This email is already registered. Please login.";
        } else {
            // Create Account
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO buyers (name, email, password) VALUES (?, ?, ?)");
            
            try {
                $stmt->execute([$name, $email, $hash]);
                // Redirect to login with success flag
                header('Location: buyers_login.php?registered=1');
                exit;
            } catch (PDOException $e) {
                // Log error internally in a real app
                $errors[] = "System error: Could not register account.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | Agro Market</title>
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
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-[url('https://images.unsplash.com/photo-1625246333195-58197bd47d26?q=80&w=2071&auto=format&fit=crop')] bg-cover bg-center relative">
    
    <!-- Dark Overlay -->
    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px] z-0"></div>

    <!-- Registration Card -->
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden z-10 fade-in relative">
        
        <!-- Decoration Header -->
        <div class="h-2 bg-gradient-to-r from-agro-500 to-agro-700"></div>

        <div class="p-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <a href="index.php" class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-agro-50 text-agro-600 mb-4 shadow-sm">
                    <i class="ri-user-add-line text-2xl"></i>
                </a>
                <h1 class="text-2xl font-bold text-gray-900">Create Account</h1>
                <p class="text-sm text-gray-500 mt-1">Join Agro Market to buy fresh produce</p>
            </div>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-100 text-red-600 text-sm font-medium animate-pulse">
                    <ul class="list-disc list-inside">
                        <?php foreach($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="" class="space-y-4" onsubmit="document.getElementById('submitBtn').disabled = true; document.getElementById('btnText').classList.add('hidden'); document.getElementById('btnSpinner').classList.remove('hidden');">
                
                <!-- Full Name -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Full Name</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                            <i class="ri-user-smile-line"></i>
                        </div>
                        <input type="text" name="name" required value="<?= $name_val ?>"
                               class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-agro-500 focus:bg-white focus:border-transparent outline-none transition text-gray-800 placeholder-gray-400"
                               placeholder="John Doe">
                    </div>
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                            <i class="ri-mail-line"></i>
                        </div>
                        <input type="email" name="email" required value="<?= $email_val ?>"
                               class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-agro-500 focus:bg-white focus:border-transparent outline-none transition text-gray-800 placeholder-gray-400"
                               placeholder="you@example.com">
                    </div>
                </div>

                <!-- Password Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Password -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                <i class="ri-lock-2-line"></i>
                            </div>
                            <input type="password" name="password" id="pass1" required minlength="6"
                                   class="w-full pl-10 pr-10 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-agro-500 focus:bg-white focus:border-transparent outline-none transition text-gray-800 placeholder-gray-400"
                                   placeholder="••••••">
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Confirm Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                <i class="ri-lock-check-line"></i>
                            </div>
                            <input type="password" name="confirm_password" id="pass2" required minlength="6"
                                   class="w-full pl-10 pr-10 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-agro-500 focus:bg-white focus:border-transparent outline-none transition text-gray-800 placeholder-gray-400"
                                   placeholder="••••••">
                        </div>
                    </div>
                </div>
                
                <!-- Password Toggle & Hint -->
                <div class="flex justify-between items-center text-xs text-gray-500 px-1">
                    <span>Min. 6 characters</span>
                    <button type="button" onclick="togglePasswords()" class="flex items-center gap-1 hover:text-agro-600 transition">
                        <i class="ri-eye-line" id="eyeIcon"></i> Show Passwords
                    </button>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="submitBtn" class="w-full bg-agro-600 hover:bg-agro-700 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-agro-600/30 transition transform active:scale-[0.98] mt-6 flex items-center justify-center gap-2">
                    <span id="btnText">Create Account</span>
                    <span id="btnSpinner" class="hidden">
                        <i class="ri-loader-4-line animate-spin text-xl"></i> Processing...
                    </span>
                </button>
            </form>

            <!-- Footer Links -->
            <div class="mt-8 text-center border-t border-gray-100 pt-6">
                <p class="text-sm text-gray-600">
                    Already have an account? 
                    <a href="buyers_login.php" class="font-bold text-agro-600 hover:text-agro-700 hover:underline">Login here</a>
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
        function togglePasswords() {
            const p1 = document.getElementById('pass1');
            const p2 = document.getElementById('pass2');
            const icon = document.getElementById('eyeIcon');
            
            if (p1.type === 'password') {
                p1.type = 'text';
                p2.type = 'text';
                icon.classList.remove('ri-eye-line');
                icon.classList.add('ri-eye-off-line');
            } else {
                p1.type = 'password';
                p2.type = 'password';
                icon.classList.remove('ri-eye-off-line');
                icon.classList.add('ri-eye-line');
            }
        }
    </script>
</body>
</html>