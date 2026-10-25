<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/review_ui_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    ajax_auth_required(); // AJAX callers get a JSON 401 instead of the login page
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int)$payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'staff'));

if ($role !== 'admin' && $role !== 'staff') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

// Reviews are the customer's own feedback, so staff/admin can view them but cannot
// approve, decline or delete them. Only an admin can post, edit or remove the
// shop's public reply to a review.
$can_reply = ($role === 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['reply_review', 'delete_reply'], true)) {
    if (!$can_reply) {
        if (is_ajax_request()) {
            ajax_fail('Only an admin can reply to reviews.', 403);
        }
        http_response_code(403);
        exit('Only an admin can reply to reviews.');
    }
    csrf_require();

    $review_id = (int) ($_POST['review_id'] ?? 0);
    $flash = 'Something went wrong. Please try again.';
    $flash_type = 'error';

    if ($_POST['action'] === 'reply_review') {
        $reply_text = trim((string) ($_POST['reply_text'] ?? ''));
        if ($reply_text === '') {
            $flash = 'Please write a reply before sending.';
        } else {
            $result = save_review_reply($review_id, $user_id, $reply_text);
            if ($result === false) {
                $flash = 'That review could not be found, so the reply was not saved.';
            } else {
                $flash = $result === 'created' ? 'Your reply was posted.' : 'Your reply was updated.';
                $flash_type = 'success';

                // Let the reviewer know (in-app), only when a reply is first posted.
                if ($result === 'created') {
                    try {
                        $n_stmt = $conn->prepare(
                            "SELECT r.customer_id, p.name AS product_name
                             FROM tbl_product_reviews r
                             JOIN tbl_product_inventory p ON p.id = r.product_id
                             WHERE r.id = ?"
                        );
                        $n_stmt->bind_param('i', $review_id);
                        $n_stmt->execute();
                        $n_row = $n_stmt->get_result()->fetch_assoc();
                        $n_stmt->close();
                        if ($n_row) {
                            $snippet = mb_strlen($reply_text) > 200 ? mb_substr($reply_text, 0, 200) . '…' : $reply_text;
                            notifications_create($conn, [
                                'customer_id'     => (int) $n_row['customer_id'],
                                'type'            => 'review_reply',
                                'channel'         => 'in_app',
                                'title'           => 'The shop replied to your review',
                                'message'         => 'We replied to your review of "' . $n_row['product_name'] . '": ' . $snippet,
                                'reference_table' => 'tbl_product_reviews',
                                'reference_id'    => $review_id,
                            ]);
                        }
                    } catch (Throwable $e) {
                        error_log('review reply notification failed: ' . $e->getMessage());
                    }
                }
            }
        }
    } else { // delete_reply
        if (delete_review_reply($review_id)) {
            $flash = 'Your reply was removed.';
            $flash_type = 'success';
        }
    }

    // AJAX: answer with JSON (no session flash, no redirect); the page then refreshes just this review card.
    if (is_ajax_request()) {
        if ($flash_type === 'success') {
            ajax_json(['success' => true, 'message' => $flash, 'review_id' => $review_id]);
        }
        ajax_fail($flash, 422);
    }

    $_SESSION['message'] = $flash;
    $_SESSION['message_type'] = $flash_type;
    header('Location: reviews.php' . ($review_id > 0 ? '#review-' . $review_id : ''));
    exit();
}

$all_reviews = get_all_reviews();
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

// Statistics Calculation
$total_reviews = count($all_reviews);
$total_rating = 0;

foreach ($all_reviews as $r) {
    $total_rating += (int)$r['rating'];
}
$avg_rating = $total_reviews > 0 ? number_format($total_rating / $total_reviews, 1) : '0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/product_ratings.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/product_ratings.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/product-ratings.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/product-ratings.js') ?: time(); ?>"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/reviews-admin.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/reviews-admin.js') ?: time(); ?>"></script>
    <style>
        body {
            overflow-x: hidden;
            background-color: var(--surface-2);
            color: var(--text);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: var(--surface);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
        .icon-blue { background: var(--info-soft); color: var(--info); }
        .icon-yellow { background: var(--dk-warning-soft, #fefce8); color: var(--warning); }
        .icon-green { background: var(--success-soft); color: var(--success); }

        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--text); }
        .stat-info p { margin: 2px 0 0; font-size: 0.85rem; color: var(--muted); font-weight: 500; }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .tab-btn.active, .tab-btn:hover {
            background: var(--dk-primary, #3b82f6);
            color: var(--dk-on-primary, #ffffff);
        }

        /* Review Cards */
        .review-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .review-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .avatar-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--info-soft);
            color: var(--info);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .user-details h4 {
            margin: 0;
            font-size: 0.95rem;
            color: var(--text);
        }
        .product-tag {
            font-size: 0.8rem;
            color: var(--text-2);
            background: var(--surface-2);
            padding: 2px 8px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 3px;
        }
        .star-rating {
            color: #f59e0b;
            font-size: 1rem;
            margin-top: 3px;
            letter-spacing: 1px;
        }
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-approved { background: var(--success-soft); color: var(--success); }
        .status-pending { background: var(--warning-soft); color: var(--warning); }

        .review-content {
            color: var(--text-2);
            font-size: 0.95rem;
            line-height: 1.6;
            background: var(--surface-2);
            padding: 12px 16px;
            border-radius: 8px;
            border-left: 4px solid var(--border);
            margin: 12px 0 15px;
        }

        .action-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-action {
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .btn-approve { background: #10b981; color: white; }
        .btn-approve:hover { background: #059669; }
        .btn-delete { background: #ef4444; color: white; }
        .btn-delete:hover { background: #dc2626; }

        .empty-card {
            background: var(--surface);
            padding: 40px;
            text-align: center;
            border-radius: 12px;
            border: 1px dashed var(--border);
            color: var(--muted);
        }
        .empty-card i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>
    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 25px;">
            <h1>Customer Reviews</h1>
            <p><?php echo $can_reply
                ? 'Customer feedback on products. Reply to a review and your answer will appear publicly under it on the product page.'
                : 'Customer feedback on products (view only).'; ?></p>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert-message alert-<?php echo $_SESSION['message_type']; ?>" style="margin-bottom: 20px;"><?php echo $_SESSION['message']; ?></div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php echo render_reviews_admin($all_reviews, $can_reply); ?>
        <script>
            var reviewsRoot = document.getElementById('reviewsRatings');
            PRRatings.init(reviewsRoot); // photo lightbox
            RVAdmin.init(reviewsRoot);   // search / filters / sort / paging

            // Reply / edit reply / delete reply without a page refresh:
            //   submit -> fetch() POST -> JSON -> replace only that review card (+ the overview numbers).
            // Cards are keyed by their id, so a review can never appear twice.
            (function () {
                if (!window.LiveUI || !reviewsRoot) return;

                function refresh(forceId) {
                    return LiveUI.fetchDoc(location.pathname + location.search).then(function (doc) {
                        var fresh = doc.getElementById('reviewsRatings');
                        if (fresh) RVAdmin.applyFresh(reviewsRoot, fresh, { forceId: forceId || null });
                        LiveUI.syncSidebar(doc);
                    });
                }

                reviewsRoot.addEventListener('submit', function (e) {
                    var form = e.target;
                    if (!form.matches || !form.matches('form[method="post"]') || e.defaultPrevented) return; // e.g. "Remove your reply?" cancelled
                    var action = form.querySelector('[name="action"]'), rid = form.querySelector('[name="review_id"]');
                    if (!action || !rid) return;
                    e.preventDefault();

                    var cardId = 'review-' + rid.value;
                    var release = LiveUI.lock(cardId);
                    if (!release) return;                        // already being processed

                    var btn = e.submitter || form.querySelector('button[type="submit"]');
                    var card = document.getElementById(cardId);
                    var done = LiveUI.busy(btn, action.value === 'delete_reply' ? 'Removing…' : 'Sending…');
                    if (card) card.classList.add('liveui-row-busy');

                    LiveUI.post(location.pathname + location.search, LiveUI.formData(form, btn)).then(function (res) {
                        LiveUI.toast(res.message, 'success');
                        return true;
                    }).catch(function (err) {
                        LiveUI.toast(err.message, 'error');      // the form stays open with the typed text
                        return false;
                    }).then(function (ok) {
                        done();
                        if (card) card.classList.remove('liveui-row-busy');
                        release();
                        if (ok) return refresh(cardId).catch(function (err) { LiveUI.toast(err.message, 'error'); });
                    });
                });

                // New reviews / replies made elsewhere: a tiny probe every ~5s, the reviews are re-read only when
                // the reviews fingerprint changed (and only the changed cards are replaced).
                LiveUI.watch('reviews', function () { return refresh(null); }, { fallbackMs: 30000 });
            })();
        </script>
        </div>
</body>
</html>