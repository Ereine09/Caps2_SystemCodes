<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

// JWT Authentication
$token = getJWTFromCookie();
if (!$token) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = $payload['user_id'];

$message = "";

// CONSIDER: Database schema checks and alterations should be handled by migration scripts, not on every page load.
// Example:
// $clientGenderColumn = mysqli_query($conn, "SHOW COLUMNS FROM clients LIKE 'gender'");
// if ($clientGenderColumn && mysqli_num_rows($clientGenderColumn) === 0) {
//     mysqli_query($conn, "ALTER TABLE clients ADD COLUMN gender VARCHAR(20) DEFAULT NULL AFTER phone");
// }
// $clientAgeColumn = mysqli_query($conn, "SHOW COLUMNS FROM clients LIKE 'age'");
// if ($clientAgeColumn && mysqli_num_rows($clientAgeColumn) === 0) {
//     mysqli_query($conn, "ALTER TABLE clients ADD COLUMN age INT DEFAULT NULL AFTER gender");
// }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $gender = $_POST['gender'] ?? '';
    $age = isset($_POST['age']) && $_POST['age'] !== '' ? (int) $_POST['age'] : null;
    $address = $_POST['address'];

    // Insert into customers table
    $stmt = $conn->prepare("INSERT INTO customers (name, email, phone, gender, age, address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssis", $name, $email, $phone, $gender, $age, $address);

    if ($stmt->execute()) {
        // 🔥 LOG ACTION: Napaka-importante sa Capstone defense!
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Add Customer', ?)");
        $details = "Added new customer: " . $name;
        $log_stmt->bind_param("is", $user_id, $details);
        $log_stmt->execute();

        $message = "<p style='color: green;'>Customer added successfully! <a href='customers.php#customers-section'>View Customers</a></p>";
    } else {
        $message = "<p style='color: red;'>Error adding customer: " . $conn->error . "</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Customer</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .form-container { max-width: 500px; margin: 50px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        input, textarea { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
        .btn-submit { background: #4a3e94; color: white; border: none; padding: 12px; width: 100%; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body style="background: #f0f2f5;">

<div class="form-container">
    <h2>Add New Customer</h2>
    <?php echo $message; ?> <?php // XSS: $message is output directly. ?>
    <form method="POST">
        <label>Full Name</label>
        <input type="text" name="name" required placeholder="e.g. Juan Dela Cruz">
        
        <label>Email Address</label>
        <input type="email" name="email" required placeholder="juan@example.com">
        
        <label>Phone Number</label>
        <input type="text" name="phone" placeholder="+639...">

        <label>Gender</label>
        <select name="gender" required style="width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px;">
            <option value="">Select Gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
        </select>

        <label>Age</label>
        <input type="number" name="age" min="0" max="120" required placeholder="e.g. 21">
        
        <label>Address</label>
        <textarea name="address" rows="3" placeholder="Customer's Address"></textarea>
        
        <button type="submit" class="btn-submit">Save Customer</button>
        <a href="customers.php" style="display:block; text-align:center; margin-top:15px; text-decoration:none; color:#666;">Cancel</a>
    </form>
</div>

</body>
</html>