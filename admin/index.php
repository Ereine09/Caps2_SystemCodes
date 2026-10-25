<?php
// Short address for the Admin/Staff sign-in: yourdomain/admin -> modules/auth/login.php
// (the site root now opens the customer shop - see ../index.php).
header('Location: ../modules/auth/login.php', true, 302);
exit();
