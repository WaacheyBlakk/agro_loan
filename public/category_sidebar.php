<aside class="category-sidebar">
    <h3>Categories</h3>
    <nav>
        <ul>
            <!-- Add class="active" to the current page link manually or via PHP -->
            <li><a href="shop.php" class="active">All Products</a></li>
            <li><a href="shop.php?category=grains">Grains</a></li>
            <li><a href="shop.php?category=vegetables">Vegetables</a></li>
            <li><a href="shop.php?category=fruits">Fruits</a></li>
            <li><a href="shop.php?category=tubers">Tubers</a></li>
            <li><a href="shop.php?category=others">Others</a></li>
        </ul>
    </nav>
</aside>

<style>
/* Container */
.category-sidebar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border: 1px solid #eee;
    /* Responsive width */
    width: 100%; 
    max-width: 250px; 
}

/* Typography */
.category-sidebar h3 {
    margin: 0 0 15px 0;
    font-size: 1.2rem;
    color: #333;
    padding-bottom: 10px;
    border-bottom: 2px solid #4CAF50; /* distinct divider */
}

/* List Reset */
.category-sidebar ul {
    padding: 0;
    margin: 0;
    list-style: none;
}

/* Link Styling */
.category-sidebar ul li a {
    display: block; 
    color: #555;
    text-decoration: none;
    font-weight: 500;
    padding: 10px 12px;
    border-radius: 8px;
    transition: all 0.3s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Hover State */
.category-sidebar ul li a:hover {
    background-color: #f1f8e9; 
    color: #4CAF50;
    transform: translateX(5px); 
}

/* Active State (Simulated) */
.category-sidebar ul li a.active {
    background-color: #4CAF50;
    color: white;
    font-weight: bold;
    box-shadow: 0 2px 5px rgba(76, 175, 80, 0.3);
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .category-sidebar {
        max-width: 100%;
        margin-bottom: 20px;
    }
}
</style>