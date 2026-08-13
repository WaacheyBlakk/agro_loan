<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
// public/admin_marketplace_oversight.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php'; 
require_once __DIR__ . '/../src/mailer.php';

// Role Verification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Administrator';
$pdo = getPDO();

// Declare $activeTab at top
$activeTab = $_GET['tab'] ?? 'transactions';
if (!in_array($activeTab, ['transactions', 'disputes'])) {
    $activeTab = 'transactions';
}

$successMessage = '';
$errorMessage = '';

/* ==========================================
   ADMINISTRATIVE INTERVENTION PROCESSING
   ========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intervention_type'])) {
    $intervention = $_POST['intervention_type'];
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    csrf_verify();
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

            $successMessage = "Dispute case reference #{$dispute_id} updated to '" . ucfirst($status) . "'.";
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

        // 3. Force Escrow & Lifecycle Override (Scoped to Order Group ID)
        elseif ($intervention === 'execute_intervention') {
            $action_type = $_POST['action_type'] ?? ''; // release_to_seller or refund_to_buyer
            $decision_text = trim($_POST['decision'] ?? '');
            $group_id = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;

            if ($group_id <= 0 || empty($decision_text)) {
                throw new Exception("Please select a valid order group and provide an intervention ruling statement.");
            }

            // Retrieve general details of parent order group
            $groupQuery = $pdo->prepare("SELECT order_id, group_code FROM order_groups WHERE id = ?");
            $groupQuery->execute([$group_id]);
            $groupMeta = $groupQuery->fetch(PDO::FETCH_ASSOC);

            if (!$groupMeta) {
                throw new Exception("Referenced order group details could not be found.");
            }

            $order_id = (int)$groupMeta['order_id'];
            $group_code = $groupMeta['group_code'];

            // Fetch held escrow records associated with this specific order group
            $escrowStmt = $pdo->prepare("
                SELECT e.*, u.momo_phone, u.name AS farmer_name, u.email AS farmer_email
                FROM escrow e
                JOIN users u ON e.farmer_id = u.id
                WHERE e.order_group_id = ? AND e.status = 'held'
            ");
            $escrowStmt->execute([$group_id]);
            $escrowRecords = $escrowStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($action_type === 'release_to_seller') {
                if (empty($escrowRecords)) {
                    throw new Exception("No active, held escrow records found for this order group.");
                }

                // Update order group status to delivered
                $pdo->prepare("UPDATE order_groups SET group_status = 'delivered', updated_at = NOW() WHERE id = ?")
                    ->execute([$group_id]);

                // Record administrative intervention in tracker logs
                $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?, 'delivered', ?, ?)")
                    ->execute([$order_id, "Admin Intervention: Released escrow for Group {$group_code}. Decision: " . $decision_text, $admin_id]);

                // Settle any open disputes on this order group
                $pdo->prepare("UPDATE market_disputes SET status = 'resolved', decision = ?, decision_date = NOW() WHERE order_group_id = ? AND status IN ('open', 'under_review')")
                    ->execute([$decision_text, $group_id]);

                // Disburse held balances to vendors
                $warnings = [];
                foreach ($escrowRecords as $escrow) {
                    if (empty($escrow['momo_phone'])) {
                        $warnings[] = "Manual payout required for vendor {$escrow['farmer_name']} (No MoMo number set).";
                        $pdo->prepare("UPDATE escrow SET status = 'released', released_at = NOW(), momo_disbursement_ref = 'MANUAL_REQUIRED' WHERE id = ?")
                            ->execute([$escrow['id']]);
                        continue;
                    }

                    if (!empty($escrow['farmer_email'])) {
                        send_escrow_release_email($escrow['farmer_email'], $escrow['farmer_name'], $order_id, $escrow['amount']);
                    }

                    $momoPhone = '233' . ltrim(preg_replace('/\D/', '', $escrow['momo_phone']), '0');

                    // API mobile payout call
                    $disburse = disburseMoMoPayment([
                        'amount'      => $escrow['amount'],
                        'currency'    => 'GHS',
                        'phone'       => $momoPhone,
                        'external_id' => 'ESCROW-ADMIN-' . $escrow['id'] . '-' . time(),
                        'description' => "AgroMarket Escrow Override Group #{$group_id}",
                    ]);

                    $ref = $disburse['reference'] ?? ('ADMIN-RELEASE-' . uniqid());

                    $pdo->prepare("UPDATE escrow SET status = 'released', released_at = NOW(), momo_disbursement_ref = ? WHERE id = ?")
                        ->execute([$ref, $escrow['id']]);

                    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes) VALUES (?, 'escrow_released', ?)")
                        ->execute([$order_id, "₵" . number_format($escrow['amount'], 2) . " released to {$escrow['farmer_name']} for Group {$group_code}."]);
                }

                $successMessage = "Intervention successful: Escrow funds released to seller(s) for Group {$group_code}.";
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

                // Update order group status to cancelled
                $pdo->prepare("UPDATE order_groups SET group_status = 'cancelled', updated_at = NOW() WHERE id = ?")
                    ->execute([$group_id]);

                // Record intervention tracking log
                $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?, 'cancelled', ?, ?)")
                    ->execute([$order_id, "Admin Intervention: Group {$group_code} cancelled and refunded. Decision: " . $decision_text, $admin_id]);

                // Settle linked disputes
                $pdo->prepare("UPDATE market_disputes SET status = 'resolved', decision = ?, decision_date = NOW() WHERE order_group_id = ? AND status IN ('open', 'under_review')")
                    ->execute([$decision_text, $group_id]);

                // Calculate total refund sum from held escrow (Farmer portion + fee) for this specific group
                $refundSum = 0;
                foreach ($escrowRecords as $escrow) {
                    $refundSum += ($escrow['amount'] + $escrow['platform_fee_portion']);
                    $pdo->prepare("UPDATE escrow SET status = 'refunded', released_at = NOW(), momo_disbursement_ref = 'REFUNDED_TO_BUYER' WHERE id = ?")
                        ->execute([$escrow['id']]);
                }

                if ($refundSum <= 0) {
                    $groupAmountStmt = $pdo->prepare("SELECT SUM(subtotal) FROM order_items WHERE order_group_id = ?");
                    $groupAmountStmt->execute([$group_id]);
                    $refundSum = (float)$groupAmountStmt->fetchColumn();
                }

                $buyerPhoneNum = $buyerData['momo_phone'] ?: $buyerData['phone'];

                if (empty($buyerPhoneNum)) {
                    $successMessage = "Group escrow marked as refunded. Manual refund required (No buyer phone configured).";
                } else {
                    $momoPhone = '233' . ltrim(preg_replace('/\D/', '', $buyerPhoneNum), '0');

                    // API refund payout to buyer MoMo phone
                    $disburse = disburseMoMoPayment([
                        'amount'      => $refundSum,
                        'currency'    => 'GHS',
                        'phone'       => $momoPhone,
                        'external_id' => 'REFUND-ADMIN-' . $group_id . '-' . time(),
                        'description' => "AgroMarket Refund Override Group {$group_code}",
                    ]);

                    $ref = $disburse['reference'] ?? ('ADMIN-REFUND-' . uniqid());

                    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes) VALUES (?, 'escrow_refunded', ?)")
                        ->execute([$order_id, "₵" . number_format($refundSum, 2) . " refunded back to buyer {$buyerData['name']} for Group {$group_code} (Ref: $ref)."]);

                    $successMessage = "Group {$group_code} transaction cancelled. Escrow refunded to buyer MoMo account.";
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
$selected_order_groups  = [];
$selected_order_items    = [];
$selected_order_tracking = [];
$selected_order_escrow   = [];
$selected_disputes      = [];
$selected_dispute       = null;
$dispute_evidence       = [];

// Fetch dispute context if accessed from dispute audit
if ($selected_dispute_id) {
    $sdStmt = $pdo->prepare("
        SELECT d.*, 
               CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
               CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name,
               o.total_amount AS order_amount, o.order_status,
               og.group_code
        FROM market_disputes d
        LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
        LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
        LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
        LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
        LEFT JOIN orders o ON d.order_id = o.id
        LEFT JOIN order_groups og ON d.order_group_id = og.id
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
        // Fetch order groups
        $sogStmt = $pdo->prepare("
            SELECT og.*, u.name AS farmer_name, u.momo_phone AS farmer_momo
            FROM order_groups og
            JOIN users u ON og.farmer_id = u.id
            WHERE og.order_id = ?
        ");
        $sogStmt->execute([$selected_order_id]);
        $selected_order_groups = $sogStmt->fetchAll(PDO::FETCH_ASSOC);

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
                       CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name,
                       og.group_code
                FROM market_disputes d
                LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
                LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
                LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
                LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
                LEFT JOIN order_groups og ON d.order_group_id = og.id
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
$disputeQuery = "
    SELECT d.*, 
           CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
           CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name,
           o.total_amount AS order_amount, o.order_status,
           og.group_code
        FROM market_disputes d
        LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
        LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
        LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
        LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
        LEFT JOIN orders o ON d.order_id = o.id
        LEFT JOIN order_groups og ON d.order_group_id = og.id
        ORDER BY d.created_at DESC
";
$allDisputes = $pdo->query($disputeQuery)->fetchAll(PDO::FETCH_ASSOC);

// Total overview metrics
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

// Status display mappings
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
    <title>Marketplace Oversight Desk | AgroLoan Administration</title>
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

        /* --- PAGE CONTENT CONTAINER --- */
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

        /* VIEW TABS NAVIGATION */
        .tab-nav-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }
        .tab-nav-link {
            padding: 10px 18px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .tab-nav-link:hover { color: var(--primary); }
        .tab-nav-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        /* METRIC CARDS */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
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

        /* FILTERS BAR */
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
            min-width: 0;
        }

        .card-header-padded {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
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
            min-width: 780px;
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
        .badge-rejected { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; }
        .badge-disbursed { background: #faf5ff; color: #6d28d9; border: 1px solid #e9d5ff; }

        /* DETAIL / DRILL-DOWN SPLIT LAYOUT */
        .detail-container {
            display: grid;
            grid-template-columns: 1.65fr 1fr;
            gap: 20px;
            align-items: start;
            width: 100%;
        }

        .detail-container > div {
            min-width: 0;
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
            min-width: 0;
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

        /* TIMELINE TRACKER */
        .timeline { position: relative; padding-left: 20px; border-left: 2px solid #cbd5e1; margin-left: 10px; display: flex; flex-direction: column; gap: 18px; }
        .timeline-item { position: relative; }
        .timeline-item::before {
            content: ''; position: absolute; left: -27px; top: 4px; width: 12px; height: 12px;
            background: #fff; border: 2px solid var(--primary); border-radius: 50%; z-index: 10;
        }
        .timeline-date { font-size: 11px; color: var(--text-muted); display: block; margin-top: 2px; }

        /* INTERVENTION BOXES */
        .intervention-box {
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px;
            padding: 18px; margin-bottom: 20px; width: 100%; min-width: 0;
        }
        .intervention-box h3, .intervention-box h4 { margin: 0 0 6px 0; color: #b45309; font-size: 15px; display: flex; align-items: center; gap: 8px; }

        /* CONTROL FORMS */
        .control-form { display: flex; flex-direction: column; gap: 12px; width: 100%; }
        .control-form label { font-size: 11.5px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .control-textarea {
            width: 100%; min-height: 75px; border-radius: 8px;
            border: 1px solid var(--border-color); padding: 9px 12px;
            font-family: inherit; font-size: 13px; outline: none; resize: vertical;
            background-color: #fafbfa;
        }
        .control-textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15); }

        /* EVIDENCE PREVIEWS */
        .evidence-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .evidence-card {
            border: 1px solid var(--border-color);
            padding: 10px;
            border-radius: 8px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

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
        }

        @media (max-width: 480px) {
            .btn-search, .btn-action, .btn-submit {
                font-size: 12px;
                padding: 0 10px;
            }
            .metric-card {
                padding: 14px;
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
            <a href="admin_loan_oversight.php" class="nav-link">
                <i data-feather="shield"></i>
                <span>Loan Oversight</span>
            </a>
            <a href="admin_marketplace_oversight.php" class="nav-link active">
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
            <button id="toggleBtn" class="toggle-btn" aria-label="Toggle Sidebar"><i data-feather="menu"></i></button>
            <div class="user-profile">
                <div class="user-meta">
                    <div class="user-name"><?= htmlspecialchars($username) ?></div>
                    <div class="user-role">Administrator</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username,0,1)) ?>
                </div>
            </div>
        </header>

        <div class="content">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Marketplace Oversight Registry</h1>
                    <p class="page-subtitle">Manage transaction logs, system tracking audits, and dispute intervention profiles.</p>
                </div>
                <?php if ($selected_order_id || $selected_dispute_id): ?>
                    <a href="admin_marketplace_oversight.php?tab=<?= htmlspecialchars($activeTab) ?>" class="btn-action">
                        <i data-feather="arrow-left" style="width:14px"></i> Back to Directory
                    </a>
                <?php endif; ?>
            </div>

            <!-- FEEDBACK MESSAGES -->
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i data-feather="check-circle" style="width:17px; flex-shrink:0;"></i> <span><?= htmlspecialchars($successMessage) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error"><i data-feather="alert-circle" style="width:17px; flex-shrink:0;"></i> <span><?= htmlspecialchars($errorMessage) ?></span></div>
            <?php endif; ?>

            <!-- OVERVIEW METRICS -->
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

                <!-- DIRECTORY VIEW TABS (ALL ORDERS vs DISPUTES) -->
                <div class="tab-nav-bar">
                    <a href="admin_marketplace_oversight.php?tab=transactions" class="tab-nav-link <?= ($activeTab === 'transactions') ? 'active' : '' ?>">
                        <i data-feather="list" style="width:15px;"></i> All Transaction Orders (<?= count($all_orders) ?>)
                    </a>
                    <a href="admin_marketplace_oversight.php?tab=disputes" class="tab-nav-link <?= ($activeTab === 'disputes') ? 'active' : '' ?>">
                        <i data-feather="alert-triangle" style="width:15px;"></i> Logged Market Disputes (<?= count($allDisputes) ?>)
                    </a>
                </div>
            <?php endif; ?>

            <!-- DRILL-DOWN AUDIT TRANSACTION VIEW -->
            <?php if ($selected_order_id && $selected_order): ?>
                
                <!-- DEDICATED SINGLE DISPUTE CONTEXT (When opened via dispute audit) -->
                <?php if ($selected_dispute): ?>
                    <div class="detail-card" style="border-left: 4px solid var(--danger); background: #fffdfd; border-color: #fecaca;">
                        <h2 class="detail-title" style="color:var(--danger);">
                            <span><i data-feather="alert-triangle" style="width:18px; vertical-align:middle;"></i> Dispute Case File #<?= $selected_dispute['id'] ?>: <?= htmlspecialchars($selected_dispute['title']) ?></span>
                            <span class="badge badge-rejected"><?= str_replace('_', ' ', $selected_dispute['status']) ?></span>
                        </h2>

                        <div class="detail-group">
                            <span class="detail-label">Filing Parties</span>
                            <span class="detail-val">
                                Initiator: <strong><?= htmlspecialchars($selected_dispute['initiator_name']) ?> (<?= strtoupper($selected_dispute['initiator_role']) ?>)</strong> &rarr; 
                                Defendant: <strong><?= htmlspecialchars($selected_dispute['defendant_name']) ?> (<?= strtoupper($selected_dispute['defendant_role']) ?>)</strong>
                            </span>
                        </div>

                        <div class="detail-group">
                            <span class="detail-label">Claim Summary</span>
                            <span class="detail-val" style="background:#fff; padding:10px; border-radius:6px; border:1px solid #fecaca; line-height:1.5;">
                                "<?= nl2br(htmlspecialchars($selected_dispute['description'])) ?>"
                            </span>
                        </div>

                        <?php if (!empty($dispute_evidence)): ?>
                            <div class="detail-group">
                                <span class="detail-label">Proof Documents</span>
                                <span class="detail-val">
                                    <div class="evidence-grid">
                                        <?php foreach ($dispute_evidence as $evi): 
                                            $filePath = "../uploads/disputes/" . rawurlencode($evi['file_path']);
                                            $isImg = str_contains($evi['file_type'] ?? '', 'image');
                                        ?>
                                            <div class="evidence-card">
                                                <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" style="font-size:12px; color:var(--primary); font-weight:600; text-decoration:none; display:flex; align-items:center; gap:4px;">
                                                    <i data-feather="<?= $isImg ? 'image' : 'file-text' ?>" style="width:14px;"></i> View Attached Proof
                                                </a>
                                                <span style="font-size:10px; color:var(--text-muted);"><?= date('M d, Y', strtotime($evi['created_at'])) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($selected_dispute['decision'])): ?>
                            <div class="detail-group">
                                <span class="detail-label">Ruling Issued</span>
                                <span class="detail-val" style="background:#ecfdf5; padding:8px 10px; border-radius:6px; border:1px solid #10b981; color:#065f46;">
                                    <strong>Directive:</strong> <?= htmlspecialchars($selected_dispute['decision']) ?>
                                    <div style="font-size:11px; margin-top:2px;">Decided on: <?= date('d M Y, h:i A', strtotime($selected_dispute['decision_date'])) ?></div>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (in_array($selected_dispute['status'], ['open', 'under_review'])): ?>
                            <form method="POST" class="control-form" style="margin-top:14px; border-top:1px dashed #fecaca; padding-top:12px;">
                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <?php endif; ?>
                                <input type="hidden" name="intervention_type" value="resolve_dispute">
                                <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                                <input type="hidden" name="dispute_id" value="<?= $selected_dispute['id'] ?>">

                                <div>
                                    <label>Write Official Resolution Directive</label>
                                    <textarea name="admin_decision" class="control-textarea" rows="2" placeholder="State binding administrative decision..." required></textarea>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <button type="submit" name="status" value="resolved" class="btn-submit" style="flex:1;">Resolve Dispute Case</button>
                                    <button type="submit" name="status" value="dismissed" class="btn-action" style="flex:1; justify-content:center;">Dismiss Claim</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="detail-container">
                    
                    <!-- Left Column: Order Groups & Progression Events -->
                    <div>
                        <?php if (empty($selected_order_groups)): ?>
                            <div class="detail-card">
                                <h3 style="font-size:15px; margin: 0 0 14px 0; font-weight:600;"><i data-feather="package" style="width:15px; vertical-align:middle;"></i> Line Items</h3>
                                <div class="table-responsive">
                                    <table class="table">
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
                                                    <div style="display:flex; align-items:center; gap:8px;">
                                                        <img src="<?= !empty($item['photo']) ? "../uploads/produce/".htmlspecialchars($item['photo']) : "https://via.placeholder.com/40" ?>" style="width:34px; height:34px; border-radius:6px; object-fit:cover;" onerror="this.src='https://via.placeholder.com/40'">
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
                        <?php else: ?>
                            <?php foreach ($selected_order_groups as $group): ?>
                                <?php 
                                $group_id = $group['id'];
                                $group_status = $group['group_status'] ?? 'pending';
                                $scGroup = $statusConfig[$group_status] ?? ['label' => $group_status, 'color' => 'badge-pending'];

                                $group_items = array_filter($selected_order_items, function($item) use ($group_id) {
                                    return (isset($item['order_group_id']) && $item['order_group_id'] == $group_id);
                                });

                                $group_escrows = array_filter($selected_order_escrow, function($escrow) use ($group_id) {
                                    return (isset($escrow['order_group_id']) && $escrow['order_group_id'] == $group_id);
                                });

                                $group_disputes = array_filter($selected_disputes, function($dispute) use ($group_id) {
                                    return (isset($dispute['order_group_id']) && $dispute['order_group_id'] == $group_id);
                                });
                                ?>
                                <div class="detail-card" style="border-left: 4px solid var(--primary);">
                                    <div class="detail-title" style="margin-bottom: 12px;">
                                        <div>
                                            <span style="font-size: 10.5px; text-transform: uppercase; color: var(--text-muted); font-weight: 600;">Vendor Segment</span>
                                            <h3 style="margin: 2px 0 0 0; font-size: 15px; font-weight: 600;">
                                                Group: <span style="color: var(--primary);"><?= htmlspecialchars($group['group_code'] ?? 'N/A') ?></span>
                                            </h3>
                                            <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                                                Farmer Vendor: <strong><?= htmlspecialchars($group['farmer_name']) ?></strong> (<?= htmlspecialchars($group['farmer_momo'] ?: 'No MoMo') ?>)
                                            </div>
                                        </div>
                                        <span class="badge <?= $scGroup['color'] ?>"><?= $scGroup['label'] ?></span>
                                    </div>

                                    <!-- Group Line Items -->
                                    <div class="table-responsive" style="margin-bottom: 12px;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Listing</th>
                                                    <th>Quantity</th>
                                                    <th>Unit Cost</th>
                                                    <th>Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($group_items)): ?>
                                                    <tr>
                                                        <td colspan="4" style="text-align: center; font-style: italic; color: var(--text-muted); font-size: 12px;">No line items stored.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($group_items as $item): ?>
                                                    <tr>
                                                        <td>
                                                            <div style="display:flex; align-items:center; gap:8px;">
                                                                <img src="<?= !empty($item['photo']) ? "../uploads/produce/".htmlspecialchars($item['photo']) : "https://via.placeholder.com/40" ?>" style="width:30px; height:30px; border-radius:6px; object-fit:cover;" onerror="this.src='https://via.placeholder.com/40'">
                                                                <span style="font-weight:600; font-size: 12.5px;"><?= htmlspecialchars($item['produce_name']) ?></span>
                                                            </div>
                                                        </td>
                                                        <td style="font-size: 12.5px;"><?= $item['quantity'] ?> bags</td>
                                                        <td style="font-size: 12.5px;">₵<?= number_format($item['unit_price'], 2) ?></td>
                                                        <td style="font-weight:600; font-size: 12.5px;">₵<?= number_format($item['subtotal'], 2) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Group Financial Escrow Ledgers -->
                                    <div style="background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 12px;">
                                        <h4 style="margin: 0 0 6px 0; font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                            <i data-feather="shield" style="width: 12px; height: 12px;"></i> Group Escrow Ledger
                                        </h4>
                                        <?php if (empty($group_escrows)): ?>
                                            <p style="font-size:12px; color:var(--text-muted); font-style:italic; margin: 0;">No escrow assets generated for this group.</p>
                                        <?php else: ?>
                                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                                <?php foreach ($group_escrows as $esc): ?>
                                                <?php $ec = $escrowConfig[$esc['status']] ?? ['label'=>'Unknown','color'=>'badge-pending']; ?>
                                                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px;">
                                                    <div>
                                                        <span style="font-weight: 600;">₵<?= number_format($esc['amount'], 2) ?></span> 
                                                        <span style="color: var(--text-muted);"> (Fee: ₵<?= number_format($esc['platform_fee_portion'], 2) ?>)</span>
                                                    </div>
                                                    <span class="badge <?= $ec['color'] ?>" style="font-size: 9.5px; padding: 2px 6px;"><?= $ec['label'] ?></span>
                                                </div>
                                                <?php if ($esc['momo_disbursement_ref']): ?>
                                                <div style="font-size:10px; font-family:monospace; color:var(--text-muted); word-break: break-all; background: #fff; padding: 4px; border-radius: 4px; border: 1px solid #e2e8f0;">
                                                    Disbursement Reference: <?= htmlspecialchars($esc['momo_disbursement_ref']) ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Group Disputes Docket -->
                                    <?php if (!empty($group_disputes)): ?>
                                        <div style="border: 1px solid #fecaca; border-radius: 8px; padding: 12px; background: #fffdfd; margin-bottom: 12px;">
                                            <h4 style="margin: 0 0 6px 0; font-size: 11px; color: var(--danger); text-transform: uppercase; display: flex; align-items: center; gap: 4px;">
                                                <i data-feather="alert-triangle" style="width: 12px; height: 12px;"></i> Segment Disputes
                                            </h4>
                                            <?php foreach ($group_disputes as $disp): ?>
                                            <div style="border-bottom: 1px dashed #fecaca; padding-bottom: 8px; margin-bottom: 8px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                                    <strong style="font-size:12px;"><?= htmlspecialchars($disp['title']) ?></strong>
                                                    <span class="badge badge-rejected" style="font-size: 9.5px; padding: 2px 6px;"><?= str_replace('_', ' ', $disp['status']) ?></span>
                                                </div>
                                                <p style="font-size:11.5px; margin: 4px 0; font-style:italic;">"<?= htmlspecialchars($disp['description']) ?>"</p>
                                                
                                                <?php if ($disp['decision']): ?>
                                                    <div style="font-size:11px; margin-top:4px; color:var(--text-main);">
                                                        <strong>Ruling:</strong> <em>"<?= htmlspecialchars($disp['decision']) ?>"</em>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($disp['status'] === 'open'): ?>
                                                <form method="POST" style="margin-top:6px; display:inline-block;">
                                                    <?php if (isset($_SESSION['csrf_token'])): ?>
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="intervention_type" value="review_dispute">
                                                    <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                                                    <input type="hidden" name="dispute_id" value="<?= $disp['id'] ?>">
                                                    <button type="submit" class="btn-action" style="height:26px; font-size:10px; padding:0 6px;"><i data-feather="eye" style="width:10px;"></i> Under Review</button>
                                                </form>
                                                <?php endif; ?>

                                                <?php if (in_array($disp['status'], ['open', 'under_review'])): ?>
                                                    <form method="POST" class="control-form" style="margin-top:6px; border-top:1px dashed #e2e8f0; padding-top:6px;">
                                                        <?php if (isset($_SESSION['csrf_token'])): ?>
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                        <?php endif; ?>
                                                        <input type="hidden" name="intervention_type" value="resolve_dispute">
                                                        <input type="hidden" name="order_id" value="<?= $selected_order['id'] ?>">
                                                        <input type="hidden" name="dispute_id" value="<?= $disp['id'] ?>">
                                                        
                                                        <div>
                                                            <textarea name="admin_decision" class="control-textarea" rows="2" placeholder="Write official resolution directive..." required style="min-height:55px; font-size:11.5px;"></textarea>
                                                        </div>
                                                        <div style="display:flex; gap:6px;">
                                                            <button type="submit" name="status" value="resolved" class="btn-submit" style="font-size:11px; padding:6px 10px; flex:1;">Resolve Dispute</button>
                                                            <button type="submit" name="status" value="dismissed" class="btn-action" style="font-size:11px; padding:6px 10px; flex:1;">Dismiss</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Group Escrow Override Intervention -->
                                    <?php if ($group_status !== 'delivered' && $group_status !== 'cancelled'): ?>
                                        <div class="intervention-box" style="margin-top: 12px; padding: 14px;">
                                            <h4><i data-feather="sliders" style="width: 13px; height: 13px;"></i> Escrow Group Override</h4>
                                            <form method="POST" class="control-form" style="margin-top:6px;">
                                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="intervention_type" value="execute_intervention">
                                                <input type="hidden" name="group_id" value="<?= $group_id ?>">

                                                <div>
                                                    <label style="font-size:10.5px;">Action Type</label>
                                                    <select name="action_type" class="filter-input" required style="height:34px; font-size:11.5px;">
                                                        <option value="release_to_seller">Release Escrow (Mark Segment Delivered)</option>
                                                        <option value="refund_to_buyer">Refund Escrow (Cancel Segment & Reimburse Buyer)</option>
                                                    </select>
                                                </div>

                                                <div>
                                                    <label style="font-size:10.5px;">Justification Statement</label>
                                                    <textarea name="decision" class="control-textarea" rows="2" required placeholder="State official reasoning for audit trail..." style="min-height:55px; font-size:11.5px;"></textarea>
                                                </div>

                                                <button type="submit" onclick="return confirm('You are authorizing a manual override for group <?= htmlspecialchars($group['group_code']) ?>. Proceed?');" class="btn-submit" style="background:#d97706;">Execute Group Intervention</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Progression Events Timeline -->
                        <div class="detail-card">
                            <h3 style="font-size:15px; margin: 0 0 16px 0; font-weight:600;"><i data-feather="activity" style="width:15px; vertical-align:middle;"></i> Audit Milestones & Transition Logs</h3>
                            <?php if (empty($selected_order_tracking)): ?>
                                <div class="empty-state" style="padding:20px 0;">No milestone tracking generated yet.</div>
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

                    <!-- Right Column: Meta & Customer Information -->
                    <div>
                        <div class="detail-card">
                            <h2 class="detail-title">
                                <span><i data-feather="file-text" style="width:16px; vertical-align:middle;"></i> Order Details: #<?= $selected_order['id'] ?></span>
                                <?php $sc = $statusConfig[$selected_order['order_status']] ?? ['label'=>$selected_order['order_status'],'color'=>'badge-pending']; ?>
                                <span class="badge <?= $sc['color'] ?>"><?= $sc['label'] ?></span>
                            </h2>

                            <div class="detail-group">
                                <span class="detail-label">Customer Profile</span>
                                <span class="detail-val">
                                    <strong><?= htmlspecialchars($selected_order['buyer_name'] ?? 'Guest Buyer') ?></strong><br>
                                    <span style="color:var(--text-muted); font-size:12px;"><?= htmlspecialchars($selected_order['buyer_email'] ?? 'No Email') ?> &bull; <?= htmlspecialchars($selected_order['buyer_phone'] ?? 'No Phone') ?></span>
                                </span>
                            </div>

                            <div class="detail-group">
                                <span class="detail-label">Financial Ledger</span>
                                <span class="detail-val">
                                    Total Amount: <strong>₵<?= number_format($selected_order['total_amount'], 2) ?></strong><br>
                                    <span style="color:var(--primary); font-size:12px; font-weight:600;">Platform Profit Fee: ₵<?= number_format($selected_order['platform_fee'], 2) ?></span><br>
                                    <span style="color:var(--text-muted); font-size:12px;">Payout MoMo: <?= htmlspecialchars($selected_order['buyer_momo'] ?: 'None Configured') ?></span>
                                </span>
                            </div>

                            <div class="detail-group">
                                <span class="detail-label">Delivery Address</span>
                                <span class="detail-val">
                                    <strong><?= htmlspecialchars($selected_order['delivery_name']) ?></strong><br>
                                    <?= htmlspecialchars($selected_order['delivery_address']) ?><br>
                                    <span style="color:var(--text-muted); font-size:12px;">Contact: <?= htmlspecialchars($selected_order['delivery_phone']) ?></span>
                                </span>
                            </div>

                            <div class="detail-group">
                                <span class="detail-label">Payment Status</span>
                                <span class="detail-val">
                                    <strong style="text-transform:uppercase; font-size:12px;"><?= htmlspecialchars($selected_order['payment_status']) ?></strong><br>
                                    <span style="color:var(--text-muted); font-size:11px;">Placed On: <?= date('d M Y, h:i A', strtotime($selected_order['created_at'])) ?></span>
                                </span>
                            </div>

                            <?php if (!empty($selected_order['buyer_notes'])): ?>
                            <div class="detail-group" style="border-bottom:none;">
                                <span class="detail-label">Instructions</span>
                                <span class="detail-val" style="background: #faf8f5; padding: 8px 10px; border-radius: 6px; border: 1px dashed #f59e0b; font-style: italic; font-size:12px;">
                                    "<?= htmlspecialchars($selected_order['buyer_notes']) ?>"
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <!-- GLOBAL LIST REGISTRY VIEW -->
            <?php else: ?>
                <!-- FILTERS BAR -->
                <form method="GET" class="filters-bar">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                    
                    <div class="filter-group">
                        <span class="filter-label">Keyword Search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Ref ID, Recipient name, Address..." class="filter-input">
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Progression State</span>
                        <select name="status" class="filter-input">
                            <option value="">All Active & Archived</option>
                            <?php foreach ($statusConfig as $key => $val): ?>
                                <option value="<?= $key ?>" <?= ($status_filter === $key) ? 'selected' : '' ?>><?= $val['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <span class="filter-label">Dispute Condition</span>
                        <select name="has_dispute" class="filter-input">
                            <option value="">All Accounts</option>
                            <option value="1" <?= ($dispute_filter === '1') ? 'selected' : '' ?>>Flagged Disputes Only</option>
                        </select>
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="btn-search">
                            <i data-feather="filter" style="width:14px;"></i> Search
                        </button>
                        <a href="admin_marketplace_oversight.php?tab=<?= htmlspecialchars($activeTab) ?>" class="btn-action">
                            Reset
                        </a>
                    </div>
                </form>

                <!-- PORTFOLIOS TABLE CARD -->
                <div class="card">
                    <div class="card-header-padded">
                        <h2 class="card-title">
                            <?php if ($activeTab === 'disputes'): ?>
                                <i data-feather="alert-triangle"></i> Registry of Logged Platform Disputes
                            <?php else: ?>
                                <i data-feather="list"></i> Global Marketplace Ledger
                            <?php endif; ?>
                        </h2>
                    </div>

                    <!-- Render Tab Contents -->
                    <?php if ($activeTab === 'disputes'): ?>
                        <div class="table-responsive">
                            <?php 
                            $filteredDisputes = array_filter($allDisputes, function($disp) use ($search) {
                                if (!empty($search)) {
                                    $s = strtolower($search);
                                    return (str_contains(strtolower($disp['title']), $s) || str_contains(strtolower($disp['initiator_name'] ?? ''), $s) || $disp['order_id'] == $s || str_contains(strtolower($disp['group_code'] ?? ''), $s));
                                }
                                return true;
                            });
                            if (empty($filteredDisputes)): 
                            ?>
                                <div class="empty-state">
                                    <i data-feather="alert-triangle" style="width:38px; height:38px; margin-bottom:8px; opacity:0.5;"></i>
                                    <p>No logged disputes matching filter criteria.</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Case ID</th>
                                            <th>Order Ref</th>
                                            <th>Group Code</th>
                                            <th>Claim Overview</th>
                                            <th>Lodge Party</th>
                                            <th>Defendant</th>
                                            <th>Order Cost</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($filteredDisputes as $disp): ?>
                                        <tr style="background:#fffafa;">
                                            <td style="font-weight:600; color:var(--text-muted);">#<?= $disp['id'] ?></td>
                                            <td><strong>#<?= $disp['order_id'] ?></strong></td>
                                            <td><span class="badge badge-completed"><?= htmlspecialchars($disp['group_code'] ?? 'N/A') ?></span></td>
                                            <td><strong style="color:var(--text-main); font-size:13px;"><?= htmlspecialchars($disp['title']) ?></strong></td>
                                            <td><?= htmlspecialchars($disp['initiator_name'] ?? 'System User') ?> <span style="font-size:10px; font-weight:700; color:var(--text-muted);">(<?= strtoupper($disp['initiator_role']) ?>)</span></td>
                                            <td><?= htmlspecialchars($disp['defendant_name'] ?? 'System User') ?> <span style="font-size:10px; font-weight:700; color:var(--text-muted);">(<?= strtoupper($disp['defendant_role']) ?>)</span></td>
                                            <td style="font-weight:600;">₵<?= number_format($disp['order_amount'], 2) ?></td>
                                            <td><span class="badge badge-rejected"><?= str_replace('_', ' ', $disp['status']) ?></span></td>
                                            <td>
                                                <a href="admin_marketplace_oversight.php?dispute_id=<?= $disp['id'] ?>&tab=disputes" class="btn-action">
                                                    <i data-feather="sliders" style="width:12px;"></i> Audit Case
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <div class="table-responsive">
                            <?php if (empty($all_orders)): ?>
                                <div class="empty-state">
                                    <i data-feather="shopping-bag" style="width:38px; height:38px; margin-bottom:8px; opacity:0.5;"></i>
                                    <p>No orders match search parameters.</p>
                                </div>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Order Ref</th>
                                            <th>Recipient</th>
                                            <th>Lines</th>
                                            <th>Order Cost</th>
                                            <th>Platform Fee</th>
                                            <th>Lifecycle Status</th>
                                            <th>Disputes Audit</th>
                                            <th>Order Date</th>
                                            <th>Control Panel</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_orders as $ord): ?>
                                        <?php 
                                        $sc = $statusConfig[$ord['order_status']] ?? ['label'=>$ord['order_status'],'color'=>'badge-pending'];
                                        $has_active_dispute = $ord['active_disputes_count'] > 0;
                                        ?>
                                        <tr style="<?= ($has_active_dispute) ? 'background:#fffafa;' : '' ?>">
                                            <td style="font-weight:600; color:var(--text-muted);">#<?= $ord['id'] ?></td>
                                            <td>
                                                <strong style="color:var(--text-main); font-size:13px;"><?= htmlspecialchars($ord['buyer_name'] ?? 'Guest Buyer') ?></strong>
                                                <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($ord['delivery_phone']) ?></div>
                                            </td>
                                            <td><?= $ord['item_count'] ?> items</td>
                                            <td style="font-weight:600;">₵<?= number_format($ord['total_amount'], 2) ?></td>
                                            <td style="color:var(--primary); font-weight:600;">₵<?= number_format($ord['platform_fee'], 2) ?></td>
                                            <td><span class="badge <?= $sc['color'] ?>"><?= $sc['label'] ?></span></td>
                                            <td>
                                                <?php if ($has_active_dispute): ?>
                                                    <span class="badge badge-rejected"><i data-feather="alert-triangle" style="width:11px; height:11px;"></i> <?= $ord['active_disputes_count'] ?> FLAGGED</span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted); font-size:12px;">Clear</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--text-muted); font-size:12px;"><?= date('M d, Y', strtotime($ord['created_at'])) ?></td>
                                            <td>
                                                <a href="admin_marketplace_oversight.php?id=<?= $ord['id'] ?>&tab=transactions" class="btn-action">
                                                    <i data-feather="sliders" style="width:12px;"></i> Audit
                                                </a>
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
        // Initialize Feather Icons
        feather.replace();

        // Responsive Sidebar Drawer Controls
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
    </script>
</body>
</html>