<?php
require_once __DIR__ . '/../src/db.php';
$pdoSidebar = getPDO();
$active_category = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);

// Retrieve categories directly from our database configuration 
$sidebarCategories = $pdoSidebar->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<aside class="category-sidebar">
    <h3>Categories</h3>
    <nav>
        <ul>
            <li>
                <a href="shop.php" class="<?= !$active_category ? 'active' : '' ?>">All Products</a>
            </li>
            <?php foreach ($sidebarCategories as $cat): ?>
                <li>
                    <a href="shop.php?category=<?= (int)$cat['id'] ?>" class="<?= $active_category == $cat['id'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>

<style>
.category-sidebar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border: 1px solid #eee;
    width: 100%; 
    max-width: 250px; 
}
.category-sidebar h3 {
    margin: 0 0 15px 0;
    font-size: 1.2rem;
    color: #333;
    padding-bottom: 10px;
    border-bottom: 2px solid #4CAF50;
}
.category-sidebar ul {
    padding: 0;
    margin: 0;
    list-style: none;
}
.category-sidebar ul li a {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #555;
    text-decoration: none;
    font-weight: 500;
    padding: 10px 12px;
    border-radius: 8px;
    transition: all 0.3s ease;
}
.category-sidebar ul li a:hover {
    background-color: #f1f8e9; 
    color: #4CAF50;
    transform: translateX(5px); 
}
.category-sidebar ul li a.active {
    background-color: #4CAF50;
    color: white;
    font-weight: bold;
    box-shadow: 0 2px 5px rgba(76, 175, 80, 0.3);
}
@media (max-width: 768px) {
    .category-sidebar {
        max-width: 100%;
        margin-bottom: 20px;
    }
}
</style>