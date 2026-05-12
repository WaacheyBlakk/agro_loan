<?php
session_start();
require_once '../src/db.php';

$pdo = getPDO();

// 1. Validation and Sanitation
$product_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$product_id) {
    header("Location: shop.php"); 
    exit;
}

// 2. Fetch Product Details (with Category Name)
// Note: Adapting column names to match standard convention (price_per_bag, bags_available)
$stmt = $pdo->prepare("
    SELECT p.*, u.name AS farmer_name, u.phone AS farmer_contact, c.name AS category_name, c.id as category_id
    FROM products p 
    JOIN users u ON p.farmer_id = u.id 
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if product exists
if (!$product) {
    die("<h2 style='text-align:center; margin-top:50px;'>Product not found. <a href='index.php'>Go Back</a></h2>");
}

// 3. Fetch Images
$imgStmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
$imgStmt->execute([$product_id]);
$images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

// If no gallery images, check if main image exists in product table or use placeholder
$mainImage = !empty($product['image']) ? $product['image'] : ($images[0] ?? null);
$displayImages = array_merge([$mainImage], $images); // Combine main + gallery
$displayImages = array_filter(array_unique($displayImages)); // Remove duplicates and empty

// 4. Fetch Related Products (Same Category)
$relatedStmt = $pdo->prepare("
    SELECT * FROM products 
    WHERE category_id = ? AND id != ? 
    LIMIT 4
");
$relatedStmt->execute([$product['category_id'], $product_id]);
$related_products = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

$is_logged = isset($_SESSION['id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> | Agro Market</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        agro: { 50: '#ecfdf5', 100: '#d1fae5', 600: '#059669', 700: '#047857', 900: '#064e3b' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-sans text-gray-800">

    <nav class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <a href="index.php" class="flex items-center gap-2 text-gray-700 hover:text-agro-600 transition">
                <i class="fa-solid fa-arrow-left"></i> <span class="font-medium">Back to Market</span>
            </a>
            <div class="flex items-center gap-4">
                 <a href="cart.php" class="relative text-gray-600 hover:text-agro-600">
                    <i class="fa-solid fa-cart-shopping text-xl"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        
        <!-- Breadcrumbs -->
        <nav class="text-sm text-gray-500 mb-6">
            <ol class="list-none p-0 inline-flex">
                <li class="flex items-center"><a href="index.php" class="hover:text-agro-600">Home</a></li>
                <li class="flex items-center"><span class="mx-2">/</span><a href="index.php?category=<?= $product['category_id'] ?>" class="hover:text-agro-600"><?= htmlspecialchars($product['category_name']) ?></a></li>
                <li class="flex items-center"><span class="mx-2">/</span><span class="text-gray-800 font-medium"><?= htmlspecialchars($product['name']) ?></span></li>
            </ol>
        </nav>

        <!-- Product Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            
            <!-- LEFT COLUMN: Image Gallery -->
            <div class="space-y-4">
                <div class="w-full h-96 bg-gray-100 rounded-xl overflow-hidden relative group">
                    <?php $initialImg = !empty($displayImages) ? "../uploads/produce/" . $displayImages[0] : "https://via.placeholder.com/600x600?text=No+Image"; ?>
                    <img id="mainImage" src="<?= $initialImg ?>" class="w-full h-full object-cover object-center transition-transform duration-500 hover:scale-105">
                </div>
                
                <?php if (count($displayImages) > 1): ?>
                <div class="flex gap-3 overflow-x-auto py-2 custom-scrollbar">
                    <?php foreach ($displayImages as $img): ?>
                        <button onclick="changeImage(this, '../uploads/produce/<?= $img ?>')" 
                                class="w-20 h-20 flex-shrink-0 border-2 border-transparent hover:border-agro-600 rounded-lg overflow-hidden transition focus:border-agro-600 focus:outline-none">
                            <img src="../uploads/produce/<?= $img ?>" class="w-full h-full object-cover">
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: Details -->
            <div class="flex flex-col">
                <div class="mb-2">
                    <span class="bg-agro-50 text-agro-700 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide">
                        <?= htmlspecialchars($product['category_name']) ?>
                    </span>
                </div>
                
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4"><?= htmlspecialchars($product['name']) ?></h1>
                
                <div class="flex items-end gap-3 mb-6">
                    <p class="text-3xl font-bold text-agro-700">GH₵<?= number_format($product['price_per_bag'] ?? $product['price'], 2) ?></p>
                    <span class="text-gray-500 mb-1">/ per bag</span>
                </div>

                <!-- Farmer Info Card -->
                <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-xl mb-6 border border-gray-100">
                    <div class="w-12 h-12 bg-agro-200 rounded-full flex items-center justify-center text-agro-700 text-xl">
                        <i class="fa-solid fa-user-farmer"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Sold by</p>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($product['farmer_name']) ?></p>
                    </div>
                </div>

                <!-- Description -->
                <div class="prose text-gray-600 mb-8">
                    <h3 class="font-bold text-gray-900 mb-2">Description</h3>
                    <p class="leading-relaxed"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                </div>

                <!-- Stock & Cart Actions -->
                <div class="mt-auto border-t border-gray-100 pt-6">
                    <?php if ($product['bags_available'] > 0): ?>
                        <div class="flex items-center gap-2 mb-4 text-green-600 font-medium">
                            <i class="fa-solid fa-check-circle"></i> In Stock (<?= $product['bags_available'] ?> bags)
                        </div>

                        <form action="add_to_cart.php" method="POST" class="flex flex-col sm:flex-row gap-4">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            
                            <!-- Quantity Selector -->
                            <div class="flex items-center border border-gray-300 rounded-lg w-32">
                                <button type="button" onclick="updateQty(-1)" class="w-10 h-10 text-gray-500 hover:bg-gray-100 rounded-l-lg transition">-</button>
                                <input type="number" id="qty" name="quantity" value="1" min="1" max="<?= $product['bags_available'] ?>" 
                                       class="w-12 h-10 text-center border-none focus:ring-0 text-gray-800 font-medium appearance-none">
                                <button type="button" onclick="updateQty(1)" class="w-10 h-10 text-gray-500 hover:bg-gray-100 rounded-r-lg transition">+</button>
                            </div>

                            <button type="submit" class="flex-1 bg-agro-600 hover:bg-agro-700 text-white font-bold py-3 px-6 rounded-lg shadow-lg shadow-agro-600/20 transition transform hover:-translate-y-0.5 flex justify-center items-center gap-2">
                                <i class="fa-solid fa-cart-plus"></i> Add to Cart
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="bg-red-50 text-red-600 p-4 rounded-lg text-center font-bold">
                            <i class="fa-solid fa-circle-xmark mr-2"></i> Out of Stock
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Related Products Section -->
        <?php if (!empty($related_products)): ?>
        <div class="mt-16">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Similar Produce</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <?php foreach ($related_products as $rp): ?>
                    <a href="product_detail.php?id=<?= $rp['id'] ?>" class="group block bg-white rounded-xl shadow-sm hover:shadow-md transition border border-gray-100 overflow-hidden">
                        <div class="h-40 bg-gray-100 overflow-hidden">
                            <img src="../uploads/produce/<?= !empty($rp['image']) ? $rp['image'] : 'placeholder.jpg' ?>" 
                                 class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                        </div>
                        <div class="p-4">
                            <h3 class="font-bold text-gray-800 truncate"><?= htmlspecialchars($rp['name']) ?></h3>
                            <p class="text-agro-700 font-bold mt-1">GH₵<?= $rp['price_per_bag'] ?? $rp['price'] ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- JavaScript for Interactions -->
    <script>
        // Image Gallery Swap
        function changeImage(element, src) {
            document.getElementById('mainImage').src = src;
        }

        // Quantity Selector Logic
        function updateQty(change) {
            const input = document.getElementById('qty');
            let val = parseInt(input.value);
            let max = parseInt(input.getAttribute('max'));
            
            val += change;
            
            if (val >= 1 && val <= max) {
                input.value = val;
            }
        }
    </script>
</body>
</html>