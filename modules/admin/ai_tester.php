<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/gemini_helper.php';

// 1. SECURITY: Admin Only
$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    die("Access denied. Admin login required.");
}

$test_message = $_POST['test_message'] ?? '';
$ai_response = '';
$raw_payload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($test_message)) {
    // This mimics the exact payload sent by your messaging system
    $raw_payload = [
        "context" => "customer_inquiry", 
        "message" => $test_message, 
        "system" => SYSTEM_NAME,
        "persona" => "You are a friendly expert from Darius Poultry Supply. Our brand is warm, neighborly, and deeply cares about animals.",
        "instructions" => "Help the customer with their question. If they mention pets like cats, emphasize how our quality feeds and supplies provide health benefits, happiness, and vitality to their pets. Keep responses concise (max 2 sentences) and encouraging."
    ];

    $ai_response = getGeminiBusinessInsight($raw_payload);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Auto-Reply Tester</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
</head>
<body style="background: #f0f2f5; padding: 40px;">
    <div class="table-box" style="max-width: 800px; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        <h1><i class="fas fa-robot" style="color: #4a3e94;"></i> AI Auto-Reply Debugger</h1>
        <p>Use this tool to test if your Gemini API key and Brand Persona are working correctly.</p>
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

        <form method="POST">
            <label style="font-weight: bold; display: block; margin-bottom: 10px;">Simulate Customer Message:</label>
            <textarea name="test_message" placeholder="e.g., Do you have benefits for my cat if I buy your food?" 
                      style="width: 100%; height: 100px; padding: 15px; border-radius: 10px; border: 1px solid #ddd; font-family: inherit;" required><?php echo htmlspecialchars($test_message); ?></textarea>
            
            <button type="submit" style="background: #4a3e94; color: white; border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: bold; margin-top: 15px; width: 100%;">
                Generate AI Response
            </button>
        </form>

        <?php if ($ai_response): ?>
            <div style="margin-top: 30px; padding: 20px; background: #eef2ff; border-left: 5px solid #4a3e94; border-radius: 8px;">
                <h3 style="margin-top: 0; color: #312e81;"><i class="fas fa-comment-dots"></i> AI Result:</h3>
                <p style="font-size: 1.1rem; line-height: 1.6; color: #1e293b;">
                    <strong>[AI Auto-Reply]:</strong> <?php echo htmlspecialchars($ai_response); ?>
                </p>
            </div>

            <div style="margin-top: 20px;">
                <button onclick="document.getElementById('debug-data').style.display='block'" style="font-size: 0.8rem; background: none; border: none; color: #667eea; cursor: pointer; text-decoration: underline;">
                    Show Debug Payload
                </button>
                <pre id="debug-data" style="display: none; background: #1e272e; color: #d2dae2; padding: 15px; border-radius: 8px; margin-top: 10px; font-size: 0.8rem; overflow-x: auto;">
<?php echo print_r($raw_payload, true); ?>
                </pre>
            </div>
        <?php endif; ?>

        <div style="margin-top: 30px; text-align: center;">
            <a href="dashboard.php" style="color: #666; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>
</body>
</html>