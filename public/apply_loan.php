<?php
// public/apply_loan.php
session_start();
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/users.php';
require_once __DIR__ . '/../src/loan.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];


$pdo = getPDO();
$agents = $pdo->query("
    SELECT u.id, u.name, ap.interest_rate, ap.loan_terms 
    FROM users u 
    JOIN agent_profiles ap ON u.id = ap.user_id
")->fetchAll();

$errorMessage = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $totalAmount = floatval($_POST['amount']);
    $stageSum = 0;

    $stages = [];
    for ($i = 1; $i <= 3; $i++) {
        $amt = floatval($_POST["stage_{$i}_amount"] ?? 0);
        $stageSum += $amt;
        $stages[] = ['stage_number' => $i, 'required_amount' => $amt];
    }

    // Backend Validation
    if ($stageSum !== $totalAmount) {
        $errorMessage = "The total of all stage amounts (GHS {$stageSum}) must equal the loan amount (GHS {$totalAmount}).";
    } else {
        $appId = create_application(
            $user['id'],
            $_POST['agent_id'],
            $_POST['title'],
            $totalAmount,
            $_POST['purpose'],
            $stages
        );
        header("Location: view_application.php?id={$appId}");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Apply for Loan | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    /* --- CORE THEME (From Dashboard) --- */
    :root {
        --primary: #059669; /* Emerald 600 */
        --primary-dark: #064e3b; /* Emerald 900 */
        --secondary: #10b981; /* Emerald 500 */
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #111827;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Poppins', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        display: flex;
        height: 100vh;
        overflow: hidden;
    }

    /* --- SIDEBAR --- */
    .sidebar {
        width: var(--sidebar-width);
        background: var(--primary-dark);
        color: #fff;
        display: flex;
        flex-direction: column;
        padding: 20px;
        transition: width 0.3s ease;
        z-index: 100;
        box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    }

    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 40px;
        padding-left: 5px;
        overflow: hidden;
    }

    .brand img {
        width: 40px; height: 40px; border-radius: 8px;
        object-fit: cover; border: 2px solid rgba(255,255,255,0.2);
    }

    .brand h2 {
        font-size: 20px; font-weight: 600; white-space: nowrap;
        opacity: 1; transition: opacity 0.2s; margin: 0;
    }

    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .nav-link {
        display: flex; align-items: center; gap: 14px;
        padding: 12px 15px; color: #d1fae5; text-decoration: none;
        border-radius: 10px; transition: all 0.2s ease;
        white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1); color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 12px; border-radius: 10px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 10px; font-family: inherit; font-weight: 600;
        transition: 0.2s; width: 100%;
    }

    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
    }

    /* TOPBAR */
    .topbar {
        background: var(--bg-card); padding: 15px 30px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50;
    }

    .toggle-btn {
        background: transparent; border: none; color: var(--text-muted);
        cursor: pointer; padding: 5px;
    }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary);
        color: white; border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* --- FORM & PAGE CONTENT --- */
    .content { padding: 30px; max-width: 1200px; margin: 0 auto; width: 100%; }
    
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* Layout Grid for Form + Info */
    .layout-grid {
        display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start;
    }
    @media (max-width: 992px) { .layout-grid { grid-template-columns: 1fr; } }

    /* Cards */
    .card {
        background: var(--bg-card); padding: 30px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #f0f0f0;
    }

    /* Form Elements */
    .form-group { margin-bottom: 20px; }
    
    label {
        display: block; font-weight: 500; margin-bottom: 8px;
        font-size: 14px; color: var(--text-main);
    }

    input[type="text"],
    input[type="number"],
    textarea,
    select {
        width: 100%; padding: 12px 16px; border-radius: 10px;
        border: 1px solid #e5e7eb; font-size: 14px;
        font-family: inherit; background: #fff;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    input:focus, textarea:focus, select:focus {
        border-color: var(--primary); outline: none;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
    }

    textarea { resize: vertical; }

    /* Stage Inputs Grid */
    .stages-container {
        background: #f9fafb; padding: 20px; border-radius: 12px;
        border: 1px solid #e5e7eb;
    }
    .stages-grid {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;
    }
    @media (max-width: 600px) { .stages-grid { grid-template-columns: 1fr; } }

    .stage-item label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }

    /* Buttons */
    .btn-submit {
        background: var(--primary); color: white; border: none;
        padding: 14px 24px; border-radius: 10px; cursor: pointer;
        width: 100%; font-weight: 600; font-size: 16px;
        transition: background 0.2s; margin-top: 10px;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover { background: var(--primary-dark); }

    /* Info Card */
    .info-card {
        background: #ecfdf5; border: 1px solid #d1fae5;
    }
    .info-card h3 {
        margin: 0 0 15px 0; font-size: 18px; color: var(--primary-dark);
        display: flex; align-items: center; gap: 8px;
    }
    .info-card ul { padding-left: 20px; margin: 0; color: var(--primary-dark); }
    .info-card li { margin-bottom: 12px; font-size: 14px; line-height: 1.6; }

    /* Alerts */
    .alert {
        padding: 15px; border-radius: 10px; margin-bottom: 25px;
        font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px;
    }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
    }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Farmer</h2>
        </div>

        <nav class="nav">
            <a href="farmer_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            <a href="add_product.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'add_product.php' ? 'active' : '' ?>">
                <i data-feather="shopping-bag"></i>
                <span>Add Produce</span>
            </a>
            <a href="apply_loan.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'apply_loan.php' ? 'active' : '' ?>">
                <i data-feather="dollar-sign"></i>
                <span>Apply for Loan</span>
            </a>
            <a href="view_application.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'view_application.php' ? 'active' : '' ?>">
                <i data-feather="file-text"></i>
                <span>Applications</span>
            </a>
            <a href="upload_proof.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'upload_proof.php' ? 'active' : '' ?>">
                <i data-feather="upload-cloud"></i>
                <span>Upload Proof</span>
            </a>
            <a href="farmer_repayment.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_repayment.php' ? 'active' : '' ?>">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="farmer_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_profile.php' ? 'active' : '' ?>">
                <i data-feather="user"></i>
                <span>Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <!-- MAIN AREA -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Farmer</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">New Application</h1>
                <p class="page-subtitle">Fill in the details below to request financing.</p>
            </div>

            <div class="layout-grid">
                
                <!-- Left Column: Form -->
                <div class="card">
                    
                    <!-- PHP Error Message -->
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger">
                            <i data-feather="alert-circle"></i>
                            <?= htmlspecialchars($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <!-- JS Error Message -->
                    <div id="stage-error" class="alert alert-danger" style="display:none;"></div>

                    <form method="POST">
                        <div class="form-group">
                            <label for="title">Loan Title</label>
                            <input type="text" id="title" name="title" placeholder="e.g., Maize Season 2025" required>
                        </div>

                        <div class="form-group">
                            <label for="agent">Select Agent</label>
                            <select name="agent_id" id="agent" required>
                                <option value="">-- Choose an Agent --</option>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= htmlspecialchars($a['name']) ?> (<?= $a['interest_rate'] ?>% Interest)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="amount">Total Loan Amount (GHS)</label>
                            <input type="number" id="amount" name="amount" placeholder="0.00" step="1" required>
                        </div>

                        <div class="form-group">
                            <label for="purpose">Purpose of Loan</label>
                            <textarea name="purpose" id="purpose" rows="4" placeholder="Describe what you need the funds for..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label>Distribution Stages (Must equal Total Amount)</label>
                            <div class="stages-container">
                                <div class="stages-grid">
                                    <div class="stage-item">
                                        <label>Stage 1 (GHS)</label>
                                        <input type="number" name="stage_1_amount" placeholder="0.00" step="1">
                                    </div>
                                    <div class="stage-item">
                                        <label>Stage 2 (GHS)</label>
                                        <input type="number" name="stage_2_amount" placeholder="0.00" step="1">
                                    </div>
                                    <div class="stage-item">
                                        <label>Stage 3 (GHS)</label>
                                        <input type="number" name="stage_3_amount" placeholder="0.00" step="1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">
                            Submit Application
                        </button>
                    </form>
                </div>

                <!-- Right Column: Info -->
                <div class="card info-card">
                    <h3><i data-feather="help-circle"></i> Instructions</h3>
                    <ul>
                        <li><strong>Title:</strong> Give your loan a short, clear name (e.g., "Fertilizer Purchase").</li>
                        <li><strong>Agent:</strong> Choose an agent based on their interest rates.</li>
                        <li><strong>Amount:</strong> Enter the total amount you need in GHS.</li>
                        <li><strong>Purpose:</strong> Explain how the money will be used to help the agent approve your request.</li>
                        <li><strong>Stages:</strong> You can split the loan into up to 3 installments. The sum of these stages must equal the <strong>Total Loan Amount</strong>.</li>
                    </ul>
                </div>

            </div>
        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Sidebar Toggle Logic
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        const amountField = document.getElementById("amount");
        const stageFields = document.querySelectorAll("input[name^='stage_'][name$='_amount']");
        const msgBox = document.getElementById("stage-error");

        function validateStageAmounts() {
            let total = parseFloat(amountField.value) || 0;
            let sum = 0;

            stageFields.forEach(f => {
                sum += parseFloat(f.value) || 0;
            });

            if (total > 0 && sum > 0) {
                if (sum !== total) {
                    msgBox.innerHTML = `<i data-feather="alert-triangle" style="width:16px;height:16px;"></i> &nbsp; Stage total (GHS ${sum}) must equal the loan amount (GHS ${total}).`;
                    msgBox.style.display = "flex";
                    feather.replace(); 
                } else {
                    msgBox.style.display = "none";
                }
            } else {
                msgBox.style.display = "none";
            }
        }

        amountField.addEventListener("input", validateStageAmounts);
        stageFields.forEach(f => f.addEventListener("input", validateStageAmounts));
    </script>
</body>
</html>