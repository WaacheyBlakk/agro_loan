<?php

function sendMailToFarmer($farmer_id, $buyer_name, $product_name) {
    // Fetch farmer email
    global $pdo;
    $f = $pdo->prepare("SELECT email FROM users WHERE id=?");
    $f->execute([$farmer_id]);
    $email = $f->fetchColumn();

    $subject = "New Purchase Order – $product_name";
    $msg = "Hello, your product '$product_name' has been purchased by $buyer_name.";

    mail($email, $subject, $msg);
}

function sendMailToBuyer($buyer_id, $total) {
    global $pdo;
    $b = $pdo->prepare("SELECT email FROM users WHERE id=?");
    $b->execute([$buyer_id]);
    $email = $b->fetchColumn();

    $subject = "Order Confirmation";
    $msg = "Thank you for your order! Your total amount is GHC $total.";

    mail($email, $subject, $msg);
}
?>
