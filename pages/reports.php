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

// دریافت تب فعال
$active_tab = $_GET['tab'] ?? 'dashboard';

// دریافت لیست‌ها برای فیلترها
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll();

// فیلترهای مشترک
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_company = $_GET['company'] ?? '';
$filter_vendor = $_GET['vendor'] ?? '';
$filter_status = $_GET['status'] ?? '';

// ============================================
// تابع دریافت داده
// ============================================
function getReportData($pdo, $type, $date_from, $date_to, $filter_company, $filter_vendor, $filter_status) {
    $sql = "SELECT d.*, 
                   c.name as company_name,
                   v.name as vendor_name,
                   holder_dep.name as holder_department_name,
                   holder_user.full_name as holder_user_name,
                   creator.full_name as creator_name
            FROM documents d
            LEFT JOIN companies c ON d.company_id = c.id
            LEFT JOIN vendors v ON d.vendor_id = v.id
            LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
            LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
            LEFT JOIN users creator ON d.created_by = creator.id
            WHERE d.type = ? AND d.created_at BETWEEN ? AND ?";
    $params = [$type, $date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    
    if ($filter_company) {
        $sql .= " AND d.company_id = ?";
        $params[] = $filter_company;
    }
    if ($filter_vendor) {
        $sql .= " AND d.vendor_id = ?";
        $params[] = $filter_vendor;
    }
    if ($filter_status) {
        $sql .= " AND d.status = ?";
        $params[] = $filter_status;
    }
    
    $sql .= " ORDER BY d.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// آمار کلی برای داشبورد
$stats = [];
$stats['total_invoices'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice'")->fetchColumn();
$stats['total_waybills'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='waybill'")->fetchColumn();
$stats['total_tax'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='tax'")->fetchColumn();
$stats['total_amount'] = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM documents WHERE type='invoice'")->fetchColumn();
$stats['pending'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice' AND status IN ('pending','forwarded')")->fetchColumn();
$stats['approved'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice' AND status='approved'")->fetchColumn();
$stats['rejected'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE type='invoice' AND status='rejected'")->fetchColumn();

// آمار ماهانه
$monthly_stats = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
           COUNT(*) as count,
           COALESCE(SUM(amount),0) as total
    FROM documents
    WHERE type='invoice' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// تبدیل ماه‌ها به شمسی برای نمایش
$monthly_labels = [];
foreach ($monthly_stats as $m) {
    $parts = explode('-', $m['month']);
    $monthly_labels[] = $parts[0] . '/' . $parts[1];
}

// آمار به تفکیک شرکت
$company_stats = $pdo->query("
    SELECT c.name, COUNT(d.id) as count, COALESCE(SUM(d.amount),0) as total
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    WHERE d.type='invoice' AND d.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY d.company_id
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

// دریافت داده‌ها
$invoices_data = getReportData($pdo, 'invoice', $date_from, $date_to, $filter_company, $filter_vendor, $filter_status);
$waybills_data = getReportData($pdo, 'waybill', $date_from, $date_to, $filter_company, $filter_vendor, $filter_status);
$tax_data = getReportData($pdo, 'tax', $date_from, $date_to, $filter_company, $filter_vendor, $filter_status);

// نقشه مسیر
$trace_doc = null;
$trace_history = [];
$trace_search = $_GET['trace_search'] ?? '';
if ($trace_search) {
    $stmt = $pdo->prepare("SELECT d.*, u.full_name as creator_name FROM documents d LEFT JOIN users u ON d.created_by = u.id WHERE d.document_number LIKE ? OR d.id = ? LIMIT 1");
    $stmt->execute(["%$trace_search%", $trace_search]);
    $trace_doc = $stmt->fetch();
    if ($trace_doc) {
        $hist_stmt = $pdo->prepare("
            SELECT fh.*, 
                   u_from.full_name as from_name,
                   u_to.full_name as to_name,
                   r_to.name as to_department_name
            FROM forwarding_history fh
            LEFT JOIN users u_from ON fh.from_user_id = u_from.id
            LEFT JOIN users u_to ON fh.to_user_id = u_to.id
            LEFT JOIN roles r_to ON fh.to_department_id = r_to.id
            WHERE fh.document_id = ?
            ORDER BY fh.created_at ASC
        ");
        $hist_stmt->execute([$trace_doc['id']]);
        $trace_history = $hist_stmt->fetchAll();
    }
}

$status_texts = [
    'draft' => 'پیش‌نویس', 'pending' => 'در انتظار', 'forwarded' => 'ارسال شده',
    'viewed' => 'مشاهده شده', 'under_review' => 'در حال بررسی',
    'approved' => 'تایید شده', 'rejected' => 'رد شده', 'completed' => 'بسته شده'
];

$page_title = 'گزارش‌های پیشرفته';
ob_start();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    :root {
        --primary: #3498db;
        --primary-dark: #2980b9;
        --success: #27ae60;
        --warning: #f39c12;
        --danger: #e74c3c;
        --purple: #9b59b6;
        --gray: #95a5a6;
        --dark: #2c3e50;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s;
        border: none;
    }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
    .btn-outline { background: white; border: 1px solid var(--gray); color: var(--dark); }
    .btn-outline:hover { background: #f8f9fa; }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        transition: all 0.3s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        color: white;
    }
    .stat-info h3 { font-size: 13px; color: var(--gray); margin-bottom: 5px; }
    .stat-info p { font-size: 24px; font-weight: bold; color: var(--dark); }
    
    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 30px;
        flex-wrap: wrap;
        background: white;
        padding: 8px;
        border-radius: 50px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .tab-btn {
        padding: 12px 28px;
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        border-radius: 40px;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .tab-btn.active {
        background: var(--primary);
        color: white;
        box-shadow: 0 4px 12px rgba(52,152,219,0.3);
    }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .filter-bar {
        background: white;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .filter-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        align-items: end;
    }
    .filter-group label {
        display: block;
        font-size: 12px;
        color: var(--gray);
        margin-bottom: 6px;
        font-weight: 500;
    }
    .filter-group select, .filter-group input {
        width: 100%;
        padding: 12px;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    .filter-group select:focus, .filter-group input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .data-table th {
        background: #f8f9fa;
        padding: 15px;
        text-align: right;
        font-weight: 600;
        color: var(--dark);
    }
    .data-table td {
        padding: 12px 15px;
        border-bottom: 1px solid #eee;
    }
    .data-table tr:hover { background: #f8f9fa; }
    
    .trace-container {
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    .trace-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }
    .trace-timeline {
        position: relative;
        padding: 20px 0;
    }
    .timeline-line {
        position: absolute;
        right: 30px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, var(--primary), var(--success), var(--warning));
    }
    .timeline-item {
        position: relative;
        padding-right: 70px;
        margin-bottom: 30px;
    }
    .timeline-icon {
        position: absolute;
        right: 15px;
        top: 0;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        color: white;
        z-index: 2;
    }
    .timeline-icon.create { background: var(--success); }
    .timeline-icon.forward { background: var(--primary); }
    .timeline-icon.approve { background: var(--success); }
    .timeline-icon.reject { background: var(--danger); }
    .timeline-icon.view { background: var(--purple); }
    .timeline-content {
        background: #f8f9fa;
        border-radius: 16px;
        padding: 18px;
        transition: all 0.3s;
    }
    .timeline-content:hover {
        background: white;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .timeline-title {
        font-weight: bold;
        color: var(--dark);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .timeline-detail {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        font-size: 13px;
        color: var(--gray);
    }
    .trace-summary {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        border-radius: 16px;
        padding: 20px;
        color: white;
        margin-bottom: 30px;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
    }
    
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
        margin-bottom: 30px;
    }
    .chart-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .chart-card h3 {
        margin-bottom: 20px;
        color: var(--dark);
        font-size: 16px;
    }
    
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .charts-grid { grid-template-columns: 1fr; }
        .filter-row { grid-template-columns: 1fr; }
        .trace-summary { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div class="tabs">
    <button class="tab-btn <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
        <i class="fas fa-chart-line"></i> داشبورد تحلیلی
    </button>
    <button class="tab-btn <?php echo $active_tab == 'invoices' ? 'active' : ''; ?>" data-tab="invoices">
        <i class="fas fa-file-invoice"></i> فاکتورها
    </button>
    <button class="tab-btn <?php echo $active_tab == 'waybills' ? 'active' : ''; ?>" data-tab="waybills">
        <i class="fas fa-truck"></i> بارنامه‌ها
    </button>
    <button class="tab-btn <?php echo $active_tab == 'tax' ? 'active' : ''; ?>" data-tab="tax">
        <i class="fas fa-cloud-upload-alt"></i> مودیان
    </button>
    <button class="tab-btn <?php echo $active_tab == 'trace' ? 'active' : ''; ?>" data-tab="trace">
        <i class="fas fa-map"></i> نقشه مسیر
    </button>
</div>

<!-- ==================== تب 1: داشبورد ==================== -->
<div id="tab-dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);"><i class="fas fa-file-invoice"></i></div><div class="stat-info"><h3>کل فاکتورها</h3><p><?php echo number_format($stats['total_invoices']); ?></p></div></div>
        <div class="stat-card"><div class="stat-icon" style="background: linear-gradient(135deg, #f39c12, #e67e22);"><i class="fas fa-truck"></i></div><div class="stat-info"><h3>کل بارنامه‌ها</h3><p><?php echo number_format($stats['total_waybills']); ?></p></div></div>
        <div class="stat-card"><div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);"><i class="fas fa-cloud-upload-alt"></i></div><div class="stat-info"><h3>اسناد مودیان</h3><p><?php echo number_format($stats['total_tax']); ?></p></div></div>
        <div class="stat-card"><div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #219a52);"><i class="fas fa-money-bill-wave"></i></div><div class="stat-info"><h3>مجموع مبالغ</h3><p><?php echo number_format($stats['total_amount']); ?> تومان</p></div></div>
    </div>
    <div class="charts-grid">
        <div class="chart-card"><h3>📈 روند ماهانه فاکتورها</h3><canvas id="monthlyChart" style="height: 250px;"></canvas></div>
        <div class="chart-card"><h3>🥧 وضعیت فاکتورها</h3><canvas id="statusChart" style="height: 250px;"></canvas></div>
        <div class="chart-card"><h3>🏢 مبلغ به تفکیک شرکت</h3><canvas id="companyChart" style="height: 250px;"></canvas></div>
        <div class="chart-card"><h3>⏳ عملکرد ماهانه</h3><canvas id="performanceChart" style="height: 250px;"></canvas></div>
    </div>
</div>

<!-- ==================== تب 2: فاکتورها ==================== -->
<div id="tab-invoices" class="tab-content <?php echo $active_tab == 'invoices' ? 'active' : ''; ?>">
    <div class="filter-bar">
        <form method="GET" id="filterForm">
            <input type="hidden" name="tab" value="invoices">
            <div class="filter-row">
                <div class="filter-group"><label>📅 از تاریخ</label><input type="date" name="date_from" value="<?php echo $date_from; ?>"></div>
                <div class="filter-group"><label>📅 تا تاریخ</label><input type="date" name="date_to" value="<?php echo $date_to; ?>"></div>
                <div class="filter-group"><label>🏢 شرکت</label><select name="company"><option value="">همه</option><?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $filter_company==$c['id']?'selected':''; ?>><?php echo $c['name']; ?></option><?php endforeach; ?></select></div>
                <div class="filter-group"><label>🏪 فروشنده</label><select name="vendor"><option value="">همه</option><?php foreach($vendors as $v): ?><option value="<?php echo $v['id']; ?>" <?php echo $filter_vendor==$v['id']?'selected':''; ?>><?php echo $v['name']; ?></option><?php endforeach; ?></select></div>
                <div class="filter-group"><label>⚙️ وضعیت</label><select name="status"><option value="">همه</option><option value="pending" <?php echo $filter_status=='pending'?'selected':''; ?>>در انتظار</option><option value="approved" <?php echo $filter_status=='approved'?'selected':''; ?>>تایید شده</option><option value="rejected" <?php echo $filter_status=='rejected'?'selected':''; ?>>رد شده</option></select></div>
                <div><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> اعمال فیلتر</button></div>
            </div>
        </form>
    </div>
    <table class="data-table">
        <thead><tr><th>شماره</th><th>عنوان</th><th>شرکت</th><th>فروشنده</th><th>مبلغ</th><th>تاریخ</th><th>وضعیت</th><th>ایجادکننده</th><th>در دست</th></tr></thead>
        <tbody><?php if(empty($invoices_data)): ?><tr><td colspan="9" style="text-align:center;">هیچ فاکتوری یافت نشد</td></tr><?php else: foreach($invoices_data as $doc): ?>
        <tr>
            <td><?php echo $doc['document_number']; ?></td>
            <td><?php echo htmlspecialchars($doc['title']); ?></td>
            <td><?php echo htmlspecialchars($doc['company_name']??'-'); ?></td>
            <td><?php echo htmlspecialchars($doc['vendor_name']??'-'); ?></td>
            <td><?php echo number_format($doc['amount']); ?> تومان</td>
            <td><?php echo jdate('Y/m/d', strtotime($doc['created_at'])); ?></td>
            <td><?php echo $status_texts[$doc['status']] ?? $doc['status']; ?></td>
            <td><?php echo htmlspecialchars($doc['creator_name']??'-'); ?></td>
            <td><?php echo htmlspecialchars($doc['holder_user_name']??$doc['holder_department_name']??'-'); ?></td>
        </tr>
        <?php endforeach; endif; ?></tbody>
    </table>
</div>

<!-- ==================== تب 3: بارنامه‌ها ==================== -->
<div id="tab-waybills" class="tab-content <?php echo $active_tab == 'waybills' ? 'active' : ''; ?>">
    <div class="filter-bar">
        <form method="GET">
            <input type="hidden" name="tab" value="waybills">
            <div class="filter-row">
                <div class="filter-group"><label>📅 از تاریخ</label><input type="date" name="date_from" value="<?php echo $date_from; ?>"></div>
                <div class="filter-group"><label>📅 تا تاریخ</label><input type="date" name="date_to" value="<?php echo $date_to; ?>"></div>
                <div class="filter-group"><label>🏢 شرکت</label><select name="company"><option value="">همه</option><?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $filter_company==$c['id']?'selected':''; ?>><?php echo $c['name']; ?></option><?php endforeach; ?></select></div>
                <div><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> اعمال فیلتر</button></div>
            </div>
        </form>
    </div>
    <table class="data-table">
        <thead><tr><th>شماره</th><th>عنوان</th><th>شرکت</th><th>مبلغ</th><th>فرستنده</th><th>گیرنده</th><th>تاریخ</th><th>وضعیت</th><th>در دست</th></tr></thead>
        <tbody><?php if(empty($waybills_data)): ?><tr><td colspan="9" style="text-align:center;">هیچ بارنامه‌ای یافت نشد</td></tr><?php else: foreach($waybills_data as $doc): ?>
        <tr>
            <td><?php echo $doc['document_number']; ?></td>
            <td><?php echo htmlspecialchars($doc['title']); ?></td>
            <td><?php echo htmlspecialchars($doc['company_name']??'-'); ?></td>
            <td><?php echo number_format($doc['amount']); ?> تومان</td>
            <td><?php echo htmlspecialchars($doc['sender_name']??'-'); ?></td>
            <td><?php echo htmlspecialchars($doc['receiver_name']??'-'); ?></td>
            <td><?php echo jdate('Y/m/d', strtotime($doc['created_at'])); ?></td>
            <td><?php echo $status_texts[$doc['status']] ?? $doc['status']; ?></td>
            <td><?php echo htmlspecialchars($doc['holder_user_name']??$doc['holder_department_name']??'-'); ?></td>
        </tr>
        <?php endforeach; endif; ?></tbody>
    </table>
</div>

<!-- ==================== تب 4: مودیان ==================== -->
<div id="tab-tax" class="tab-content <?php echo $active_tab == 'tax' ? 'active' : ''; ?>">
    <div class="filter-bar">
        <form method="GET">
            <input type="hidden" name="tab" value="tax">
            <div class="filter-row">
                <div class="filter-group"><label>📅 از تاریخ</label><input type="date" name="date_from" value="<?php echo $date_from; ?>"></div>
                <div class="filter-group"><label>📅 تا تاریخ</label><input type="date" name="date_to" value="<?php echo $date_to; ?>"></div>
                <div class="filter-group"><label>🏢 شرکت</label><select name="company"><option value="">همه</option><?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $filter_company==$c['id']?'selected':''; ?>><?php echo $c['name']; ?></option><?php endforeach; ?></select></div>
                <div><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> اعمال فیلتر</button></div>
            </div>
        </form>
    </div>
    <table class="data-table">
        <thead><tr><th>شماره</th><th>عنوان</th><th>شرکت</th><th>شناسه مالیاتی</th><th>مبلغ</th><th>تاریخ</th><th>وضعیت</th><th>در دست</th></tr></thead>
        <tbody><?php if(empty($tax_data)): ?><tr><td colspan="8" style="text-align:center;">هیچ سندی یافت نشد</td></tr><?php else: foreach($tax_data as $doc): ?>
        <tr>
            <td><?php echo $doc['document_number']; ?></td>
            <td><?php echo htmlspecialchars($doc['title']); ?></td>
            <td><?php echo htmlspecialchars($doc['company_name']??'-'); ?></td>
            <td><?php echo htmlspecialchars($doc['tax_id']??'-'); ?></td>
            <td><?php echo number_format($doc['amount']); ?> تومان</td>
            <td><?php echo jdate('Y/m/d', strtotime($doc['created_at'])); ?></td>
            <td><?php echo $status_texts[$doc['status']] ?? $doc['status']; ?></td>
            <td><?php echo htmlspecialchars($doc['holder_user_name']??$doc['holder_department_name']??'-'); ?></td>
        </tr>
        <?php endforeach; endif; ?></tbody>
    </table>
</div>

<!-- ==================== تب 5: نقشه مسیر ==================== -->
<div id="tab-trace" class="tab-content <?php echo $active_tab == 'trace' ? 'active' : ''; ?>">
    <div class="filter-bar">
        <form method="GET">
            <input type="hidden" name="tab" value="trace">
            <div class="filter-row">
                <div class="filter-group"><label>🔍 جستجوی سند (شماره سند)</label><input type="text" name="trace_search" placeholder="مثال: INV-20240501-123" value="<?php echo htmlspecialchars($trace_search); ?>" style="flex:2;"></div>
                <div><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> جستجو</button></div>
            </div>
        </form>
    </div>

    <?php if ($trace_search && !$trace_doc): ?>
        <div style="background: #fef9e6; padding: 30px; border-radius: 20px; text-align: center; color: #e67e22;">
            <i class="fas fa-truck" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
            ⚠️ سندی با این شماره یافت نشد
        </div>
    <?php endif; ?>

    <?php if ($trace_doc): ?>
        <div class="trace-container">
            <div class="trace-header">
                <h2><i class="fas fa-map"></i> نقشه مسیر سند</h2>
                <div class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> چاپ</div>
            </div>
            
            <div class="trace-summary">
                <div><i class="fas fa-hashtag"></i> شماره: <?php echo $trace_doc['document_number']; ?></div>
                <div><i class="fas fa-tag"></i> عنوان: <?php echo htmlspecialchars($trace_doc['title']); ?></div>
                <div><i class="fas fa-layer-group"></i> نوع: <?php echo $trace_doc['type']; ?></div>
                <div><i class="fas <?php echo $trace_doc['status']=='approved'?'fa-check-circle':($trace_doc['status']=='rejected'?'fa-times-circle':'fa-clock'); ?>"></i> وضعیت: <?php echo $status_texts[$trace_doc['status']] ?? $trace_doc['status']; ?></div>
            </div>

            <div class="trace-timeline">
                <div class="timeline-line"></div>
                
                <div class="timeline-item">
                    <div class="timeline-icon create">✅</div>
                    <div class="timeline-content">
                        <div class="timeline-title">ایجاد سند</div>
                        <div class="timeline-detail">
                            <span><i class="fas fa-user"></i> ایجادکننده: <?php echo htmlspecialchars($trace_doc['creator_name'] ?? 'نامشخص'); ?></span>
                            <span><i class="fas fa-calendar"></i> تاریخ: <?php echo jdate('Y/m/d H:i', strtotime($trace_doc['created_at'])); ?></span>
                        </div>
                    </div>
                </div>

                <?php foreach ($trace_history as $h): ?>
                <div class="timeline-item">
                    <div class="timeline-icon <?php echo $h['action'] == 'forward' ? 'forward' : ($h['action'] == 'approve' ? 'approve' : ($h['action'] == 'reject' ? 'reject' : 'view')); ?>">
                        <?php echo $h['action'] == 'forward' ? '🔄' : ($h['action'] == 'approve' ? '✅' : ($h['action'] == 'reject' ? '❌' : '👁️')); ?>
                    </div>
                    <div class="timeline-content">
                        <div class="timeline-title">
                            <?php echo $h['action'] == 'forward' ? 'ارجاع سند' : ($h['action'] == 'approve' ? 'تایید نهایی' : ($h['action'] == 'reject' ? 'رد سند' : 'مشاهده سند')); ?>
                        </div>
                        <div class="timeline-detail">
                            <span><i class="fas fa-user"></i> از: <?php echo htmlspecialchars($h['from_name']); ?></span>
                            <span><i class="fas fa-arrow-left"></i> به: <?php echo htmlspecialchars($h['to_name'] ?? $h['to_department_name'] ?? '-'); ?></span>
                            <span><i class="fas fa-calendar"></i> تاریخ: <?php echo jdate('Y/m/d H:i', strtotime($h['created_at'])); ?></span>
                            <?php if ($h['notes']): ?>
                                <span><i class="fas fa-comment"></i> توضیحات: <?php echo htmlspecialchars($h['notes']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// نمودارها
const monthlyData = <?php echo json_encode($monthly_stats); ?>;
const monthlyLabels = <?php echo json_encode($monthly_labels); ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: { labels: monthlyLabels, datasets: [{ label: 'تعداد فاکتورها', data: monthlyData.map(m => m.count), borderColor: '#3498db', fill: false, tension: 0.3 }] }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels: ['✅ تایید شده', '⏳ در انتظار', '❌ رد شده'], datasets: [{ data: [<?php echo $stats['approved']; ?>, <?php echo $stats['pending']; ?>, <?php echo $stats['rejected']; ?>], backgroundColor: ['#2ecc71', '#f39c12', '#e74c3c'] }] }
});

const companyData = <?php echo json_encode($company_stats); ?>;
new Chart(document.getElementById('companyChart'), {
    type: 'bar',
    data: { labels: companyData.map(c => c.name), datasets: [{ label: 'مبلغ (تومان)', data: companyData.map(c => c.total), backgroundColor: '#3498db' }] }
});

new Chart(document.getElementById('performanceChart'), {
    type: 'line',
    data: { labels: monthlyLabels, datasets: [{ label: 'مبلغ (میلیون تومان)', data: monthlyData.map(m => (m.total / 1000000).toFixed(1)), borderColor: '#e67e22', fill: false }] }
});

// تب‌ها
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        document.getElementById(`tab-${tabId}`).classList.add('active');
        history.pushState(null, '', `?tab=${tabId}`);
    });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>