<?php
// public/admin_marketplace_oversight.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php'; // Required for transaction and payout disbursements

// Role Verification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Administrator';
$pdo = getPDO();

// Declare $activeTab at the very top to prevent "Undefined variable" warnings
$activeTab = $_GET['tab'] ?? 'overview';
if (!in_array($activeTab, ['overview', 'transactions', 'disputes'])) {
    $activeTab = 'overview';
}

$successMessage = '';
$errorMessage = '';

/* ==========================================
   ADMINISTRATIVE INTERVENTION PROCESSING
   ========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intervention_type'])) {
    $intervention = $_POST['intervention_type'];
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    try {
        $pdo->beginTransaction();

        // 1. Resolve Active Dispute Case
        if ($intervention === 'resolve_dispute') {
            $dispute_id = intval($_POST['dispute_id'] ?? 0);
            $decision = trim($_POST['admin_decision'] ?? '');
            $status = $_POST['status'] ?? 'resolved'; // resolved or dismissed

            if ($dispute_id <= 0 || empty($decision)) {
                throw new Exception("Please specify a dispute reference and provide a ruling/decision message.");
            }

            $stmt = $pdo->prepare("UPDATE market_disputes SET status = ?, decision = ?, decision_date = NOW() WHERE id = ?");
            $stmt->execute([$status, $decision, $dispute_id]);

            $successMessage = "Dispute case reference #{$dispute_id} resolved with status set to '" . ucfirst($status) . "'.";
        }

        // 2. Mark Dispute as Under Review
        elseif ($intervention === 'review_dispute') {
            $dispute_id = intval($_POST['dispute_id'] ?? 0);
            if ($dispute_id <= 0) {
                throw new Exception("Missing required dispute identifier.");
            }

            $pdo->prepare("UPDATE market_disputes SET status = 'under_review' WHERE id = ? AND status = 'open'")
                ->execute([$dispute_id]);

            $successMessage = "Dispute Case #{$dispute_id} is now updated to Under Review.";
        }

        // 3. Force Escrow & Lifecycle Override
        elseif ($intervention === 'execute_intervention') {
            $action_type = $_POST['action_type'] ?? ''; // release_to_seller or refund_to_buyer
            $decision_text = trim($_POST['decision'] ?? '');

            if ($order_id <= 0 || empty($decision_text)) {
                throw new Exception("Please select a valid order and provide an intervention ruling statement.");
            }

            // Fetch held escrow records associated with this order
            $escrowStmt = $pdo->prepare("
                SELECT e.*, u.momo_phone, u.name AS farmer_name
                FROM escrow e
                JOIN users u ON e.farmer_id = u.id
                WHERE e.order_id = ? AND e.status = 'held'
            ");
            $escrowStmt->execute([$order_id]);
            $escrowRecords = $escrowStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($action_type === 'release_to_seller') {
                if (empty($escrowRecords)) {
                    throw new Exception("No active, held escrow records found for this transaction.");
                }

                // Update order status to delivered
                $pdo->prepare("UPDATE orders SET order_status = 'delivered', updated_at = NOW() WHERE id = ?")
                    ->execute([$order_id]);

                // Record administrative intervention in tracker logs
                $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?, 'delivered', ?, ?)")
                    ->execute([$order_id, 'Admin Intervention: Released escrow to seller. Decision: ' . $decision_text, $admin_id]);

                // Settle any open disputes on this transaction
                $pdo->prepare("UPDATE market_disputes SET status = 'resolved', decision = ?, decision_date = NOW() WHERE order_id = ? AND status IN ('open', 'under_review')")
                    ->execute([$decision_text, $order_id]);

                // Disburse held balances to vendors
                $warnings = [];
                foreach ($escrowRecords as $escrow) {
                    if (empty($escrow['momo_phone'])) {
                        $warnings[] = "Manual payout required for vendor {$escrow['farmer_name']} (No MoMo number set).";
                        $pdo->prepare("UPDATE escrow SET status = 'released', released_at = NOW(), momo_disbursement_ref = 'MANUAL_REQUIRED' WHERE id = ?")
                            ->execute([$escrow['id']]);
                        continue;
                    }

                    $momoPhone = '233' . ltrim(preg_replace('/\D/', '', $escrow['momo_phone']), '0');

                    // API mobile payout call
                    $disburse = disburseMoMoPayment([
                        'amount'      => $escrow['amount'],
                        'currency'    => 'GHS',
                        'phone'       => $momoPhone,
                        'external_id' => 'ESCROW-ADMIN-' . $escrow['id'] . '-' . time(),
                        'description' => "AgroMarket Escrow Override Order #{$order_id}",
                    ]);

                    $ref = $disburse['reference'] ?? ('ADMIN-RELEASE-' . uniqid());

                    $pdo->prepare("UPDATE escrow SET status = 'released', released_at = NOW(), momo_disbursement_ref = ? WHERE id = ?")
                        ->execute([$ref, $escrow['id']]);

                    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes) VALUES (?, 'escrow_released', ?)")
                        ->execute([$order_id, "₵" . number_format($escrow['amount'], 2) . " released to {$escrow['farmer_name']}."]);
                }

                $successMessage = "Intervention successful: Escrow funds released to seller(s).";
                if (!empty($warnings)) {
                    $successMessage .= ' ' . implode(' ', $warnings);
                }

            } elseif ($action_type === 'refund_to_buyer') {
                $buyerStmt = $pdo->prepare("SELECT b.name, b.momo_phone, b.phone FROM buyers b JOIN orders o ON o.buyer_id = b.id WHERE o.id = ?");
                $buyerStmt->execute([$order_id]);
                $buyerData = $buyerStmt->fetch(PDO::FETCH_ASSOC);

                if (!$buyerData) {
                    throw new Exception("Unable to resolve buyer details for processing refund.");
                }

                // Update status to cancelled
                $pdo->prepare("UPDATE orders SET order_status = 'cancelled', updated_at = NOW() WHERE id = ?")
                    ->execute([$order_id]);

                // Record intervention tracking log
                $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?, 'cancelled', ?, ?)")
                    ->execute([$order_id, 'Admin Intervention: Transaction cancelled and refunded. Decision: ' . $decision_text, $admin_id]);

                // Settle linked disputes
                $pdo->prepare("UPDATE market_disputes SET status = 'resolved', decision = ?, decision_date = NOW() WHERE order_id = ? AND status IN ('open', 'under_review')")
                    ->execute([$decision_text, $order_id]);

                // Calculate total refund sum from held escrow (Farmer portion + fee)
                $refundSum = 0;
                foreach ($escrowRecords as $escrow) {
                    $refundSum += ($escrow['amount'] + $escrow['platform_fee_portion']);
                    $pdo->prepare("UPDATE escrow SET status = 'refunded', released_at = NOW(), momo_disbursement_ref = 'REFUNDED_TO_BUYER' WHERE id = ?")
                        ->execute([$escrow['id']]);
                }

                if ($refundSum <= 0) {
                    $orderAmountStmt = $pdo->prepare("SELECT total_amount FROM orders WHERE id = ?");
                    $orderAmountStmt->execute([$order_id]);
                    $refundSum = (float)$orderAmountStmt->fetchColumn();
                }

                $buyerPhoneNum = $buyerData['momo_phone'] ?: $buyerData['phone'];

                if (empty($buyerPhoneNum)) {
                    $successMessage = "Escrow successfully marked as refunded. Manual refund required (No buyer phone configured).";
                } else {
                    $momoPhone = '233' . ltrim(preg_replace('/\D/', '', $buyerPhoneNum), '0');

                    // API refund payout to buyer momo phone
                    $disburse = disburseMoMoPayment([
                        'amount'      => $refundSum,
                        'currency'    => 'GHS',
                        'phone'       => $momoPhone,
                        'external_id' => 'REFUND-ADMIN-' . $order_id . '-' . time(),
                        'description' => "AgroMarket Refund Override Order #{$order_id}",
                    ]);

                    $ref = $disburse['reference'] ?? ('ADMIN-REFUND-' . uniqid());

                    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes) VALUES (?, 'escrow_refunded', ?)")
                        ->execute([$order_id, "₵" . number_format($refundSum, 2) . " refunded back to buyer {$buyerData['name']} (Ref: $ref)."]);

                    $successMessage = "Transaction cancelled successfully. Escrow refunded to buyer MoMo account.";
                }
            } else {
                throw new Exception("Unknown override intervention action specified.");
            }
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Intervention Failed: " . $e->getMessage();
    }
}

// Read-only single drill-down load evaluation
$selected_order_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$selected_dispute_id = isset($_GET['dispute_id']) ? intval($_GET['dispute_id']) : null;

$selected_order         = null;
$selected_order_items    = [];
$selected_order_tracking = [];
$selected_order_escrow   = [];
$selected_disputes      = [];
$selected_dispute       = null;
$dispute_evidence       = [];

// Fetch dispute context if accessed from dispute logs page
if ($selected_dispute_id) {
    $sdStmt = $pdo->prepare("
        SELECT d.*, 
               CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
               CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name,
               o.total_amount AS order_amount, o.order_status
        FROM market_disputes d
        LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
        LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
        LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
        LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
        LEFT JOIN orders o ON d.order_id = o.id
        WHERE d.id = ?
    ");
    $sdStmt->execute([$selected_dispute_id]);
    $selected_dispute = $sdStmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_dispute) {
        $selected_order_id = (int)$selected_dispute['order_id'];

        $seStmt = $pdo->prepare("SELECT * FROM market_dispute_evidence WHERE dispute_id = ? ORDER BY created_at ASC");
        $seStmt->execute([$selected_dispute_id]);
        $dispute_evidence = $seStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch single order details
if ($selected_order_id) {
    $soStmt = $pdo->prepare("
        SELECT o.*, b.name AS buyer_name, b.email AS buyer_email, b.phone AS buyer_phone, b.momo_phone AS buyer_momo
        FROM orders o
        LEFT JOIN buyers b ON o.buyer_id = b.id
        WHERE o.id = ?
    ");
    $soStmt->execute([$selected_order_id]);
    $selected_order = $soStmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_order) {
        // Fetch order items list
        $soiStmt = $pdo->prepare("
            SELECT oi.*, p.produce_name, p.photo, u.name AS farmer_name, u.momo_phone AS farmer_momo
            FROM order_items oi
            JOIN produce_listings p ON oi.produce_id = p.id
            JOIN users u ON oi.farmer_id = u.id
            WHERE oi.order_id = ?
        ");
        $soiStmt->execute([$selected_order_id]);
        $selected_order_items = $soiStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch progression events
        $sotStmt = $pdo->prepare("SELECT * FROM order_tracking WHERE order_id = ? ORDER BY created_at ASC");
        $sotStmt->execute([$selected_order_id]);
        $selected_order_tracking = $sotStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch escrow logs
        $soeStmt = $pdo->prepare("
            SELECT e.*, u.name AS farmer_name
            FROM escrow e
            JOIN users u ON e.farmer_id = u.id
            WHERE e.order_id = ?
        ");
        $soeStmt->execute([$selected_order_id]);
        $selected_order_escrow = $soeStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch related disputes
        if (!$selected_dispute) {
            $sodStmt = $pdo->prepare("
                SELECT d.*, 
                       CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
                       CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name
                FROM market_disputes d
                LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
                LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
                LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
                LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
                WHERE d.order_id = ?
                ORDER BY d.created_at DESC
            ");
            $sodStmt->execute([$selected_order_id]);
            $selected_disputes = $sodStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Fetch global transaction profiles list for dashboard
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$dispute_filter = $_GET['has_dispute'] ?? '';

$query_str = "
    SELECT o.*, 
           b.name AS buyer_name,
           (SELECT COUNT(*) FROM market_disputes d WHERE d.order_id = o.id AND d.status = 'open') AS active_disputes_count,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
    FROM orders o
    LEFT JOIN buyers b ON o.buyer_id = b.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query_str .= " AND (o.id = ? OR b.name LIKE ? OR o.delivery_name LIKE ?)";
    $search_int = intval($search);
    $search_like = "%{$search}%";
    $params[] = $search_int;
    $params[] = $search_like;
    $params[] = $search_like;
}

if (!empty($status_filter)) {
    $query_str .= " AND o.order_status = ?";
    $params[] = $status_filter;
}

if ($dispute_filter === '1') {
    $query_str .= " AND o.id IN (SELECT DISTINCT order_id FROM market_disputes WHERE status IN ('open', 'under_review'))";
}

$query_str .= " ORDER BY active_disputes_count DESC, o.created_at DESC";
$stmtAll = $pdo->prepare($query_str);
$stmtAll->execute($params);
$all_orders = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// Populate $allDisputes registry for disputes view
$allDisputes = [];
if ($activeTab === 'disputes') {
    $disputeQuery = "
        SELECT d.*, 
               CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
               CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name,
               o.total_amount AS order_amount, o.order_status
        FROM market_disputes d
        LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
        LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
        LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
        LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
        LEFT JOIN orders o ON d.order_id = o.id
        ORDER BY d.created_at DESC
    ";
    $allDisputes = $pdo->query($disputeQuery)->fetchAll(PDO::FETCH_ASSOC);
}

// Total overview metrics for marketplace dashboard
$metrics = [
    'total_volume'    => 0.0,
    'held_escrow'     => 0.0,
    'platform_profit' => 0.0,
    'open_disputes'   => 0,
    'active_orders'   => 0
];

$m_tx = $pdo->query("SELECT total_amount, platform_fee, order_status FROM orders");
while ($row = $m_tx->fetch(PDO::FETCH_ASSOC)) {
    $metrics['total_volume'] += (float)$row['total_amount'];
    if ($row['order_status'] === 'delivered') {
        $metrics['platform_profit'] += (float)($row['platform_fee'] ?? 0.0);
    }
    if (!in_array($row['order_status'], ['delivered', 'cancelled'])) {
        $metrics['active_orders']++;
    }
}

$m_escrow = $pdo->query("SELECT SUM(amount) AS held_sum FROM escrow WHERE status = 'held'")->fetch(PDO::FETCH_ASSOC);
$metrics['held_escrow'] = (float)($m_escrow['held_sum'] ?? 0.0);

$metrics['open_disputes'] = (int)$pdo->query("SELECT COUNT(*) FROM market_disputes WHERE status IN ('open', 'under_review')")->fetchColumn();

// Status labels and display config
$statusConfig = [
    'pending_payment'   => ['label'=>'Pending Payment',   'color'=>'badge-pending'],
    'payment_confirmed' => ['label'=>'Payment Confirmed', 'color'=>'badge-completed'],
    'preparing'         => ['label'=>'Preparing',         'color'=>'badge-disbursed'],
    'in_transit'        => ['label'=>'In Transit',        'color'=>'badge-disbursed'],
    'ready_for_pickup'  => ['label'=>'Ready for Pickup',  'color'=>'badge-approved'],
    'delivered'         => ['label'=>'Delivered',         'color'=>'badge-approved'],
    'cancelled'         => ['label'=>'Cancelled',         'color'=>'badge-rejected'],
];

$escrowConfig = [
    'held'     => ['label'=>'Held In Escrow', 'color'=>'badge-pending'],
    'released' => ['label'=>'Disbursed Out',  'color'=>'badge-approved'],
    'refunded' => ['label'=>'Refunded Back',  'color'=>'badge-rejected'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Market Oversight & Intervention Desk | AgroLoan Administration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        :root {
            --primary: #059669;
            --primary-dark: #576868ff;
            --secondary: #10b981;
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --sidebar-width: 260px;
            --sidebar-collapsed: 80px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
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
            box-shadow: 4px 0 10px rgba(0,0,0,0.05);
            flex-shrink: 0;
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
        .nav-link.active { background: var(--secondary); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .nav-link svg { width: 20px; height: 20px; }
        .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
        .sidebar.collapsed .nav-link span { display: none; }
        
        .logout-btn {
            background: rgba(239, 68, 68, 0.1); color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.15);
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
            width: 35px; height: 35px; background: var(--primary); color: white;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 14px;
        }
         
        .content { padding: 30px; max-width: 1400px; margin: 0 auto; width: 100%; }

        /* Metric Widgets */
        .metrics-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: var(--bg-card); border-radius: 12px; padding: 20px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: var(--shadow); border: 1px solid #e2e8f0;
        }
        .metric-info h4 { margin: 0; font-size: 13px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; }
        .metric-info p { margin: 5px 0 0; font-size: 24px; font-weight: 700; color: var(--text-main); }
        .metric-icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .icon-blue { background: #e0e7ff; color: #4f46e5; }
        .icon-green { background: #d1fae5; color: #059669; }
        .icon-red { background: #fee2e2; color: #ef4444; }

        /* Card styles */
        .card {
            background: var(--bg-card); padding: 25px; border-radius: 12px;
            box-shadow: var(--shadow); border: 1px solid #e2e8f0; margin-bottom: 25px;
        }
        .flex-header {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;
        }
        .card-title { font-size: 18px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 8px; color: var(--text-main); }

        /* Form Filter Panel */
        .filter-panel {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; margin-bottom: 20px;
        }
        .filter-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        select, input[type="text"], textarea {
            padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px;
            font-family: inherit; width: 100%; outline: none; background: #fff; color: var(--text-main);
            transition: border-color 0.2s;
        }
        select:focus, input[type="text"]:focus, textarea:focus { border-color: var(--primary); }

        /* Tables */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        th { background: #f8fafc; font-weight: 600; color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
        tr:hover td { background: #fcfdfe; }

        /* Status badges */
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-approved { background: #ecfdf5; color: #059669; }
        .badge-rejected { background: #fef2f2; color: #dc2626; }
        .badge-completed { background: #eff6ff; color: #2563eb; }
        .badge-disbursed { background: #f5f3ff; color: #7c3aed; }

        /* Action buttons */
        .btn {
            padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: inherit; transition: 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #f1f5f9; color: var(--text-main); border: 1px solid #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }

        /* Timeline Tracker Styles */
        .timeline { position: relative; padding-left: 20px; border-left: 2px solid #cbd5e1; margin-left: 10px; display: flex; flex-direction: column; gap: 20px; }
        .timeline-item { position: relative; }
        .timeline-item::before {
            content: ''; position: absolute; left: -27px; top: 4px; width: 12px; height: 12px;
            background: #fff; border: 2px solid var(--primary); border-radius: 50%; z-index: 10;
        }
        .timeline-date { font-size: 11px; color: var(--text-muted); display: block; margin-top: 2px; }

        /* Elegant Split view for selection - exact clone from loan oversight */
        .split-view { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start; }
        .split-view > div { min-width: 0; }

        /* Overrides panel styling */
        .intervention-box {
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 20px; margin-top: 25px;
        }
        .intervention-box h3 { margin: 0 0 10px 0; color: #b45309; font-size: 16px; display: flex; align-items: center; gap: 8px; }

        .alert { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        @media (max-width: 992px) {
            .split-view { display: flex; flex-direction: column; gap: 20px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Administrator</h2>
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
            <a href="admin_loan_oversight.php" class="nav-link">
                <i data-feather="shield"></i>
                <span>Loan Oversight</span>
            </a>
            <a href="admin_marketplace_oversight.php" class="nav-link <?= ($activeTab !== 'disputes') ? 'active' : '' ?>">
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
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <main class="main">
        <!-- Top Navigation Bar -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn"><i data-feather="menu"></i></button>
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username,0,1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            <div class="flex-header">
                <div>
                    <h1 style="margin: 0; font-size: 24px; font-weight: 700;">Marketplace Oversight Registry</h1>
                    <p style="color: var(--text-muted); margin: 5px 0 0 0;">Manage transaction logs, system tracking audits, and dispute intervention profiles.</p>
                </div>
                <?php if ($selected_order_id || $selected_dispute_id): ?>
                    <a href="admin_marketplace_oversight.php?tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-secondary">&larr; Back to Directory</a>
                <?php endif; ?>
            </div>

            <!-- Feedback Messages -->
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i data-feather="check-circle"></i> <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error"><i data-feather="alert-circle"></i> <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Global Dashboard Metrics Cards -->
            <?php if (!$selected_order_id && !$selected_dispute_id): ?>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Marketplace Volume</h4>
                            <p>₵<?= number_format($metrics['total_volume'], 2) ?></p>
                        </div>
                        <div class="metric-icon icon-blue"><i data-feather="activity"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Held Escrow Balance</h4>
                            <p>₵<?= number_format($metrics['held_escrow'], 2) ?></p>
                        </div>
                        <div class="metric-icon icon-green"><i data-feather="lock"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Platform Profit</h4>
                            <p>₵<?= number_format($metrics['platform_profit'], 2) ?></p>
                        </div>
                        <div class="metric-icon icon-green"><i data-feather="trending-up"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Active Shipments</h4>
                            <p><?= $metrics['active_orders'] ?></p>
                        </div>
                        <div class="metric-icon icon-blue"><i data-feather="truck"></i></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-info">
                            <h4>Active Disputes</h4>
                            <p><?= $metrics['open_disputes'] ?></p>
                        </div>
                        <div class="metric-icon icon-red"><i data-feather="alert-triangle"></i></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- DRILL-DOWN AUDIT TRANSACTION VIEW -->
            <?php if ($selected_order_id && $selected_order): ?>
                <div class="split-view">
                    
                    <!-- Left Column: Detailed Order Record -->
                    <div>
                        <!-- Main Record Sheet - exact loan details panel structure -->
                        <div class="card">
                            <div class="flex-header">
                                <h2 class="card-title"><i data-feather="file-text"></i> Audit Sheet: Transaction ID #<?= $selected_order['id'] ?></h2>
                                <?php $sc = $statusConfig[$selected_order['order_status']] ?? ['label'=>$selected_order['order_status'],'color'=>'badge-pending']; ?>
                                <span class="badge <?= $sc['color'] ?>"><?= $sc['label'] ?></span>
                            </div>

                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Recipient Customer</strong>
                                    <div style="font-size:14px; font-weight:600; margin-top:3px;"><?= htmlspecialchars($selected_order['buyer_name'] ?? 'Guest Buyer') ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_order['buyer_email'] ?? 'No Email') ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_order['buyer_phone'] ?? 'No Phone') ?></div>
                                </div>
                                <div>
                                    <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Financial Ledger</strong>
                                    <div style="font-size:14px; font-weight:600; margin-top:3px;">Total Cost: ₵<?= number_format($selected_order['total_amount'], 2) ?></div>
                                    <div style="font-size:12px; color:var(--success); font-weight:600;">Platform Fee: ₵<?= number_format($selected_order['platform_fee'], 2) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);">Payout Phone: <?= htmlspecialchars($selected_order['buyer_momo'] ?: 'None Set') ?></div>
                                </div>
                                <div>
                                    <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase;">Delivery Address</strong>
                                    <div style="font-size:14px; font-weight:600; margin-top:3px;"><?= htmlspecialchars($selected_order['delivery_name']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($selected_order['delivery_address']) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);">Phone: <?= htmlspecialchars($selected_order['delivery_phone']) ?></div>
                                </div>
                            </div>

                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border:1px solid #e2e8f0; margin-bottom: 20px;">
                                <strong style="font-size:12px; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:5px;">Payment Details</strong>
                                <div style="font-size:13px; color:var(--text-main);">Payment State: <strong style="text-transform:uppercase;"><?= htmlspecialchars($selected_order['payment_status']) ?></strong></div>
                                <div style="font-size:12px; color:var(--text-muted); margin-top:3px;">Created On: <?= date('d M Y, h:i A', strtotime($selected_order['created_at'])) ?></div>
                            </div>

                            <?php if (!empty($selected_order['buyer_notes'])): ?>
                            <div style="background: #faf8f5; padding: 15px; border-radius: 8px; border: 1px dashed #f59e0b;">
                                <strong style="font-size:11px; color:var(--warning); text-transform:uppercase; display:block; margin-bottom:4px;">Direct Instruction Notes</strong>
                                <p style="font-size:12.5px; margin: 0; line-height:1.5; font-style: italic;">"<?= htmlspecialchars($selected_order['buyer_notes']) ?>"</p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Order Line Items -->
                        <div class="card">
                            <h2 class="card-title" style="margin-bottom:15px;"><i data-feather="package"></i> Line Items in Current Lifecycle</h2>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Listing</th>
                                            <th>Farmer Vendor</th>
                                            <th>Quantity</th>
                                            <th>Unit Cost</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($selected_order_items as $item): ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <img src="<?= !empty($item['photo']) ? "../uploads/produce/".htmlspecialchars($item['photo']) : "https://via.placeholder.com/40" ?>" style="width:36px; height:36px; border-radius:6px; object-fit:cover;" onerror="this.src='https://via.placeholder.com/40'">
                                                    <span style="font-weight:600;"><?= htmlspecialchars($item['produce_name']) ?></span>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($item['farmer_name']) ?></td>
                                            <td><?= $item['quantity'] ?> bags</td>
                                            <td>₵<?= number_format($item['unit_price'], 2) ?></td>
                                            <td style="font-weight:600;">₵<?= number_format($item['subtotal'], 2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Progression Events Timeline -->
                        <div class="card">
                            <h2 class="card-title" style="margin-bottom:20px;"><i data-feather="activity"></i> Audit Milestones & Transition Logs</h2>
                            <?php if (empty($selected_order_tracking)): ?>
                                <p style="font-size:12px; color:var(--text-muted); font-style:italic; margin: 0;">No milestone tracking generated yet.</p>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($selected_order_tracking as $track): ?>
                                    <div class="timeline-item">
                                        <strong style="font-size:13px; color:var(--text-main); display:block; text-transform: capitalize;"><?= str_replace('_', ' ', $track['status']) ?></strong>
                                        <?php if ($track['notes']): ?>
                                            <div style="color:var(--text-muted); font-size:12px; margin-top:2px;"><?= htmlspecialchars($track['notes']) ?></div>
                                        <?php endif; ?>
                                        <span class="timeline-date"><?= date('d M Y, h:i A', strtotime($track['created_at'])) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column: Escrow Statements, Disputes, Overrides -->
                    <div>
                        <!-- Escrow Assets Statement - Sturdy stacked vertical layout matching loan details -->
                        <div class="card">
                            <h2 class="card-title" style="margin-bottom:15px;"><i data-feather="shield"></i> Active Financial Escrow Ledgers</h2>
                            <?php if (empty($selected_order_escrow)): ?>
                                <p style="font-size:12px; color:var(--text-muted); font-style:italic; text-align:center; padding:15px 0;">No escrow assets generated for this ledger context.</p>
                            <?php else: ?>
                                <?php foreach ($selected_order_escrow as $esc): ?>
                                <?php $ec = $escrowConfig[$esc['status']] ?? ['label'=>'Unknown','color'=>'badge-pending']; ?>
                                <div style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom:12px; background:#fff;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <strong style="font-size:13px; color:var(--text-main);"><?= htmlspecialchars($esc['farmer_name']) ?></strong>
                                        <span class="badge <?= $ec['color'] ?>"><?= $ec['label'] ?></span>
                                    </div>
                                    <div style="font-size:16px; font-weight:700; color:var(--text-main); margin-bottom:6px;">₵<?= number_format($esc['amount'], 2) ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);">Fee Portion: <strong>₵<?= number_format($esc['platform_fee_portion'], 2) ?></strong></div>
                                    <?php if ($esc['momo_disbursement_ref']): ?>
                                    <div style="font-size:11px; margin-top:8px; background:#f1f5f9; padding:6px; border-radius:4px; font-family:monospace; color:var(--text-muted); word-break: break-all;">
                                        Disbursement Ref: <?= htmlspecialchars($esc['momo_disbursement_ref']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Dispute Handling Card - exact clone of the loan oversight dispute panel -->
                        <div class="card" style="border-color:#fecaca; background:#fffdfd;">
                            <h2 class="card-title" style="color:var(--danger);"><i data-feather="alert-triangle"></i> Disputes Registry Docket</h2>
                            <p style="font-size:12px; color:var(--text-muted); margin-bottom:15px;">Historical claims, complaints, and active dispute profiles mapped to this record.</p>

                            <?php 
                            $active_disputes = $selected_dispute ? [$selected_dispute] : $selected_disputes;
                            if (empty($active_disputes)): 
                            ?>
                                <p style="font-size:12px; color:var(--text-muted); text-align:center; padding:15px 0;">No active disputes registered against this sheet.</p>
                            <?php else: ?>
                                <?php foreach ($active_disputes as $disp): ?>
                                <div style="border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-bottom:12px; background:#fff;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <strong style="font-size:13px;"><?= htmlspecialchars($disp['title']) ?></strong>
                                        <span class="badge" style="background:<?= (in_array($disp['status'], ['open', 'under_review'])) ? '#fee2e2; color:#ef4444;' : '#e2e8f0; color:#475569;' ?>"><?= str_replace('_', ' ', $disp['status']) ?></span>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:3px;">Lodge Initiator: <?= htmlspecialchars($disp['initiator_name']) ?> | Target: <?= htmlspecialchars($disp['defendant_name']) ?></div>
                                    <p style="font-size:13px; margin: 8px 0; background:#fefefe; border: 1px solid #f3f4f6; padding: 8px; border-radius:4px; font-style:italic;">
                                        "<?= htmlspecialchars($disp['description']) ?>"
                                    </p>

                                    <?php if (!empty($dispute_evidence) && $selected_dispute['id'] == $disp['id']): ?>
                                    <div style="margin: 10px 0; border-top: 1px dashed #cbd5e1; padding-top:8px;">
                                        <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--text-muted); display:block; margin-bottom:5px;">Evidence Attachments (<?= count($dispute_evidence) ?>)</span>
                                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <?php foreach ($dispute_evidence as $ev): ?>
                                            <a href="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" target="_blank" style="font-size:11px; color:var(--primary); font-weight:500; text-decoration:none; display:inline-flex; align-items:center; gap:2px;">
                                                <i data-feather="image" style="width:12px; height:12px;"></i> View File
                                            </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($disp['decision']): ?>
                                        <div style="font-size:12px; border-top:1px dashed #cbd5e1; padding-top:8px; margin-top:8px; color:var(--text-main);">
                                            <strong>Ruling Decision Note:</strong> <em>"<?= htmlspecialchars($disp['decision']) ?>"</em>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($disp['status'] === 'open'): ?>
                                    <form method="POST" style="margin-top:10px; display:inline-block;">
                                        <input type="hidden" name="intervention_type" value="review_dispute">
                                        <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                                        <input type="hidden" name="dispute_id" value="<?= $disp['id'] ?>">
                                        <button type="submit" class="btn btn-secondary" style="font-size:11px; padding:4px 8px;"><i data-feather="eye" style="width:12px;"></i> Mark Under Review</button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if (in_array($disp['status'], ['open', 'under_review'])): ?>
                                        <form method="POST" style="margin-top:10px; border-top:1px dashed #e2e8f0; padding-top:10px;">
                                            <input type="hidden" name="intervention_type" value="resolve_dispute">
                                            <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                                            <input type="hidden" name="dispute_id" value="<?= $disp['id'] ?>">
                                            
                                            <div style="margin-bottom:8px;">
                                                <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:3px;">Write Official Resolution directive</label>
                                                <textarea name="admin_decision" rows="2" placeholder="Resolution directive message..." required style="font-size:11px;"></textarea>
                                            </div>
                                            <div style="display:flex; gap:8px;">
                                                <button type="submit" name="status" value="resolved" class="btn btn-primary" style="font-size:11px; padding:6px 12px;">Resolve Dispute</button>
                                                <button type="submit" name="status" value="dismissed" class="btn btn-secondary" style="font-size:11px; padding:6px 12px;">Dismiss Case</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Direct Escrow Override Systems - standard intervention-box styles -->
                        <?php if ($selected_order['order_status'] !== 'delivered' && $selected_order['order_status'] !== 'cancelled'): ?>
                        <div class="intervention-box">
                            <h3><i data-feather="sliders"></i> Escrow Overrides</h3>
                            <p style="font-size:12px; color:#92400e; margin-bottom:15px; line-height:1.4;">
                                Authoritative bypass system. Use this console to manually release funds to the seller or trigger direct cancellations to refund the buyer's payment.
                            </p>
                            
                            <form method="POST">
                                <input type="hidden" name="intervention_type" value="execute_intervention">
                                <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">

                                <div style="margin-bottom:12px;">
                                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Directive Override Mode</label>
                                    <select name="action_type" required style="font-size:12px;">
                                        <option value="release_to_seller">Release Escrow Funds to Seller (Deliver Order manual override)</option>
                                        <option value="refund_to_buyer">Refund Escrow Funds to Buyer (Cancel Transaction and reverse payment)</option>
                                    </select>
                                </div>

                                <div style="margin-bottom:12px;">
                                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; display:block; margin-bottom:4px;">Official Statement / Decision Note</label>
                                    <textarea name="decision" rows="3" required placeholder="State clear reasoning or administrative reference notes for system logs..." style="font-size:12px;"></textarea>
                                </div>

                                <button type="submit" onclick="return confirm('WARNING: You are triggering an override intervention. Direct MoMo disbursements or refunds may be immediately executed. Proceed?');" class="btn btn-primary" style="font-size:12px; width:100%; justify-content:center;">Authorize Override Execution</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- GLOBAL LIST REGISTRY VIEW -->
            <?php else: ?>
                <div class="card">
                    <div class="flex-header">
                        <h2 class="card-title">
                            <?php if ($activeTab === 'disputes'): ?>
                                <i data-feather="alert-triangle"></i> Registry of Logged Platform Disputes
                            <?php else: ?>
                                <i data-feather="list"></i> Global Marketplace Ledger
                            <?php endif; ?>
                        </h2>
                    </div>

                    <!-- Search & Filter Controls -->
                    <div class="filter-panel">
                        <form method="GET">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label for="search">Keyword Search</label>
                                    <input type="text" name="search" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="Ref ID, Recipient name, Address...">
                                </div>

                                <div class="filter-group">
                                    <label for="status">Progression State</label>
                                    <select name="status" id="status">
                                        <option value="">-- All Active & Archived --</option>
                                        <?php foreach ($statusConfig as $key => $val): ?>
                                            <option value="<?= $key ?>" <?= ($status_filter === $key) ? 'selected' : '' ?>><?= $val['label'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label for="has_dispute">Dispute State</label>
                                    <select name="has_dispute" id="has_dispute">
                                        <option value="">-- All Accounts --</option>
                                        <option value="1" <?= ($dispute_filter === '1') ? 'selected' : '' ?>>Flagged Disputes Only</option>
                                    </select>
                                </div>

                                <div class="filter-group" style="display:flex; flex-direction:row; gap:10px;">
                                    <button type="submit" class="btn btn-primary" style="flex:1; justify-content:center; height:41px;"><i data-feather="search"></i> Search</button>
                                    <a href="admin_marketplace_oversight.php?tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-secondary" style="flex:1; justify-content:center; height:41px; align-items:center;">Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Render Tab Contents -->
                    <?php if ($activeTab === 'disputes'): ?>
                        <!-- Disputes logs view -->
                        <div class="table-wrap">
                            <?php 
                            $filteredDisputes = array_filter($allDisputes, function($disp) use ($search) {
                                if (!empty($search)) {
                                    $s = strtolower($search);
                                    return (str_contains(strtolower($disp['title']), $s) || str_contains(strtolower($disp['initiator_name'] ?? ''), $s) || $disp['order_id'] == $s);
                                }
                                return true;
                            });
                            if (empty($filteredDisputes)): 
                            ?>
                                <p style="text-align:center; color:var(--text-muted); padding:30px; font-style:italic;">No logged disputes matching filters found.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Case ID</th>
                                            <th>Order ID</th>
                                            <th>Claim Overview Title</th>
                                            <th>Lodge Party</th>
                                            <th>Target Defendant</th>
                                            <th>Total Order Cost</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($filteredDisputes as $disp): ?>
                                        <tr style="background:#fff5f5;">
                                            <td>#<?= $disp['id'] ?></td>
                                            <td><strong>#<?= $disp['order_id'] ?></strong></td>
                                            <td><strong style="color:var(--text-main); font-size:13px;"><?= htmlspecialchars($disp['title']) ?></strong></td>
                                            <td><?= htmlspecialchars($disp['initiator_name'] ?? 'System User') ?> <span style="font-size:10px; font-weight:700; color:var(--text-muted);">(<?= strtoupper($disp['initiator_role']) ?>)</span></td>
                                            <td><?= htmlspecialchars($disp['defendant_name'] ?? 'System User') ?> <span style="font-size:10px; font-weight:700; color:var(--text-muted);">(<?= strtoupper($disp['defendant_role']) ?>)</span></td>
                                            <td style="font-weight:600;">₵<?= number_format($disp['order_amount'], 2) ?></td>
                                            <td><span class="badge badge-rejected"><?= str_replace('_', ' ', $disp['status']) ?></span></td>
                                            <td>
                                                <a href="admin_marketplace_oversight.php?dispute_id=<?= $disp['id'] ?>&tab=disputes" class="btn btn-secondary" style="font-size:11px; padding:5px 10px;"><i data-feather="eye" style="width:12px;"></i> Audit Case</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <!-- Standard Transactions Ledger View -->
                        <div class="table-wrap">
                            <?php if (empty($all_orders)): ?>
                                <p style="text-align:center; color:var(--text-muted); padding:30px; font-style:italic;">No orders match criteria.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Order Ref</th>
                                            <th>Recipient Name</th>
                                            <th>Lines</th>
                                            <th>Order Cost</th>
                                            <th>Platform Fee</th>
                                            <th>Lifecycle Progress</th>
                                            <th>Disputes Status</th>
                                            <th>Order Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_orders as $ord): ?>
                                        <?php 
                                        $sc = $statusConfig[$ord['order_status']] ?? ['label'=>$ord['order_status'],'color'=>'badge-pending'];
                                        $has_active_dispute = $ord['active_disputes_count'] > 0;
                                        ?>
                                        <tr style="<?= ($has_active_dispute) ? 'background:#fff5f5;' : '' ?>">
                                            <td><strong>#<?= $ord['id'] ?></strong></td>
                                            <td>
                                                <strong style="color:var(--text-main); font-size:13px;"><?= htmlspecialchars($ord['buyer_name'] ?? 'Guest Buyer') ?></strong>
                                                <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($ord['delivery_phone']) ?></div>
                                            </td>
                                            <td><?= $ord['item_count'] ?> items</td>
                                            <td style="font-weight:600;">₵<?= number_format($ord['total_amount'], 2) ?></td>
                                            <td style="color:var(--success); font-weight:600;">₵<?= number_format($ord['platform_fee'], 2) ?></td>
                                            <td><span class="badge <?= $sc['color'] ?>"><?= $sc['label'] ?></span></td>
                                            <td>
                                                <?php if ($has_active_dispute): ?>
                                                    <span class="badge badge-rejected"><i data-feather="alert-triangle" style="width:11px; height:11px; vertical-align:middle; margin-right:3px;"></i> <?= $ord['active_disputes_count'] ?> FLAG ACTIVE</span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted); font-size:12px;">Clear</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--text-muted); font-size:12px;"><?= date('M d, Y', strtotime($ord['created_at'])) ?></td>
                                            <td>
                                                <a href="admin_marketplace_oversight.php?id=<?= $ord['id'] ?>&tab=transactions" class="btn btn-secondary" style="font-size:11px; padding:5px 10px;"><i data-feather="eye" style="width:12px;"></i> Audit</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Initialize vector icons
        feather.replace();

        // Responsive sidebar collapses
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            sidebar.classList.toggle("collapsed");
        });
    </script>
</body>
</html>