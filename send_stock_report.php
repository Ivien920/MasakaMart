<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';

$db = Database::conn();

$stmt = $db->query(
    "SELECT p.product_id, p.name, p.unit, p.stock_quantity, p.low_stock_threshold, c.name AS category_name, p.updated_at
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     WHERE p.status = 'active'
     ORDER BY c.name ASC, p.name ASC"
);
$products = $stmt->fetchAll();

$rows = '';
foreach ($products as $product) {
    $low = (float)$product['stock_quantity'] <= (float)$product['low_stock_threshold'];
    $rows .= "
      <tr style=\"border-bottom: 1px solid #DDE3E7;\">
        <td style=\"padding: 10px 8px;\">" . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . "</td>
        <td style=\"padding: 10px 8px;\">" . htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') . "</td>
        <td style=\"padding: 10px 8px;\">" . htmlspecialchars((string)$product['stock_quantity'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($product['unit'], ENT_QUOTES, 'UTF-8') . "</td>
        <td style=\"padding: 10px 8px;\">" . htmlspecialchars((string)$product['low_stock_threshold'], ENT_QUOTES, 'UTF-8') . "</td>
        <td style=\"padding: 10px 8px;\">" . ($low ? '<span style="color:#F59E0B;font-weight:bold;">Low</span>' : '<span style="color:#1B9A59;font-weight:bold;">OK</span>') . "</td>
      </tr>
    ";
}

if ($rows === '') {
    $rows = "
        <tr>
            <td colspan=\"5\" style=\"padding: 16px; text-align:center; color:#64748B;\">No active products found in the system.</td>
        </tr>
    ";
}

$totalCount = count($products);
$generatedAt = date('Y-m-d H:i:s');

$recipientEmail = trim((string)($argv[1] ?? ''));
if ($recipientEmail === '') {
    $recipientEmail = trim(notification_email_value('ADMIN_EMAIL', ''));
}
if ($recipientEmail === '') {
    $adminStmt = $db->query("SELECT email FROM admins WHERE is_active = 1 ORDER BY (role = 'super_admin') DESC, admin_id ASC LIMIT 1");
    $adminRow = $adminStmt ? $adminStmt->fetch() : null;
    $recipientEmail = trim((string)($adminRow['email'] ?? 'ivienroes0@gmail.com'));
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MasakaMart Stock Report</title>
</head>
<body style="margin:0; padding:24px; background:#F7F9FA; font-family:Arial, sans-serif; color:#102A43;">
  <div style="max-width:900px; margin:0 auto; background:#FFFFFF; border:1px solid #DDE3E7; border-radius:12px; overflow:hidden;">
    <div style="background:#082B4C; color:#FFFFFF; padding:24px 30px;">
      <h1 style="margin:0; font-size:28px;">MasakaMart Stock Report</h1>
    </div>
    <div style="padding:28px 30px;">
      <p>Hello,</p>
      <p>This is the current inventory snapshot for the MasakaMart system.</p>
      <p><strong>Total active products:</strong> {$totalCount}</p>
      <table style="width:100%; border-collapse:collapse; margin-top:18px;">
        <thead>
          <tr style="background:#FFF1E8; text-align:left;">
            <th style="padding:12px 8px;">Product</th>
            <th style="padding:12px 8px;">Category</th>
            <th style="padding:12px 8px;">Stock</th>
            <th style="padding:12px 8px;">Threshold</th>
            <th style="padding:12px 8px;">Status</th>
          </tr>
        </thead>
        <tbody>
          {$rows}
        </tbody>
      </table>
      <p style="margin-top:24px; font-size:12px; color:#64748B;">Generated at: {$generatedAt}</p>
    </div>
  </div>
</body>
</html>
HTML;

$altBody = "MasakaMart Stock Report\n\n{$totalCount} active products\n\nGenerated at: {$generatedAt}";
$result = sendEmail($recipientEmail, 'MasakaMart Stock Report', $html, $altBody, 'Store Manager', 'stock-report-' . date('YmdHis'));
if (($result['ok'] ?? false) === true) {
    echo "EMAIL SUCCESS — stock report submitted to SMTP for " . katale_mask_email($recipientEmail) . "\n";
    exit(0);
}

echo "EMAIL FAILED — stock report was not accepted by SMTP. Error: " . ($result['error'] ?? 'unknown') . "\n";
exit(1);
