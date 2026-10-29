<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

date_default_timezone_set(APP_TIMEZONE);

$timezone = new DateTimeZone(APP_TIMEZONE);
$today = new DateTimeImmutable('today', $timezone);
$preset = $_GET['preset'] ?? 'daily';
$startInput = trim((string)($_GET['start'] ?? ''));
$endInput = trim((string)($_GET['end'] ?? ''));

if ($preset === 'weekly') {
    $startDate = $today->modify('monday this week');
    $endDate = $startDate->modify('+6 days');
} elseif ($preset === 'monthly') {
    $startDate = $today->modify('first day of this month');
    $endDate = $today->modify('last day of this month');
} elseif ($preset === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endInput)) {
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startInput, $timezone);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endInput, $timezone);
    if (!$startDate || !$endDate || $endDate < $startDate) {
        $startDate = $today;
        $endDate = $today;
        $preset = 'daily';
    }
} else {
    $preset = 'daily';
    $startDate = $today;
    $endDate = $today;
}

$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');
$endExclusive = $endDate->modify('+1 day')->format('Y-m-d');
$periodLabel = $start === $end ? $startDate->format('d M Y') : $startDate->format('d M Y') . ' - ' . $endDate->format('d M Y');
$productSort = ($_GET['sort'] ?? 'quantity') === 'revenue' ? 'revenue' : 'quantity';
$validOrderSql = "o.payment_status = 'paid' AND o.order_status <> 'cancelled' AND o.created_at >= :start AND o.created_at < :end";
$orderSql = "o.created_at >= :start AND o.created_at < :end";
$rangeParams = ['start' => $start, 'end' => $endExclusive];

$summaryStmt = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.order_status <> 'cancelled' THEN o.grand_total ELSE 0 END), 0) AS revenue,
    COUNT(*) AS orders,
    COUNT(CASE WHEN o.payment_status = 'paid' AND o.order_status <> 'cancelled' THEN 1 END) AS paid_orders,
    COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' AND o.order_status <> 'cancelled' THEN o.user_id END) AS purchasing_customers,
    SUM(CASE WHEN o.order_status = 'received' THEN 1 ELSE 0 END) AS pending_orders,
    SUM(CASE WHEN o.order_status IN ('processing','preparing','ready','out_for_delivery') THEN 1 ELSE 0 END) AS processing_orders,
    SUM(CASE WHEN o.order_status = 'delivered' THEN 1 ELSE 0 END) AS completed_orders,
    SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
    FROM orders o WHERE o.created_at >= :summary_start AND o.created_at < :summary_end");
$summaryStmt->execute(['summary_start' => $start, 'summary_end' => $endExclusive]);
$summary = $summaryStmt->fetch() ?: [];

$itemsStmt = $db->prepare("SELECT COALESCE(SUM(oi.quantity), 0) AS items_sold FROM order_items oi JOIN orders o ON o.order_id = oi.order_id WHERE $validOrderSql");
$itemsStmt->execute($rangeParams);
$itemsSold = (float)($itemsStmt->fetch()['items_sold'] ?? 0);

$registeredTotal = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$newUsersStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE created_at >= :start AND created_at < :end");
$newUsersStmt->execute($rangeParams);
$newUsers = (int)$newUsersStmt->fetchColumn();
$newUsersDailyStmt = $db->prepare("SELECT DATE(created_at) AS report_date, COUNT(*) AS registrations FROM users WHERE created_at >= :start AND created_at < :end GROUP BY DATE(created_at)");
$newUsersDailyStmt->execute($rangeParams);
$newUsersDaily = [];
foreach ($newUsersDailyStmt->fetchAll() as $row) $newUsersDaily[$row['report_date']] = (int)$row['registrations'];
$guestOrdersStmt = $db->prepare("SELECT COUNT(*) AS orders, COUNT(DISTINCT customer_email) AS customers FROM orders WHERE user_id IS NULL AND created_at >= :start AND created_at < :end");
$guestOrdersStmt->execute($rangeParams);
$guestOrders = $guestOrdersStmt->fetch() ?: ['orders' => 0, 'customers' => 0];
$validOrders = (int)($summary['paid_orders'] ?? 0);
$revenue = (float)($summary['revenue'] ?? 0);

$productsStmt = $db->prepare("SELECT oi.product_id, oi.product_name, p.image_path, c.name AS category,
    SUM(oi.quantity) AS quantity_sold, SUM(oi.line_total) AS revenue, COUNT(DISTINCT o.order_id) AS order_count
    FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
    LEFT JOIN products p ON p.product_id = oi.product_id LEFT JOIN categories c ON c.category_id = p.category_id
    WHERE $validOrderSql GROUP BY oi.product_id, oi.product_name, p.image_path, c.name
    ORDER BY " . ($productSort === 'revenue' ? 'revenue DESC, quantity_sold DESC' : 'quantity_sold DESC, revenue DESC'));
$productsStmt->execute($rangeParams);
$products = $productsStmt->fetchAll();

$dailyStmt = $db->prepare("SELECT DATE(o.created_at) AS report_date, SUM(o.grand_total) AS revenue,
    COUNT(*) AS orders, SUM((SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.order_id = o.order_id)) AS items_sold,
    COUNT(DISTINCT o.user_id) AS purchasing_customers FROM orders o WHERE $validOrderSql
    GROUP BY DATE(o.created_at) ORDER BY report_date");
$dailyStmt->execute($rangeParams);
$dailyRows = [];
foreach ($dailyStmt->fetchAll() as $row) {
    $dailyRows[$row['report_date']] = $row;
}
$weekdayStmt = $db->prepare("SELECT WEEKDAY(o.created_at) AS weekday_index, SUM(o.grand_total) AS revenue FROM orders o WHERE $validOrderSql GROUP BY WEEKDAY(o.created_at) ORDER BY weekday_index");
$weekdayStmt->execute($rangeParams);
$weekdayRevenue = array_fill(0, 7, 0);
foreach ($weekdayStmt->fetchAll() as $row) $weekdayRevenue[(int)$row['weekday_index']] = (float)$row['revenue'];
$daily = [];
for ($date = $startDate; $date <= $endDate; $date = $date->modify('+1 day')) {
    $key = $date->format('Y-m-d');
    $daily[] = array_merge(['report_date' => $key, 'revenue' => 0, 'orders' => 0, 'items_sold' => 0, 'purchasing_customers' => 0], $dailyRows[$key] ?? []);
}
$bestDay = $daily ? $daily[0] : ['report_date' => $start, 'revenue' => 0, 'orders' => 0, 'items_sold' => 0, 'purchasing_customers' => 0];
foreach ($daily as $row) {
    if ((float)$row['revenue'] > (float)$bestDay['revenue']) $bestDay = $row;
}

$customersStmt = $db->prepare("SELECT u.user_id, u.full_name, u.email, COUNT(o.order_id) AS order_count,
    SUM(o.grand_total) AS total_spending FROM users u JOIN orders o ON o.user_id = u.user_id
    WHERE $validOrderSql GROUP BY u.user_id, u.full_name, u.email ORDER BY total_spending DESC");
$customersStmt->execute($rangeParams);
$customers = $customersStmt->fetchAll();
$repeatCustomers = count(array_filter($customers, static fn($row) => (int)$row['order_count'] > 1));
$neverStmt = $db->query("SELECT COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.user_id)");
$neverOrdered = (int)$neverStmt->fetchColumn();

$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    $dataset = $_GET['dataset'] ?? 'daily';
    $filename = 'masakamart-' . $dataset . '-' . $start . '-to-' . $end . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['MasakaMart', 'Sales & Analytics Report']);
    fputcsv($out, ['Reporting period', $periodLabel]);
    fputcsv($out, ['Generated', (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s')]);
    if ($dataset === 'products') {
        fputcsv($out, ['Product', 'Category', 'Quantity sold', 'Revenue (UGX)', 'Orders']);
        foreach ($products as $row) fputcsv($out, [$row['product_name'], $row['category'] ?: 'Uncategorised', $row['quantity_sold'], $row['revenue'], $row['order_count']]);
    } elseif ($dataset === 'customers') {
        fputcsv($out, ['Customer', 'Email', 'Orders', 'Total spending (UGX)']);
        foreach ($customers as $row) fputcsv($out, [$row['full_name'], $row['email'], $row['order_count'], $row['total_spending']]);
    } else {
        fputcsv($out, ['Date', 'Day', 'Revenue (UGX)', 'Orders', 'Items sold', 'Purchasing customers']);
        foreach ($daily as $row) fputcsv($out, [$row['report_date'], date('l', strtotime($row['report_date'])), $row['revenue'], $row['orders'], $row['items_sold'], $row['purchasing_customers']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Reports & Analytics — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="reports-heading"><div><p class="eyebrow">MasakaMart performance</p><h1>Reports &amp; Analytics</h1><p class="muted">Paid sales only. Cancelled orders are excluded from revenue and product totals.</p></div><button type="button" class="btn btn-sm" onclick="window.location.reload()">Refresh data</button></div>

<div class="admin-panel report-filters">
  <form method="get" id="reportFilters" class="admin-search-form">
    <label>Reporting period <select name="preset" id="reportPreset"><option value="daily" <?= $preset === 'daily' ? 'selected' : '' ?>>Today</option><option value="weekly" <?= $preset === 'weekly' ? 'selected' : '' ?>>This week</option><option value="monthly" <?= $preset === 'monthly' ? 'selected' : '' ?>>This month</option><option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom dates</option></select></label>
    <label>From <input type="date" name="start" value="<?= e($start) ?>" id="reportStart"></label>
    <label>To <input type="date" name="end" value="<?= e($end) ?>" id="reportEnd"></label>
    <button type="submit" class="btn btn-sm">Apply</button>
  </form>
  <div class="report-meta"><strong><?= e($periodLabel) ?></strong><span>Generated <?= e((new DateTimeImmutable('now', $timezone))->format('d M Y H:i')) ?></span><span>All times <?= e(APP_TIMEZONE) ?></span></div>
</div>

<div class="stat-grid report-stat-grid">
  <div class="stat-card"><span class="stat-label">Paid revenue</span><span class="stat-value"><?= money($revenue) ?></span></div>
  <div class="stat-card"><span class="stat-label">Orders received</span><span class="stat-value"><?= (int)($summary['orders'] ?? 0) ?></span></div>
  <div class="stat-card"><span class="stat-label">Items sold</span><span class="stat-value"><?= rtrim(rtrim(number_format($itemsSold, 2), '0'), '.') ?></span></div>
  <div class="stat-card"><span class="stat-label">Average order value</span><span class="stat-value"><?= money($validOrders ? $revenue / $validOrders : 0) ?></span></div>
  <div class="stat-card"><span class="stat-label">Purchasing customers</span><span class="stat-value"><?= (int)($summary['purchasing_customers'] ?? 0) ?></span></div>
  <div class="stat-card"><span class="stat-label">Registered users</span><span class="stat-value"><?= $registeredTotal ?></span></div>
  <div class="stat-card"><span class="stat-label">New registrations</span><span class="stat-value"><?= $newUsers ?></span></div>
    <div class="stat-card"><span class="stat-label">Guest orders</span><span class="stat-value"><?= (int)$guestOrders['orders'] ?></span></div>
</div>

<div class="report-status-strip"><span>Pending <strong><?= (int)($summary['pending_orders'] ?? 0) ?></strong></span><span>Processing <strong><?= (int)($summary['processing_orders'] ?? 0) ?></strong></span><span>Completed <strong><?= (int)($summary['completed_orders'] ?? 0) ?></strong></span><span>Cancelled <strong><?= (int)($summary['cancelled_orders'] ?? 0) ?></strong></span></div>

<div class="admin-grid-2"><div class="admin-panel"><h2>Daily revenue trend</h2><canvas id="revenueChart" height="220"></canvas></div><div class="admin-panel"><h2>Orders and new customers</h2><canvas id="activityChart" height="220"></canvas></div></div>
<div class="admin-grid-2"><div class="admin-panel"><h2>Best-selling products</h2><canvas id="productChart" height="220"></canvas></div><div class="admin-panel"><h2>Best-performing day</h2><div class="best-day"><strong><?= e(date('l, d M Y', strtotime($bestDay['report_date']))) ?></strong><span><?= money((float)$bestDay['revenue']) ?></span><small><?= (int)$bestDay['orders'] ?> orders - <?= rtrim(rtrim(number_format((float)$bestDay['items_sold'], 2), '0'), '.') ?> items - <?= (int)$bestDay['purchasing_customers'] ?> customers</small></div><canvas id="weekdayChart" height="130"></canvas></div></div>

<div class="admin-panel"><div class="report-table-heading"><h2>Daily sales summaries</h2><a class="btn btn-sm" href="?<?= http_build_query(['preset' => $preset, 'start' => $start, 'end' => $end, 'export' => 'csv', 'dataset' => 'daily']) ?>">Export CSV</a></div><div class="table-scroll"><table class="data-table report-table" data-searchable><thead><tr><th>Date</th><th>Day</th><th>Revenue</th><th>Orders</th><th>Items</th><th>Customers</th></tr></thead><tbody><?php foreach ($daily as $row): ?><tr><td><?= e($row['report_date']) ?></td><td><?= e(date('l', strtotime($row['report_date']))) ?></td><td><?= money((float)$row['revenue']) ?></td><td><?= (int)$row['orders'] ?></td><td><?= e((string)$row['items_sold']) ?></td><td><?= (int)$row['purchasing_customers'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>

<div class="admin-panel"><div class="report-table-heading"><div><h2>Best-selling products</h2><div class="report-rank-links"><a class="link-btn" href="?<?= http_build_query(['preset' => $preset, 'start' => $start, 'end' => $end, 'sort' => 'quantity']) ?>">Rank by quantity</a><a class="link-btn" href="?<?= http_build_query(['preset' => $preset, 'start' => $start, 'end' => $end, 'sort' => 'revenue']) ?>">Rank by revenue</a></div></div><a class="btn btn-sm" href="?<?= http_build_query(['preset' => $preset, 'start' => $start, 'end' => $end, 'sort' => $productSort, 'export' => 'csv', 'dataset' => 'products']) ?>">Export CSV</a></div><div class="table-tools"><input type="search" placeholder="Search products..." data-table-filter="products"></div><div class="table-scroll"><table class="data-table report-table" data-table="products"><thead><tr><th data-sortable>Product</th><th data-sortable>Category</th><th data-sortable>Quantity</th><th data-sortable>Revenue</th><th data-sortable>Orders</th></tr></thead><tbody><?php foreach ($products as $row): ?><tr><td class="product-cell"><?php if (!empty($row['image_path'])): ?><img src="<?= e(APP_URL . '/' . ltrim($row['image_path'], '/')) ?>" alt=""><?php endif; ?><span><?= e($row['product_name']) ?></span></td><td><?= e($row['category'] ?: 'Uncategorised') ?></td><td><?= e((string)$row['quantity_sold']) ?></td><td><?= money((float)$row['revenue']) ?></td><td><?= (int)$row['order_count'] ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$products): ?><p class="empty-state-sm">No paid product sales in this period.</p><?php endif; ?></div></div>

<div class="admin-panel"><div class="report-table-heading"><div><h2>Customer purchasing activity</h2><p class="muted">Registered customers only. Personal details are visible to authorized administrators.</p></div><a class="btn btn-sm" href="?<?= http_build_query(['preset' => $preset, 'start' => $start, 'end' => $end, 'export' => 'csv', 'dataset' => 'customers']) ?>">Export CSV</a></div><div class="customer-insights"><span>Repeat customers <strong><?= $repeatCustomers ?></strong></span><span>Never placed an order <strong><?= $neverOrdered ?></strong></span><span>Guest customers <strong><?= (int)$guestOrders['customers'] ?></strong></span></div><div class="table-tools"><input type="search" placeholder="Search customers..." data-table-filter="customers"></div><div class="table-scroll"><table class="data-table report-table" data-table="customers"><thead><tr><th data-sortable>Customer</th><th data-sortable>Email</th><th data-sortable>Orders</th><th data-sortable>Total spending</th></tr></thead><tbody><?php foreach ($customers as $row): ?><tr><td><?= e($row['full_name']) ?></td><td><?= e($row['email']) ?></td><td><?= (int)$row['order_count'] ?></td><td><?= money((float)$row['total_spending']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$customers): ?><p class="empty-state-sm">No registered customers purchased in this period.</p><?php endif; ?></div></div>

<div class="admin-panel report-print-panel"><h2>Report actions</h2><p class="muted">The print view includes the business name, reporting period, generation time, and the visible report tables.</p><button type="button" class="btn" onclick="window.print()">Print / Save PDF</button></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
const reportDays = <?= json_encode($daily) ?>;
const reportProducts = <?= json_encode(array_slice($products, 0, 8)) ?>;
const reportLabels = reportDays.map(row => row.report_date.slice(5));
const chartDefaults = {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: false}}, scales: {y: {beginAtZero: true}}};
new Chart(document.getElementById('revenueChart'), {type: 'line', data: {labels: reportLabels, datasets: [{data: reportDays.map(row => Number(row.revenue)), borderColor: '#f47b20', backgroundColor: 'rgba(244,123,32,.14)', fill: true, tension: .3}]}, options: chartDefaults});
new Chart(document.getElementById('activityChart'), {type: 'bar', data: {labels: reportLabels, datasets: [{label: 'Orders', data: reportDays.map(row => Number(row.orders)), backgroundColor: '#123b5d'}, {label: 'New customers', data: <?= json_encode(array_map(fn($row) => $newUsersDaily[$row['report_date']] ?? 0, $daily)) ?>, backgroundColor: '#f47b20'}]}, options: Object.assign({}, chartDefaults, {plugins: {legend: {display: true}}})});
new Chart(document.getElementById('productChart'), {type: 'bar', data: {labels: reportProducts.map(row => row.product_name), datasets: [{data: reportProducts.map(row => Number(row.quantity_sold)), backgroundColor: '#f47b20'}]}, options: Object.assign({}, chartDefaults, {indexAxis: 'y'})});
new Chart(document.getElementById('weekdayChart'), {type: 'bar', data: {labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], datasets: [{data: <?= json_encode($weekdayRevenue) ?>, backgroundColor: '#123b5d'}]}, options: chartDefaults});
const filterForm = document.getElementById('reportFilters');
document.getElementById('reportPreset').addEventListener('change', event => { if (event.target.value !== 'custom') filterForm.submit(); });
document.querySelectorAll('#reportStart, #reportEnd').forEach(input => input.addEventListener('change', () => { if (document.getElementById('reportStart').value && document.getElementById('reportEnd').value) { document.getElementById('reportPreset').value = 'custom'; filterForm.submit(); } }));
document.querySelectorAll('[data-table-filter]').forEach(input => input.addEventListener('input', event => { const table = document.querySelector('[data-table="' + event.target.dataset.tableFilter + '"]'); const term = event.target.value.toLowerCase(); table.querySelectorAll('tbody tr').forEach(row => row.hidden = !row.textContent.toLowerCase().includes(term)); }));
document.querySelectorAll('[data-sortable]').forEach(header => header.addEventListener('click', () => { const table = header.closest('table'); const index = Array.from(header.parentNode.children).indexOf(header); const rows = Array.from(table.tBodies[0].rows); const numeric = rows.every(row => !Number.isNaN(Number(row.cells[index].textContent.replace(/[^0-9.-]/g, '')))); rows.sort((a, b) => { const left = numeric ? Number(a.cells[index].textContent.replace(/[^0-9.-]/g, '')) : a.cells[index].textContent.toLowerCase(); const right = numeric ? Number(b.cells[index].textContent.replace(/[^0-9.-]/g, '')) : b.cells[index].textContent.toLowerCase(); return left > right ? 1 : left < right ? -1 : 0; }); rows.forEach(row => table.tBodies[0].appendChild(row)); }));
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>