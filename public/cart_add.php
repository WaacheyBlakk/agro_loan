<?php
session_start();
require_once __DIR__ . '/../src/db.php';

// 1. Helper to detect AJAX requests (from shop.php fetch)
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

// 2. Response Helper
function sendResponse($success, $message, $redirect = 'shop.php') {
    if (isAjax()) {
        header('Content-Type: application/json');
        http_response_code($success ? 200 : 400);
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    } else {
        // Store message in session for standard page reloads
        $_SESSION['msg'] = $message;
        header("Location: " . $redirect);
        exit;
    }
}

// 3. Auth & Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, "Invalid request method.");
}

$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? '';

if (!$user_id) {
    if (isAjax()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please login to shop.']);
        exit;
    }
    // You might want to change this to a generic login page if farmers have a different login URL
    header("Location: buyers_login.php"); 
    exit;
}


// 4. Data Sanitization
$produce_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$quantity_to_add = 1; 

if (!$produce_id) {
    sendResponse(false, "Invalid product specified.");
}

$pdo = getPDO();

try {
    // 5. Check Stock Availability
    $stockStmt = $pdo->prepare("SELECT id, bags_available, price_per_bag FROM produce_listings WHERE id = ?");
    $stockStmt->execute([$produce_id]);
    $product = $stockStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        sendResponse(false, "Product does not exist.");
    }

    if ($product['bags_available'] < $quantity_to_add) {
        sendResponse(false, "Item is out of stock.");
    }

    // 6. Check if item exists in user's cart
    $cartStmt = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE buyer_id = ? AND produce_id = ?");
    $cartStmt->execute([$user_id, $produce_id]);
    $cartItem = $cartStmt->fetch(PDO::FETCH_ASSOC);

    if ($cartItem) {
        // UPDATE existing row
        $new_quantity = $cartItem['quantity'] + $quantity_to_add;

        // Ensure we don't exceed stock
        if ($new_quantity > $product['bags_available']) {
            sendResponse(false, "Cannot add more. Max stock available reached.");
        }

        $updateStmt = $pdo->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$new_quantity, $cartItem['id']]);
        
        sendResponse(true, "Cart updated successfully.");

    } else {
        // INSERT new row
        $insertStmt = $pdo->prepare("INSERT INTO cart_items (buyer_id, produce_id, quantity, added_at) VALUES (?, ?, ?, NOW())");
        $insertStmt->execute([$user_id, $produce_id, $quantity_to_add]);
        
        sendResponse(true, "Item added to cart.");
    }

} catch (PDOException $e) {
    error_log("Cart Error: " . $e->getMessage());
    sendResponse(false, "Database error occurred.");
}
?>