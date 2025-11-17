<?php
// public/farmer_dashboard.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];

$pdo = getPDO();

// Fetch all loans belonging to this farmer
$stmt = $pdo->prepare("SELECT 
                            l.*, 
                            u.name AS agent_name, 
                            ap.interest_rate, 
                            ap.loan_terms
                       FROM loan_applications l
                       LEFT JOIN users u ON l.agent_id = u.id
                       LEFT JOIN agent_profiles ap ON u.id = ap.user_id
                       WHERE l.farmer_id = ?
                       ORDER BY l.created_at DESC");
$stmt->execute([$farmer_id]);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Farmer Dashboard - Agro Loan</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root{
            --sidebar-width: 240px;
            --sidebar-collapsed-width: 72px;
            --brand: #2f855a;
            --brand-dark: #276749;
            --danger: #e53e3e;
            --bg: #f6f8fa;
            --text: #1f2937;
            --muted: #6b7280;
            --card-bg: #fff;
        }

        * { box-sizing: border-box; }
        body {
            margin:0;
            font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            height:100vh;
            overflow:hidden;
            display:flex;
        }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            background: var(--brand);
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 18px;
            gap: 8px;
            transition: width .28s ease, padding .2s ease;
            overflow: hidden;
        }
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
            min-width: var(--sidebar-collapsed-width);
            padding-left: 10px;
            padding-right: 10px;
        }

        .brand {
            display:flex;
            align-items:center;
            gap:12px;
            margin-bottom:6px;
        }
        .brand .logo {
            width:44px;
            height:44px;
            background: rgba(255,255,255,0.12);
            border-radius:8px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:700;
            font-size:18px;
        }
        .brand h2 {
            font-size:18px;
            margin:0;
            line-height:1;
            font-weight:600;
            transition:opacity .18s ease;
        }
        .sidebar.collapsed .brand h2 { opacity:0; width:0; margin:0; }

        /* Links */
        .nav {
            display:flex;
            flex-direction:column;
            gap:6px;
            margin-top:8px;
        }
        .nav a {
            display:flex;
            align-items:center;
            gap:12px;
            padding:10px;
            border-radius:8px;
            color:#fff;
            text-decoration:none;
            font-weight:500;
            transition: background .15s, transform .08s;
            white-space:nowrap;
            overflow:hidden;
        }
        .nav a .icon {
            width:36px;
            height:36px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
            border-radius:6px;
            background: rgba(255,255,255,0.06);
        }
        .nav a .label { transition: opacity .18s ease; }
        .nav a:hover { background: var(--brand-dark); transform: translateY(-1px); }
        .nav a.active { background: rgba(0,0,0,0.12); }

        /* When collapsed: hide labels and center icons */
        .sidebar.collapsed .nav a { justify-content:center; padding:8px; }
        .sidebar.collapsed .nav a .label { display:none; }
        .sidebar.collapsed .nav a .icon { margin:0; }

        /* Push logout to bottom */
        .sidebar .spacer { flex:1 1 auto; }

        .logout-btn {
            background: var(--danger);
            border: none;
            color:#fff;
            padding:10px;
            border-radius:8px;
            cursor:pointer;
            width:100%;
            font-weight:600;
        }
        .sidebar.collapsed .logout-btn {
            padding:8px;
            width:48px;
            height:48px;
            border-radius:8px;
            align-self:center;
            display:flex;
            justify-content:center;
            align-items:center;
        }
        .sidebar.collapsed .logout-btn span.label { display:none; }

        /* CONTENT */
        .main {
            flex:1 1 auto;
            display:flex;
            flex-direction:column;
            height:100vh;
            overflow:auto;
        }
        .topbar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:20px;
            border-bottom:1px solid rgba(15,23,42,0.04);
        }

        .left-controls {
            display:flex;
            align-items:center;
            gap:12px;
        }

        .toggle-btn {
            background: var(--brand);
            color: white;
            border: none;
            padding:8px 10px;
            border-radius:8px;
            font-size:18px;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .toggle-btn .text { font-weight:600; }
        /* Hide toggle text when sidebar collapsed */
        .sidebar.collapsed + .main .toggle-btn .text { display:none; }

        .user-greet { font-size:18px; font-weight:600; }

        .content-area {
            padding:28px 40px;
            display:flex;
            flex-direction:column;
            gap:22px;
            align-items:stretch;
        }

        /* Summary cards */
        .card-summary {
            display:flex;
            gap:18px;
            align-items:stretch;
        }
        .card {
            background: var(--card-bg);
            padding:18px;
            border-radius:12px;
            box-shadow: 0 4px 18px rgba(16,24,40,0.06);
            flex:1;
            min-width:0;
            text-align:center;
        }
        .card h3 { margin:0; color:var(--brand); font-size:14px; font-weight:700; }
        .card p { margin-top:10px; font-size:20px; font-weight:700; color:var(--text); }

        /* Table container */
        .table-wrap {
            background:var(--card-bg);
            border-radius:12px;
            padding:18px;
            box-shadow: 0 4px 18px rgba(16,24,40,0.06);
        }
        table {
            width:100%;
            border-collapse:collapse;
            font-size:14px;
        }
        th, td {
            padding:12px 10px;
            border-bottom:1px solid #eef2f3;
            text-align:left;
        }
        th { background:transparent; color:var(--brand); font-weight:700; text-transform:uppercase; font-size:12px; }
        tr:hover td { background:rgba(15,23,42,0.02); }

        /* Center main content when sidebar is collapsed */
        .centered {
            align-items:center;
        }
        .centered .card-summary { width:100%; max-width:980px; }
        .centered .table-wrap { width:100%; max-width:980px; }

        /* Responsive behavior */
        @media (max-width:900px) {
            .card-summary { flex-direction:column; }
            .sidebar { position:fixed; z-index:30; height:100vh; left:0; top:0; }
            .main { margin-left: var(--sidebar-collapsed-width); }
        }
    </style>
</head>
<body>

    <aside class="sidebar" id="sidebar" aria-hidden="false">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo"></a>
            <h2>AgroLoan Farmer</h2>
        </div>

        <nav class="nav" role="navigation" aria-label="Main navigation">
            <a href="farmer_dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'farmer_dashboard.php' ? 'active' : '' ?>">
                <span class="icon">🏠</span>
                <span class="label">Dashboard</span>
            </a>

            <a href="apply_loan.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'apply_loan.php' ? 'active' : '' ?>">
                <span class="icon">💰</span>
                <span class="label">Apply for Loan</span>
            </a>

            <a href="view_application.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_application.php' ? 'active' : '' ?>">
                <span class="icon">📄</span>
                <span class="label">View Applications</span>
            </a>

            <a href="upload_proof.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'upload_proof.php' ? 'active' : '' ?>">
                <span class="icon">📸</span>
                <span class="label">Upload Proof</span>
            </a>

            <a href="farmer_profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'farmer_profile.php' ? 'active' : '' ?>">
                <span class="icon">⚙️</span>
                <span class="label">Profile</span>
            </a>
        </nav>

        <div class="spacer" aria-hidden="true"></div>

        <form method="POST" action="logout.php" style="margin-top:6px;">
            <button class="logout-btn" title="Logout">
                <span class="icon">🚪</span>
                <span class="label">Logout</span>
            </button>
        </form>
    </aside>

    <main class="main" id="main">
        <div class="topbar">
            <div class="left-controls">
                <button id="toggleBtn" class="toggle-btn" aria-pressed="false" aria-label="Toggle sidebar">
                    <span class="icon">☰</span>
                </button>
                <div class="user-greet">Welcome, <?php echo htmlspecialchars($username); ?> 👩‍🌾</div>
            </div>
            <div class="right-empty" aria-hidden="true"></div>
        </div>

        <div class="content-area" id="contentArea">
            <div class="card-summary" id="cardSummary">
                <div class="card">
                    <h3>Total Loans</h3>
                    <p><?php echo count($loans); ?></p>
                </div>
                <div class="card">
                    <h3>Approved</h3>
                    <p><?php echo count(array_filter($loans, function($l){ return isset($l['status']) && $l['status'] === 'approved'; })); ?></p>
                </div>
                <div class="card">
                    <h3>Pending</h3>
                    <p><?php echo count(array_filter($loans, function($l){ return !isset($l['status']) || $l['status'] === 'pending'; })); ?></p>
                </div>
            </div>

            <div class="table-wrap" id="tableWrap">
                <h3 style="margin:0 0 12px 0; color:var(--muted); font-size:16px;">Your Loan Applications</h3>

                <?php if (empty($loans)): ?>
                    <p style="color:var(--muted); margin-top:12px;">You have no active loan applications yet.</p>
                <?php else: ?>
                    <table aria-describedby="Your applications">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Agent</th>
                                <th>Title</th>
                                <th>Amount</th>
                                <th>Interest</th>
                                <th>Repayment</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($loan['id']); ?></td>
                                <td><?php echo htmlspecialchars($loan['agent_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($loan['title'] ?? '-'); ?></td>
                                <td>GHc<?php echo number_format($loan['amount'] ?? 0, 2); ?></td>
                                <td><?php echo isset($loan['interest_rate']) ? htmlspecialchars($loan['interest_rate'] . '%') : '-'; ?></td>
                                <td><?php echo isset($loan['repayment_period']) ? htmlspecialchars($loan['repayment_period'] . ' months') : '-'; ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($loan['status'] ?? 'pending')); ?></td>
                                <td><?php echo htmlspecialchars($loan['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        (function(){
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleBtn');
            const main = document.getElementById('main');
            const contentArea = document.getElementById('contentArea');

            // Helper to set centered state when collapsed
            function updateCentered() {
                if (sidebar.classList.contains('collapsed')) {
                    contentArea.classList.add('centered');
                } else {
                    contentArea.classList.remove('centered');
                }
            }

            // Toggle handler
            toggleBtn.addEventListener('click', function(){
                sidebar.classList.toggle('collapsed');
                // Update aria-pressed
                const pressed = sidebar.classList.contains('collapsed');
                toggleBtn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
                updateCentered();
            });

            // On load, check saved state (optional): use sessionStorage
            try {
                const saved = sessionStorage.getItem('agro_sidebar_collapsed');
                if (saved === 'true') {
                    sidebar.classList.add('collapsed');
                    toggleBtn.setAttribute('aria-pressed','true');
                }
            } catch(e){}

            // Save state whenever toggled
            toggleBtn.addEventListener('click', function(){
                try {
                    sessionStorage.setItem('agro_sidebar_collapsed', sidebar.classList.contains('collapsed') ? 'true' : 'false');
                } catch(e){}
            });

            // initial apply
            updateCentered();
        })();
    </script>
</body>
</html>
