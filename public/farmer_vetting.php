<?php
session_start();

require_once __DIR__ . '/../src/db.php';

$pdo = getPDO();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit;
}

$agent_id = $_SESSION['user_id'];
$username = $_SESSION['name'];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id'], $_POST['action'])) {
    $application_id = $_POST['application_id'];
    $action = ($_POST['action'] === 'approve') ? 'approved' : 'rejected';

    $stmt = $pdo->prepare("UPDATE loan_applications SET status = ? WHERE id = ? AND agent_id = ?");
    if ($stmt->execute([$action, $application_id, $agent_id])) {
        $successMessage = ($action === 'approved')
            ? 'Application Approved Successfully!'
            : 'Application Rejected!';
    }
}

// Fetching updated details from the users table
$stmt = $pdo->prepare("
    SELECT 
        la.id AS application_id,
        la.title,
        la.amount,
        la.purpose,
        la.status,
        la.created_at,
        u.name AS farmer_name,
        u.email AS farmer_email,
        u.phone,
        fp.house_address AS farmer_address,
        fp.id_card AS farmer_id_card,
        fp.farm_type AS farmer_farm_type,
        fp.acreage AS farmer_acreage,
        fp.passport_photo AS farmer_photo,
        fp.id_card_number AS farmer_id_number
    FROM loan_applications la
    INNER JOIN users u ON la.farmer_id = u.id
    LEFT JOIN farmer_profiles fp ON u.id = fp.user_id
    WHERE la.agent_id = ?
    ORDER BY la.created_at DESC
");
$stmt->execute([$agent_id]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Farmer Vetting | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root {
        --primary: #1e40af;      
        --primary-dark: #172554;  
        --secondary: #3b82f6;   
        
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #1f2937;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --success: #10b981;
        
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
        padding: 12px 15px; color: #dbeafe; /* Light Blue Text */
        text-decoration: none; border-radius: 10px;
        transition: all 0.2s ease; white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
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

    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
    }

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

    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    .table-container {
        background: var(--bg-card); border-radius: 16px;
        padding: 24px; box-shadow: var(--shadow);
        overflow-x: auto; margin-bottom: 30px;
    }
    
    .table-header { 
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 20px; 
    }
    .table-title { font-size: 18px; font-weight: 600; color: var(--text-main); }

    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    
    th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f3f4f6; }
    
    th {
        font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--text-muted); font-weight: 600; background: #f9fafb;
    }
    
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f9fafb; }

    .btn-sm {
        padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 500;
        text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        transition: all 0.2s; border: none; cursor: pointer; color: white;
    }
    
    .btn-approve { background: #dcfce7; color: #16a34a; }
    .btn-approve:hover { background: #16a34a; color: white; }

    .btn-reject { background: #fee2e2; color: #dc2626; }
    .btn-reject:hover { background: #dc2626; color: white; }
    
    .btn-view { background: #e0f2fe; color: #0284c7; margin-right: 5px; }
    .btn-view:hover { background: #0284c7; color: white; }
    
    .badge {
        padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;
    }
    .badge-pending { background: #fff7ed; color: #b45309; }
    .badge-approved { background: #ecfdf5; color: #047857; }
    .badge-rejected { background: #fef2f2; color: #b91c1c; }
    
    .action-form { display: inline-block; margin-right: 5px; }

    .modal {
        display: none; position: fixed; z-index: 1000; 
        left: 0; top: 0; width: 100%; height: 100%; 
        background-color: rgba(0,0,0,0.5); backdrop-filter: blur(2px);
        overflow-y: auto;
    }

    .modal-content {
        background-color: var(--bg-card);
        margin: 5% auto; padding: 0; border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        width: 90%; max-width: 800px; /* Increased width to support images cleanly */
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from { transform: translateY(-30px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
        padding: 20px 24px; border-bottom: 1px solid #f3f4f6;
        display: flex; justify-content: space-between; align-items: center;
    }
    .modal-header h3 { margin: 0; font-size: 18px; color: var(--primary-dark); }
    
    .modal-close {
        background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);
    }
    .modal-close:hover { color: var(--danger); }

    .modal-body { padding: 24px; }

    .detail-row { margin-bottom: 15px; }
    .detail-label {
        font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 4px;
    }
    .detail-value {
        font-size: 15px; color: var(--text-main); font-weight: 500; line-height: 1.5;
    }
    .detail-full-text {
        background: #f9fafb; padding: 15px; border-radius: 8px; font-size: 14px;
        border: 1px solid #e5e7eb; margin-top: 5px; white-space: pre-wrap;
    }

    /* Verification Documents Container */
    .doc-preview-wrapper {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }
    .doc-card {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 15px;
        text-align: center;
    }
    .doc-card h4 {
        margin: 0 0 10px 0;
        font-size: 13px;
        text-transform: uppercase;
        color: var(--text-muted);
    }
    .doc-img {
        max-width: 100%;
        height: 180px;
        object-fit: contain;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        background: #fff;
    }
    .doc-placeholder {
        height: 180px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        font-size: 13px;
        font-style: italic;
        background: #f3f4f6;
        border-radius: 6px;
        border: 1px dashed #d1d5db;
    }
    .view-link {
        display: inline-block;
        margin-top: 8px;
        font-size: 12px;
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
    }
    .view-link:hover {
        text-decoration: underline;
    }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .modal-body > div { flex-direction: column; }
        .doc-preview-wrapper { grid-template-columns: 1fr; }
    }
</style>
</head>

<body>

    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Agent</h2>
        </div>

        <nav class="nav">
            <a href="agent_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            <a href="farmer_vetting.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_vetting.php' ? 'active' : '' ?>">
                <i data-feather="users"></i>
                <span>Farmer Vetting</span>
            </a>
            <a href="proof_verification.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'proof_verification.php' ? 'active' : '' ?>">
                <i data-feather="check-square"></i>
                <span>Proof Verify</span>
            </a>
            <a href="agent_repayments.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_repayments.php' ? 'active' : '' ?>">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="agent_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'agent_profile.php' ? 'active' : '' ?>">
                <i data-feather="user"></i>
                <span>My Profile</span>
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
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Agent</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Farmer Vetting</h1>
                <p class="page-subtitle">Review applications and make approval decisions.</p>
            </div>

            <div class="table-container">
                <div class="table-header">
                    <div class="table-title">Loan Applications</div>
                </div>
                
                <?php if (empty($applications)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:10px;">No loan applications found assigned to you.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Farmer Details</th>
                                <th>Project & Purpose</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th style="width: 250px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($app['farmer_name']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($app['farmer_email']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?= htmlspecialchars($app['title'] ?: 'Untitled Project') ?></div>
                                    <div style="font-size:12px; color:var(--text-muted); max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" 
                                         title="<?= htmlspecialchars($app['purpose']) ?>">
                                        <?= htmlspecialchars($app['purpose']) ?>
                                    </div>
                                </td>
                                <td style="font-weight:600;">GHS <?= number_format((float)$app['amount'], 2) ?></td>
                                <td><?= date('M d, Y', strtotime($app['created_at'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars($app['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($app['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <!-- Injected details into the button as data-attributes -->
                                    <button type="button" class="btn-sm btn-view open-modal-btn"
                                            data-name="<?= htmlspecialchars($app['farmer_name']) ?>"
                                            data-email="<?= htmlspecialchars($app['farmer_email']) ?>"
                                            data-phone="<?= htmlspecialchars($app['phone']) ?>"
                                            data-address="<?= htmlspecialchars($app['farmer_address'] ?? 'No Address Provided') ?>"
                                            data-nationalid="<?= htmlspecialchars($app['farmer_id_card'] ?? '') ?>"
                                            data-farmtype="<?= htmlspecialchars($app['farmer_farm_type'] ?? '') ?>"
                                            data-acreage="<?= htmlspecialchars($app['farmer_acreage'] ?? '') ?>"
                                            data-photo="<?= htmlspecialchars($app['farmer_photo'] ?? '') ?>"
                                            data-idcardnumber="<?= htmlspecialchars($app['farmer_id_number'] ?? '') ?>"
                                            data-amount="<?= number_format((float)$app['amount'], 2) ?>"
                                            data-title="<?= htmlspecialchars($app['title']) ?>"
                                            data-date="<?= date('F d, Y', strtotime($app['created_at'])) ?>"
                                            data-status="<?= htmlspecialchars($app['status']) ?>"
                                            data-purpose="<?= htmlspecialchars($app['purpose']) ?>">
                                        <i data-feather="eye" style="width:14px; height:14px;"></i> View
                                    </button>

                                    <?php if ($app['status'] === 'pending'): ?>
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                            <button type="submit" name="action" value="approve" class="btn-sm btn-approve" title="Approve">
                                                <i data-feather="check" style="width:14px; height:14px;"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
                                            <button type="submit" name="action" value="reject" class="btn-sm btn-reject" title="Reject">
                                                <i data-feather="x" style="width:14px; height:14px;"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div id="vettingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Application Details</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: flex; gap: 20px;">
                    <div style="flex: 1;">
                        <div class="detail-row">
                            <span class="detail-label">Farmer Name</span>
                            <span class="detail-value" id="m_name">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Email Address</span>
                            <span class="detail-value" id="m_email">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Phone</span>
                            <span class="detail-value" id="m_phone">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">House Address</span>
                            <span class="detail-value" id="m_address">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">National ID Card Path</span>
                            <span class="detail-value" id="m_national_id">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Farm Type</span>
                            <span class="detail-value" id="m_farmtype">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Farm Acreage</span>
                            <span class="detail-value" id="m_acreage">Loading...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">National ID Number</span>
                            <span class="detail-value" id="m_idnumber">Loading...</span>
                        </div>
                    </div>
                    <div style="flex: 1;">
                         <div class="detail-row">
                            <span class="detail-label">Project Title</span>
                            <span class="detail-value" id="m_title">Agro Project</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Requested Amount</span>
                            <span class="detail-value" style="color:var(--primary); font-weight:700;">GHS <span id="m_amount">0.00</span></span>
                        </div>
                         <div class="detail-row">
                            <span class="detail-label">Applied Date</span>
                            <span class="detail-value" id="m_date">...</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Application Status</span>
                            <span class="detail-value" id="m_status">Pending</span>
                        </div>
                    </div>
                </div>

                <div class="detail-row" style="margin-top: 20px;">
                    <span class="detail-label">Full Project Purpose</span>
                    <div class="detail-full-text" id="m_purpose">
                        Description goes here...
                    </div>
                </div>

                <!-- Verification Document Preview Grid -->
                <div class="doc-preview-wrapper">
                    <div class="doc-card">
                        <h4>Farmer Photo</h4>
                        <img id="img_photo" class="doc-img" src="" alt="Farmer Photo" style="display:none;">
                        <div id="no_photo" class="doc-placeholder">No photo uploaded</div>
                        <a id="lnk_photo" href="#" target="_blank" class="view-link" style="display:none;">View full resolution</a>
                    </div>
                    <div class="doc-card">
                        <h4>ID Card</h4>
                        <img id="img_idcard" class="doc-img" src="" alt="ID Card" style="display:none;">
                        <div id="no_idcard" class="doc-placeholder">No ID Card uploaded</div>
                        <a id="lnk_idcard" href="#" target="_blank" class="view-link" style="display:none;">View full resolution</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        feather.replace();

        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        const modal = document.getElementById("vettingModal");
        const closeBtn = document.querySelector(".modal-close");
        const openBtns = document.querySelectorAll(".open-modal-btn");

        const m_name = document.getElementById("m_name");
        const m_email = document.getElementById("m_email");
        const m_phone = document.getElementById("m_phone");
        const m_address = document.getElementById("m_address");
        const m_national_id = document.getElementById("m_national_id");
        const m_farmtype = document.getElementById("m_farmtype");
        const m_acreage = document.getElementById("m_acreage");
        const m_idnumber = document.getElementById("m_idnumber");
        const m_amount = document.getElementById("m_amount");
        const m_title = document.getElementById("m_title");
        const m_date = document.getElementById("m_date");
        const m_status = document.getElementById("m_status");
        const m_purpose = document.getElementById("m_purpose");

        const img_photo = document.getElementById("img_photo");
        const no_photo = document.getElementById("no_photo");
        const lnk_photo = document.getElementById("lnk_photo");

        const img_idcard = document.getElementById("img_idcard");
        const no_idcard = document.getElementById("no_idcard");
        const lnk_idcard = document.getElementById("lnk_idcard");

        // Helper to adjust path relative to the directory containing index/agent views
        function resolveImagePath(path) {
            if (!path || path.trim() === "" || path === "N/A") {
                return "";
            }
            // If absolute path or directory traversing pattern already exists, return as is
            if (path.startsWith("http://") || path.startsWith("https://") || path.startsWith("../") || path.startsWith("/")) {
                return path;
            }
            // Prepend relative mapping to access root uploads folder
            return "../uploads/farmers/" + path;
        }

        openBtns.forEach(btn => {
            btn.addEventListener("click", function() {
                m_name.textContent = this.getAttribute("data-name");
                m_email.textContent = this.getAttribute("data-email");
                m_phone.textContent = this.getAttribute("data-phone");
                m_address.textContent = this.getAttribute("data-address");
                
                const nationalIdVal = this.getAttribute("data-nationalid");
                m_national_id.textContent = (nationalIdVal && nationalIdVal.trim() !== "") ? nationalIdVal : "No file recorded";
                
                m_farmtype.textContent = this.getAttribute("data-farmtype");
                m_acreage.textContent = this.getAttribute("data-acreage");
                m_idnumber.textContent = this.getAttribute("data-idcardnumber") || "N/A";
                m_amount.textContent = this.getAttribute("data-amount");
                m_title.textContent = this.getAttribute("data-title");
                m_date.textContent = this.getAttribute("data-date");
                m_status.textContent = this.getAttribute("data-status").toUpperCase();
                m_purpose.textContent = this.getAttribute("data-purpose");

                const status = this.getAttribute("data-status");
                if(status === 'pending') m_status.style.color = 'var(--warning)';
                else if(status === 'approved') m_status.style.color = 'var(--success)';
                else m_status.style.color = 'var(--danger)';

                // Handle Farmer Profile Photo Preview
                const photoVal = this.getAttribute("data-photo");
                const resolvedPhotoPath = resolveImagePath(photoVal);
                
                if (resolvedPhotoPath) {
                    img_photo.src = resolvedPhotoPath;
                    img_photo.style.display = "inline-block";
                    lnk_photo.href = resolvedPhotoPath;
                    lnk_photo.style.display = "inline-block";
                    no_photo.style.display = "none";
                } else {
                    img_photo.style.display = "none";
                    lnk_photo.style.display = "none";
                    no_photo.style.display = "flex";
                }

                // Handle ID Card Preview
                const resolvedIdCardPath = resolveImagePath(nationalIdVal);
                
                if (resolvedIdCardPath) {
                    img_idcard.src = resolvedIdCardPath;
                    img_idcard.style.display = "inline-block";
                    lnk_idcard.href = resolvedIdCardPath;
                    lnk_idcard.style.display = "inline-block";
                    no_idcard.style.display = "none";
                } else {
                    img_idcard.style.display = "none";
                    lnk_idcard.style.display = "none";
                    no_idcard.style.display = "flex";
                }

                modal.style.display = "block";
            });
        });

        // Close modal when X is clicked
        closeBtn.addEventListener("click", () => {
            modal.style.display = "none";
        });

        // Close modal when clicking outside the box
        window.addEventListener("click", (e) => {
            if (e.target === modal) {
                modal.style.display = "none";
            }
        });

        // SweetAlert Logic (Success Message)
        <?php if (!empty($successMessage)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?= $successMessage ?>',
                showConfirmButton: false,
                timer: 2000,
                confirmButtonColor: '#1e40af'
            }).then(() => {
                // Remove post data
                window.location.href = 'farmer_vetting.php';
            });
        <?php endif; ?>
    </script>
</body>
</html>