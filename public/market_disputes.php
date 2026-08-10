<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//market_disputes.php
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

$pdo = getPDO();
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;

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
    csrf_verify();
    $group_id    = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $title       = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_SPECIAL_CHARS));
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS));

    // Validate that this group belongs to the current user (as buyer of
    // the parent order, or as the farmer who owns the package)
    if ($user_role === 'buyer') {
        $groupStmt = $pdo->prepare("
            SELECT og.id, og.order_id, og.farmer_id, og.group_code
            FROM order_groups og
            JOIN orders o ON o.id = og.order_id
            WHERE og.id = ? AND o.buyer_id = ?
        ");
        $groupStmt->execute([$group_id, $user_id]);
    } else {
        $groupStmt = $pdo->prepare("
            SELECT og.id, og.order_id, og.farmer_id, og.group_code
            FROM order_groups og
            WHERE og.id = ? AND og.farmer_id = ?
        ");
        $groupStmt->execute([$group_id, $user_id]);
    }
    $validGroup = $groupStmt->fetch(PDO::FETCH_ASSOC);

    if (!$validGroup) {
        $errorMsg = 'Invalid package reference selected.';
    } elseif (empty($title) || empty($description)) {
        $errorMsg = 'Please complete both the title and details field.';
    } else {
        if ($user_role === 'buyer') {
            $defendant_id   = $validGroup['farmer_id'];
            $defendant_role = 'farmer';
        } else {
            $buyerQuery = $pdo->prepare("SELECT buyer_id FROM orders WHERE id = ?");
            $buyerQuery->execute([$validGroup['order_id']]);
            $defendant_id   = $buyerQuery->fetchColumn();
            $defendant_role = 'buyer';
        }

        if ($defendant_id) {
            $ins = $pdo->prepare("
                INSERT INTO market_disputes
                    (order_id, order_group_id, initiator_id, initiator_role, defendant_id, defendant_role, title, description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')
            ");
            $ins->execute([$validGroup['order_id'], $group_id, $user_id, $user_role, $defendant_id, $defendant_role, $title, $description]);
            $dispute_id = $pdo->lastInsertId();

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
            $successMsg = "Your dispute against package {$validGroup['group_code']} has been logged. Admin review will proceed shortly.";
        } else {
            $errorMsg = 'Could not resolve transaction recipient information.';
        }
    }
}

// Handle submitting supplemental evidence on an active dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_evidence'])) {
    csrf_verify();
    $dispute_id = filter_input(INPUT_POST, 'dispute_id', FILTER_VALIDATE_INT);
    $notes      = trim(filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_SPECIAL_CHARS));

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

// Handle deleting uploaded evidence
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_evidence'])) {
    csrf_verify();
    $evidence_id = filter_input(INPUT_POST, 'evidence_id', FILTER_VALIDATE_INT);

    // Retrieve evidence to verify ownership
    $stmt = $pdo->prepare("SELECT * FROM market_dispute_evidence WHERE id = ?");
    $stmt->execute([$evidence_id]);
    $evidence = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($evidence) {
        // Enforce restriction: only the exact submitter can delete their own evidence
        if ((int)$evidence['submitter_id'] === (int)$user_id && $evidence['submitter_role'] === $user_role) {
            $filePath = '../uploads/disputes/' . $evidence['file_path'];
            if (file_exists($filePath) && is_file($filePath)) {
                unlink($filePath);
            }
            
            $del = $pdo->prepare("DELETE FROM market_dispute_evidence WHERE id = ?");
            $del->execute([$evidence_id]);
            $successMsg = 'Selected evidence has been deleted.';
        } else {
            $errorMsg = 'You are not authorized to delete this evidence file.';
        }
    } else {
        $errorMsg = 'Evidence file record could not be found.';
    }
}

if ($user_role === 'buyer') {
    $eligibleGroupsStmt = $pdo->prepare("
        SELECT og.id, og.group_code, og.subtotal, og.created_at, u.name AS farmer_name
        FROM order_groups og
        JOIN orders o ON o.id = og.order_id
        JOIN users u ON u.id = og.farmer_id
        WHERE o.buyer_id = ?
        ORDER BY og.created_at DESC
    ");
    $eligibleGroupsStmt->execute([$user_id]);
} else {
    $eligibleGroupsStmt = $pdo->prepare("
        SELECT og.id, og.group_code, og.subtotal, og.created_at, b.name AS buyer_name
        FROM order_groups og
        JOIN orders o ON o.id = og.order_id
        JOIN buyers b ON b.id = o.buyer_id
        WHERE og.farmer_id = ?
        ORDER BY og.created_at DESC
    ");
    $eligibleGroupsStmt->execute([$user_id]);
}
$eligibleGroups = $eligibleGroupsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch disputes where current user is involved — joined through the
// order_group so we can show exactly which package/farmer it's about.
$disputesStmt = $pdo->prepare("
    SELECT d.*, og.group_code,
           CASE WHEN d.initiator_role = 'buyer' THEN b.name ELSE u.name END AS initiator_name,
           CASE WHEN d.defendant_role = 'buyer' THEN b2.name ELSE u2.name END AS defendant_name
    FROM market_disputes d
    LEFT JOIN order_groups og ON og.id = d.order_group_id
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
    'open'         => 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-950/30 dark:text-yellow-400 dark:border-yellow-900/40',
    'under_review' => 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-950/30 dark:text-blue-400 dark:border-blue-900/40',
    'resolved'     => 'bg-green-100 text-green-800 border-green-200 dark:bg-green-950/30 dark:text-green-400 dark:border-green-900/40',
    'dismissed'    => 'bg-gray-100 text-gray-800 border-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700',
];

$page_title = 'Dispute Resolution Center | AgroMarket';
$active_nav = 'dashboard';
include 'nav.php';
?>

<!-- Fonts & RemixIcons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    agro: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' },
                    jumia: { orange: '#f68b1e', blue: '#264996' } 
                },
                fontFamily: {
                    sans: ['Plus Jakarta Sans', 'sans-serif']
                }
            }
        }
    }
</script>

<style>
:root {
    /* Variables synchronized directly with index.php, shop.php & buyer_dashboard.php */
    --primary: #15803d;       
    --primary-dark: #14532d;  
    --accent: #22c55e;        
    --accent-hover: #16a34a;
    --bg-body: #f8fafc;       
    --bg-card: #ffffff;
    --text-main: #1e293b;     
    --text-muted: #64748b;    
    --border: #e2e8f0;
    --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --glass: rgba(255, 255, 255, 0.85);
    
    --primary-light: #dcfce7;
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}

body.dark {
    /* Dark Variables synchronized directly with index.php, shop.php & buyer_dashboard.php */
    --primary: #22c55e;
    --primary-dark: #4ade80;
    --accent: #15803d;
    --bg-body: #0f172a;       
    --bg-card: #1e293b;       
    --text-main: #f1f5f9;
    --text-muted: #94a3b8;
    --border: #334155;
    --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
    --glass: rgba(15, 23, 42, 0.85);
    
    --primary-light: #14532d;
}

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg-body);
    color: var(--text-main);
    transition: background 0.3s ease, color 0.3s ease;
}
</style>

<div class="pt-28 md:pt-32 pb-16 min-h-screen px-4 sm:px-6 max-w-6xl mx-auto">
    
    <!-- Top breadcrumb / Navigation -->
    <div class="mb-6">
        <a href="<?= $user_role === 'farmer' ? 'seller_dashboard.php' : 'buyer_dashboard.php' ?>" class="inline-flex items-center gap-2 text-sm text-[var(--text-muted)] hover:text-[var(--primary)] font-semibold transition">
            <i class="ri-arrow-left-line"></i> Back to Dashboard
        </a>
    </div>

    <!-- Page Header -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 md:p-8 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6 transition-colors duration-300">
        <div>
            <h1 class="text-2xl font-black text-[var(--text-main)] tracking-tight flex items-center gap-2">
                <i class="ri-scales-3-line text-[var(--primary)] text-3xl"></i> Dispute Resolution Center
            </h1>
            <p class="text-[var(--text-muted)] text-sm mt-1">
                Fulfill and manage claims securely. Provide descriptive proof so administrators can mediate disputes.
            </p>
        </div>
        <button onclick="document.getElementById('new-dispute-modal').classList.remove('hidden')" class="bg-red-600 dark:bg-red-500 text-white px-5 py-3 rounded-xl font-bold text-sm hover:bg-red-700 dark:hover:bg-red-600 transition shadow-md flex items-center justify-center gap-2 self-start md:self-auto">
            <i class="ri-alert-line text-base"></i> File New Dispute
        </button>
    </div>

    <!-- System Messages -->
    <?php if ($errorMsg): ?>
    <div class="mb-6 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/50 text-red-700 dark:text-red-400 text-sm rounded-xl flex items-center gap-2">
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
            <span class="w-2.5 h-2.5 rounded-full bg-red-600 dark:bg-red-500"></span> Active & Past Disputes
        </h2>

        <?php if (empty($disputes)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-16 text-center shadow-sm transition-colors duration-300">
            <div class="w-16 h-16 rounded-full bg-[var(--bg-body)] flex items-center justify-center mx-auto mb-4 border border-[var(--border)]">
                <i class="ri-shield-check-line text-2xl text-[var(--text-muted)]"></i>
            </div>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">Clean Record</h3>
            <p class="text-[var(--text-muted)] text-sm max-w-sm mx-auto">There are currently no active or historic disputes linked to your user account profile.</p>
        </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($disputes as $d): ?>
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm transition-colors duration-300">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[var(--border)] pb-4 mb-4">
                        <div>
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="font-bold text-base text-[var(--text-main)]">Case #<?= $d['id'] ?>: <?= htmlspecialchars($d['title']) ?></span>
                                <span class="text-xs px-2.5 py-1 rounded-full font-bold uppercase border <?= $statusBadge[$d['status']] ?>">
                                    <?= str_replace('_', ' ', $d['status']) ?>
                                </span>
                            </div>
                            <p class="text-xs text-[var(--text-muted)] mt-1">
                                Package: <span class="text-[var(--text-main)] font-semibold">#<?= htmlspecialchars($d['group_code']) ?></span> · Opened <?= date('d M Y, h:i A', strtotime($d['created_at'])) ?>
                            </p>
                        </div>
                        <div class="text-xs text-[var(--text-muted)] flex flex-col items-end gap-1">
                            <div>Initiator: <span class="text-[var(--text-main)] font-semibold"><?= htmlspecialchars($d['initiator_name']) ?> (<?= ucfirst($d['initiator_role']) ?>)</span></div>
                            <div>Defendant: <span class="text-[var(--text-main)] font-semibold"><?= htmlspecialchars($d['defendant_name']) ?> (<?= ucfirst($d['defendant_role']) ?>)</span></div>
                        </div>
                    </div>

                    <!-- Separated Statements & Supporting Evidence List -->
                    <?php
                    $evStmt = $pdo->prepare("SELECT * FROM market_dispute_evidence WHERE dispute_id = ? ORDER BY created_at ASC");
                    $evStmt->execute([$d['id']]);
                    $evidences = $evStmt->fetchAll(PDO::FETCH_ASSOC);

                    $buyer_evidences = [];
                    $farmer_evidences = [];
                    foreach ($evidences as $ev) {
                        if ($ev['submitter_role'] === 'buyer') {
                            $buyer_evidences[] = $ev;
                        } else {
                            $farmer_evidences[] = $ev;
                        }
                    }
                    ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Buyer Segment -->
                        <div class="bg-[var(--bg-body)] p-4 rounded-xl border border-[var(--border)]">
                            <h4 class="text-xs font-black uppercase tracking-wider mb-3 flex items-center gap-1.5 text-blue-700 dark:text-blue-400">
                                <i class="ri-user-shared-line"></i> Buyer's Case & Evidence
                            </h4>
                            
                            <?php if ($d['initiator_role'] === 'buyer'): ?>
                                <div class="mb-4 p-3 bg-[var(--bg-card)] rounded-lg border border-[var(--border)] text-xs shadow-sm">
                                    <span class="font-bold block text-[var(--text-muted)] uppercase tracking-wider text-[9px] mb-1">Primary Statement (Initiator)</span>
                                    <p class="text-[var(--text-main)] leading-relaxed italic">"<?= nl2br(htmlspecialchars($d['description'])) ?>"</p>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($buyer_evidences)): ?>
                                <p class="text-xs text-[var(--text-muted)] italic">No visual evidence files attached by the buyer.</p>
                            <?php else: ?>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <?php foreach ($buyer_evidences as $ev): ?>
                                        <div class="group relative rounded-xl border border-[var(--border)] overflow-hidden bg-[var(--bg-card)] p-1">
                                            <img src="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" alt="Buyer Evidence" class="w-full aspect-square object-cover rounded-lg">
                                            <div class="absolute inset-0 bg-[var(--bg-card)]/95 opacity-0 group-hover:opacity-100 transition-all duration-200 flex flex-col items-center justify-center p-3 text-center text-[11px] text-[var(--text-main)] backdrop-blur-xs">
                                                <?php if (!empty($ev['notes'])): ?>
                                                    <p class="italic w-full mb-2 bg-[var(--bg-body)] p-2 rounded max-h-[60px] overflow-y-auto text-xs leading-snug border border-[var(--border)]">
                                                        <strong class="text-amber-700 dark:text-amber-400">Note:</strong> <?= htmlspecialchars($ev['notes']) ?>
                                                    </p>
                                                <?php else: ?>
                                                    <p class="italic mb-2 text-[var(--text-muted)]">No notes attached.</p>
                                                <?php endif; ?>
                                                <div class="flex items-center gap-3 mt-1">
                                                    <a href="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" target="_blank" class="text-blue-600 dark:text-sky-400 hover:underline font-bold">View File</a>
                                                    <?php if ((int)$ev['submitter_id'] === (int)$user_id && $ev['submitter_role'] === $user_role): ?>
                                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this evidence?');" class="inline">
                                                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="evidence_id" value="<?= $ev['id'] ?>">
                                                            <button type="submit" name="delete_evidence" class="text-red-600 dark:text-rose-400 hover:underline font-bold">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Farmer Segment -->
                        <div class="bg-[var(--bg-body)] p-4 rounded-xl border border-[var(--border)]">
                            <h4 class="text-xs font-black uppercase tracking-wider mb-3 flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400">
                                <i class="ri-user-received-line"></i> Farmer's Case & Evidence
                            </h4>
                            
                            <?php if ($d['initiator_role'] === 'farmer'): ?>
                                <div class="mb-4 p-3 bg-[var(--bg-card)] rounded-lg border border-[var(--border)] text-xs shadow-sm">
                                    <span class="font-bold block text-[var(--text-muted)] uppercase tracking-wider text-[9px] mb-1">Primary Statement (Initiator)</span>
                                    <p class="text-[var(--text-main)] leading-relaxed italic">"<?= nl2br(htmlspecialchars($d['description'])) ?>"</p>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($farmer_evidences)): ?>
                                <p class="text-xs text-[var(--text-muted)] italic">No visual evidence files attached by the farmer.</p>
                            <?php else: ?>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <?php foreach ($farmer_evidences as $ev): ?>
                                        <div class="group relative rounded-xl border border-[var(--border)] overflow-hidden bg-[var(--bg-card)] p-1">
                                            <img src="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" alt="Farmer Evidence" class="w-full aspect-square object-cover rounded-lg">
                                            <div class="absolute inset-0 bg-[var(--bg-card)]/95 opacity-0 group-hover:opacity-100 transition-all duration-200 flex flex-col items-center justify-center p-3 text-center text-[11px] text-[var(--text-main)] backdrop-blur-xs">
                                                <?php if (!empty($ev['notes'])): ?>
                                                    <p class="italic w-full mb-2 bg-[var(--bg-body)] p-2 rounded max-h-[60px] overflow-y-auto text-xs leading-snug border border-[var(--border)]">
                                                        <strong class="text-amber-700 dark:text-amber-400">Note:</strong> <?= htmlspecialchars($ev['notes']) ?>
                                                    </p>
                                                <?php else: ?>
                                                    <p class="italic mb-2 text-[var(--text-muted)]">No notes attached.</p>
                                                <?php endif; ?>
                                                <div class="flex items-center gap-3 mt-1">
                                                    <a href="../uploads/disputes/<?= htmlspecialchars($ev['file_path']) ?>" target="_blank" class="text-blue-600 dark:text-sky-400 hover:underline font-bold">View File</a>
                                                    <?php if ((int)$ev['submitter_id'] === (int)$user_id && $ev['submitter_role'] === $user_role): ?>
                                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this evidence?');" class="inline">
                                                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="evidence_id" value="<?= $ev['id'] ?>">
                                                            <button type="submit" name="delete_evidence" class="text-red-600 dark:text-rose-400 hover:underline font-bold">Delete</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Supplemental Form (Active Cases Only) -->
                    <?php if (in_array($d['status'], ['open', 'under_review'])): ?>
                    <details class="border-t border-[var(--border)] pt-4 group">
                        <summary class="text-xs font-bold text-[var(--primary)] cursor-pointer hover:underline flex items-center gap-1 select-none">
                            <i class="ri-add-circle-line text-sm"></i> Submit Additional Evidence
                        </summary>

                        <form method="POST" enctype="multipart/form-data" class="mt-4 space-y-4 max-w-lg">
                            <?php if (isset($_SESSION['csrf_token'])): ?>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <?php endif; ?>
                            <input type="hidden" name="dispute_id" value="<?= $d['id'] ?>">
                            <div>
                                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Select Images *</label>
                                <input type="file" name="evidence[]" multiple required accept="image/*" class="w-full text-sm text-[var(--text-muted)] file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border file:border-[var(--border)] file:text-xs file:font-semibold file:bg-[var(--bg-body)] file:text-[var(--text-main)] hover:file:bg-[var(--primary-light)] file:transition cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Reason / Clarification Note</label>
                                <input type="text" name="notes" placeholder="e.g., Detailed image displaying quality metrics variation" class="w-full border border-[var(--border)] rounded-xl px-3 py-2 text-xs bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                            </div>
                            <button type="submit" name="add_evidence" class="bg-[var(--primary)] text-white text-xs px-4 py-2 rounded-xl font-bold hover:bg-[var(--primary-dark)] transition">
                                Upload Evidences
                            </button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <!-- Decision Resolution Box -->
                    <?php if ($d['status'] === 'resolved' || $d['status'] === 'dismissed'): ?>
                    <?php 
                        $decisionBg = $d['status'] === 'resolved' 
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/20 dark:border-emerald-800/40 dark:text-emerald-300' 
                            : 'bg-slate-100 border-slate-300 text-slate-800 dark:bg-slate-900/40 dark:border-slate-800 dark:text-slate-200';
                    ?>
                    <div class="mt-4 p-5 rounded-xl border <?= $decisionBg ?>">
                        <div class="flex items-center gap-2 text-sm font-bold mb-1">
                            <i class="ri-checkbox-circle-line text-lg"></i> Resolution Decision (<?= ucfirst($d['status']) ?>)
                        </div>
                        <p class="text-xs opacity-85 mb-3">Processed on: <?= date('d M Y, h:i A', strtotime($d['decision_date'])) ?></p>
                        <div class="text-sm italic p-4 rounded-lg bg-[var(--bg-card)] border border-[var(--border)] leading-relaxed font-medium text-[var(--text-main)]">
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
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl max-w-xl w-full p-6 shadow-xl relative animate-fadeIn text-[var(--text-main)] transition-colors duration-300">
        <button onclick="document.getElementById('new-dispute-modal').classList.add('hidden')" class="absolute top-4 right-4 text-[var(--text-muted)] hover:text-[var(--text-main)] transition text-xl">
            <i class="ri-close-line"></i>
        </button>
        <h3 class="text-lg font-bold mb-1">File Transaction Dispute</h3>
        <p class="text-xs text-[var(--text-muted)] mb-4">Highlight order transaction anomalies. Admin staff will cross-verify provided data fields.</p>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?php if (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php endif; ?>
            <input type="hidden" name="file_dispute" value="1">
            
            <div>
                <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Linked Order ID *</label>
                <select name="group_id" required class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-1 focus:ring-[var(--primary)]">
                    <option value="">-- Choose Order --</option>
                    <?php foreach ($eligibleGroups as $eg): ?>
                        <option value="<?= $eg['id'] ?>"><?= htmlspecialchars($eg['group_code']) ?> — <?= htmlspecialchars($eg['farmer_name'] ?? $eg['buyer_name']) ?> (₵<?= number_format($eg['subtotal'], 2) ?>)</option>
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
                <input type="file" name="evidence[]" multiple accept="image/*" class="w-full text-sm text-[var(--text-muted)] file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border file:border-[var(--border)] file:text-xs file:font-semibold file:bg-[var(--bg-body)] file:text-[var(--text-main)] hover:file:bg-[var(--primary-light)] file:transition cursor-pointer">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('new-dispute-modal').classList.add('hidden')" class="border border-[var(--border)] text-[var(--text-muted)] px-4 py-2 rounded-xl text-xs font-bold hover:bg-[var(--bg-body)] transition">
                    Cancel
                </button>
                <button type="submit" class="bg-red-600 dark:bg-red-500 hover:bg-red-700 dark:hover:bg-red-600 text-white px-5 py-2 rounded-xl text-xs font-bold transition">
                    Submit Dispute
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Sync dark mode setting from localStorage
if (localStorage.getItem('theme') === 'dark') {
    document.body.classList.add('dark');
}
</script>

</body>
</html>