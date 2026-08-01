You are there and an hour classing know why they don't share on our screenshot. Thank you. I don't know PowerPoint, so let's go down with Word. Type machine just have this one has been in the chair. don't machine all no PowerPoint so last time we talk about asymmetric asymmetric asymmetric we talk about asymmetric encryption so when we talk about asymmetric encryption in a class in Kiseron so we are talking about two keys we have public key and private key so with the AES we are working with asymmetric block asymmetric symmetric sorry symmetric block so it means that it's unclassing keys that's why it is work faster than the asymmetric so it encrypts data on the fixed size blocks of using one two eight one nine two N two five six as it was established by the National Institute of Standards and Technology or the NIST for data protection worldwide so this one device uses a key sizes and correspond multiple rounds of substitution and permutation so in one twenty eight feats we are working with ten rounds in one ninety two param fourteen rounds number of beats number of rounds so you are just simply one round so most common these are the uses for our use cases for an AES because it much work faster the rotatory but it's for internet communication this encryption the WPA and WPA two the wire security the PPN and the cloud storage so the most commonly used for the AES encryption so the key generation we're going to start with the initial key so the initial key will be provided such as one two eight one ninety two point two five six based on our example we're going to use one two eight so the key expansion we're going to expand it your given keys with ten rounds to be in ten rounds with ten different keys using the substitution box or the S box nang AES so when we talk about zero zero that is sixty three when we talk about one zero that is CA when it is one seven so it is one seven that is F zero so we're going to transform that into a state name value is coming from this so we have the rotation word subation word from the given transform transform so we're going to have the rotation so we're going to rotate so the first row doesn't need to rotate the second row so we have the third row the longest in which I rotate the same but then of course of course we have the round constant archon so it's a constant point so in every layer constant so the kind so every time create ion we have generated a different round key to generate kind key probably call it process the sub by it's the sub location rotation in the round constant we are done with the ten rounds under one hundred twenty so let's have this given context so an example so we're going to arrange the plain text in the state metric so this is sixteen so four by four the number of columns and the number of row is four as in the sixteen yet zero zero zero zero zero one one two three three four four five five six six seven seven eight eight nine nine eight BBC DEEFF so this is now your plaintext so your plaintext not in Canina wait I'm going to open an Excel AC language to share the whole screen we have zero zero blah blah zero zero zero one zero two zero three IDB one one pala sorry one one two three three so four four five five six six seven seven eight eight nine nine zero eight remember that this plain text into hexadecimal formal letters so because remember that our character is character is not anti computer is into hexadecimal format and we have the round key so this is will be our plain text the round key given zero zero zero zero zero two we have zero a zero B this zero C zero D zero E and we have zero on our so the next thing to do is and add round key initial add device will be our second one but we're going to have the initial add round so in detail so initial ad so yeah round key using an export plain text so etoyon etosha cap is not indito then we're going to export that to its corresponding the insert cause one more insert yes one more insert
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    // --- Authentication ---
    $token = getJWTFromCookie();
    if (!$token || !($payload = verifyJWT($token)) || !in_array($payload['role'], ['admin', 'staff'])) {
        throw new Exception('Unauthorized access.');
    }
    $staff_user_id = (int)$payload['user_id'];

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'get_pending_remittances':
            // This query finds all riders who have unremitted COD balances.
            // It sums up the total of completed 'cod' orders where payment has not been settled yet.
            $query = "
                SELECT 
                    u.id AS rider_id,
                    u.first_name,
                    u.last_name,
                    COALESCE(SUM(o.total), 0) AS total_unremitted_cod   
                FROM users u
                JOIN tbl_delivery d ON u.id = d.rider_id
                JOIN tbl_orders o ON d.order_id = o.id
                WHERE u.role = 'rider'
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0
                GROUP BY u.id, u.first_name, u.last_name
                HAVING total_unremitted_cod > 0
                ORDER BY u.last_name, u.first_name;
            ";
            $result = $conn->query($query);
            $remittances = $result->fetch_all(MYSQLI_ASSOC);

            $response['success'] = true;
            $response['message'] = 'Pending remittances fetched.';
            $response['data'] = $remittances;
            break;

        case 'get_remittance_details':
            $rider_id = (int)($_GET['rider_id'] ?? 0);
            if ($rider_id <= 0) {
                throw new Exception('Invalid Rider ID.');
            }

            // Fetches the specific COD orders that make up the rider's unremitted balance.
            $query = "
                SELECT
                    o.id AS order_id,
                    o.order_number,
                    o.total,
                    o.created_at
                FROM tbl_orders o
                JOIN tbl_delivery d ON o.id = d.order_id
                WHERE d.rider_id = ?
                  AND o.payment_method = 'cod'
                  AND o.order_status = 'completed'
                  AND o.payment_settled = 0
                ORDER BY o.created_at ASC;
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $rider_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $orders = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Remittance details fetched.';
            $response['data'] = $orders;
            break;

        case 'approve_remittance':
            $input = json_decode(file_get_contents('php://input'), true);
            $rider_id = (int)($input['rider_id'] ?? 0);
            $remitted_amount = (float)($input['amount'] ?? 0);
            $order_ids = $input['order_ids'] ?? [];

            if ($rider_id <= 0 || $remitted_amount <= 0 || empty($order_ids)) {
                throw new Exception('Invalid data for approval.');
            }

            $conn->begin_transaction();

            try {
                // 1. Create a remittance record
                $stmt1 = $conn->prepare(
                    "INSERT INTO tbl_rider_remittances (rider_id, amount, status, processed_by_user_id, processed_at) 
                     VALUES (?, ?, 'approved', ?, NOW())"
                );
                $stmt1->bind_param('idi', $rider_id, $remitted_amount, $staff_user_id);
                $stmt1->execute();
                $remittance_id = $conn->insert_id;
                $stmt1->close();

                // 2. Mark orders as settled and link them to the remittance record
                $order_ids_placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                
                $stmt2 = $conn->prepare("UPDATE tbl_orders SET payment_settled = 1 WHERE id IN ($order_ids_placeholders)");
                $types = str_repeat('i', count($order_ids));
                $stmt2->bind_param($types, ...$order_ids);
                $stmt2->execute();
                $stmt2->close();

                $stmt3 = $conn->prepare("INSERT INTO tbl_rider_remittance_items (remittance_id, order_id) VALUES (?, ?)");
                foreach ($order_ids as $order_id) {
                    $stmt3->bind_param('ii', $remittance_id, $order_id);
                    $stmt3->execute();
                }
                $stmt3->close();

                $conn->commit();

                $response['success'] = true;
                $response['message'] = 'Remittance approved and recorded successfully.';

            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception('Database transaction failed: ' . $e->getMessage());
            }
            break;

        case 'reject_remittance':
            $input = json_decode(file_get_contents('php://input'), true);
            $rider_id = (int)($input['rider_id'] ?? 0);
            $remitted_amount = (float)($input['amount'] ?? 0);
            $notes = trim($input['notes'] ?? '');

            if ($rider_id <= 0 || empty($notes)) {
                throw new Exception('Rider ID and rejection reason are required.');
            }

            // For rejection, we just log the attempt. The balance remains open.
            $stmt = $conn->prepare(
                "INSERT INTO tbl_rider_remittances (rider_id, amount, status, notes, processed_by_user_id, processed_at) 
                 VALUES (?, ?, 'rejected', ?, ?, NOW())"
            );
            $stmt->bind_param('idsi', $rider_id, $remitted_amount, $notes, $staff_user_id);
            $stmt->execute();
            $stmt->close();

            $response['success'] = true;
            $response['message'] = 'Remittance rejection has been logged.';
            break;

        default:
            throw new Exception('Invalid action specified.');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>