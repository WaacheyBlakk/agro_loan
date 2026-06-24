<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

$pdo = getPDO();
$user_role = $_SESSION['role'] ?? 'buyer'; // 'farmer' or 'buyer'
$is_logged = true;

// Get user profile details & cart counts
if ($user_role === 'farmer') {
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $cart_count = 0;
} else {
    $userStmt = $pdo->prepare("SELECT name FROM buyers WHERE id = ?");
    $cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id=?");
    $cStmt->execute([$user_id]);
    $cart_count = (int)$cStmt->fetchColumn();
}
$userStmt->execute([$user_id]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

$errorMsg = '';
$successMsg = '';

// Handle filing a new dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_dispute'])) {
    $order_id    = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $title       = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_SPECIAL_CHARS));
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS));

    // Validate if the order belongs to this user/role context
    if ($user_role === 'buyer') {
        $orderStmt = $pdo->prepare("SELECT id, id AS order_id FROM orders WHERE id = ? AND buyer_id = ?");
        $orderStmt->execute([$order_id, $user_id]);
    } else {
        $orderStmt = $pdo->prepare("SELECT DISTINCT o.id AS order_id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.id = ? AND oi.farmer_id = ?");
        $orderStmt->execute([$order_id, $user_id]);
    }
    $validOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$validOrder) {
        $errorMsg = 'Invalid order reference selected.';
    } elseif (empty($title) || empty($description)) {
        $errorMsg = 'Please complete both the title and details field.';
    } else {
        // Resolve defendant details
        if ($user_role === 'buyer') {
            // Defendant is the farmer of the item inside the order
            $farmerQuery = $pdo->prepare("SELECT farmer_id FROM order_items WHERE order_id = ? LIMIT 1");
            $farmerQuery->execute([$order_id]);
            $defendant_id = $farmerQuery->fetchColumn();
            $defendant_role = 'farmer';
        } else {
            // Defendant is the buyer who ordered
            $buyerQuery = $pdo->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $buyerQuery->execute([$order_id]);
            $defendant_id = $buyerQuery->fetchColumn();
            $defendant_role = 'buyer';
        }

        if ($defendant_id) {
            $ins = $pdo->prepare("INSERT INTO market_disputes (order_id, initiator_id, initiator_role, defendant_id, defendant_role, title, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'open')");
            $ins->execute([$order_id, $user_id, $user_role, $defendant_id, $defendant_role, $title, $description]);
            $dispute_id = $pdo->lastInsertId();

            // Handle multi-file evidence upload
            if (!empty($_FILES['evidence']['name'][0])) {
                $uploadDir = '../uploads/disputes/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES['evidence']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['evidence']['error'][$key] === UPLOAD_ERR_OK) {
                        $fileName = time() . '_' . basename($_FILES['evidence']['name'][$key]);
                        $targetPath = $uploadDir . $fileName;

                        if (move_uploaded_file($tmpName, $targetPath)) {
                            $stmt = $pdo->prepare("INSERT INTO market_dispute_evidence (dispute_id, submitter_id, submitter_role, file_path) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$dispute_id, $user_id, $user_role, $fileName]);
                        }
                    }
                }
            }
            $successMsg = 'Your dispute has been logged. Admin review will proceed shortly.';
        } else {
            $errorMsg = 'Could not resolve transaction recipient information.';
        }
    }
}

// Handle submitting supplemental evidence on an active dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evidence'])) {
    $dispute_id = filter_input(INPUT_POST, 'dispute_id', FILTER_VALIDATE_INT);
    $notes      = trim(filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_SPECIAL_CHARS));

    // Confirm involvement in this dispute
    $check = $pdo->prepare("SELECT id FROM market_disputes WHERE id = ? AND ((initiator_id = ? AND initiator_role = ?) OR (defendant_id = ? AND defendant_role = ?))");
    $check->execute([$dispute_id, $user_id, $user_role, $user_id, $user_role]);
    
    if ($check->fetch()) {
        if (!empty($_FILES['evidence']['name'][0])) {
            $uploadDir = '../uploads/disputes/';
            foreach ($_FILES['evidence']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['evidence']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = time() . '_' . basename($_FILES['evidence']['name'][$key]);
                    $targetPath = $uploadDir . $fileName;
                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $stmt = $pdo->prepare("INSERT INTO market_dispute_evidence (dispute_id, submitter_id, submitter_role, file_path, notes) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$dispute_id, $user_id, $user_role, $fileName, $notes]);
                    }
                }
            }
            $successMsg = 'Evidence attachments updated.';
        } else {
            $errorMsg = 'Please attach valid images or documents to submit.';
        }
    } else {
        $errorMsg = 'Unauthorized access sequence.';
    }
}

// Fetch list of orders this user is linked with to populate dropdown choices
if ($user_role === 'buyer') {
    $eligibleOrdersStmt = $pdo->prepare("SELECT id, created_at, total_amount FROM orders WHERE buyer_id = ? ORDER BY created_at DESC");
    $eligibleOrdersStmt->execute([$user_id]);
} else {
    $eligibleOrdersStmt = $pdo->prepare("SELECT DISTINCT o.id, o.created_at, o.total_amount FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.farmer_id = ? ORDER BY o.created_at DESC");
    $eligibleOrdersStmt->execute([$user_id]);
}
$eligibleOrders = $eligibleOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch disputes where current user is involved
$disputesStmt = $pdo->prepare("
    SELECT d.*, 
           CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
           CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name
    FROM market_disputes d
    LEFT JOIN buyers b ON (d.initiator_id = b.id AND d.initiator_role = 'buyer')
    LEFT JOIN users u ON (d.initiator_id = u.id AND d.initiator_role = 'farmer')
    LEFT JOIN buyers b2 ON (d.defendant_id = b2.id AND d.defendant_role = 'buyer')
    LEFT JOIN users u2 ON (d.defendant_id = u2.id AND d.defendant_role = 'farmer')
    WHERE (d.initiator_id = ? AND d.initiator_role = ?) OR (d.defendant_id = ? AND d.defendant_role = ?)
    ORDER BY d.created_at DESC
");
$disputesStmt->execute([$user_id, $user_role, $user_id, $user_role]);
$disputes = $disputesStmt->fetchAll(PDO::FETCH_ASSOC);

$statusBadge = [
    'open'         => 'bg-yellow-100 text-yellow-800 border-yellow-200',
    'under_review' => 'bg-blue-100 text-blue-800 border-blue-200',
    'resolved'     => 'bg-green-100 text-green-800 border-green-200',
    'dismissed'    => 'bg-gray-100 text-gray-800 border-gray-200',
];

$page_title = 'Dispute Resolution Center | AgroMarket';
$active_nav = 'dashboard';
include 'nav.php';
?>

<div class="pt-28 md:pt-32 pb-16 min-h-screen px-4 sm:px-6 max-w-6xl mx-auto">
    
    <!-- Top breadcrumb / Navigation -->
    <div class="mb-6">
        <a href="<?= $user_role === 'farmer' ? 'seller_dashboard.php' : 'buyer_dashboard.php' ?>" class="inline-flex items-center gap-2 text-sm text-[var(--text-muted)] hover:text-[var(--primary)] font-semibold transition">
            <i class="ri-arrow-left-line"></i> Back to Dashboard
        </a>
    </div>

    <!-- Page Header -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 md:p-8 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <h1 class="text-2xl font-black text-[var(--text-main)] tracking-tight flex items-center gap-2">
                <i class="ri-scales-3-line text-[var(--primary)] text-3xl"></i> Dispute Resolution Center
            </h1>
            <p class="text-[var(--text-muted)] text-sm mt-1">
                Fulfill and manage claims securely. Provide descriptive proof so administrators can mediate disputes.
            </p>
        </div>
        <button onclick="document.getElementById('new-dispute-modal').classList.remove('hidden')" class="bg-red-600 text-white px-5 py-3 rounded-xl font-bold text-sm hover:bg-red-700 transition shadow-md flex items-center justify-center gap-2 self-start md:self-auto">
            <i class="ri-alert-line text-base"></i> File New Dispute
        </button>
    </div>

    <!-- System Messages -->
    <?php if ($errorMsg): ?>
    <div class="mb-6 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm rounded-xl flex items-center gap-2">
        <i class="ri-error-warning-line text-lg"></i> <?= htmlspecialchars($errorMsg) ?>
    </div>
    <?php endif; ?>
    <?php if ($successMsg): ?>
    <div class="mb-6 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm rounded-xl flex items-center gap-2">
        <i class="ri-checkbox-circle-line text-lg"></i> <?= htmlspecialchars($successMsg) ?>
    </div>
    <?php endif; ?>

    <!-- Dispute List -->
    <div class="space-y-6">
        <h2 class="text-lg font-bold text-[var(--text-main)] flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-red-600"></span> Active & Past Disputes
        </h2>

        <?php if (empty($disputes)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-16 text-center shadow-sm">
            <div class="w-16 h-16 rounded-full bg-slate-50 dark:bg-slate-900/40 flex items-center justify-center mx-auto mb-4">
                <i class="ri-shield-check-line text-2xl text-[var(--text-muted)] opacity-40"></i>
            </div>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">Clean Record</h3>
            <p class="text-[var(--text-muted)] text-sm max-w-sm mx-auto">There are currently no active or historic disputes linked to your user account profile.</p>
        </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($disputes as $d): ?>
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[var(--border)] pb-4 mb-4">
                        <div>
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="font-bold text-base text-[var(--text-main)]">Case #<?= $d['id'] ?>: <?= htmlspecialchars($d['title']) ?></span>
                                <span class="text-xs px-2.5 py-1 rounded-full font-bold uppercase border <?= $statusBadge[$d['status']] ?>">
                                    <?= str_replace('_', ' ', $d['status']) ?>
                                </span>
                            </div>
                            <p class="text-xs text-[var(--text-muted)] mt-1">
                                Order Ref: <span class="text-[var(--text-main)] font-semibold">#<?= $d['order_id'] ?></span> · Opened <?= date('d M Y, h:i A', strtotime($d['created_at'])) ?>
                            </p>
                        </div>
                        <div class="text-xs text-[var(--text-muted)] flex flex-col items-end gap-1">
                            <div>Initiator: <span class="text-[var(--text-main)] font-semibold"><?= htmlspecialchars($d['initiator_name']) ?> (<?= ucfirst($d['initiator_role']) ?>)</span></div>
                            <div>Defendant: <span class="text-[var(--text-main)] font-semibold"><?= htmlspecialchars($d['defendant_name']) ?> (<?= ucfirst($d['defendant_role']) ?>)</span></div>
                        </div>
                    </div>

                    <div class="text-sm text-[var(--text-main)] mb-6 leading-relaxed">
                        <strong class="block text-xs uppercase text-[var(--text-muted)] mb-1">Issue Details:</strong>
                        <?= nl2br(htmlspecialchars($d['description'])) ?>
                    </div>

                    <!-- Evidence List -->
                    <?php
                    $evStmt = $pdo->prepare("SELECT * FROM market_dispute_evidence WHERE dispute_id = ? ORDER BY created_at ASC");
                    $evStmt->execute([$d['id']]);
                    $evidences = $evStmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="mb-6">
                        <strong class="block text-xs uppercase text-[var(--text-muted)] mb-2">Supporting Evidences (<?= count($evidences) ?>)</strong>
                        <?php if (empty($evidences)): ?>
                            <p class="text-xs text-[var(--text-muted)] italic">No visual file proof attached yet.</p>
                        <?php else: ?>
                            <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3">
                                <?php foreach ($evidences as $ev): ?>
                                <div class="group relative rounded-xl border border-[var(--border)] overflow-hidden bg-slate-50 dark:bg-slate-900 p-1">
                                    <img src="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" alt="Evidence" class="w-full aspect-square object-cover rounded-lg">
                                    <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center p-2 text-center text-[10px] text-white">
                                        <p class="font-semibold"><?= ucfirst($ev['submitter_role']) ?></p>
                                        <?php if ($ev['notes']): ?>
                                            <p class="italic truncate w-full mt-1"><?= htmlspecialchars($ev['notes']) ?></p>
                                        <?php endif; ?>
                                        <a href="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" target="_blank" class="mt-2 text-emerald-400 hover:underline">Full Image</a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Supplemental Form (Active Cases Only) -->
                    <?php if (in_array($d['status'], ['open', 'under_review'])): ?>
                    <details class="border-t border-[var(--border)] pt-4 group">
                        <summary class="text-xs font-bold text-[var(--primary)] cursor-pointer hover:underline flex items-center gap-1">
                            <i class="ri-add-circle-line"></i> Submit Additional Evidence
                        </summary>
        
                        <?php $preSelectedOrder = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0; ?>

                        <select name="order_id" required class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                            <option value="">-- Choose Order --</option>
                            <?php foreach ($eligibleOrders as $eo): ?>
                                <option value="<?= $eo['id'] ?>" <?= $eo['id'] === $preSelectedOrder ? 'selected' : '' ?>>
                                    Order #<?= $eo['id'] ?> (₵<?= number_format($eo['total_amount'], 2) ?>) - <?= date('M d, Y', strtotime($eo['created_at'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <form method="POST" enctype="multipart/form-data" class="mt-4 space-y-4 max-w-lg">
                            <input type="hidden" name="dispute_id" value="<?= $d['id'] ?>">
                            <div>
                                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Select Images *</label>
                                <input type="file" name="evidence[]" multiple required accept="image/*" class="w-full text-sm text-[var(--text-muted)] file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[var(--primary-light)] file:text-[var(--primary)] hover:file:bg-[var(--primary)] hover:file:text-white file:transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Short note/clarification</label>
                                <input type="text" name="notes" placeholder="e.g., Picture of the inner lining decay detail" class="w-full border border-[var(--border)] rounded-xl px-3 py-2 text-xs bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                            </div>
                            <button type="submit" name="add_evidence" class="bg-[var(--primary)] text-white text-xs px-4 py-2 rounded-xl font-bold hover:bg-[var(--primary-dark)] transition">
                                Upload Evidences
                            </button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <!-- Decision Resolution Box -->
                    <?php if ($d['status'] === 'resolved' || $d['status'] === 'dismissed'): ?>
                    <div class="mt-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-900 border border-[var(--border)]">
                        <div class="flex items-center gap-2 text-sm font-bold text-[var(--text-main)] mb-1">
                            <i class="ri-bookmark-3-line text-[var(--primary)]"></i> Final Decision Outcome
                        </div>
                        <p class="text-xs text-[var(--text-muted)] font-medium mb-2">Processed on: <?= date('d M Y, h:i A', strtotime($d['decision_date'])) ?></p>
                        <div class="text-sm italic text-[var(--text-main)] bg-[var(--bg-card)] p-3 border border-[var(--border)] rounded-lg leading-relaxed">
                            "<?= nl2br(htmlspecialchars($d['decision'] ?? 'No formal statement submitted.')) ?>"
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

</div>

<!-- FILE NEW DISPUTE MODAL -->
<div id="new-dispute-modal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl max-w-xl w-full p-6 shadow-xl relative animate-fadeIn">
        <button onclick="document.getElementById('new-dispute-modal').classList.add('hidden')" class="absolute top-4 right-4 text-[var(--text-muted)] hover:text-[var(--text-main)] text-xl">
            <i class="ri-close-line"></i>
        </button>
        <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">File Transaction Dispute</h3>
        <p class="text-xs text-[var(--text-muted)] mb-4">Highlight order transaction anomalies. Admin staff will cross-verify provided data fields.</p>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="file_dispute" value="1">
            
            <div>
                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Linked Order ID *</label>
                <select name="order_id" required class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                    <option value="">-- Choose Order --</option>
                    <?php foreach ($eligibleOrders as $eo): ?>
                        <option value="<?= $eo['id'] ?>">Order #<?= $eo['id'] ?> (₵<?= number_format($eo['total_amount'], 2) ?>) - <?= date('M d, Y', strtotime($eo['created_at'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Issue Overview Title *</label>
                <input type="text" name="title" required placeholder="e.g., Damp/spoiled maize kernel sack delivery" class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
            </div>

            <div>
                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Detailed Explanation *</label>
                <textarea name="description" rows="4" required placeholder="Describe what went wrong in complete detail. Highlight date, delivery driver terms, quantities mismatch..." class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)] resize-none"></textarea>
            </div>

            <div>
                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Support Files / Photos (Multiple Allowed)</label>
                <input type="file" name="evidence[]" multiple accept="image/*" class="w-full text-sm text-[var(--text-muted)] file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[var(--primary-light)] file:text-[var(--primary)] hover:file:bg-[var(--primary)] hover:file:text-white file:transition">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('new-dispute-modal').classList.add('hidden')" class="border border-[var(--border)] text-[var(--text-muted)] px-4 py-2 rounded-xl text-xs font-bold hover:bg-slate-50 dark:hover:bg-slate-900 transition">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-xl text-xs font-bold transition">
                    Submit Dispute
                </button>
            </div>
        </form>
    </div>
</div>

</body>
</html>