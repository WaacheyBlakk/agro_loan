<?php
require_once '../src/db.php';
$pdo = getPDO();

// 1. Sanitize Input
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    exit; // Don't search for single characters
}

// 2. Optimized Query 
// Fetches product details + ONE image in a single database call
$sql = "
    SELECT p.id, p.name, p.price_per_bag, 
           COALESCE(
               (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1), 
               p.image
           ) as image_path
    FROM products p 
    WHERE p.name LIKE ? 
    LIMIT 6
";

$stmt = $pdo->prepare($sql);
$stmt->execute(["%$q%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Output Modern HTML
if ($results) {
    echo '<ul class="bg-white border border-gray-100 rounded-lg shadow-xl overflow-hidden">';
    
    foreach ($results as $p) {
        // Fallback image logic
        $img = !empty($p['image_path']) ? "../uploads/produce/" . htmlspecialchars($p['image_path']) : "https://via.placeholder.com/100?text=No+Img";
        
        echo '
        <li class="border-b border-gray-100 last:border-0 hover:bg-gray-50 transition duration-150">
            <a href="product_detail.php?id=' . $p['id'] . '" class="flex items-center gap-3 p-3">
                <img src="' . $img . '" class="w-12 h-12 rounded-md object-cover border border-gray-200">
                <div class="flex-1">
                    <h4 class="text-sm font-semibold text-gray-800">' . htmlspecialchars($p['name']) . '</h4>
                    <span class="text-xs text-agro-600 font-bold">₵' . number_format($p['price_per_bag'], 2) . '</span>
                </div>
                <i class="fa-solid fa-chevron-right text-gray-300 text-xs"></i>
            </a>
        </li>';
    }
    
    // "View All" link
    echo '
    <li class="bg-gray-50 p-2 text-center">
        <a href="shop.php?search=' . htmlspecialchars($q) . '" class="text-xs text-agro-600 font-bold hover:underline">
            View all results for "' . htmlspecialchars($q) . '"
        </a>
    </li>
    </ul>';
} else {
    echo '
    <div class="bg-white p-4 text-center text-gray-500 text-sm rounded-lg shadow-xl border border-gray-100">
        No produce found.
    </div>';
}
?>