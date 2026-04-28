<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$host = 'localhost';
$dbname = 'invoice_system';
$username_db = 'root';
$password_db = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("خطا در اتصال به پایگاه داده: " . $e->getMessage());
}

// دریافت لیست بخش‌ها و کاربران برای فیلتر
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();

// فیلترهای دریافتی
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_holder_department = $_GET['holder_department'] ?? '';
$filter_holder_user = $_GET['holder_user'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// ساخت کوئری پایه
$base_sql = "SELECT d.*, 
                    c.name as company_name,
                    v.name as vendor_name,
                    holder_dep.name as holder_department_name,
                    holder_user.full_name as holder_user_name
             FROM documents d
             LEFT JOIN companies c ON d.company_id = c.id
             LEFT JOIN vendors v ON d.vendor_id = v.id
             LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
             LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
             WHERE d.created_at BETWEEN ? AND ?";
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

if ($filter_type) {
    $base_sql .= " AND d.type = ?";
    $params[] = $filter_type;
}
if ($filter_status) {
    $base_sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($filter_holder_department) {
    $base_sql .= " AND d.current_holder_department_id = ?";
    $params[] = $filter_holder_department;
}
if ($filter_holder_user) {
    $base_sql .= " AND d.current_holder_user_id = ?";
    $params[] = $filter_holder_user;
}

// کوئری شمارش
$count_sql = str_replace("SELECT d.*, c.name as company_name, v.name as vendor_name, holder_dep.name as holder_department_name, holder_user.full_name as holder_user_name", "SELECT COUNT(*) as total", $base_sql);
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_count = $stmt->fetchColumn();
$total_pages = ceil($total_count / $per_page);

// کوئری اصلی با LIMIT
$sql = $base_sql . " ORDER BY d.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// آمار کلی برای کارت‌ها
$stats = [];
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice'");
$stats['total_invoices'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM documents WHERE type='invoice'");
$stats['total_amount'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice' AND status='pending'");
$stats['pending'] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice' AND status='approved'");
$stats['approved'] = $stmt->fetchColumn();

// آمار ماهانه
$monthly_stats = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           COUNT(*) as count,
           COALESCE(SUM(amount),0) as total
    FROM documents
    WHERE type='invoice'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
")->fetchAll();

$page_title = 'گزارش‌گیری پیشرفته';
ob_start();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1>📊 گزارش‌گیری پیشرفته</h1>
    <div>
        <button onclick="window.print()" style="background:#3498db; color:white; border:none; padding:8px 15px; border-radius:5px;">🖨️ چاپ</button>
        <a href="dashboard.php" style="background:#95a5a6; color:white; padding:8px 15px; border-radius:5px; text-decoration:none;">بازگشت</a>
    </div>
</div>

<!-- کارت‌های آمار -->
<div style="display: grid; grid-template-columns: repeat(4,1fr); gap:20px; margin-bottom:30px;">
    <div style="background:linear-gradient(135deg,#3498db,#2980b9); padding:20px; border-radius:10px; color:white;">
        <div>کل فاکتورها</div>
        <div style="font-size:32px;"><?php echo number_format($stats['total_invoices']); ?></div>
    </div>
    <div style="background:linear-gradient(135deg,#2ecc71,#27ae60); padding:20px; border-radius:10px; color:white;">
        <div>مجموع مبالغ</div>
        <div style="font-size:32px;"><?php echo number_format($stats['total_amount']); ?> تومان</div>
    </div>
    <div style="background:linear-gradient(135deg,#f39c12,#e67e22); padding:20px; border-radius:10px; color:white;">
        <div>در انتظار</div>
        <div style="font-size:32px;"><?php echo number_format($stats['pending']); ?></div>
    </div>
    <div style="background:linear-gradient(135deg,#9b59b6,#8e44ad); padding:20px; border-radius:10px; color:white;">
        <div>تایید شده</div>
        <div style="font-size:32px;"><?php echo number_format($stats['approved']); ?></div>
    </div>
</div>

<!-- نمودارها -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:30px;">
    <div style="background:white; border-radius:10px; padding:20px;">
        <canvas id="monthlyChart"></canvas>
    </div>
    <div style="background:white; border-radius:10px; padding:20px;">
        <canvas id="statusChart"></canvas>
    </div>
</div>

<!-- فرم فیلتر -->
<div style="background:white; border-radius:10px; padding:20px; margin-bottom:20px;">
    <h3>🔍 فیلترهای پیشرفته</h3>
    <form method="GET">
        <div style="display: grid; grid-template-columns: repeat(4,1fr); gap:15px; margin-bottom:15px;">
            <div><label>نوع سند</label><select name="type" style="width:100%; padding:8px;"><option value="">همه</option><option value="invoice" <?php echo $filter_type=='invoice'?'selected':''; ?>>فاکتور</option><option value="waybill" <?php echo $filter_type=='waybill'?'selected':''; ?>>بارنامه</option><option value="tax" <?php echo $filter_type=='tax'?'selected':''; ?>>سامانه مودیان</option></select></div>
            <div><label>وضعیت</label><select name="status" style="width:100%; padding:8px;"><option value="">همه</option><option value="draft" <?php echo $filter_status=='draft'?'selected':''; ?>>پیش‌نویس</option><option value="forwarded" <?php echo $filter_status=='forwarded'?'selected':''; ?>>ارسال شده</option><option value="viewed" <?php echo $filter_status=='viewed'?'selected':''; ?>>مشاهده شده</option><option value="under_review" <?php echo $filter_status=='under_review'?'selected':''; ?>>در حال بررسی</option><option value="approved" <?php echo $filter_status=='approved'?'selected':''; ?>>تایید شده</option><option value="rejected" <?php echo $filter_status=='rejected'?'selected':''; ?>>رد شده</option></select></div>
            <div><label>در دست (بخش)</label><select name="holder_department" style="width:100%; padding:8px;"><option value="">همه</option><?php foreach ($departments as $dept): ?><option value="<?php echo $dept['id']; ?>" <?php echo $filter_holder_department==$dept['id']?'selected':''; ?>><?php echo $dept['name']; ?></option><?php endforeach; ?></select></div>
            <div><label>در دست (شخص)</label><select name="holder_user" style="width:100%; padding:8px;"><option value="">همه</option><?php foreach ($users as $user): ?><option value="<?php echo $user['id']; ?>" <?php echo $filter_holder_user==$user['id']?'selected':''; ?>><?php echo $user['full_name']; ?></option><?php endforeach; ?></select></div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3,1fr); gap:15px; margin-bottom:15px;">
            <div><label>از تاریخ</label><input type="date" name="date_from" value="<?php echo $date_from; ?>" style="width:100%; padding:8px;"></div>
            <div><label>تا تاریخ</label><input type="date" name="date_to" value="<?php echo $date_to; ?>" style="width:100%; padding:8px;"></div>
            <div style="display:flex; align-items:flex-end;"><button type="submit" style="background:#3498db; color:white; border:none; padding:8px 15px; border-radius:5px;">اعمال فیلتر</button></div>
        </div>
    </form>
</div>

<!-- جدول نتایج -->
<div style="background:white; border-radius:10px; padding:20px; overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse;">
        <thead><tr style="background:#f5f5f5;"><th>شماره</th><th>نوع</th><th>عنوان</th><th>شرکت</th><th>مبلغ</th><th>تاریخ</th><th>وضعیت</th><th>در دست</th></tr></thead>
        <tbody>
            <?php if(empty($documents)): ?><tr><td colspan="8" style="text-align:center;">هیچ سندی یافت نشد</td></tr><?php else: ?>
            <?php foreach($documents as $doc): ?>
            <tr style="border-bottom:1px solid #eee;">
                <td><?php echo $doc['document_number']; ?></td>
                <td><?php echo $doc['type']; ?></td>
                <td><?php echo htmlspecialchars($doc['title']); ?></td>
                <td><?php echo htmlspecialchars($doc['company_name']??'-'); ?></td>
                <td><?php echo number_format($doc['amount']); ?> تومان</td>
                <td><?php echo jdate('Y/m/d', strtotime($doc['created_at'])); ?></td>
                <td><?php echo $doc['status']; ?></td>
                <td><?php echo htmlspecialchars($doc['holder_user_name']??$doc['holder_department_name']??'-'); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages>1): ?>
    <div style="display:flex; justify-content:center; gap:10px; margin-top:20px;">
        <a href="?page=1&<?php echo http_build_query(array_filter($_GET,'strlen')); ?>">اول</a>
        <?php if($page>1): ?><a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query(array_filter($_GET,'strlen')); ?>">قبلی</a><?php endif; ?>
        <span><?php echo $page; ?></span>
        <?php if($page<$total_pages): ?><a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query(array_filter($_GET,'strlen')); ?>">بعدی</a><?php endif; ?>
        <a href="?page=<?php echo $total_pages; ?>&<?php echo http_build_query(array_filter($_GET,'strlen')); ?>">آخر</a>
    </div>
    <?php endif; ?>
</div>

<script>
const monthlyData = <?php echo json_encode(array_reverse($monthly_stats)); ?>;
new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: { labels: monthlyData.map(m=>m.month), datasets: [{ label: 'تعداد فاکتورها', data: monthlyData.map(m=>m.count), borderColor: '#3498db' }] }
});
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels: ['تایید شده', 'در انتظار', 'رد شده'], datasets: [{ data: [<?php echo $stats['approved']; ?>, <?php echo $stats['pending']; ?>, <?php echo $stats['total_invoices']-$stats['approved']-$stats['pending']; ?>], backgroundColor: ['#2ecc71','#f39c12','#e74c3c'] }] }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>

