<?php
// Site root -> the customer shop (customer/index.php: the dashboard when logged in, otherwise the
// products page, which guests can browse before making an account). Admin and staff sign in at
// /admin (admin/index.php -> modules/auth/login.php).
// A relative URL, so it works both locally (/loyalty_managements/) and on Hostinger (domain root).
// 302 (not 301) so browsers don't cache it forever if the landing page changes again.
header('Location: customer/', true, 302);
exit();
