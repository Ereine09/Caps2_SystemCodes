<?php
require_once __DIR__ . '/includes/auth.php';
// require_customer_login();

$customer = current_customer();
$message = '';

// Handle updates from the cart UI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_quantity'])) {
        $product_id = (int)$_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        update_customer_cart_item((int)$customer['id'], $product_id, $quantity);
        header('Location: cart.php'); // Redirect to prevent form resubmission and refresh page
        exit();
    } elseif (isset($_POST['remove_item'])) {
        $product_id = (int)$_POST['product_id'];
        remove_customer_cart_item((int)$customer['id'], $product_id);
        header('Location: cart.php'); // Redirect to prevent form resubmission and refresh page
        exit();
    }
}

$cart = get_customer_cart();
$subtotal_net = cart_subtotal_ex_vat();
$subtotal_gross = cart_subtotal();
?>
<?php $page_title = 'My Shopping Cart'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .cart-container { background: white; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
    .cart-header { padding: 25px; border-bottom: 2px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
    .cart-table { width: 100%; border-collapse: collapse; }
    .cart-table th { background: #f8fafc; padding: 15px; text-align: left; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }
    .cart-table td { padding: 20px 15px; border-bottom: 1px solid #f1f5f9; }
    .cart-item-info { display: flex; align-items: center; gap: 15px; }
    .cart-item-img { width: 70px; height: 70px; object-fit: contain; border-radius: 10px; background: #f8fafc; border: 1px solid #eee; }
    .qty-input { width: 70px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; text-align: center; }
    .btn-remove { color: #ef4444; background: none; border: none; cursor: pointer; font-size: 0.9rem; font-weight: 600; }
    .btn-remove:hover { text-decoration: underline; }
    .cart-footer { padding: 30px; background: #f8fafc; display: flex; justify-content: flex-end; }
    .cart-totals { width: 300px; }
    .total-row { display: flex; justify-content: space-between; margin-bottom: 15px; }
    .total-row.grand-total { font-size: 1.5rem; font-weight: 800; color: #1e293b; border-top: 2px solid #e2e8f0; padding-top: 15px; }

    @media (max-width: 600px) {
        .cart-footer {
            justify-content: center;
        }
        .cart-totals {
            width: 100%;
        }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Shopping Cart 🛒</h2>
        <p>Review and organize your items before proceeding to checkout.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert-message alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($cart): ?>
        <div class="cart-container">
            <div class="cart-header">
                <span style="font-weight: 700; color: #64748b;"><?php echo count($cart); ?> Items Selected</span>
                <a href="products.php" style="color: #6366f1; text-decoration: none; font-weight: 600;"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
            </div>
            <div style="overflow-x: auto;">
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th style="text-align:right;">Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart as $item): ?>
                            <tr>
                                <td>
                                    <div class="cart-item-info">
                                        <img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL.'/assets/img/placeholder.png'); ?>" class="cart-item-img">
                                        <div>
                                            <div style="font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <div style="font-size: 0.8rem; color: #64748b;">ID: #<?php echo $item['id']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>PHP <?php echo number_format($item['unit_price'], 2); ?></td>
                                <td>
                                    <form method="POST" style="display:flex; align-items:center; gap:5px;">
                                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                        <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" class="qty-input">
                                        <button type="submit" name="update_quantity" class="button" style="padding: 8px 12px; font-size: 0.75rem;"><i class="fas fa-sync"></i></button>
                                    </form>
                                </td>
                                <td style="text-align:right; font-weight: 700; color: #312e81;">
                                    PHP <?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?>
                                </td>
                                <td style="text-align:center;">
                                    <form method="POST" onsubmit="return confirm('Remove this item?');">
                                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" name="remove_item" class="btn-remove"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="cart-footer">
                <div class="cart-totals">
                    <?php $vat_amount = $subtotal_net * 0.12; ?>
                    <div class="total-row">
                        <span style="color: #64748b;">Subtotal (Net)</span>
                        <span style="font-weight: 700;">PHP <?php echo number_format($subtotal_net, 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span style="color: #64748b;">VAT (12%)</span>
                        <span style="font-weight: 700;">PHP <?php echo number_format($vat_amount, 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span style="color: #64748b;">Estimated Points</span>
                        <span style="color: #10b981; font-weight: 700;">+<?php echo number_format($subtotal_gross / 100, 2); ?> pts</span>
                    </div>
                    <div class="total-row grand-total">
                        <span>Total</span>
                        <span>PHP <?php echo number_format($subtotal_gross, 2); ?></span>
                    </div>
                    <a href="checkout.php" class="button" style="width: 100%; text-align: center; display: block; padding: 15px;">Proceed to Checkout <i class="fas fa-credit-card"></i></a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 50px; background: white; border-radius: 15px;">
            <div style="font-size: 4rem; color: #e2e8f0; margin-bottom: 20px;"><i class="fas fa-shopping-cart"></i></div>
            <h3>Your cart is empty</h3>
            <p style="color: #64748b; margin-bottom: 25px;">Looks like you haven't added anything to your cart yet.</p>
            <a href="products.php" class="button">Start Shopping</a>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>