<?php
/** Rider logout: clears the rider's login cookie (the same cookie the rider APIs read). */
require_once __DIR__ . '/includes/bootstrap.php';

rider_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
	csrf_require();
	clearJWTCookie();
	app_session_end();
	header('Location: ' . BASE_URL . '/rider/login.php?msg=logged_out');
	exit();
}

rider_page_start('Log out', ['back' => 'profile.php']);
?>
<section class="rd-card rd-stack" style="max-width:480px;margin:32px auto;text-align:center;">
	<h2 style="margin:0;">Log out?</h2>
	<p class="rd-help" style="margin:0;">You will need to sign in again to view your deliveries.</p>
	<form method="post" class="rd-stack">
		<?php echo csrf_field(); ?>
		<button type="submit" name="confirm_logout" value="1" class="rd-btn rd-btn-danger rd-btn-block rd-btn-lg">
			<i class="fas fa-right-from-bracket"></i> Log out
		</button>
	</form>
</section>
<?php rider_page_end('profile'); ?>
