<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
//admin_loan_oversight.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

// Role Verification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Administrator';
$pdo = getPDO();

$successMessage = '';
$errorMessage = '';

/**
 * Dispatches an HTML email notification from the administration desk.
 */
function send_admin_email_notification($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: AgroLoan Admin Portal <admin-support@agroloan.com>" . "\r\n";
    return @mail($to, $subject, $message, $headers);
}

/**
 * Logs SMS dispatches for local administrative record keeping.
 */
function log_admin_sms_notification($phone, $message) {
    error_log("ADMIN SMS Dispatch to {$phone}: {$message}");
    return true;
}

/* ==========================================
   ADMINISTRATIVE INTERVENTION PROCESSING
   ========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intervention_type'])) {
    csrf_verify();
    $intervention = $_POST['intervention_type'];
    $loan_id = isset($_POST['loan_id']) ? intval($_POST['loan_id']) : 0;

    try {
        $pdo->beginTransaction();

        // 1. Manually Override Dispute Case
        if ($intervention === 'resolve_dispute') {
            $dispute_id = intval($_POST['dispute_id'] ?? 0);
            $decision = trim($_POST['admin_decision'] ?? '');
            $status = $_POST['status'] ?? 'resolved'; // resolved or dismissed

            if ($dispute_id <= 0 || empty($decision)) {
                throw new Exception("Please specify a dispute reference and provide a decision message.");
            }

            $stmt = $pdo->prepare("UPDATE disputes SET status = ?, admin_decision = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $decision, $dispute_id]);

            // Notify disputing parties
            $dispStmt = $pdo->prepare("
                SELECT d.title, uc.name AS creator_name, uc.email AS creator_email, ud.name AS defendant_name, ud.email AS defendant_email
                FROM disputes d
                JOIN users uc ON d.creator_id = uc.id
                JOIN users ud ON d.defendant_id = ud.id
                WHERE d.id = ?
            ");
            $dispStmt->execute([$dispute_id]);
            $parties = $dispStmt->fetch(PDO::FETCH_ASSOC);

            if ($parties) {
                $subject = "Administrative Decision Issued: " . $parties['title'];
                $email_body = "
                    <h2>Administrative Review Action Notice</h2>
                    <p>The system administration team has formally closed the dispute file regarding '<strong>" . htmlspecialchars($parties['title']) . "</strong>'.</p>
                    <p><strong>Official Directive/Decision:</strong></p>
                    <blockquote style='background:#f3f4f6; padding:15px; border-left:4px solid #4f46e5;'>" . nl2br(htmlspecialchars($decision)) . "</blockquote>
                    <p>Status set to: <strong>" . ucfirst($status) . "</strong></p>
                    <p>Please log in to review any updated transaction statuses or repayments related to this override.</p>
                ";

                if (!empty($parties['creator_email'])) {
                    send_admin_email_notification($parties['creator_email'], $subject, $email_body);
                }
                if (!empty($parties['defendant_email'])) {
                    send_admin_email_notification($parties['defendant_email'], $subject, $email_body);
                }
            }

            $successMessage = "Dispute reference #{$dispute_id} updated. Decision registered and parties notified.";
        }

        // 2. Override Stage Status (Unblocking Stalls)
        elseif ($intervention === 'override_stage') {
            $stage_id = intval($_POST['stage_id'] ?? 0);
            $new_stage_status = $_POST['stage_status'] ?? ''; 
            $disbursed_flag = isset($_POST['disbursed']) ? intval($_POST['disbursed']) : 0;

            if ($stage_id <= 0 || empty($new_stage_status)) {
                throw new Exception("Missing required stage identifier or status override value.");
            }

            // Get original stage details prior to manual override
            $origStmt = $pdo->prepare("SELECT application_id, required_amount, disbursed, stage_number FROM loan_stages WHERE id = ?");
            $origStmt->execute([$stage_id]);
            $origStage = $origStmt->fetch(PDO::FETCH_ASSOC);

            if (!$origStage) {
                throw new Exception("Stage record not identified.");
            }

            // Update individual stage status
            $upd = $pdo->prepare("UPDATE loan_stages SET status = ?, disbursed = ? WHERE id = ?");
            $upd->execute([$new_stage_status, $disbursed_flag, $stage_id]);

            // Adjust disbursement amount tracker on main application if flag flipped
            if ($disbursed_flag == 1 && $origStage['disbursed'] == 0) {
                $updApp = $pdo->prepare("UPDATE loan_applications SET disbursed_amount = disbursed_amount + ? WHERE id = ?");
                $updApp->execute([$origStage['required_amount'], $origStage['application_id']]);
            } elseif ($disbursed_flag == 0 && $origStage['disbursed'] == 1) {
                $updApp = $pdo->prepare("UPDATE loan_applications SET disbursed_amount = GREATEST(0, disbursed_amount - ?) WHERE id = ?");
                $updApp->execute([$origStage['required_amount'], $origStage['application_id']]);
            }

            // Check if stage is marked completed ('verified') and increment active stage indexes
            if ($new_stage_status === 'verified') {
                $nextStage = $origStage['stage_number'] + 1;
                $updIndex = $pdo->prepare("UPDATE loan_applications SET current_stage = ? WHERE id = ?");
                $updIndex->execute([$nextStage, $origStage['application_id']]);

                // Check overall application complete logic
                $checkRem = $pdo->prepare("SELECT COUNT(*) FROM loan_stages WHERE application_id = ? AND status != 'verified'");
                $checkRem->execute([$origStage['application_id']]);
                if ($checkRem->fetchColumn() == 0) {
                    $pdo->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?")->execute([$origStage['application_id']]);
                }
            }

            $successMessage = "Stage reference #{$stage_id} manually adjusted to '{$new_stage_status}'. Disbursement limits recalculated.";
        }

        // 3. Override Specific Evidence Proof
        elseif ($intervention === 'override_proof') {
            $proof_id = intval($_POST['proof_id'] ?? 0);
            $new_proof_status = $_POST['proof_status'] ?? ''; // verified, rejected

            if ($proof_id <= 0 || !in_array($new_proof_status, ['verified', 'rejected'])) {
                throw new Exception("Specify a valid proof file and verification action.");
            }

            $upd = $pdo->prepare("UPDATE stage_proofs SET status = ? WHERE id = ?");
            $upd->execute([$new_proof_status, $proof_id]);

            $successMessage = "Proof document status manually overridden to '{$new_proof_status}'.";
        }

        // 4. Force Update Main Application Lifecycle Status
        elseif ($intervention === 'override_application') {
            $new_app_status = $_POST['app_status'] ?? '';
            $current_stage = intval($_POST['current_stage'] ?? 1);
            $outstanding = !empty($_POST['outstanding_balance']) ? floatval($_POST['outstanding_balance']) : null;

            if (empty($new_app_status) || $loan_id <= 0) {
                throw new Exception("Select a clean application state reference.");
            }

            $upd = $pdo->prepare("
                UPDATE loan_applications 
                SET status = ?, current_stage = ?, outstanding_balance = ? 
                WHERE id = ?
            ");
            $upd->execute([$new_app_status, $current_stage, $outstanding, $loan_id]);

            $successMessage = "Main loan profile status overridden. Active stage state tracking restructured.";
        }

        // 5. Override Repayment Verification Status (Oversight Desk)
        elseif ($intervention === 'override_repayment') {
            $repayment_id = intval($_POST['repayment_id'] ?? 0);
            $new_repayment_status = $_POST['repayment_status'] ?? ''; // confirmed, rejected, pending

            if ($repayment_id <= 0 || !in_array($new_repayment_status, ['pending', 'confirmed', 'rejected'])) {
                throw new Exception("Specify a valid repayment status override.");
            }

            // Retrieve original repayment parameters
            $repOrigStmt = $pdo->prepare("SELECT loan_id, amount_paid, status FROM loan_repayments WHERE id = ?");
            $repOrigStmt->execute([$repayment_id]);
            $repOrig = $repOrigStmt->fetch(PDO::FETCH_ASSOC);

            if (!$repOrig) {
                throw new Exception("Repayment reference not found.");
            }

            $old_status = $repOrig['status'];

            // Update status
            $updRep = $pdo->prepare("UPDATE loan_repayments SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $updRep->execute([$new_repayment_status, $admin_id, $repayment_id]);

            // Adjust outstanding balance on parent application if state flipped to/from Confirmed
            if ($new_repayment_status === 'confirmed' && $old_status !== 'confirmed') {
                $updApp = $pdo->prepare("UPDATE loan_applications SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id = ?");
                $updApp->execute([$repOrig['amount_paid'], $repOrig['loan_id']]);

                // Recalculate if fully paid
                $balCheck = $pdo->prepare("SELECT outstanding_balance FROM loan_applications WHERE id = ?");
                $balCheck->execute([$repOrig['loan_id']]);
                $outstanding = (float)$balCheck->fetchColumn();
                if ($outstanding <= 0.01) {
                    $pdo->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?")->execute([$repOrig['loan_id']]);
                }
            } elseif ($new_repayment_status !== 'confirmed' && $old_status === 'confirmed') {
                // Revert deduction
                $updApp = $pdo->prepare("UPDATE loan_applications SET outstanding_balance = outstanding_balance + ? WHERE id = ?");
                $updApp->execute([$repOrig['amount_paid'], $repOrig['loan_id']]);

                // Revert completed state
                $pdo->prepare("UPDATE loan_applications SET status = 'approved' WHERE id = ? AND status = 'completed'")->execute([$repOrig['loan_id']]);
            }

            $successMessage = "Repayment transaction reference #{$repayment_id} overridden to '{$new_repayment_status}'. Outstanding balance adjustments recalculated.";
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Intervention Aborted: " . $e->getMessage();
    }
}

// Read-only single drill-down load evaluation
$selected_loan_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$selected_loan = null;
$stages = [];
$disputes = [];
$repayments = [];

if ($selected_loan_id) {
    // Fetch loan parameters
    $stmt = $pdo->prepare("
        SELECT la.*, 
               uf.name AS farmer_name, uf.email AS farmer_email, uf.phone AS farmer_phone,
               ua.name AS agent_name, ua.email AS agent_email, ua.phone AS agent_phone,
               ap.interest_rate
        FROM loan_applications la
        LEFT JOIN users uf ON la.farmer_id = uf.id
        LEFT JOIN users ua ON la.agent_id = ua.id
        LEFT JOIN agent_profiles ap ON la.agent_id = ap.user_id
        WHERE la.id = ?
    ");
    $stmt->execute([$selected_loan_id]);
    $selected_loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_loan) {
        // Fetch stages
        $stStmt = $pdo->prepare("SELECT * FROM loan_stages WHERE application_id = ? ORDER BY stage_number ASC");
        $stStmt->execute([$selected_loan_id]);
        $stages = $stStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch related dispute entries
        $dispStmt = $pdo->prepare("
            SELECT d.*, uc.name AS creator_name, ud.name AS defendant_name
            FROM disputes d
            JOIN users uc ON d.creator_id = uc.id
            JOIN users ud ON d.defendant_id = ud.id
            WHERE d.loan_id = ?
            ORDER BY d.created_at DESC
        ");
        $dispStmt->execute([$selected_loan_id]);
        $disputes = $dispStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch repayment records with reviewer information
        $repStmt = $pdo->prepare("
            SELECT r.*, u.name AS reviewer_name 
            FROM loan_repayments r
            LEFT JOIN users u ON r.reviewed_by = u.id
            WHERE r.loan_id = ? 
            ORDER BY r.submitted_at DESC
        ");
        $repStmt->execute([$selected_loan_id]);
        $repayments = $repStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch global list of loans for dashboard overview
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$dispute_filter = $_GET['has_dispute'] ?? '';

$query_str = "
    SELECT la.*, 
           uf.name AS farmer_name, 
           ua.name AS agent_name,
           (SELECT COUNT(*) FROM disputes d WHERE d.loan_id = la.id AND d.status = 'pending') AS active_disputes_count
    FROM loan_applications la
    LEFT JOIN users uf ON la.farmer_id = uf.id
    LEFT JOIN users ua ON la.agent_id = ua.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query_str .= " AND (la.title LIKE ? OR uf.name LIKE ? OR ua.name LIKE ?)";
    $bind = "%{$search}%";
    $params[] = $bind;
    $params[] = $bind;
    $params[] = $bind;
}

if (!empty($status_filter)) {
    $query_str .= " AND la.status = ?";
    $params[] = $status_filter;
}

if ($dispute_filter === '1') {
    $query_str .= " AND la.id IN (SELECT DISTINCT loan_id FROM disputes WHERE status = 'pending')";
}

$query_str .= " ORDER BY active_disputes_count DESC, la.created_at DESC";
$stmtAll = $pdo->prepare($query_str);
$stmtAll->execute($params);
$all_loans = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// Total overview metrics
$metrics = [
    'total_active' => 0,
    'total_disbursed' => 0.0,
    'open_disputes' => 0
];
$m_stmt = $pdo->query("SELECT status, disbursed_amount FROM loan_applications");
while ($row = $m_stmt->fetch(PDO::FETCH_ASSOC)) {
    if (in_array($row['status'], ['approved', 'disbursed', 'pending'])) $metrics['total_active']++;
    $metrics['total_disbursed'] += (float)$row['disbursed_amount'];
}
$metrics['open_disputes'] = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Oversight Desk | AgroLoan Administration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Feather Icons -->
    <script src="https://unpkg.com/feather-icons"></script>

    <style>
        :root {
            --primary: #059669; /* Emerald 600 */
            --primary-dark: #576868ff;
            --secondary: #10b981; /* Emerald 500 */
            --bg-body: #f3f4f6;
            --bg-card: #ffffff;
            --text-main: #111827;
            --text-muted: #6b7280;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --sidebar-width: 260px;
            --sidebar-collapsed: 80px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            --border-color: #e5e7eb;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            min-height: 100dvh;
            height: 100vh;
            overflow: hidden;
            width: 100%;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--primary-dark);
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 20px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            flex-shrink: 0;
            height: 100%;
        }

        .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }
        
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            padding-left: 4px;
            overflow: hidden;
        }

        .brand img {
            width: 38px; height: 38px; border-radius: 8px;
            object-fit: cover; border: 2px solid rgba(255,255,255,0.2);
            flex-shrink: 0;
        }

        .brand h2 {
            font-size: 18px; font-weight: 600; white-space: nowrap;
            opacity: 1; transition: opacity 0.2s; color: #fff;
        }

        .sidebar.collapsed .brand h2 { opacity: 0; width: 0; display: none; }
        
        .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; overflow-y: auto; overflow-x: hidden; }

        .nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; color: #d1fae5; text-decoration: none;
            border-radius: 10px; transition: all 0.2s ease;
            white-space: nowrap; font-weight: 500; font-size: 13.5px;
        }

        .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(3px); }
        .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .nav-link svg { width: 18px; height: 18px; flex-shrink: 0; }

        .sidebar.collapsed .nav-link { justify-content: center; padding: 11px; }
        .sidebar.collapsed .nav-link span { display: none; }

        .logout-btn {
            background: rgba(239, 68, 68, 0.12); color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 11px; border-radius: 10px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            gap: 10px; font-family: inherit; font-weight: 600; font-size: 13.5px;
            transition: 0.2s; width: 100%; margin-top: 15px;
        }
        .logout-btn:hover { background: var(--danger); color: white; }
        .sidebar.collapsed .logout-btn span { display: none; }

        /* --- MAIN VIEWPORT & TOPBAR --- */
        .main {
            flex: 1; display: flex; flex-direction: column;
            min-width: 0; width: 100%; height: 100%;
            overflow-y: auto; overflow-x: hidden; position: relative;
        }

        .topbar {
            background: var(--bg-card); padding: 12px 24px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: var(--shadow); position: sticky; top: 0; z-index: 40;
            width: 100%; border-bottom: 1px solid var(--border-color);
        }

        .toggle-btn {
            background: transparent; border: none; color: var(--text-muted);
            cursor: pointer; padding: 6px; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 6px;
        }
        .toggle-btn:hover { color: var(--primary); background: #f1f5f9; }

        .user-profile { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .user-meta { text-align: right; line-height: 1.2; }
        .user-name { font-size: 13px; font-weight: 600; color: var(--text-main); white-space: nowrap; }
        .user-role { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
        .user-avatar {
            width: 36px; height: 36px; background: var(--primary); color: white;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 14px; flex-shrink: 0;
        }

        /* --- PAGE CONTENT --- */
        .content {
            padding: 24px;
            max-width: 1350px;
            width: 100%;
            margin: 0 auto;
            min-width: 0;
        }

        .page-header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            width: 100%;
        }
        .page-title { font-size: 22px; font-weight: 700; color: var(--text-main); line-height: 1.25; }
        .page-subtitle { color: var(--text-muted); margin-top: 4px; font-size: 13px; }

        /* METRIC CARDS */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
            width: 100%;
        }
        .metric-card {
            background: var(--bg-card); border-radius: 12px; padding: 18px 20px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: var(--shadow); border: 1px solid var(--border-color);
            min-width: 0;
        }
        .metric-info { min-width: 0; }
        .metric-info h4 { margin: 0; font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-info p { margin: 4px 0 0; font-size: 20px; font-weight: 700; color: var(--text-main); word-break: break-word; }
        .metric-icon { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-left: 12px; }
        .icon-blue { background: #e0e7ff; color: #4f46e5; }
        .icon-green { background: #d1fae5; color: #059669; }
        .icon-red { background: #fee2e2; color: #ef4444; }

        /* FILTERS BAR (Matched to User Management) */
        .filters-bar {
            background: var(--bg-card);
            padding: 18px 20px;
            border-radius: 14px;
            box-shadow: var(--shadow);
            margin-bottom: 22px;
            border: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 2fr 1.2fr 1fr auto;
            gap: 14px;
            align-items: flex-end;
            width: 100%;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 0;
            width: 100%;
        }

        .filter-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-input {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            color: var(--text-main);
            outline: none;
            background-color: #fafbfa;
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 100%;
            height: 38px;
        }

        select.filter-input {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 14px;
            padding-right: 32px;
            cursor: pointer;
        }

        .filter-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }

        .filter-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-search {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 16px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-family: inherit;
            height: 38px;
            transition: background-color 0.2s;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-search:hover { background-color: var(--secondary); }

        .btn-action {
            text-decoration: none;
            padding: 0 14px;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            font-family: inherit;
            height: 38px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-action:hover { border-color: var(--primary); color: var(--primary); background: #f0fdfa; }

        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 38px;
        }
        .btn-submit:hover { background: var(--secondary); }

        /* CARDS & TABLES */
        .card {
            background: var(--bg-card);
            border-radius: 14px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: 22px;
            overflow: hidden;
            width: 100%;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            display: block;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 820px;
        }
        .table thead { background: #f9fafb; border-bottom: 2px solid var(--border-color); }
        .table th { padding: 13px 18px; text-align: left; font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .table td { padding: 13px 18px; border-bottom: 1px solid var(--border-color); vertical-align: middle; font-size: 13px; }
        .table tr:last-child td { border-bottom: none; }
        .table tbody tr:hover { background: #f8fafc; }

        /* BADGES */
        .badge {
            padding: 4px 9px; border-radius: 50px; font-size: 11px;
            font-weight: 600; display: inline-flex; align-items: center; gap: 4px;
            text-transform: capitalize; white-space: nowrap;
        }
        .badge-pending { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
        .badge-approved, .badge-verified, .badge-confirmed { background: #ecfdf5; color: #065f46; border: 1px solid #10b981; }
        .badge-completed { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
        .badge-rejected, .badge-disbursement_rejected { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; }
        .badge-disbursed, .badge-full { background: #faf5ff; color: #6d28d9; border: 1px solid #e9d5ff; }
        .badge-awaiting_disbursement, .badge-partial { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }

        /* DETAIL / DRILL-DOWN SPLIT LAYOUT */
        .detail-container {
            display: grid;
            grid-template-columns: 1.65fr 1fr;
            gap: 20px;
            align-items: start;
            width: 100%;
        }

        .detail-card {
            background: var(--bg-card);
            border-radius: 14px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
            word-break: break-word;
            width: 100%;
        }

        .detail-title {
            font-size: 16px;
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
            margin-top: 0;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            width: 100%;
        }

        .detail-group {
            display: grid;
            grid-template-columns: 130px 1fr;
            padding: 9px 0;
            border-bottom: 1px solid #f9fafb;
            gap: 10px;
            font-size: 13px;
        }

        .detail-label { font-weight: 600; color: var(--text-muted); font-size: 12px; }
        .detail-val { font-size: 13px; color: var(--text-main); word-break: break-word; }

        /* TABS */
        .tabs-header {
            display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color);
            overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%;
        }
        .tab-btn {
            background: none; border: none; font-family: inherit; font-size: 13px; font-weight: 600;
            color: var(--text-muted); padding: 9px 14px; cursor: pointer; transition: 0.2s;
            border-bottom: 3px solid transparent; margin-bottom: -1px; white-space: nowrap; flex-shrink: 0;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-pane { display: none; width: 100%; }
        .tab-pane.active { display: block; }

        /* STAGE & INTERVENTION BOXES */
        .stage-box {
            background: #fafbfc; border: 1px solid var(--border-color);
            border-radius: 10px; padding: 14px; margin-bottom: 14px;
            word-break: break-word; width: 100%;
        }
        .stage-box.stalled { border-left: 4px solid var(--warning); background: #fffdfa; }

        .intervention-box {
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px;
            padding: 18px; margin-bottom: 20px; width: 100%;
        }
        .intervention-box h3 { margin: 0 0 6px 0; color: #b45309; font-size: 15px; display: flex; align-items: center; gap: 8px; }

        /* CONTROL FORMS */
        .control-form { display: flex; flex-direction: column; gap: 12px; width: 100%; }
        .control-form label { font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .control-textarea {
            width: 100%; min-height: 80px; border-radius: 8px;
            border: 1px solid var(--border-color); padding: 9px 12px;
            font-family: inherit; font-size: 13px; outline: none; resize: vertical;
            background-color: #fafbfa;
        }
        .control-textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15); }

        /* ALERTS */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; display: flex; align-items: center; gap: 10px; width: 100%; word-break: break-word; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #10b981; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #f87171; }
        .empty-state { text-align: center; padding: 35px 15px; color: var(--text-muted); font-size: 13px; }

        /* MOBILE BACKDROP */
        .sidebar-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 95; opacity: 0; transition: opacity 0.3s;
        }
        .sidebar-backdrop.active { display: block; opacity: 1; }

        /* --- RESPONSIVE MEDIA QUERIES --- */
        @media (max-width: 1080px) {
            .filters-bar {
                grid-template-columns: 1fr 1fr;
            }
            .filter-buttons {
                grid-column: span 2;
                justify-content: flex-end;
            }
        }

        @media (max-width: 992px) {
            .detail-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                overflow-x: hidden;
            }
            .sidebar {
                position: fixed;
                top: 0; left: 0; bottom: 0;
                height: 100%;
                width: 270px;
                max-width: 82vw;
                transform: translateX(-100%);
                z-index: 1000;
                box-shadow: 6px 0 20px rgba(0,0,0,0.25);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .content {
                padding: 14px;
            }
            .topbar {
                padding: 10px 14px;
            }
            .user-meta {
                display: none;
            }
            .page-title {
                font-size: 19px;
            }
            .filters-bar {
                grid-template-columns: 1fr;
                padding: 14px;
                gap: 12px;
            }
            .filter-buttons {
                grid-column: span 1;
                width: 100%;
            }
            .filter-buttons button, .filter-buttons a {
                flex: 1;
            }
            .metrics-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .detail-card {
                padding: 14px;
            }
            .detail-group {
                grid-template-columns: 1fr;
                gap: 3px;
                padding: 8px 0;
            }
            .stage-box {
                padding: 12px;
            }
        }

        @media (max-width: 480px) {
            .btn-search, .btn-action, .btn-submit {
                font-size: 12px;
                padding: 0 10px;
            }
            .metric-card {
                padding: 14px;
            }
            .tab-btn {
                padding: 8px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>Administrator</h2>
        </div>

        <nav class="nav">
            <a href="admin_dashboard.php" class="nav-link">
                <i data-feather="pie-chart"></i>
                <span>Dashboard</span>
            </a>
            <a href="admin_user_management.php" class="nav-link">
                <i data-feather="users"></i>
                <span>User Management</span>
            </a>
            <a href="admin_verifications.php" class="nav-link">
                <i data-feather="check-square"></i>
                <span>Verifications</span>
            </a>
            <a href="admin_loan_oversight.php" class="nav-link active">
                <i data-feather="shield"></i>
                <span>Loan Oversight</span>
            </a>
            <a href="admin_marketplace_oversight.php" class="nav-link">
                <i data-feather="shopping-bag"></i>
                <span>Market Oversight</span>
            </a>
            <a href="admin_disputes.php" class="nav-link">
                <i data-feather="alert-triangle"></i>
                <span>Dispute Center</span>
            </a>
            <a href="admin_profile.php" class="nav-link">
                <i data-feather="user"></i>
                <span>My Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
            <?php if (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php endif; ?>
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <!-- MAIN WRAPPER -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn" aria-label="Toggle Sidebar">
                <i data-feather="menu"></i>
            </button>
            <div class="user-profile">
                <div class="user-meta">
                    <div class="user-name"><?= htmlspecialchars($username) ?></div>
                    <div class="user-role">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Global System Loan Registry</h1>
                    <p class="page-subtitle">System audits, manual overrides, and transactional dispute resolution controls.</p>
                </div>
                <?php if ($selected_loan_id): ?>
                    <a href="admin_loan_oversight.php" class="btn-action">
                        <i data-feather="arrow-left" style="width:14px"></i> Back to Loan List
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success">
                    <i data-feather="check-circle" style="width:17px; flex-shrink:0;"></i>
                    <span><?= htmlspecialchars($successMessage) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error">
                    <i data-feather="alert-circle" style="width:17px; flex-shrink:0;"></i>
                    <span><?= htmlspecialchars($errorMessage) ?></span>
                </div>
            <?php endif; ?>

            <!-- OVERVIEW METRICS -->
            <?php if (!$selected_loan_id): ?>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Active Loan Accounts</h4>
                            <p><?= $metrics['total_active'] ?></p>
                        </div>
                        <div class="metric-icon icon-blue"><i data-feather="activity"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Total Disbursed Volume</h4>
                            <p>GHS <?= number_format($metrics['total_disbursed'], 2) ?></p>
                        </div>
                        <div class="metric-icon icon-green"><i data-feather="dollar-sign"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Flagged Stalled Disputes</h4>
                            <p><?= $metrics['open_disputes'] ?></p>
                        </div>
                        <div class="metric-icon icon-red"><i data-feather="alert-triangle"></i></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SINGLE LOAN AUDITING DRILL-DOWN VIEW -->
            <?php if ($selected_loan_id && $selected_loan): ?>
                <div class="detail-container">
                    
                    <!-- Left Segment: Dossier, Stages Timeline & Repayments -->
                    <div>
                        <!-- Dossier Card -->
                        <div class="detail-card">
                            <h2 class="detail-title">
                                <span><i data-feather="file-text" style="width:16px; vertical-align:middle; margin-right:4px;"></i> Dossier #<?= $selected_loan['id'] ?>: <?= htmlspecialchars($selected_loan['title']) ?></span>
                                <span class="badge badge-<?= strtolower($selected_loan['status']) ?>"><?= htmlspecialchars(ucfirst($selected_loan['status'])) ?></span>
                            </h2>

                            <div class="detail-group">
                                <span class="detail-label">Farmer Profile</span>
                                <span class="detail-val">
                                    <strong><?= htmlspecialchars($selected_loan['farmer_name']) ?></strong><br>
                                    <span style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($selected_loan['farmer_email']) ?> &bull; <?= htmlspecialchars($selected_loan['farmer_phone'] ?? 'No Phone') ?></span>
                                </span>
                            </div>

                            <div class="detail-group">
                                <span class="detail-label">Assigned Agent</span>
                                <span class="detail-val">
                                    <strong><?= htmlspecialchars($selected_loan['agent_name'] ?? 'Unassigned') ?></strong>
                                    <?php if (!empty($selected_loan['agent_email'])): ?>
                                        <br><span style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($selected_loan['agent_email']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="detail-group">
                                <span class="detail-label">Loan Financials</span>
                                <span class="detail-val">
                                    Total Limit: <strong>GHS <?= number_format($selected_loan['amount'], 2) ?></strong> | 
                                    Disbursed: <strong style="color:var(--primary);">GHS <?= number_format($selected_loan['disbursed_amount'], 2) ?></strong>
                                    <?php if (isset($selected_loan['outstanding_balance'])): ?>
                                        <br>Outstanding Balance: <strong>GHS <?= number_format($selected_loan['outstanding_balance'], 2) ?></strong>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="detail-group">
                                <span class="detail-label">Current Progress</span>
                                <span class="detail-val">
                                    Active Stage Index: <strong>Stage <?= $selected_loan['current_stage'] ?></strong>
                                </span>
                            </div>

                            <div class="detail-group" style="border-bottom:none;">
                                <span class="detail-label">Loan Purpose</span>
                                <span class="detail-val" style="background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #e2e8f0; display:block; line-height:1.4;">
                                    <?= nl2br(htmlspecialchars($selected_loan['purpose'])) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Left Panel Tabs -->
                        <div class="tabs-header">
                            <button class="tab-btn active" onclick="switchTab(event, 'stages-tab')">
                                <i data-feather="git-commit" style="width:14px; vertical-align:middle; margin-right:4px;"></i> Stages Timeline
                            </button>
                            <button class="tab-btn" onclick="switchTab(event, 'repayments-tab')">
                                <i data-feather="credit-card" style="width:14px; vertical-align:middle; margin-right:4px;"></i> Repayments Ledger (<?= count($repayments) ?>)
                            </button>
                        </div>

                        <!-- TAB CONTENT: STAGES -->
                        <div id="stages-tab" class="tab-pane active">
                            <div class="detail-card">
                                <h3 style="font-size:15px; margin: 0 0 14px 0; font-weight:600;">Verification Timeline Stages</h3>
                                
                                <?php if (empty($stages)): ?>
                                    <div class="empty-state">No timeline stages recorded for this loan.</div>
                                <?php else: ?>
                                    <?php foreach ($stages as $stg): ?>
                                        <div class="stage-box <?= (in_array($stg['status'], ['awaiting_disbursement'])) ? 'stalled' : '' ?>">
                                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; border-bottom: 1px dashed var(--border-color); padding-bottom:8px; margin-bottom:10px;">
                                                <div>
                                                    <strong style="font-size:13.5px;">Stage <?= $stg['stage_number'] ?></strong>
                                                    <span style="font-size:12px; color:var(--text-muted); margin-left:6px;">Cap: GHS <?= number_format($stg['required_amount'], 2) ?></span>
                                                </div>
                                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                                    <span class="badge badge-<?= $stg['status'] ?>"><?= str_replace('_', ' ', $stg['status']) ?></span>
                                                    <span style="font-size:11.5px; color:var(--text-muted);">Disbursed: <strong><?= $stg['disbursed'] ? 'Yes' : 'No' ?></strong></span>
                                                </div>
                                            </div>

                                            <?php
                                            $p_stmt = $pdo->prepare("SELECT * FROM stage_proofs WHERE stage_id = ? ORDER BY uploaded_at DESC");
                                            $p_stmt->execute([$stg['id']]);
                                            $proof_files = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
                                            ?>

                                            <?php if (empty($proof_files)): ?>
                                                <p style="font-size:12px; color:var(--text-muted); font-style:italic; margin:0;">No proof documents uploaded for this stage.</p>
                                            <?php else: ?>
                                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; margin-top: 8px;">
                                                    <?php foreach ($proof_files as $pf): 
                                                        $pf_filename = !empty($pf['filename']) ? $pf['filename'] : '';
                                                        $path = "../uploads/app_{$selected_loan['id']}/stage_{$stg['id']}/" . rawurlencode($pf_filename);
                                                        $is_img = str_contains($pf['file_type'] ?? '', 'image');
                                                    ?>
                                                        <div style="border: 1px solid var(--border-color); padding: 8px 10px; border-radius: 8px; background: #fff; display:flex; flex-direction:column; justify-content:space-between; gap:6px;">
                                                            <div>
                                                                <div style="font-size: 10.5px; text-transform: uppercase; font-weight:700; color:var(--text-muted);"><?= htmlspecialchars($pf['proof_type']) ?> Proof</div>
                                                                <a href="<?= htmlspecialchars($path) ?>" target="_blank" style="font-size:12px; color:var(--primary); font-weight:600; display:inline-flex; align-items:center; gap:4px; margin-top:3px; text-decoration:none; word-break:break-all;">
                                                                    <i data-feather="<?= $is_img ? 'image' : 'file-text' ?>" style="width:12px; height:12px; flex-shrink:0;"></i> View File
                                                                </a>
                                                            </div>
                                                            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f1f5f9; padding-top:5px;">
                                                                <span style="font-size:10.5px; color:var(--text-muted); text-transform:capitalize;">Status: <strong><?= htmlspecialchars($pf['status']) ?></strong></span>
                                                                
                                                                <form method="POST" style="margin:0; display:inline-flex; gap:2px;">
                                                                    <?php if (isset($_SESSION['csrf_token'])): ?>
                                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                                    <?php endif; ?>
                                                                    <input type="hidden" name="intervention_type" value="override_proof">
                                                                    <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                                                    <input type="hidden" name="proof_id" value="<?= $pf['id'] ?>">
                                                                    <button type="submit" name="proof_status" value="verified" title="Approve Proof" style="background:none; border:none; cursor:pointer; color:var(--success); padding:2px;"><i data-feather="check-circle" style="width:14px;"></i></button>
                                                                    <button type="submit" name="proof_status" value="rejected" title="Reject Proof" style="background:none; border:none; cursor:pointer; color:var(--danger); padding:2px;"><i data-feather="x-circle" style="width:14px;"></i></button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- TAB CONTENT: REPAYMENTS -->
                        <div id="repayments-tab" class="tab-pane">
                            <div class="detail-card">
                                <h3 style="font-size:15px; margin: 0 0 14px 0; font-weight:600;">Repayments Ledger</h3>
                                
                                <?php if (empty($repayments)): ?>
                                    <div class="empty-state">No repayment records submitted for this account yet.</div>
                                <?php else: ?>
                                    <div style="display:flex; flex-direction:column; gap:12px;">
                                        <?php foreach ($repayments as $rep): 
                                            $proofPath = "../uploads/repayments/loan_{$selected_loan['id']}/" . basename($rep['proof_filename'] ?? '');
                                            $is_pdf = str_contains($rep['proof_file_type'] ?? '', 'pdf') || pathinfo($rep['proof_filename'] ?? '', PATHINFO_EXTENSION) === 'pdf';
                                        ?>
                                            <div class="stage-box" style="border-left: 4px solid var(--primary); background: #fafbfc; padding: 14px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; border-bottom:1px dashed var(--border-color); padding-bottom:6px; margin-bottom:8px;">
                                                    <div>
                                                        <strong style="font-size:13.5px;">GHS <?= number_format($rep['amount_paid'], 2) ?></strong>
                                                        <span class="badge badge-<?= htmlspecialchars($rep['repayment_type']) ?>" style="margin-left: 4px;"><?= htmlspecialchars(ucfirst($rep['repayment_type'])) ?></span>
                                                    </div>
                                                    <div>
                                                        <span class="badge badge-<?= htmlspecialchars($rep['status']) ?>"><?= htmlspecialchars(ucfirst($rep['status'])) ?></span>
                                                    </div>
                                                </div>

                                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap:6px; font-size:11.5px; margin-bottom:8px; background:#fff; padding:8px; border-radius:6px; border:1px solid #f1f5f9;">
                                                    <div>
                                                        <span style="color:var(--text-muted); display:block; font-size:10px; text-transform:uppercase;">Prev Balance</span>
                                                        <strong>GHS <?= number_format($rep['balance_before'], 2) ?></strong>
                                                    </div>
                                                    <div>
                                                        <span style="color:var(--text-muted); display:block; font-size:10px; text-transform:uppercase;">New Balance</span>
                                                        <strong>GHS <?= number_format($rep['balance_after'], 2) ?></strong>
                                                    </div>
                                                    <div>
                                                        <span style="color:var(--text-muted); display:block; font-size:10px; text-transform:uppercase;">Date</span>
                                                        <strong><?= date('M d, Y', strtotime($rep['submitted_at'])) ?></strong>
                                                    </div>
                                                </div>

                                                <?php if (!empty($rep['agent_note'])): ?>
                                                    <div style="background:#fffbeb; border: 1px solid #fde68a; padding:6px 10px; border-radius:6px; font-size:11.5px; margin-bottom:8px; color:#92400e;">
                                                        <strong>Review Note:</strong> "<?= htmlspecialchars($rep['agent_note']) ?>"
                                                        <?php if (!empty($rep['reviewer_name'])): ?>
                                                            <div style="font-size:10px; text-align:right; margin-top:2px; font-weight:600;">— <?= htmlspecialchars($rep['reviewer_name']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                                    <div>
                                                        <?php if (!empty($rep['proof_filename'])): ?>
                                                            <a href="<?= htmlspecialchars($proofPath) ?>" target="_blank" style="font-size:11.5px; color:var(--primary); font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                                                <i data-feather="<?= $is_pdf ? 'file-text' : 'image' ?>" style="width:13px; height:13px;"></i> View Receipt
                                                            </a>
                                                        <?php else: ?>
                                                            <span style="color:var(--text-muted); font-size:11.5px; font-style:italic;">No receipt attached</span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div style="display:flex; gap:4px; align-items:center;">
                                                        <span style="font-size:10.5px; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Set:</span>
                                                        <form method="POST" style="margin:0; display:inline-flex; gap:3px;">
                                                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="intervention_type" value="override_repayment">
                                                            <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                                            <input type="hidden" name="repayment_id" value="<?= $rep['id'] ?>">

                                                            <button type="submit" name="repayment_status" value="confirmed" class="btn-action" style="height:28px; padding:0 6px; font-size:11px; border-color:var(--success); color:var(--success);" title="Confirm">
                                                                <i data-feather="check" style="width:11px;"></i>
                                                            </button>
                                                            <button type="submit" name="repayment_status" value="rejected" class="btn-action" style="height:28px; padding:0 6px; font-size:11px; border-color:var(--danger); color:var(--danger);" title="Reject">
                                                                <i data-feather="x" style="width:11px;"></i>
                                                            </button>
                                                            <button type="submit" name="repayment_status" value="pending" class="btn-action" style="height:28px; padding:0 6px; font-size:11px; border-color:var(--warning); color:var(--warning);" title="Reset">
                                                                <i data-feather="refresh-cw" style="width:11px;"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Segment: Interventions, Disputes & Status Overrides -->
                    <div>
                        <!-- Active Disputes Panel -->
                        <div class="detail-card" style="border-color:#fecaca; background:#fffdfd;">
                            <h2 class="detail-title" style="color:var(--danger);">
                                <span><i data-feather="alert-triangle" style="width:16px; vertical-align:middle; margin-right:4px;"></i> Dispute Folder</span>
                                <span class="badge badge-rejected"><?= count($disputes) ?> Files</span>
                            </h2>

                            <?php if (empty($disputes)): ?>
                                <div class="empty-state" style="padding:15px 0;">No active dispute files reported.</div>
                            <?php else: ?>
                                <?php foreach ($disputes as $disp): ?>
                                    <div style="border: 1px solid #fecaca; border-radius: 8px; padding: 10px; margin-bottom:10px; background:#fff;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                            <strong style="font-size:12.5px;"><?= htmlspecialchars($disp['title']) ?></strong>
                                            <span class="badge" style="background:<?= ($disp['status'] === 'pending') ? '#fee2e2; color:#ef4444;' : '#e2e8f0; color:#475569;' ?>"><?= htmlspecialchars(ucfirst($disp['status'])) ?></span>
                                        </div>
                                        <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;">By: <?= htmlspecialchars($disp['creator_name']) ?> &rarr; <?= htmlspecialchars($disp['defendant_name']) ?></div>
                                        <p style="font-size:12px; margin: 6px 0; background:#fafafa; border: 1px solid #f1f5f9; padding: 6px 8px; border-radius:6px; font-style:italic;">
                                            "<?= htmlspecialchars($disp['description']) ?>"
                                        </p>

                                        <?php if (!empty($disp['admin_decision'])): ?>
                                            <div style="font-size:11.5px; border-top:1px dashed #e2e8f0; padding-top:6px; margin-top:6px; color:var(--text-main);">
                                                <strong>Directive:</strong> <em><?= htmlspecialchars($disp['admin_decision']) ?></em>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($disp['status'] === 'pending'): ?>
                                            <form method="POST" class="control-form" style="margin-top:8px; border-top:1px dashed #e2e8f0; padding-top:8px;">
                                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="intervention_type" value="resolve_dispute">
                                                <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                                <input type="hidden" name="dispute_id" value="<?= $disp['id'] ?>">
                                                
                                                <div>
                                                    <label for="admin_decision_<?= $disp['id'] ?>">Write Resolution Directive</label>
                                                    <textarea name="admin_decision" id="admin_decision_<?= $disp['id'] ?>" class="control-textarea" placeholder="Enter binding resolution instructions..." required></textarea>
                                                </div>
                                                <div style="display:flex; gap:6px;">
                                                    <button type="submit" name="status" value="resolved" class="btn-submit" style="font-size:11.5px; padding:6px 10px; flex:1;">Resolve Dispute</button>
                                                    <button type="submit" name="status" value="dismissed" class="btn-action" style="font-size:11.5px; padding:6px 10px; flex:1;">Dismiss</button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Manual Stage Intervention Box -->
                        <div class="intervention-box">
                            <h3><i data-feather="settings"></i> Force Stage Intervention</h3>
                            <p style="font-size:11.5px; color:#92400e; margin-bottom:12px; line-height:1.4;">Unblock stalled transitions or forcefully change verification status & disbursements.</p>
                            
                            <form method="POST" class="control-form">
                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <?php endif; ?>
                                <input type="hidden" name="intervention_type" value="override_stage">
                                <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">
                                
                                <div>
                                    <label for="override_stage_id">Target Stage</label>
                                    <select name="stage_id" id="override_stage_id" class="filter-input" required>
                                        <option value="">-- Choose Stage --</option>
                                        <?php foreach($stages as $stg): ?>
                                            <option value="<?= $stg['id'] ?>">Stage <?= $stg['stage_number'] ?> (Status: <?= $stg['status'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="override_stage_status">Assign Stage Status</label>
                                    <select name="stage_status" id="override_stage_status" class="filter-input" required>
                                        <option value="pending">Pending (Initial Status)</option>
                                        <option value="awaiting_disbursement">Awaiting Disbursement (Before-Work Uploaded)</option>
                                        <option value="disbursed">Disbursed (Funds Released / Work Ongoing)</option>
                                        <option value="verified">Verified (Stage Completed & Approved)</option>
                                        <option value="disbursement_rejected">Disbursement Rejected (Before-Work Rejected)</option>
                                        <option value="rejected">Stage Rejected (After-Work Rejected)</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="override_disbursed">Disbursement Execution Flag</label>
                                    <select name="disbursed" id="override_disbursed" class="filter-input">
                                        <option value="0">Not Disbursed</option>
                                        <option value="1">Mark Disbursed (Adjusts Disbursed Amount)</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn-submit" style="background:#d97706;">
                                    Execute Stage Override
                                </button>
                            </form>
                        </div>

                        <!-- Manual Loan Lifecycle Reset -->
                        <div class="detail-card">
                            <h3 style="font-size:15px; margin: 0 0 6px 0; font-weight:600;"><i data-feather="sliders" style="width:15px; vertical-align:middle; margin-right:4px;"></i> Lifecycle Parameters</h3>
                            <p style="font-size:11.5px; color:var(--text-muted); margin-bottom:12px;">Direct override of parent application state parameters.</p>
                            
                            <form method="POST" class="control-form">
                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <?php endif; ?>
                                <input type="hidden" name="intervention_type" value="override_application">
                                <input type="hidden" name="loan_id" value="<?= $selected_loan['id'] ?>">

                                <div>
                                    <label for="app_status">Overall Application Status</label>
                                    <select name="app_status" id="app_status" class="filter-input" required>
                                        <option value="pending" <?= ($selected_loan['status'] === 'pending') ? 'selected' : '' ?>>Pending Review</option>
                                        <option value="approved" <?= ($selected_loan['status'] === 'approved') ? 'selected' : '' ?>>Approved</option>
                                        <option value="rejected" <?= ($selected_loan['status'] === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                        <option value="completed" <?= ($selected_loan['status'] === 'completed') ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= ($selected_loan['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="current_stage">Active Stage Index</label>
                                    <input type="number" name="current_stage" id="current_stage" class="filter-input" value="<?= $selected_loan['current_stage'] ?>" min="1" max="4" required>
                                </div>

                                <div>
                                    <label for="outstanding_balance">Outstanding Balance (GHS)</label>
                                    <input type="text" name="outstanding_balance" id="outstanding_balance" class="filter-input" value="<?= $selected_loan['outstanding_balance'] ?>" placeholder="0.00">
                                </div>

                                <button type="submit" class="btn-action" style="width:100%; border-color:var(--primary); color:var(--primary); font-weight:600;">
                                    Save Lifecycle Overrides
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            <!-- GLOBAL LIST REGISTRY DASHBOARD -->
            <?php else: ?>
                <!-- FILTERS BAR -->
                <form method="GET" class="filters-bar">
                    <div class="filter-group">
                        <span class="filter-label">Search Directory</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Title, Farmer or Agent Name..." class="filter-input">
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Lifecycle Status</span>
                        <select name="status" class="filter-input">
                            <option value="">All Statuses</option>
                            <option value="pending" <?= ($status_filter === 'pending') ? 'selected' : '' ?>>Pending Review</option>
                            <option value="approved" <?= ($status_filter === 'approved') ? 'selected' : '' ?>>Approved / Disbursing</option>
                            <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                            <option value="completed" <?= ($status_filter === 'completed') ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= ($status_filter === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Dispute Condition</span>
                        <select name="has_dispute" class="filter-input">
                            <option value="">All Portfolios</option>
                            <option value="1" <?= ($dispute_filter === '1') ? 'selected' : '' ?>>Flagged Disputes Only</option>
                        </select>
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="btn-search">
                            <i data-feather="filter" style="width:14px;"></i> Filter
                        </button>
                        <a href="admin_loan_oversight.php" class="btn-action">
                            Reset
                        </a>
                    </div>
                </form>

                <!-- PORTFOLIOS TABLE CARD -->
                <div class="card">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Ref ID</th>
                                    <th>Loan Application</th>
                                    <th>Farmer</th>
                                    <th>Vetting Agent</th>
                                    <th>Active Stage</th>
                                    <th>Limit & Disbursed</th>
                                    <th>Status</th>
                                    <th>Dispute Audit</th>
                                    <th>Control Panel</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($all_loans)): ?>
                                    <?php foreach ($all_loans as $ln): ?>
                                        <tr style="<?= ($ln['active_disputes_count'] > 0) ? 'background:#fffafa;' : '' ?>">
                                            <td style="font-weight:600; color:var(--text-muted);">#<?= $ln['id'] ?></td>
                                            <td>
                                                <strong style="color:var(--text-main); font-size:13px;"><?= htmlspecialchars($ln['title']) ?></strong>
                                                <div style="font-size:11px; color:var(--text-muted);"><?= date('M d, Y', strtotime($ln['created_at'])) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($ln['farmer_name'] ?? 'System User') ?></td>
                                            <td><?= htmlspecialchars($ln['agent_name'] ?? 'Unassigned') ?></td>
                                            <td><span class="badge badge-submitted">Stage <?= $ln['current_stage'] ?></span></td>
                                            <td>
                                                <div style="font-weight:600;">GHS <?= number_format($ln['amount'], 2) ?></div>
                                                <div style="font-size:11px; color:var(--primary); font-weight:500;">Disbursed: GHS <?= number_format($ln['disbursed_amount'], 2) ?></div>
                                            </td>
                                            <td><span class="badge badge-<?= strtolower($ln['status']) ?>"><?= htmlspecialchars(ucfirst($ln['status'])) ?></span></td>
                                            <td>
                                                <?php if ($ln['active_disputes_count'] > 0): ?>
                                                    <span class="badge badge-rejected">
                                                        <i data-feather="alert-triangle" style="width:11px; height:11px;"></i> <?= $ln['active_disputes_count'] ?> FLAGGED
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted); font-size:12px;">Clear</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="admin_loan_oversight.php?id=<?= $ln['id'] ?>" class="btn-action">
                                                    <i data-feather="sliders" style="width:12px;"></i> Details & Action
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9">
                                            <div class="empty-state">
                                                <i data-feather="shield" style="width:38px; height:38px; margin-bottom:8px; opacity:0.5;"></i>
                                                <p>No loan portfolios identified matching your filter criteria.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Responsive Sidebar Drawer & Desktop Toggle
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");
        const backdrop = document.getElementById("sidebarBackdrop");

        function toggleSidebar() {
            if (window.innerWidth <= 768) {
                const isActive = sidebar.classList.toggle("active");
                if (backdrop) backdrop.classList.toggle("active", isActive);
            } else {
                sidebar.classList.toggle("collapsed");
            }
        }

        toggleBtn.addEventListener("click", toggleSidebar);
        if (backdrop) {
            backdrop.addEventListener("click", () => {
                sidebar.classList.remove("active");
                backdrop.classList.remove("active");
            });
        }

        // Tab Switching Function
        function switchTab(event, tabId) {
            document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Dynamic Stage Form Sync
        const stageData = <?= json_encode(array_map(function($s) {
            return [
                'id' => (int)$s['id'],
                'status' => $s['status'],
                'disbursed' => (int)$s['disbursed']
            ];
        }, $stages)) ?>;

        const stageSelect = document.getElementById('override_stage_id');
        const statusSelect = document.getElementById('override_stage_status');
        const disbursedSelect = document.getElementById('override_disbursed');

        if (stageSelect && statusSelect && disbursedSelect) {
            stageSelect.addEventListener('change', function() {
                const selectedId = parseInt(this.value);
                const matchedStage = stageData.find(s => s.id === selectedId);
                if (matchedStage) {
                    statusSelect.value = matchedStage.status;
                    disbursedSelect.value = matchedStage.disbursed === 1 ? "1" : "0";
                }
            });
        }
    </script>
</body>
</html>