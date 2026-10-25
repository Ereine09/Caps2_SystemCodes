<?php
/**
 * Customer Reviews dashboard markup for the Admin and Staff review pages
 * (view-only; admins can also reply, see $can_reply). Styles: assets/css/product_ratings.css (.rv-*), behaviour:
 * assets/js/reviews-admin.js. Requires customer/includes/functions.php
 * (summarize_reviews) to be loaded by the calling page.
 */

if (!function_exists('review_ui_stars')) {
    function review_ui_stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        return '<span class="pr-stars" role="img" aria-label="' . $rating . ' out of 5">'
            . str_repeat('★', $rating)
            . '<span class="pr-stars-off">' . str_repeat('★', 5 - $rating) . '</span></span>';
    }
}

/** "5 minutes ago" for recent reviews, a plain date once older than a week. */
if (!function_exists('review_ui_time_ago')) {
    function review_ui_time_ago(?string $timestamp): string
    {
        $t = strtotime((string) $timestamp);
        if (!$t) {
            return '';
        }
        $diff = time() - $t;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $n = (int) floor($diff / 60);
            return $n . ($n === 1 ? ' minute ago' : ' minutes ago');
        }
        if ($diff < 86400) {
            $n = (int) floor($diff / 3600);
            return $n . ($n === 1 ? ' hour ago' : ' hours ago');
        }
        if ($diff < 7 * 86400) {
            $n = (int) floor($diff / 86400);
            return $n . ($n === 1 ? ' day ago' : ' days ago');
        }
        return date('M j, Y', $t);
    }
}

/**
 * The whole dashboard: KPI cards, rating breakdown, toolbar and review list.
 * Every number is computed from the reviews the page already loaded.
 */
if (!function_exists('render_reviews_admin')) {
    function render_reviews_admin(array $reviews, bool $can_reply = false): string
    {
        $s = summarize_reviews($reviews);
        $count = (int) $s['count'];
        $week_ago = time() - 7 * 86400;
        $new_this_week = 0;
        $awaiting_reply = 0;
        $products = [];
        foreach ($reviews as $r) {
            if (empty($r['reply'])) {
                $awaiting_reply++;
            }
            if ((int) strtotime((string) $r['created_at']) >= $week_ago) {
                $new_this_week++;
            }
            $pid = (int) $r['product_id'];
            if (!isset($products[$pid])) {
                $products[$pid] = ['name' => (string) ($r['product_name'] ?? ''), 'n' => 0];
            }
            $products[$pid]['n']++;
        }
        uasort($products, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $positive_pct = $count ? (int) round((($s['stars'][4] + $s['stars'][5]) / $count) * 100) : 0;

        ob_start(); ?>
        <div class="pr-scope rv-admin" id="reviewsRatings">

            <?php if ($count === 0): ?>
                <div class="rv-card rv-empty-state">
                    <i class="fas fa-comment-slash"></i>
                    <h3>No reviews yet</h3>
                    <p>Customer reviews will show up here once customers start rating their completed orders.</p>
                </div>
            <?php else: ?>

            <div class="rv-kpis">
                <div class="rv-card rv-kpi">
                    <span class="rv-kpi-icon rv-ic-blue"><i class="fas fa-comments"></i></span>
                    <div><div class="rv-kpi-num"><?php echo $count; ?></div><div class="rv-kpi-label">Total reviews</div></div>
                </div>
                <div class="rv-card rv-kpi">
                    <span class="rv-kpi-icon rv-ic-amber"><i class="fas fa-star"></i></span>
                    <div><div class="rv-kpi-num"><?php echo number_format($s['avg'], 1); ?><small> / 5</small></div><div class="rv-kpi-label">Average rating</div></div>
                </div>
                <div class="rv-card rv-kpi">
                    <span class="rv-kpi-icon rv-ic-green"><i class="fas fa-thumbs-up"></i></span>
                    <div><div class="rv-kpi-num"><?php echo $positive_pct; ?>%</div><div class="rv-kpi-label">Positive (4–5 stars)</div></div>
                </div>
                <div class="rv-card rv-kpi">
                    <span class="rv-kpi-icon rv-ic-violet"><i class="fas fa-bolt"></i></span>
                    <div><div class="rv-kpi-num"><?php echo $new_this_week; ?></div><div class="rv-kpi-label">New in the last 7 days</div></div>
                </div>
            </div>

            <div class="rv-layout">
                <aside class="rv-aside">
                    <div class="rv-card">
                        <div class="rv-score">
                            <div class="rv-score-num"><?php echo number_format($s['avg'], 1); ?></div>
                            <div>
                                <?php echo review_ui_stars((int) round($s['avg'])); ?>
                                <div class="rv-score-sub">Based on <?php echo $count; ?> review<?php echo $count === 1 ? '' : 's'; ?></div>
                            </div>
                        </div>

                        <div class="rv-bars" role="group" aria-label="Filter by rating">
                            <?php for ($i = 5; $i >= 1; $i--):
                                $n = (int) $s['stars'][$i];
                                $pct = $count ? round($n / $count * 100) : 0; ?>
                                <button type="button" class="rv-bar" data-star="<?php echo $i; ?>" aria-pressed="false" <?php echo $n === 0 ? 'disabled' : ''; ?>>
                                    <span class="rv-bar-label"><?php echo $i; ?> <span class="pr-stars">★</span></span>
                                    <span class="rv-bar-track"><span class="rv-bar-fill" style="width: <?php echo $pct; ?>%"></span></span>
                                    <span class="rv-bar-count"><?php echo $n; ?></span>
                                </button>
                            <?php endfor; ?>
                        </div>

                        <div class="rv-quick">
                            <button type="button" class="rv-toggle" data-flag="comments" aria-pressed="false"><i class="fas fa-comment-dots"></i> With comments <span><?php echo (int) $s['with_comments']; ?></span></button>
                            <button type="button" class="rv-toggle" data-flag="media" aria-pressed="false"><i class="fas fa-image"></i> With photos <span><?php echo (int) $s['with_media']; ?></span></button>
                            <button type="button" class="rv-toggle" data-flag="unreplied" aria-pressed="false"><i class="fas fa-reply"></i> Awaiting reply <span><?php echo (int) $awaiting_reply; ?></span></button>
                        </div>
                    </div>
                </aside>

                <section class="rv-main">
                    <div class="rv-card rv-toolbar">
                        <div class="rv-search">
                            <i class="fas fa-search"></i>
                            <input type="search" id="rvSearch" placeholder="Search reviews…" autocomplete="off" aria-label="Search reviews">
                        </div>
                        <select id="rvProduct" aria-label="Filter by product">
                            <option value="">All products</option>
                            <?php foreach ($products as $pid => $p): ?>
                                <option value="<?php echo (int) $pid; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo (int) $p['n']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <select id="rvSort" aria-label="Sort reviews">
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first</option>
                            <option value="highest">Highest rating</option>
                            <option value="lowest">Lowest rating</option>
                        </select>
                    </div>

                    <div class="rv-status">
                        <span id="rvCount" aria-live="polite">Showing <?php echo $count; ?> reviews</span>
                        <button type="button" id="rvClear" class="rv-clear" hidden><i class="fas fa-xmark"></i> Clear filters</button>
                    </div>

                    <div class="rv-list" id="rvList">
                        <?php foreach ($reviews as $r):
                            $text = trim((string) ($r['review_text'] ?? ''));
                            $images = $r['images'] ?? [];
                            $name = (string) ($r['customer_name'] ?? 'Customer');
                            $initial = mb_strtoupper(mb_substr($name, 0, 1)) ?: 'C';
                            $hue = crc32($name) % 360;
                            $variation = trim((string) ($r['variation'] ?? ''));
                            $product_name = (string) ($r['product_name'] ?? '');
                            $product_image = trim((string) ($r['product_image'] ?? ''));
                            $ts = (int) strtotime((string) $r['created_at']);
                            $search = mb_strtolower($name . ' ' . $product_name . ' ' . $variation . ' ' . $text);
                            $reply = $r['reply'] ?? null;
                            $review_id = (int) $r['id'];
                        ?>
                            <article class="rv-card rv-item" id="review-<?php echo $review_id; ?>"
                                     data-replied="<?php echo $reply ? 1 : 0; ?>"
                                     data-rating="<?php echo (int) $r['rating']; ?>"
                                     data-comment="<?php echo $text !== '' ? 1 : 0; ?>"
                                     data-media="<?php echo $images ? 1 : 0; ?>"
                                     data-product="<?php echo (int) $r['product_id']; ?>"
                                     data-ts="<?php echo $ts; ?>"
                                     data-search="<?php echo htmlspecialchars($search); ?>">
                                <div class="rv-avatar" style="--h: <?php echo $hue; ?>"><?php echo htmlspecialchars($initial); ?></div>
                                <div class="rv-content">
                                    <header class="rv-item-head">
                                        <div>
                                            <strong class="rv-name"><?php echo htmlspecialchars($name); ?></strong>
                                            <div class="rv-rating"><?php echo review_ui_stars((int) $r['rating']); ?> <span><?php echo (int) $r['rating']; ?>.0</span></div>
                                        </div>
                                        <time class="rv-time" datetime="<?php echo htmlspecialchars((string) $r['created_at']); ?>" title="<?php echo htmlspecialchars(substr((string) $r['created_at'], 0, 16)); ?>"><?php echo htmlspecialchars(review_ui_time_ago($r['created_at'])); ?></time>
                                    </header>

                                    <div class="rv-tags">
                                        <span class="rv-product">
                                            <?php if ($product_image !== ''): ?><img src="<?php echo htmlspecialchars($product_image); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                                            <?php echo htmlspecialchars($product_name); ?>
                                        </span>
                                        <?php if ($variation !== ''): ?><span class="rv-variation"><?php echo htmlspecialchars($variation); ?></span><?php endif; ?>
                                    </div>

                                    <?php if ($text !== ''): ?>
                                        <p class="rv-text"><?php echo htmlspecialchars($text); ?></p>
                                    <?php else: ?>
                                        <p class="rv-text rv-nocomment">No written comment.</p>
                                    <?php endif; ?>

                                    <?php if ($images): ?>
                                        <div class="pr-media">
                                            <?php foreach ($images as $url): ?>
                                                <button type="button" class="pr-thumb" data-full="<?php echo htmlspecialchars($url); ?>" aria-label="View photo"><img loading="lazy" alt="" src="<?php echo htmlspecialchars($url); ?>"></button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($reply): ?>
                                        <div class="pr-reply">
                                            <div class="pr-reply-head">
                                                <i class="fas fa-store"></i> <strong>Shop reply</strong>
                                                <time datetime="<?php echo htmlspecialchars($reply['created_at']); ?>"><?php echo htmlspecialchars(review_ui_time_ago($reply['created_at'])); ?><?php echo !empty($reply['updated_at']) ? ' &middot; edited' : ''; ?></time>
                                            </div>
                                            <p class="pr-reply-text"><?php echo htmlspecialchars($reply['text']); ?></p>
                                            <?php if ($can_reply): ?>
                                                <div class="rv-reply-actions">
                                                    <details class="rv-reply-form">
                                                        <summary><i class="fas fa-pen"></i> Edit reply</summary>
                                                        <form method="post" action="reviews.php">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="reply_review">
                                                            <input type="hidden" name="review_id" value="<?php echo $review_id; ?>">
                                                            <textarea name="reply_text" rows="3" maxlength="1000" required aria-label="Edit your reply"><?php echo htmlspecialchars($reply['text']); ?></textarea>
                                                            <div class="rv-reply-buttons"><button type="submit" class="rv-btn-primary"><i class="fas fa-paper-plane"></i> Save changes</button></div>
                                                        </form>
                                                    </details>
                                                    <form method="post" action="reviews.php" onsubmit="return confirm('Remove your reply? The customer will no longer see it.');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_reply">
                                                        <input type="hidden" name="review_id" value="<?php echo $review_id; ?>">
                                                        <button type="submit" class="rv-btn-link rv-btn-danger"><i class="fas fa-trash"></i> Delete reply</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($can_reply): ?>
                                        <details class="rv-reply-form rv-reply-new">
                                            <summary><i class="fas fa-reply"></i> Reply to this review</summary>
                                            <form method="post" action="reviews.php">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="reply_review">
                                                <input type="hidden" name="review_id" value="<?php echo $review_id; ?>">
                                                <textarea name="reply_text" rows="3" maxlength="1000" required placeholder="Write a public reply. The customer will see it under their review and get a notification." aria-label="Your reply"></textarea>
                                                <div class="rv-reply-buttons"><button type="submit" class="rv-btn-primary"><i class="fas fa-paper-plane"></i> Post reply</button></div>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="rv-card rv-empty-state" id="rvEmpty" hidden>
                        <i class="fas fa-filter-circle-xmark"></i>
                        <h3>No reviews match your filters</h3>
                        <p>Try a different search, product or rating.</p>
                    </div>

                    <div class="rv-more"><button type="button" id="rvMore" hidden>Show more reviews</button></div>
                </section>
            </div>

            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
