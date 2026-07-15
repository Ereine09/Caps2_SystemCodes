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

// Fetch data for Monthly Customer Activity
$monthly_activity_query = "
   SELECT 
       DATE_FORMAT(lt.created_at, '%Y-%m') as month,
       COUNT(DISTINCT lt.customer_id) as active_customers,
       COUNT(lt.id) as transactions,
       SUM(lt.points_earned) as points_earned
   FROM loyalty_transactions lt
   WHERE lt.created_at >= '$date_from' AND lt.created_at <= DATE_ADD('$date_to', INTERVAL 1 DAY)
   GROUP BY DATE_FORMAT(lt.created_at, '%Y-%m')
   ORDER BY month DESC
";
$monthly_activity = mysqli_query($conn, $monthly_activity_query);
$data = [];
while ($row = mysqli_fetch_assoc($monthly_activity)) {
   $data[] = $row;
}

$report_title = "Monthly Loyalty Activity Report ({$date_from} to {$date_to})";

if ($format === 'pdf') {
   // PDF Export Logic
   /*
   $dompdf = new Dompdf();
   $html = '<h1>' . $report_title . '</h1>';
   $html .= '<table border="1" cellspacing="0" cellpadding="5" width="100%">';
   $html .= '<thead><tr><th>Month</th><th>Active Customers</th><th>Transactions</th><th>Points Earned</th></tr></thead>';
   $html .= '<tbody>';
   foreach ($data as $month_data) {
       $html .= '<tr>';
       $html .= '<td>' . htmlspecialchars($month_data['month']) . '</td>';
       $html .= '<td>' . $month_data['active_customers'] . '</td>';
       $html .= '<td>' . $month_data['transactions'] . '</td>';
       $html .= '<td>' . number_format($month_data['points_earned'] ?? 0, 2) . '</td>';
       $html .= '</tr>';
   }
   $html .= '</tbody></table>';
   $dompdf->loadHtml($html);
   $dompdf->setPaper('A4', 'portrait');
   $dompdf->render();
   $dompdf->stream("monthly_activity_report.pdf", ["Attachment" => true]);
   */
   echo "PDF Export for Monthly Activity (Placeholder)";
} elseif ($format === 'excel') {
   // Excel Export Logic
   /*
   $spreadsheet = new Spreadsheet();
   $sheet = $spreadsheet->getActiveSheet();
   $sheet->setCellValue('A1', $report_title);
   $sheet->mergeCells('A1:D1');
   $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
   $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

   $header = ['Month', 'Active Customers', 'Transactions', 'Points Earned'];
   $sheet->fromArray($header, NULL, 'A3');
   $sheet->getStyle('A3:D3')->getFont()->setBold(true);

   $row_num = 4;
   foreach ($data as $month_data) {
       $sheet->setCellValue('A' . $row_num, $month_data['month']);
       $sheet->setCellValue('B' . $row_num, $month_data['active_customers']);
       $sheet->setCellValue('C' . $row_num, $month_data['transactions']);
       $sheet->setCellValue('D' . $row_num, $month_data['points_earned'] ?? 0);
       $row_num++;
   }

   foreach (range('A', 'D') as $col) {
       $sheet->getColumnDimension($col)->setAutoSize(true);
   }

   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
   header('Content-Disposition: attachment;filename="monthly_activity_report.xlsx"');
   header('Cache-Control: max-age=0');
   $writer = new Xlsx($spreadsheet);
   $writer->save('php://output');
   */
   echo "Excel Export for Monthly Activity (Placeholder)";
} else {
   echo "Invalid format specified.";
}
exit();
