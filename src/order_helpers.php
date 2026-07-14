<?php
/**
 * src/order_helpers.php
 *
 * Shared helpers for the per-farmer "order group" model.
 * Include this anywhere you touch orders/order_groups/order_items.
 */

if (!function_exists('generateGroupCode')) {
    /**
     * Human-readable code for a farmer's package within an order.
     * sequenceIndex 0 => A, 1 => B, 2 => C ...
     */
    function generateGroupCode(int $orderId, int $sequenceIndex): string {
        $letter = chr(65 + ($sequenceIndex % 26));
        return "ORD-{$orderId}-{$letter}";
    }
}

if (!function_exists('recomputeOrderStatus')) {
    /**
     * The parent `orders` row no longer owns the source of truth for
     * status — each order_groups row does (one per farmer). This rolls
     * the group statuses up into a single summary status on `orders`,
     * purely for list views / buyer-facing "overall order" display.
     *
     * Rule: the order is only 'delivered' once every non-cancelled group
     * is delivered; otherwise it reflects the least-advanced active group.
     */
    function recomputeOrderStatus(PDO $pdo, int $orderId): void {
        $stmt = $pdo->prepare("SELECT status FROM order_groups WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($statuses)) return;

        $rank = [
            'pending_payment'   => 0,
            'payment_confirmed' => 1,
            'preparing'         => 2,
            'in_transit'        => 3,
            'ready_for_pickup'  => 3,
            'delivered'         => 4,
        ];

        $active = array_values(array_filter($statuses, fn($s) => $s !== 'cancelled'));

        if (empty($active)) {
            $overall = 'cancelled';
        } elseif (count(array_filter($active, fn($s) => $s === 'delivered')) === count($active)) {
            $overall = 'delivered';
        } else {
            usort($active, fn($a, $b) => ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0));
            $overall = $active[0];
        }

        $pdo->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$overall, $orderId]);
    }
}

if (!function_exists('assertGroupBelongsToFarmer')) {
    /** Throws if $groupId isn't owned by $farmerId. Returns the group row on success. */
    function assertGroupBelongsToFarmer(PDO $pdo, int $groupId, int $farmerId): array {
        $stmt = $pdo->prepare("SELECT * FROM order_groups WHERE id = ? AND farmer_id = ?");
        $stmt->execute([$groupId, $farmerId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            throw new Exception('Package not found or not yours.');
        }
        return $group;
    }
}

if (!function_exists('assertGroupBelongsToBuyerOrder')) {
    /** Throws if $groupId's parent order doesn't belong to $buyerId. Returns the group row. */
    function assertGroupBelongsToBuyerOrder(PDO $pdo, int $groupId, int $buyerId): array {
        $stmt = $pdo->prepare("
            SELECT og.* FROM order_groups og
            JOIN orders o ON o.id = og.order_id
            WHERE og.id = ? AND o.buyer_id = ?
        ");
        $stmt->execute([$groupId, $buyerId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            throw new Exception('Package not found on your account.');
        }
        return $group;
    }
}
