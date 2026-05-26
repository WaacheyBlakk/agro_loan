<?php
// public/farmer_repayment.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username  = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];
$pdo       = getPDO();

$message = '';
$msgType = '';

function getOutstandingBalance(PDO $pdo, array $loan): float {
    // If we already have a persisted balance, use it
    if ($loan['outstanding_balance'] !== null) {
        return (float) $loan['outstanding_balance'];
    }
    // First time: total repayable = principal + interest
    $principal    = (float) $loan['amount'];
    $interestRate = (float) ($loan['interest_rate'] ?? 0);
    return $principal + ($principal * $interestRate / 100);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_id'])) {

    $loan_id        = (int) $_POST['loan_id'];
    $amount_paid    = (float) ($_POST['amount_paid'] ?? 0);
    $repayment_type = in_array($_POST['repayment_type'] ?? '', ['partial','full'])
                        ? $_POST['repayment_type'] : 'partial';

    //Validate the loan belongs to this farmer and is approved
    $stmt = $pdo->prepare("
        SELECT la.*, ap.interest_rate
          FROM loan_applications la
          LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
         WHERE la.id = ? AND la.farmer_id = ? AND la.status = 'approved'
    ");
    $stmt->execute([$loan_id, $farmer_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        $message = "Invalid loan selected or loan is not yet approved.";
        $msgType = "error";
    } else {
        $outstanding = getOutstandingBalance($pdo, $loan);

        // Clamp full repayment to exact balance
        if ($repayment_type === 'full') {
            $amount_paid = $outstanding;
        }

        // Validation
        if ($amount_paid <= 0) {
            $message = "Please enter a valid repayment amount.";
            $msgType = "error";
        } elseif ($amount_paid > $outstanding + 0.01) {
            $message = "Repayment amount (GHc " . number_format($amount_paid, 2) . ") exceeds your outstanding balance (GHc " . number_format($outstanding, 2) . ").";
            $msgType = "error";
        } elseif (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
            $message = "Please upload your payment proof (receipt/screenshot).";
            $msgType = "error";
        } else {
            // Handle file upload
            $file         = $_FILES['payment_proof'];
            $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            $maxSize      = 5 * 1024 * 1024; // 5 MB

            if (!in_array($file['type'], $allowedTypes)) {
                $message = "Invalid file type. Please upload a JPG, PNG, or PDF.";
                $msgType = "error";
            } elseif ($file['size'] > $maxSize) {
                $message = "File is too large. Maximum size is 5 MB.";
                $msgType = "error";
            } else {
                $uploadDir = __DIR__ . "/../uploads/repayments/loan_{$loan_id}/";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $ext          = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename     = 'repayment_' . time() . '_' . uniqid() . '.' . $ext;
                $targetPath   = $uploadDir . $filename;
                $fileType     = strpos($file['type'], 'image') !== false ? 'image' : 'document';

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $balance_before = $outstanding;
                    $balance_after  = max(0, $outstanding - $amount_paid);

                    try {
                        $pdo->beginTransaction();

                        // Insert repayment record
                        $ins = $pdo->prepare("
                            INSERT INTO loan_repayments
                                (loan_id, farmer_id, amount_paid, balance_before, balance_after,
                                 repayment_type, proof_filename, proof_file_type, status, submitted_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                        ");
                        $ins->execute([
                            $loan_id, $farmer_id, $amount_paid,
                            $balance_before, $balance_after,
                            $repayment_type, $filename, $fileType
                        ]);

                        $pdo->commit();

                        $message = "Repayment of GHc " . number_format($amount_paid, 2) . " submitted successfully! Your agent will verify and confirm your payment.";
                        $msgType = "success";

                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $message = "A database error occurred. Please try again.";
                        $msgType = "error";
                    }
                } else {
                    $message = "Failed to save your proof file. Please try again.";
                    $msgType = "error";
                }
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT la.*, u.name AS agent_name, ap.interest_rate, ap.loan_terms
      FROM loan_applications la
      LEFT JOIN users u         ON la.agent_id = u.id
      LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
     WHERE la.farmer_id = ? AND la.status = 'approved'
     ORDER BY la.created_at DESC
");
$stmt->execute([$farmer_id]);
$approved_loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build loan data with computed balances
$loan_data = [];
foreach ($approved_loans as $loan) {
    $principal    = (float) $loan['amount'];
    $interestRate = (float) ($loan['interest_rate'] ?? 0);
    $totalRepayable = $principal + ($principal * $interestRate / 100);
    $outstanding   = getOutstandingBalance($pdo, $loan);
    $paid          = $totalRepayable - $outstanding;

    $loan_data[] = [
        'loan'           => $loan,
        'principal'      => $principal,
        'interest_rate'  => $interestRate,
        'interest_amount'=> $principal * $interestRate / 100,
        'total_repayable'=> $totalRepayable,
        'outstanding'    => $outstanding,
        'total_paid'     => max(0, $paid),
        'percent_paid'   => $totalRepayable > 0 ? min(100, ($paid / $totalRepayable) * 100) : 0,
    ];
}

$histStmt = $pdo->prepare("
    SELECT r.*, la.title AS loan_title, la.amount AS loan_amount
      FROM loan_repayments r
      JOIN loan_applications la ON r.loan_id = la.id
     WHERE r.farmer_id = ?
     ORDER BY r.submitted_at DESC
");
$histStmt->execute([$farmer_id]);
$repayment_history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-select loan from URL param
$selected_loan_id = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Loan Repayment | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/feather-icons"></script>
<style>
    :root {
        --primary: #059669;
        --primary-dark: #064e3b;
        --secondary: #10b981;
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #111827;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --info: #3b82f6;
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06);
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; font-family: 'Poppins', sans-serif;
        background: var(--bg-body); color: var(--text-main);
        display: flex; height: 100vh; overflow: hidden;
    }

    /* ── SIDEBAR ── */
    .sidebar {
        width: var(--sidebar-width); background: var(--primary-dark); color: #fff;
        display: flex; flex-direction: column; padding: 20px;
        transition: width .3s; z-index: 100; box-shadow: 4px 0 10px rgba(0,0,0,.1);
        overflow: hidden;
    }
    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }
    .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding-left: 5px; overflow: hidden; }
    .brand img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 2px solid rgba(255,255,255,.2); }
    .brand h2 { font-size: 20px; font-weight: 600; white-space: nowrap; opacity: 1; transition: opacity .2s; margin: 0; }
    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .nav-link {
        display: flex; align-items: center; gap: 14px; padding: 12px 15px;
        color: #d1fae5; text-decoration: none; border-radius: 10px;
        transition: all .2s; white-space: nowrap; font-weight: 500;
    }
    .nav-link:hover { background: rgba(255,255,255,.1); color: #fff; transform: translateX(4px); }
    .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16,185,129,.3); }
    .nav-link svg { width: 20px; height: 20px; flex-shrink: 0; }
    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }
    .logout-btn {
        background: rgba(239,68,68,.1); color: #fca5a5; border: 1px solid rgba(239,68,68,.2);
        padding: 12px; border-radius: 10px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 10px; font-family: inherit; font-weight: 600; transition: .2s; width: 100%;
    }
    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* ── MAIN ── */
    .main { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
    .topbar {
        background: var(--bg-card); padding: 15px 30px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50;
    }
    .toggle-btn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 5px; }
    .toggle-btn:hover { color: var(--primary); }
    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary); color: white;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* ── CONTENT ── */
    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* ── LOAN SUMMARY CARDS ── */
    .loans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .loan-card {
        background: var(--bg-card); border-radius: 16px; padding: 24px;
        box-shadow: var(--shadow); border: 2px solid transparent;
        cursor: pointer; transition: all .2s; position: relative; overflow: hidden;
    }
    .loan-card:hover { border-color: var(--secondary); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.1); }
    .loan-card.selected { border-color: var(--primary); background: #f0fdf4; }
    .loan-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
    .loan-title { font-size: 15px; font-weight: 600; color: var(--text-main); }
    .loan-agent { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
    .loan-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46; }

    .loan-amounts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .amount-item label { display: block; font-size: 11px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .amount-item .value { font-size: 16px; font-weight: 700; color: var(--text-main); }
    .amount-item .value.outstanding { color: var(--danger); }
    .amount-item .value.paid { color: var(--primary); }
    .amount-item .value.total { color: var(--info); }

    /* Progress bar */
    .progress-wrap { margin-bottom: 16px; }
    .progress-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
    .progress-bar { height: 8px; background: #e5e7eb; border-radius: 99px; overflow: hidden; }
    .progress-fill { height: 100%; background: linear-gradient(90deg, var(--secondary), var(--primary)); border-radius: 99px; transition: width .5s; }

    .repay-btn {
        width: 100%; padding: 10px; border-radius: 10px; border: none;
        background: var(--primary); color: white; font-weight: 600; font-size: 14px;
        cursor: pointer; transition: background .2s; display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .repay-btn:hover { background: var(--primary-dark); }
    .fully-paid-badge {
        width: 100%; padding: 10px; border-radius: 10px;
        background: #d1fae5; color: #065f46; font-weight: 600; font-size: 14px;
        text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px;
    }

    /* ── REPAYMENT MODAL / FORM ── */
    .modal-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.5); z-index: 200; align-items: center; justify-content: center;
        padding: 20px;
    }
    .modal-overlay.open { display: flex; }
    .modal {
        background: var(--bg-card); border-radius: 20px; padding: 32px;
        width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,.2); position: relative;
    }
    .modal-close {
        position: absolute; top: 16px; right: 16px; background: #f3f4f6;
        border: none; cursor: pointer; border-radius: 8px; padding: 6px; color: var(--text-muted);
        transition: .2s;
    }
    .modal-close:hover { background: #e5e7eb; color: var(--text-main); }
    .modal-title { font-size: 20px; font-weight: 700; margin: 0 0 6px; }
    .modal-subtitle { color: var(--text-muted); font-size: 13px; margin-bottom: 24px; }

    /* Summary box */
    .summary-box {
        background: #f0fdf4; border: 1px solid #a7f3d0; border-radius: 12px;
        padding: 16px; margin-bottom: 24px;
    }
    .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; }
    .summary-row:not(:last-child) { border-bottom: 1px solid #d1fae5; }
    .summary-row .s-label { font-size: 13px; color: var(--text-muted); }
    .summary-row .s-value { font-size: 14px; font-weight: 600; color: var(--text-main); }
    .summary-row .s-value.outstanding { color: var(--danger); font-size: 16px; }

    /* Form */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
    .form-group input[type="number"],
    .form-group select,
    .form-group textarea {
        width: 100%; padding: 12px; border-radius: 8px; border: 1.5px solid #d1d5db;
        background: #f9fafb; font-family: inherit; font-size: 14px; color: var(--text-main);
        outline: none; transition: .2s;
    }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(16,185,129,.1); }

    /* Repayment type toggle */
    .type-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
    .type-option { position: relative; }
    .type-option input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
    .type-option label {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 14px; border-radius: 12px; border: 2px solid #e5e7eb;
        cursor: pointer; transition: .2s; text-align: center; gap: 6px;
        background: #f9fafb;
    }
    .type-option label svg { width: 22px; height: 22px; color: var(--text-muted); }
    .type-option label .type-name { font-size: 14px; font-weight: 600; color: var(--text-main); }
    .type-option label .type-desc { font-size: 11px; color: var(--text-muted); }
    .type-option input:checked + label { border-color: var(--primary); background: #ecfdf5; }
    .type-option input:checked + label svg { color: var(--primary); }

    /* Upload zone */
    .upload-zone {
        border: 2px dashed #d1d5db; border-radius: 12px; padding: 30px 20px;
        text-align: center; background: #f9fafb; cursor: pointer;
        position: relative; transition: .2s;
    }
    .upload-zone:hover, .upload-zone.dragover { border-color: var(--primary); background: #ecfdf5; }
    .upload-zone .upload-icon { color: var(--text-muted); width: 36px; height: 36px; margin-bottom: 8px; }
    .upload-zone .upload-text { font-size: 13px; color: var(--text-muted); }
    .upload-zone .upload-text strong { color: var(--primary); }
    .file-input-hidden { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }

    .amount-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
    .amount-hint span { color: var(--danger); font-weight: 600; }

    /* Buttons */
    .btn-submit {
        background: var(--primary); color: white; width: 100%; padding: 14px;
        border-radius: 10px; border: none; font-weight: 700; font-size: 15px;
        cursor: pointer; transition: background .2s;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover { background: var(--primary-dark); }
    .btn-submit:disabled { background: #9ca3af; cursor: not-allowed; }

    /* Alert */
    .alert {
        padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
        display: flex; align-items: flex-start; gap: 10px; font-size: 14px; font-weight: 500;
    }
    .alert svg { flex-shrink: 0; width: 18px; height: 18px; margin-top: 1px; }
    .alert.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* ── HISTORY TABLE ── */
    .section-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; color: var(--text-main); }
    .table-container { background: var(--bg-card); border-radius: 16px; padding: 24px; box-shadow: var(--shadow); overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); font-weight: 600; background: #f9fafb; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #f9fafb; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
    .badge.pending   { background: #fef3c7; color: #92400e; }
    .badge.confirmed { background: #d1fae5; color: #065f46; }
    .badge.rejected  { background: #fee2e2; color: #991b1b; }
    .badge.partial   { background: #eff6ff; color: #1d4ed8; }
    .badge.full      { background: #faf5ff; color: #6d28d9; }

    .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
    .empty-state svg { width: 48px; height: 48px; margin-bottom: 12px; opacity: .4; }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .loans-grid { grid-template-columns: 1fr; }
        .loan-amounts { grid-template-columns: 1fr 1fr; }
    }
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
        <h2>AgroLoan Farmer</h2>
    </div>
    <nav class="nav">
        <a href="farmer_dashboard.php" class="nav-link">
            <i data-feather="home"></i><span>Dashboard</span>
        </a>
        <a href="add_product.php" class="nav-link">
            <i data-feather="shopping-bag"></i><span>Add Produce</span>
        </a>
        <a href="apply_loan.php" class="nav-link">
            <i data-feather="dollar-sign"></i><span>Apply for Loan</span>
        </a>
        <a href="view_application.php" class="nav-link">
            <i data-feather="file-text"></i><span>Applications</span>
        </a>
        <a href="upload_proof.php" class="nav-link">
            <i data-feather="upload-cloud"></i><span>Upload Proof</span>
        </a>
        <a href="farmer_repayment.php" class="nav-link active">
            <i data-feather="credit-card"></i><span>Repayments</span>
        </a>
        <a href="farmer_profile.php" class="nav-link">
            <i data-feather="user"></i><span>Profile</span>
        </a>
    </nav>
    <form action="logout.php" method="POST">
        <button class="logout-btn">
            <i data-feather="log-out"></i><span>Logout</span>
        </button>
    </form>
</aside>

<main class="main">
    <header class="topbar">
        <button id="toggleBtn" class="toggle-btn"><i data-feather="menu"></i></button>
        <div class="user-profile">
            <div style="text-align:right;margin-right:8px;">
                <div style="font-size:14px;font-weight:600;"><?= htmlspecialchars($username) ?></div>
                <div style="font-size:12px;color:var(--text-muted);">Farmer</div>
            </div>
            <div class="user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
        </div>
    </header>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title">Loan Repayments</h1>
            <p class="page-subtitle">View your loan balances, make partial or full repayments, and upload payment proofs for agent confirmation.</p>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert <?= $msgType === 'error' ? 'error' : 'success' ?>">
            <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <?php endif; ?>

        <?php if (empty($loan_data)): ?>
        <div class="table-container">
            <div class="empty-state">
                <i data-feather="inbox"></i>
                <p style="font-weight:600;font-size:15px;">No approved loans found</p>
                <p style="font-size:13px;">Once your loan application is approved, it will appear here for repayment.</p>
                <a href="apply_loan.php" style="color:var(--primary);font-weight:600;text-decoration:none;">Apply for a loan →</a>
            </div>
        </div>
        <?php else: ?>

        <p class="section-title">Your Active Loans</p>
        <div class="loans-grid">
            <?php foreach ($loan_data as $ld):
                $loan    = $ld['loan'];
                $isFullyPaid = $ld['outstanding'] <= 0.01;
                $isSelected  = $selected_loan_id == $loan['id'];
            ?>
            <div class="loan-card <?= $isSelected ? 'selected' : '' ?>" id="card-<?= $loan['id'] ?>">
                <div class="loan-card-header">
                    <div>
                        <div class="loan-title"><?= htmlspecialchars($loan['title'] ?? 'Loan #' . $loan['id']) ?></div>
                        <div class="loan-agent">Agent: <?= htmlspecialchars($loan['agent_name'] ?? '—') ?></div>
                    </div>
                    <span class="loan-badge"><?= $isFullyPaid ? '✓ Cleared' : 'Active' ?></span>
                </div>

                <div class="loan-amounts">
                    <div class="amount-item">
                        <label>Loan Principal</label>
                        <div class="value">GHc <?= number_format($ld['principal'], 2) ?></div>
                    </div>
                    <div class="amount-item">
                        <label>Interest (<?= $ld['interest_rate'] ?>%)</label>
                        <div class="value">GHc <?= number_format($ld['interest_amount'], 2) ?></div>
                    </div>
                    <div class="amount-item">
                        <label>Total Repayable</label>
                        <div class="value total">GHc <?= number_format($ld['total_repayable'], 2) ?></div>
                    </div>
                    <div class="amount-item">
                        <label>Outstanding Balance</label>
                        <div class="value outstanding">GHc <?= number_format($ld['outstanding'], 2) ?></div>
                    </div>
                </div>

                <div class="progress-wrap">
                    <div class="progress-label">
                        <span>Paid: GHc <?= number_format($ld['total_paid'], 2) ?></span>
                        <span><?= number_format($ld['percent_paid'], 1) ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?= $ld['percent_paid'] ?>%"></div>
                    </div>
                </div>

                <?php if ($isFullyPaid): ?>
                <div class="fully-paid-badge">
                    <i data-feather="check-circle"></i> Fully Repaid
                </div>
                <?php else: ?>
                <button class="repay-btn"
                    onclick="openRepayModal(
                        <?= $loan['id'] ?>,
                        '<?= addslashes(htmlspecialchars($loan['title'] ?? 'Loan #' . $loan['id'])) ?>',
                        <?= $ld['principal'] ?>,
                        <?= $ld['interest_amount'] ?>,
                        <?= $ld['total_repayable'] ?>,
                        <?= $ld['outstanding'] ?>
                    )">
                    <i data-feather="credit-card"></i> Make a Repayment
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <p class="section-title" style="margin-top:20px;">Repayment History</p>
        <div class="table-container">
            <?php if (empty($repayment_history)): ?>
            <div class="empty-state">
                <i data-feather="list"></i>
                <p>No repayment records yet.</p>
            </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Loan</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount Paid</th>
                        <th>Balance Before</th>
                        <th>Balance After</th>
                        <th>Status</th>
                        <th>Proof</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($repayment_history as $r): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['loan_title'] ?? 'Loan #' . $r['loan_id']) ?></td>
                    <td><?= date('M d, Y H:i', strtotime($r['submitted_at'])) ?></td>
                    <td><span class="badge <?= $r['repayment_type'] ?>"><?= ucfirst($r['repayment_type']) ?></span></td>
                    <td style="font-weight:700;color:var(--primary);">GHc <?= number_format($r['amount_paid'], 2) ?></td>
                    <td style="color:var(--text-muted);">GHc <?= number_format($r['balance_before'], 2) ?></td>
                    <td style="color:var(--danger);">GHc <?= number_format($r['balance_after'], 2) ?></td>
                    <td><span class="badge <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td>
                        <?php if ($r['proof_filename']): ?>
                        <a href="../uploads/repayments/loan_<?= $r['loan_id'] ?>/<?= htmlspecialchars($r['proof_filename']) ?>"
                           target="_blank"
                           style="color:var(--primary);font-size:12px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px;">
                            <i data-feather="eye" style="width:14px;height:14px;"></i> View
                        </a>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</main>

<div class="modal-overlay" id="repayModal">
    <div class="modal">
        <button class="modal-close" onclick="closeRepayModal()">
            <i data-feather="x"></i>
        </button>

        <h2 class="modal-title">Make a Repayment</h2>
        <p class="modal-subtitle" id="modalLoanName">Loan repayment form</p>

        <!-- Loan Summary -->
        <div class="summary-box">
            <div class="summary-row">
                <span class="s-label">Principal Amount</span>
                <span class="s-value" id="sPrincipal">—</span>
            </div>
            <div class="summary-row">
                <span class="s-label">Agent Interest</span>
                <span class="s-value" id="sInterest">—</span>
            </div>
            <div class="summary-row">
                <span class="s-label">Total Repayable</span>
                <span class="s-value" id="sTotal">—</span>
            </div>
            <div class="summary-row">
                <span class="s-label" style="font-weight:700;">Outstanding Balance</span>
                <span class="s-value outstanding" id="sOutstanding">—</span>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="repayForm">
            <input type="hidden" name="loan_id" id="formLoanId">

            <!-- Repayment Type -->
            <div class="form-group">
                <label>Repayment Type</label>
                <div class="type-toggle">
                    <div class="type-option">
                        <input type="radio" name="repayment_type" id="typePartial" value="partial" checked>
                        <label for="typePartial" onclick="setPartial()">
                            <i data-feather="trending-up"></i>
                            <span class="type-name">Partial</span>
                            <span class="type-desc">Pay any amount towards your balance</span>
                        </label>
                    </div>
                    <div class="type-option">
                        <input type="radio" name="repayment_type" id="typeFull" value="full">
                        <label for="typeFull" onclick="setFull()">
                            <i data-feather="check-circle"></i>
                            <span class="type-name">Full</span>
                            <span class="type-desc">Clear your entire outstanding balance</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Amount -->
            <div class="form-group" id="amountGroup">
                <label for="amount_paid">Repayment Amount (GHc)</label>
                <input type="number" name="amount_paid" id="amount_paid"
                       placeholder="Enter amount" min="1" step="0.01" required>
                <div class="amount-hint">
                    Outstanding balance: <span id="hintOutstanding">—</span>
                </div>
            </div>

            <!-- Payment Proof Upload -->
            <div class="form-group">
                <label>Payment Proof <span style="color:var(--danger);">*</span></label>
                <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px;">
                    Upload a receipt, bank screenshot, or mobile money confirmation.
                </p>
                <div class="upload-zone" id="modalDropArea">
                    <input type="file" name="payment_proof" id="payment_proof"
                           class="file-input-hidden" accept=".jpg,.jpeg,.png,.pdf" required>
                    <i data-feather="upload-cloud" class="upload-icon"></i>
                    <div class="upload-text" id="modalFileMsg">
                        <strong>Click to upload</strong> or drag and drop<br>
                        <span style="font-size:11px;">(JPG, PNG, or PDF · Max 5 MB)</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <i data-feather="send"></i> Submit Repayment
            </button>
        </form>
    </div>
</div>

<script>
feather.replace();

// Sidebar toggle
const toggleBtn = document.getElementById('toggleBtn');
const sidebar   = document.getElementById('sidebar');
toggleBtn.addEventListener('click', () => {
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
    }
    // Re-render icons after collapse (they may resize)
    setTimeout(() => feather.replace(), 310);
});

// ── Modal Logic ──
let currentOutstanding = 0;

function openRepayModal(loanId, loanName, principal, interest, total, outstanding) {
    currentOutstanding = outstanding;

    document.getElementById('formLoanId').value    = loanId;
    document.getElementById('modalLoanName').textContent = loanName;
    document.getElementById('sPrincipal').textContent   = 'GHc ' + formatNum(principal);
    document.getElementById('sInterest').textContent    = 'GHc ' + formatNum(interest);
    document.getElementById('sTotal').textContent       = 'GHc ' + formatNum(total);
    document.getElementById('sOutstanding').textContent = 'GHc ' + formatNum(outstanding);
    document.getElementById('hintOutstanding').textContent = 'GHc ' + formatNum(outstanding);

    // Reset form
    document.getElementById('repayForm').reset();
    document.getElementById('typePartial').checked = true;
    document.getElementById('amountGroup').style.display = 'block';
    document.getElementById('amount_paid').max = outstanding;
    document.getElementById('amount_paid').value = '';
    document.getElementById('modalFileMsg').innerHTML =
        '<strong>Click to upload</strong> or drag and drop<br><span style="font-size:11px;">(JPG, PNG, or PDF · Max 5 MB)</span>';

    document.getElementById('repayModal').classList.add('open');
    feather.replace();
}

function closeRepayModal() {
    document.getElementById('repayModal').classList.remove('open');
}

function setPartial() {
    document.getElementById('amountGroup').style.display = 'block';
    document.getElementById('amount_paid').value = '';
    document.getElementById('amount_paid').removeAttribute('readonly');
}

function setFull() {
    document.getElementById('amountGroup').style.display = 'block';
    document.getElementById('amount_paid').value = currentOutstanding.toFixed(2);
    document.getElementById('amount_paid').setAttribute('readonly', true);
}

// Live validation on amount
document.getElementById('amount_paid').addEventListener('input', function() {
    const val = parseFloat(this.value);
    const btn = document.getElementById('submitBtn');
    if (val > currentOutstanding) {
        this.style.borderColor = 'var(--danger)';
        btn.disabled = true;
    } else {
        this.style.borderColor = '';
        btn.disabled = false;
    }
});

// File upload feedback
document.getElementById('payment_proof').addEventListener('change', function() {
    const msg = document.getElementById('modalFileMsg');
    if (this.files && this.files.length > 0) {
        msg.innerHTML = '<strong style="color:var(--primary);">Selected:</strong> ' + this.files[0].name;
    }
});

// Drag & drop visual
const dropArea = document.getElementById('modalDropArea');
['dragenter','dragover'].forEach(e => {
    dropArea.addEventListener(e, ev => { ev.preventDefault(); dropArea.classList.add('dragover'); });
});
['dragleave','drop'].forEach(e => {
    dropArea.addEventListener(e, ev => { ev.preventDefault(); dropArea.classList.remove('dragover'); });
});

// Close modal on overlay click
document.getElementById('repayModal').addEventListener('click', function(e) {
    if (e.target === this) closeRepayModal();
});

// Format numbers
function formatNum(n) {
    return parseFloat(n).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Auto-open modal if loan_id is in URL
<?php if ($selected_loan_id): ?>
window.addEventListener('load', () => {
    const card = document.getElementById('card-<?= $selected_loan_id ?>');
    if (card) {
        const btn = card.querySelector('.repay-btn');
        if (btn) btn.click();
    }
});
<?php endif; ?>
</script>
</body>
</html>