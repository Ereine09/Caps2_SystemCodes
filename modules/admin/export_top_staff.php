<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
// Include your PDF/Excel library here
// require_once __DIR__ . '/../../vendor/autoload.php';
// use Dompdf\Dompdf;
// use PhpOffice\PhpSpreadsheet\Spreadsheet;
// use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// use PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf as PdfWriter;

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
   clearJWTCookie();
   header("Location: " . BASE_URL . "/modules/auth/login.php");
   exit();
}

$role = strtolower(trim($payload['role'] ?? 'staff'));
if ($role !== 'admin') {
   header("Location: " . BASE_URL . "/modules/admin/dashboard.php");
   exit();
}

$format = $_GET['format'] ?? 'pdf';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch data for Top Performing Staff
$top_staff_query = "
   SELECT 
       u.id,
       u.username,
       u.first_name,
       u.last_name,
       COUNT(lt.id) as transactions_count,
       SUM(lt.points_earned) as total_points_awarded,
       COUNT(DISTINCT lt.customer_id) as unique_customers,
       COUNT(rr.id) as redemptions_processed
   FROM users u
   LEFT JOIN loyalty_transactions lt ON u.id = lt.user_id 
       AND lt.created_at >= '$date_from' AND lt.created_at <= DATE_ADD('$date_to', INTERVAL 1 DAY)
   LEFT JOIN reward_redemptions rr ON u.id = rr.user_id 
       AND rr.redeemed_at >= '$date_from' AND rr.redeemed_at <= DATE_ADD('$date_to', INTERVAL 1 DAY)
   WHERE u.role = 'staff'
   GROUP BY u.id, u.username, u.first_name, u.last_name
   ORDER BY total_points_awarded DESC
   LIMIT 10
";
$top_staff = mysqli_query($conn, $top_staff_query);
$data = [];
while ($row = mysqli_fetch_assoc($top_staff)) {
   $data[] = $row;
}

$report_title = "Top Performing Staff (Loyalty) Report ({$date_from} to {$date_to})";

if ($format === 'pdf') {
   // PDF Export Logic
   /*
   $dompdf = new Dompdf();
   $html = '<h1>' . $report_title . '</h1>';
   $html .= '<table border="1" cellspacing="0" cellpadding="5" width="100%">';
   $html .= '<thead><tr><th>Rank</th><th>Staff Name</th><th>Transactions</th><th>Total Points Awarded</th><th>Unique Customers</th><th>Redemptions Processed</th></tr></thead>';
   $html .= '<tbody>';
   $rank = 1;
   foreach ($data as $staff) {
       $html .= '<tr>';
       $html .= '<td>' . $rank++ . '</td>';
       $html .= '<td>' . htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) . ' (' . htmlspecialchars($staff['username']) . ')</td>';
       $html .= '<td>' . ($staff['transactions_count'] ?? 0) . '</td>';
       $html .= '<td>' . number_format($staff['total_points_awarded'] ?? 0, 2) . '</td>';
       $html .= '<td>' . ($staff['unique_customers'] ?? 0) . '</td>';
       $html .= '<td>' . ($staff['redemptions_processed'] ?? 0) . '</td>';
       $html .= '</tr>';
   }
   $html .= '</tbody></table>';
   $dompdf->loadHtml($html);
   $dompdf->setPaper('A4', 'landscape');
   $dompdf->render();
   $dompdf->stream("top_staff_report.pdf", ["Attachment" => true]);
   */
   echo "PDF Export for Top Performing Staff (Placeholder)";
} elseif ($format === 'excel') {
   // Excel Export Logic
   /*
   $spreadsheet = new Spreadsheet();
   $sheet = $spreadsheet->getActiveSheet();
   $sheet->setCellValue('A1', $report_title);
   $sheet->mergeCells('A1:F1');
   $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
   $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

   $header = ['Rank', 'Staff Name', 'Transactions', 'Total Points Awarded', 'Unique Customers', 'Redemptions Processed'];
   $sheet->fromArray($header, NULL, 'A3');
   $sheet->getStyle('A3:F3')->getFont()->setBold(true);

   $row_num = 4;
   $rank = 1;
   foreach ($data as $staff) {
       $sheet->setCellValue('A' . $row_num, $rank++);
       $sheet->setCellValue('B' . $row_num, $staff['first_name'] . ' ' . $staff['last_name'] . ' (' . $staff['username'] . ')');
       $sheet->setCellValue('C' . $row_num, $staff['transactions_count'] ?? 0);
       $sheet->setCellValue('D' . $row_num, $staff['total_points_awarded'] ?? 0);
       $sheet->setCellValue('E' . $row_num, $staff['unique_customers'] ?? 0);
       $sheet->setCellValue('F' . $row_num, $staff['redemptions_processed'] ?? 0);
       $row_num++;
   }

   foreach (range('A', 'F') as $col) {
       $sheet->getColumnDimension($col)->setAutoSize(true);
   }

   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
   header('Content-Disposition: attachment;filename="top_staff_report.xlsx"');
   header('Cache-Control: max-age=0');
   $writer = new Xlsx($spreadsheet);
   $writer->save('php://output');
   */
   echo "Excel Export for Top Performing Staff (Placeholder)";
} else {
   echo "Invalid format specified.";
}
exit();
