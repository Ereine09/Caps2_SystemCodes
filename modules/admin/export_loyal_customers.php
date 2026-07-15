<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
// Include your PDF/Excel library here, e.g.:
// require_once __DIR__ . '/../../vendor/autoload.php'; // For Composer-installed libraries like PhpSpreadsheet or Dompdf
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

// Fetch data for Top 10 Most Loyal Customers
$loyal_customers_query = "
   SELECT 
       c.id,
       c.name,
       c.email,
       c.loyalty_points,
       COUNT(lt.id) as transaction_count,
       SUM(lt.points_earned) as total_points_earned
   FROM customers c
   LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
   WHERE lt.created_at >= '$date_from' AND lt.created_at <= DATE_ADD('$date_to', INTERVAL 1 DAY)
   GROUP BY c.id, c.name, c.email, c.loyalty_points
   ORDER BY c.loyalty_points DESC
   LIMIT 10
";
$loyal_customers = mysqli_query($conn, $loyal_customers_query);
$data = [];
while ($row = mysqli_fetch_assoc($loyal_customers)) {
   $data[] = $row;
}

$report_title = "Top 10 Most Loyal Customers Report ({$date_from} to {$date_to})";

if ($format === 'pdf') {
   // PDF Export Logic (using a library like Dompdf or mPDF)
   // Example with Dompdf:
   /*
   $dompdf = new Dompdf();
   $html = '<h1>' . $report_title . '</h1>';
   $html .= '<table border="1" cellspacing="0" cellpadding="5" width="100%">';
   $html .= '<thead><tr><th>Rank</th><th>Customer Name</th><th>Email</th><th>Loyalty Points</th><th>Transactions</th><th>Total Earned</th></tr></thead>';
   $html .= '<tbody>';
   $rank = 1;
   foreach ($data as $customer) {
       $html .= '<tr>';
       $html .= '<td>' . $rank++ . '</td>';
       $html .= '<td>' . htmlspecialchars($customer['name']) . '</td>';
       $html .= '<td>' . htmlspecialchars($customer['email']) . '</td>';
       $html .= '<td>' . number_format($customer['loyalty_points'], 2) . '</td>';
       $html .= '<td>' . ($customer['transaction_count'] ?? 0) . '</td>';
       $html .= '<td>' . number_format($customer['total_points_earned'] ?? 0, 2) . '</td>';
       $html .= '</tr>';
   }
   $html .= '</tbody></table>';
   $dompdf->loadHtml($html);
   $dompdf->setPaper('A4', 'landscape');
   $dompdf->render();
   $dompdf->stream("loyal_customers_report.pdf", ["Attachment" => true]);
   */
   echo "PDF Export for Loyal Customers (Placeholder)";
} elseif ($format === 'excel') {
   // Excel Export Logic (using a library like PhpSpreadsheet)
   // Example with PhpSpreadsheet:
   /*
   $spreadsheet = new Spreadsheet();
   $sheet = $spreadsheet->getActiveSheet();
   $sheet->setCellValue('A1', $report_title);
   $sheet->mergeCells('A1:F1');
   $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
   $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

   $header = ['Rank', 'Customer Name', 'Email', 'Loyalty Points', 'Transactions', 'Total Earned'];
   $sheet->fromArray($header, NULL, 'A3');
   $sheet->getStyle('A3:F3')->getFont()->setBold(true);

   $row_num = 4;
   $rank = 1;
   foreach ($data as $customer) {
       $sheet->setCellValue('A' . $row_num, $rank++);
       $sheet->setCellValue('B' . $row_num, $customer['name']);
       $sheet->setCellValue('C' . $row_num, $customer['email']);
       $sheet->setCellValue('D' . $row_num, $customer['loyalty_points']);
       $sheet->setCellValue('E' . $row_num, $customer['transaction_count'] ?? 0);
       $sheet->setCellValue('F' . $row_num, $customer['total_points_earned'] ?? 0);
       $row_num++;
   }

   foreach (range('A', 'F') as $col) {
       $sheet->getColumnDimension($col)->setAutoSize(true);
   }

   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
   header('Content-Disposition: attachment;filename="loyal_customers_report.xlsx"');
   header('Cache-Control: max-age=0');
   $writer = new Xlsx($spreadsheet);
   $writer->save('php://output');
   */
   echo "Excel Export for Loyal Customers (Placeholder)";
} else {
   echo "Invalid format specified.";
}
exit();
