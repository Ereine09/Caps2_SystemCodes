<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

$order = get_order_by_id($order_id, (int)$customer['id']);
$product = get_product_by_id($product_id);

if (!$order || !$product || $order['order_status'] !== 'completed') {
    header('Location: orders.php');
    exit();
}

// Check if already reviewed
if (has_customer_reviewed_product((int)$customer['id'], $product_id, $order_id)) {
    $_SESSION['message'] = 'You have already reviewed this product for this order.';
    $_SESSION['message_type'] = 'error';
    header('Location: orders.php?id=' . $order_id);
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $review_text = trim($_POST['review_text'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating between 1 and 5 stars.';
    } else {
        $data = [
            'order_id' => $order_id,
            'product_id' => $product_id,
            'customer_id' => (int)$customer['id'],
            'rating' => $rating,
            'review_text' => $review_text
        ];
        if (submit_product_review($data)) {
            $_SESSION['message'] = 'Thank you for your feedback!';
            $_SESSION['message_type'] = 'success';
            header('Location: orders.php?id=' . $order_id);
            exit();
        } else {
            $error = 'There was an error submitting your review. Please try again.';
        }
    }
}

$page_title = 'Submit Review';
include __DIR__ . '/includes/header.php';
?>

<style>
.review-form-container {
    max-width: 850px; /* Widened from 700px to fill the space better */
    width: 90%; /* Ensures responsiveness on smaller screens */
    margin: 40px auto; /* Centering with generous top/bottom space */
    background: #ffffff;
    padding: 40px; /* Increased padding for better breathing room */
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0; /* Soft border matching your UI style */
}

.product-to-review {
    display: flex;
    align-items: center;
    gap: 15px;
    background: #f8fafc;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
}
.product-to-review img {
    width: 70px;
    height: 70px;
    object-fit: contain;
    border-radius: 6px;
}
.star-rating {
    display: flex;
    flex-direction: row-reverse;
    justify-content: center;
    size: 6.5rem; /* Adjust the size of the stars */
    font-size: 6.5rem;/* star sizeeeeee*/
    gap: 5px;
}
.star-rating input { display: none; }
.star-rating label {
    font-size: 4rem; /* Change this value to make them bigger (e.g., 5rem, 6rem) */
    line-height: 1;
    color: #e2e8f0;
    cursor: pointer;
    transition: color 0.2s;
}
.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label {
    color: #f59e0b;
}


</style>

<section class="customer-panel">
    <div class="review-form-container">
        <h2 style="text-align: center; margin-bottom: 10px;">Write a Review</h2>
        <p style="text-align: center; color: #64748b; margin-top: 0; margin-bottom: 20px;">Share your thoughts on this product.</p>

        <div class="product-to-review">
            <img src="<?php echo htmlspecialchars($product['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" alt="">
            <div>
                <h3 style="margin:0;"><?php echo htmlspecialchars($product['name']); ?></h3>
                <small>From Order #<?php echo htmlspecialchars($order['order_number']); ?></small>
            </div>
        </div>

        <?php if ($error): ?><div class="alert-message alert-error"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST">
            <div class="form-row">
                <label style="text-align: center; font-size: 1.1rem; margin-bottom: 15px;">Your Rating</label>
                <div class="star-rating">
                    <input type="radio" id="star5" name="rating" value="5" /><label for="star5" title="5 stars">★</label>
                    <input type="radio" id="star4" name="rating" value="4" /><label for="star4" title="4 stars">★</label>
                    <input type="radio" id="star3" name="rating" value="3" /><label for="star3" title="3 stars">★</label>
                    <input type="radio" id="star2" name="rating" value="2" /><label for="star2" title="2 stars">★</label>
                    <input type="radio" id="star1" name="rating" value="1" /><label for="star1" title="1 star">★</label>
                </div>
            </div>

            <div class="form-row" style="margin-top: 25px;">
                <label for="review_text">Your Review (Optional)</label>
                <textarea name="review_text" id="review_text" rows="5" placeholder="What did you like or dislike?"></textarea>
            </div>

            <button type="submit" class="button" style="width: 100%; margin-top: 20px;">Submit Review</button>
        </form>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>