<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
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

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();

// ========== فیلترهای سال و ماه شمسی ==========
$current_year = jdate('Y');
$current_month = jdate('n');

$selected_year = $_GET['year'] ?? $current_year;
$selected_month = $_GET['month'] ?? $current_month;

$months = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];
$years = range($current_year - 2, $current_year + 2);

$filter_company = $_GET['company'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_tax_status = $_GET['tax_status'] ?? '';
$search = $_GET['search'] ?? '';

// شرط تاریخ
$date_condition = "";
$params = [];

if ($selected_year && $selected_month) {
    $last_day = ($selected_month <= 6) ? 31 : (($selected_month <= 11) ? 30 : 29);
    $start_parts = explode('/', "$selected_year/$selected_month/01");
    $end_parts = explode('/', "$selected_year/$selected_month/$last_day");
    
    list($g_start_y, $g_start_m, $g_start_d) = jDateTime::toGregorian($start_parts[0], $start_parts[1], $start_parts[2]);
    list($g_end_y, $g_end_m, $g_end_d) = jDateTime::toGregorian($end_parts[0], $end_parts[1], $end_parts[2]);
    
    $start_date_greg = sprintf('%04d-%02d-%02d 00:00:00', $g_start_y, $g_start_m, $g_start_d);
    $end_date_greg = sprintf('%04d-%02d-%02d 23:59:59', $g_end_y, $g_end_m, $g_end_d);
    
    $date_condition = " AND d.created_at BETWEEN ? AND ?";
    $params[] = $start_date_greg;
    $params[] = $end_date_greg;
}

// کوئری اصلی با جوین برای دریافت اطلاعات دارنده و فروشنده
$sql = "SELECT d.*, 
               c.name as company_name, 
               c.short_name,
               v.name as vendor_name,
               holder_dep.name as holder_department_name,
               holder_user.full_name as holder_user_name
        FROM documents d 
        LEFT JOIN companies c ON d.company_id = c.id 
        LEFT JOIN vendors v ON d.vendor_id = v.id
        LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
        LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
        WHERE d.type = 'tax'
        AND (d.created_by = ? OR d.current_holder_user_id = ? OR d.current_holder_department_id = ?)
        $date_condition";

// پارامترها - سه مقدار اول برای سه شرط بالا
$user_id = $_SESSION['user_id'];
$params = array_merge([$user_id, $user_id, $_SESSION['user_department_id'] ?? null], $params);

if ($filter_company) {
    $sql .= " AND d.company_id = ?";
    $params[] = $filter_company;
}
if ($filter_status) {
    $sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($filter_tax_status) {
    $sql .= " AND d.tax_status = ?";
    $params[] = $filter_tax_status;
}
if ($search) {
    $sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.tax_id LIKE ? OR c.name LIKE ? OR v.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tax_docs = $stmt->fetchAll();

// آمار
$total_tax = count($tax_docs);
$sent_tax = count(array_filter($tax_docs, fn($d) => $d['tax_status'] == 'sent'));
$pending_tax = count(array_filter($tax_docs, fn($d) => $d['tax_status'] == 'pending'));
$failed_tax = count(array_filter($tax_docs, fn($d) => $d['tax_status'] == 'failed'));
$draft_count = count(array_filter($tax_docs, fn($d) => $d['status'] == 'draft'));

$status_texts = [
    'pending' => '⏳ در انتظار',
    'forwarded' => '🔄 در حال بررسی',
    'approved' => '✅ تایید شده',
    'rejected' => '❌ رد شده',
    'draft' => '📝 پیش‌نویس'
];

function getRemainingDays($deadline) {
    if (empty($deadline)) return null;
    $today = new DateTime();
    $deadline_date = new DateTime($deadline);
    $diff = $today->diff($deadline_date);
    return $diff->days;
}

function getDeadlineClass($remainingDays) {
    if ($remainingDays === null) return '';
    if ($remainingDays <= 0) return 'deadline-danger';
    if ($remainingDays <= 3) return 'deadline-warning';
    return 'deadline-normal';
}

function getDeadlineText($remainingDays) {
    if ($remainingDays === null) return 'نامشخص';
    if ($remainingDays <= 0) return 'پایان یافته';
    if ($remainingDays <= 3) return $remainingDays . ' روز';
    return $remainingDays . ' روز';
}

// تابع تولید شناسه مالیاتی فرمت شده (مشابه فاکتورها)
function generateFormattedTaxId($companyShortName, $taxIdNumber) {
    $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $companyShortName));
    if (empty($code)) $code = 'TAX';
    $numericPart = preg_replace('/[^0-9]/', '', $taxIdNumber);
    $lastSix = substr(str_pad($numericPart, 6, '0', STR_PAD_LEFT), -6);
    if (empty($lastSix)) $lastSix = '000001';
    return 'Tax-' . $code . '-' . $lastSix;
}

$page_title = 'سامانه مودیان';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #3b82f6;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
    }
    
    /* انیمیشن چرخ‌دنده (درجا) */
    .coin-area {
        display: inline-block;
        margin-left: 10px;
        vertical-align: middle;
        width: 30px;
    }
    .coin-icon {
        font-size: 22px;
        display: inline-block;
        transition: all 0.6s ease-out;
        cursor: pointer;
    }
    .coin-spin {
        animation: coinSpin 0.8s ease-out forwards;
    }
    @keyframes coinSpin {
        0% { transform: rotate(0deg); opacity: 1; }
        100% { transform: rotate(360deg); opacity: 0; }
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 15px;
        margin-bottom: 25px;
    }
    .stat-card {
        padding: 15px;
        border-radius: 12px;
        color: white;
        background: linear-gradient(135deg, var(--primary), #2563eb);
    }
    .stat-card.draft-card { background: linear-gradient(135deg, var(--warning), #d97706); }
    .stat-card div:first-child { font-size: 13px; opacity: 0.9; }
    .stat-card div:last-child { font-size: 28px; font-weight: bold; }
    
    .filters-container {
        background: white;
        border-radius: 12px;
        padding: 12px 20px;
        margin-bottom: 25px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .search-box { flex: 2; min-width: 180px; position: relative; }
    .search-box input {
        width: 100%;
        padding: 8px 15px 8px 35px;
        border: 1px solid #ddd;
        border-radius: 25px;
        font-size: 13px;
    }
    .search-box i { position: absolute; right: 15px; top: 10px; color: #7f8c8d; font-size: 13px; }
    .status-filter select, .year-month select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 25px;
        background: white;
        font-size: 13px;
    }
    .filter-btn {
        background: #3498db;
        color: white;
        border: none;
        padding: 8px 18px;
        border-radius: 25px;
        cursor: pointer;
        font-size: 13px;
    }
    .filter-btn.reset { background: #95a5a6; }
    
    /* ========== کارت‌ها - گرید فشرده (مشابه inbox.php) ========== */
    .cards-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
        max-height: 70vh;
        overflow-y: auto;
        padding: 4px;
    }
    
    .tax-card {
        background: white;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: all 0.2s ease;
        border: 1px solid #eef2f5;
    }
    
    .tax-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(0,0,0,0.1);
    }
    
    /* نوار وضعیت رنگی بالای کارت */
    .card-status-bar {
        height: 4px;
        width: 100%;
    }
    
    .status-bar-pending { background: #f39c12; }
    .status-bar-forwarded { background: #3498db; }
    .status-bar-approved { background: #2ecc71; }
    .status-bar-rejected { background: #e74c3c; }
    .status-bar-draft { background: #95a5a6; }
    
    .card-content {
        padding: 14px;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    /* شناسه مالیاتی فرمت شده */
    .tax-id-badge {
        font-size: 12px;
        font-weight: bold;
        background: linear-gradient(135deg, #8e44ad, #9b59b6);
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-family: monospace;
        direction: ltr;
    }
    
    .draft-badge {
        background: #f59e0b;
        color: white;
        padding: 4px 10px;
        border-radius: 30px;
        font-size: 11px;
        font-weight: bold;
    }
    
    .card-title {
        font-size: 13px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 12px;
        text-align: center;
        background: #f8f9fa;
        padding: 6px 10px;
        border-radius: 10px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 8px;
        font-size: 12px;
    }
    
    .info-label {
        color: #7f8c8d;
        font-size: 11px;
    }
    
    .info-value {
        font-weight: 600;
        color: #2c3e50;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        display: block;
    }
    
    .amount-value {
        color: #27ae60;
        font-size: 13px;
    }
    
    .deadline-value {
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 11px;
    }
    .deadline-normal { background: #d4edda; color: #155724; }
    .deadline-warning { background: #fff3cd; color: #856404; }
    .deadline-danger { background: #f8d7da; color: #721c24; }
    
    .holder-box {
        background: #f0f7ff;
        border-radius: 10px;
        padding: 6px 10px;
        margin: 10px 0;
        text-align: center;
        font-size: 11px;
    }
    
    .status-steps {
        display: flex;
        justify-content: space-between;
        margin: 10px 0;
        background: #f5f5f5;
        border-radius: 20px;
        padding: 5px 8px;
    }
    
    .step-item {
        font-size: 10px;
        color: #bbb;
        text-align: center;
        flex: 1;
    }
    
    .step-item.active {
        color: #f39c12;
        font-weight: bold;
    }
    
    .step-item.completed {
        color: #2ecc71;
    }
    
    .step-item i {
        font-size: 12px;
        display: block;
        margin-bottom: 3px;
    }
    
    .card-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        justify-content: flex-end;
    }
    
    .action-btn {
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 11px;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .action-btn.view { background: #e8f4fd; color: #3498db; }
    .action-btn.view:hover { background: #3498db; color: white; }
    .action-btn.edit { background: #fef5e8; color: #f39c12; }
    .action-btn.edit:hover { background: #f39c12; color: white; }
    .action-btn.delete { background: #fee8e8; color: #e74c3c; }
    .action-btn.delete:hover { background: #e74c3c; color: white; }
    .action-btn.finalize { background: #fee8e8; color: #e74c3c; }
    .action-btn.finalize:hover { background: #e74c3c; color: white; }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        background: white;
        border-radius: 16px;
        color: #95a5a6;
        grid-column: 1/-1;
    }
    
    .btn-create {
        background: linear-gradient(135deg, #f39c12, #e67e22);
        color: white;
        padding: 8px 18px;
        border-radius: 8px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-left: 10px;
        border: none;
        cursor: pointer;
        font-size: 13px;
    }
    
    @media (max-width: 768px) {
        .cards-list { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); max-height: 60vh; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters-container { flex-direction: column; align-items: stretch; }
        .filters-container > * { width: 100%; }
    }
    
    @media (max-width: 480px) {
        .cards-list {
            grid-template-columns: 1fr;
        }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <h1 style="color: #2c3e50; margin: 0; font-size: 22px;">🏛️ سامانه مودیان</h1>
    <div>
        <div style="display: inline-block;">
            <div class="coin-area">
                <div class="coin-icon" id="coinIcon">⚙️</div>
            </div>
            <button type="button" id="createTaxBtn" class="btn-create">
                <i class="fas fa-plus"></i> سند جدید
            </button>
        </div>
        <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 13px;">بازگشت</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card"><div>📄 کل اسناد</div><div><?php echo number_format($total_tax); ?></div></div>
    <div class="stat-card" style="background: linear-gradient(135deg, #2ecc71, #27ae60);"><div>✅ ارسال شده</div><div><?php echo number_format($sent_tax); ?></div></div>
    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #e67e22);"><div>⏳ در انتظار</div><div><?php echo number_format($pending_tax); ?></div></div>
    <div class="stat-card" style="background: linear-gradient(135deg, #e74c3c, #c0392b);"><div>❌ خطا</div><div><?php echo number_format($failed_tax); ?></div></div>
    <div class="stat-card draft-card"><div>📝 پیش‌نویس</div><div><?php echo number_format($draft_count); ?></div></div>
</div>

<div class="filters-container">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="    جستجو..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="year-month" style="display: flex; gap: 8px;">
        <select id="yearSelect">
            <?php foreach ($years as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endforeach; ?>
        </select>
        <select id="monthSelect">
            <?php foreach ($months as $num => $name): ?>
                <option value="<?php echo $num; ?>" <?php echo $selected_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="status-filter">
        <select id="statusFilter">
            <option value="">همه وضعیت‌ها</option>
            <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>پیش‌نویس</option>
            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>در انتظار</option>
            <option value="forwarded" <?php echo $filter_status == 'forwarded' ? 'selected' : ''; ?>>در حال بررسی</option>
            <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>تایید شده</option>
            <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>رد شده</option>
        </select>
    </div>
    <div class="status-filter">
        <select id="taxStatusFilter">
            <option value="">وضعیت ارسال</option>
            <option value="sent" <?php echo $filter_tax_status == 'sent' ? 'selected' : ''; ?>>ارسال شده</option>
            <option value="pending" <?php echo $filter_tax_status == 'pending' ? 'selected' : ''; ?>>در انتظار</option>
            <option value="failed" <?php echo $filter_tax_status == 'failed' ? 'selected' : ''; ?>>خطا</option>
        </select>
    </div>
    <div class="status-filter">
        <select id="companyFilter">
            <option value="">همه شرکت‌ها</option>
            <?php foreach ($companies as $comp): ?>
                <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($comp['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="filter-btn" id="applyFilterBtn">اعمال</button>
    <a href="tax.php" class="filter-btn reset">پاک کردن</a>
</div>

<div class="cards-list">
    <?php if (empty($tax_docs)): ?>
        <div class="empty-state"><i class="fas fa-cloud-upload-alt" style="font-size: 40px;"></i><p>هیچ سندی یافت نشد</p></div>
    <?php else: ?>
        <?php foreach ($tax_docs as $doc): 
            $remainingDays = getRemainingDays($doc['approval_deadline']);
            $deadlineClass = getDeadlineClass($remainingDays);
            $deadlineText = getDeadlineText($remainingDays);
            
            // تولید شناسه مالیاتی فرمت شده (مثلاً Tax-DNA-345231)
            $formattedTaxId = generateFormattedTaxId($doc['short_name'] ?? '', $doc['original_tax_id'] ?? $doc['tax_id'] ?? '');
            
            $shamsi_date = jdate('Y/m/d', strtotime($doc['created_at']));
            $holderText = !empty($doc['holder_user_name']) ? '👤 ' . htmlspecialchars($doc['holder_user_name']) : 
                         (!empty($doc['holder_department_name']) ? '🏢 ' . htmlspecialchars($doc['holder_department_name']) : '📭 بدون متصدی');
            $currentStatus = $doc['status'];
            $isDraft = ($currentStatus == 'draft');
            $stepCreate = ($currentStatus != 'draft') ? 'completed' : 'active';
            $stepReview = ($currentStatus == 'forwarded' || $currentStatus == 'approved') ? 'completed' : ($currentStatus == 'forwarded' ? 'active' : '');
            $stepApprove = ($currentStatus == 'approved') ? 'completed active' : '';
            
            // تشخیص نوع فایل برای دکمه مشاهده
            $file_icon = 'fa-file-pdf';
            $file_color = '#e74c3c';
            $file_type = 'pdf';
            if (!empty($doc['file_path'])) {
                $file_ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $file_icon = 'fa-image';
                    $file_color = '#27ae60';
                    $file_type = 'image';
                } elseif ($file_ext == 'pdf') {
                    $file_icon = 'fa-file-pdf';
                    $file_color = '#e74c3c';
                    $file_type = 'pdf';
                } else {
                    $file_icon = 'fa-file-alt';
                    $file_color = '#3498db';
                    $file_type = 'other';
                }
            }
        ?>
        <div class="tax-card">
            <!-- نوار وضعیت رنگی بالای کارت -->
            <div class="card-status-bar status-bar-<?php echo $currentStatus == 'draft' ? 'draft' : $currentStatus; ?>"></div>
            <div class="card-content">
                <div class="card-header">
                    <span class="tax-id-badge"><?php echo htmlspecialchars($formattedTaxId); ?></span>
                    <?php if ($isDraft): ?>
                        <span class="draft-badge"><i class="fas fa-pen-fancy"></i> پیش‌نویس</span>
                    <?php endif; ?>
                </div>
                
                <div class="card-title" title="<?php echo htmlspecialchars($doc['title']); ?>">
                    <?php echo htmlspecialchars(mb_substr($doc['title'], 0, 35)) . (mb_strlen($doc['title']) > 35 ? '...' : ''); ?>
                </div>
                
                <div class="info-row">
                    <span class="info-label">💰 مبلغ</span>
                    <span class="info-value amount-value"><?php echo number_format($doc['amount']); ?> تومان</span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏢 شرکت</span>
                    <span class="info-value"><?php echo htmlspecialchars($doc['company_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏪 فروشنده</span>
                    <span class="info-value"><?php echo htmlspecialchars($doc['vendor_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📅 تاریخ</span>
                    <span class="info-value"><?php echo $shamsi_date; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">⏰ مهلت</span>
                    <span class="deadline-value <?php echo $deadlineClass; ?>"><?php echo $deadlineText; ?></span>
                </div>
                
                <div class="holder-box">
                    <i class="fas fa-location-dot"></i> <strong>📍 در دست:</strong> <?php echo $holderText; ?>
                </div>
                
                <div class="status-steps">
                    <div class="step-item <?php echo $stepCreate; ?>"><i class="fas fa-pen"></i> ایجاد</div>
                    <div class="step-item <?php echo $stepReview; ?>"><i class="fas fa-search"></i> بررسی</div>
                    <div class="step-item <?php echo $stepApprove; ?>"><i class="fas fa-check-circle"></i> تایید</div>
                </div>
                
                <div class="card-actions">
                    <a href="taxi-view.php?id=<?php echo $doc['id']; ?>" class="action-btn view" title="مشاهده"><i class="fas fa-eye"></i></a>
                    
                    <?php if (!empty($doc['file_path'])): ?>
                        <button type="button" class="action-btn file" 
                                data-file="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                data-filename="<?php echo htmlspecialchars($doc['file_name'] ?? basename($doc['file_path'])); ?>"
                                data-type="<?php echo $file_type; ?>"
                                style="color: <?php echo $file_color; ?>; background: <?php echo $file_color; ?>10;"
                                onclick="openFileModal(this)" 
                                title="مشاهده فایل">
                            <i class="fas <?php echo $file_icon; ?>"></i>
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($doc['created_by'] == $_SESSION['user_id']): ?>
                        <?php if ($isDraft): ?>
                            <a href="tax-edit.php?id=<?php echo $doc['id']; ?>" class="action-btn finalize" title="تکمیل"><i class="fas fa-check-circle"></i> تکمیل</a>
                        <?php else: ?>
                            <a href="tax-edit.php?id=<?php echo $doc['id']; ?>" class="action-btn edit" title="ویرایش"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                        <a href="tax-delete.php?id=<?php echo $doc['id']; ?>" class="action-btn delete" title="حذف" onclick="return confirm('حذف شود؟')"><i class="fas fa-trash-alt"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// انیمیشن چرخ‌دنده + حرکت به راست
const coinIcon = document.getElementById('coinIcon');
const createBtn = document.getElementById('createTaxBtn');

if (coinIcon && createBtn) {
    createBtn.addEventListener('click', function(e) {
        e.preventDefault();
        coinIcon.classList.add('coin-spin');
        setTimeout(function() {
            window.location.href = 'tax-create.php';
        }, 800);
    });
}

function applyFilters() {
    let search = document.getElementById('searchInput').value;
    let status = document.getElementById('statusFilter').value;
    let taxStatus = document.getElementById('taxStatusFilter').value;
    let company = document.getElementById('companyFilter').value;
    let year = document.getElementById('yearSelect').value;
    let month = document.getElementById('monthSelect').value;
    let url = 'tax.php?';
    if (search) url += 'search=' + encodeURIComponent(search);
    if (status) url += (search ? '&' : '') + 'status=' + encodeURIComponent(status);
    if (taxStatus) url += (search || status ? '&' : '') + 'tax_status=' + encodeURIComponent(taxStatus);
    if (company) url += (search || status || taxStatus ? '&' : '') + 'company=' + encodeURIComponent(company);
    if (year) url += (search || status || taxStatus || company ? '&' : '') + 'year=' + encodeURIComponent(year);
    if (month) url += (search || status || taxStatus || company || year ? '&' : '') + 'month=' + encodeURIComponent(month);
    window.location.href = url;
}

document.getElementById('applyFilterBtn').addEventListener('click', applyFilters);
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') applyFilters();
});

// مودال فایل (مشابه inbox.php)
function openFileModal(button) {
    const filePath = button.getAttribute('data-file');
    const fileName = button.getAttribute('data-filename') || 'فایل';
    const fileType = button.getAttribute('data-type');
    const modal = document.getElementById('fileModal');
    const modalBody = document.getElementById('fileModalBody');
    
    if (!filePath) {
        modalBody.innerHTML = '<div class="file-loading">فایل یافت نشد</div>';
        modal.style.display = 'block';
        return;
    }
    
    const fileUrl = '/invoice-system-v2/' + filePath;
    
    if (fileType === 'pdf') {
        window.open(fileUrl, '_blank');
        return;
    }
    
    if (fileType === 'image') {
        modalBody.innerHTML = '<div class="file-loading">در حال بارگذاری...</div>';
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        const img = new Image();
        img.onload = function() {
            modalBody.innerHTML = `<img src="${fileUrl}" alt="${fileName}">`;
        };
        img.onerror = function() {
            modalBody.innerHTML = `<div style="text-align: center; padding: 40px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #e74c3c; margin-bottom: 16px; display: block;"></i>
                <p>خطا در بارگذاری تصویر</p>
                <a href="${fileUrl}" download class="action-btn view" style="display: inline-flex; margin-top: 16px;">
                    <i class="fas fa-download"></i> دانلود فایل
                </a>
            </div>`;
        };
        img.src = fileUrl;
    } else {
        modalBody.innerHTML = `<div style="text-align: center; padding: 40px;">
            <i class="fas fa-file-alt" style="font-size: 48px; color: #64748b; margin-bottom: 16px; display: block;"></i>
            <p>پیش‌نمایش برای این نوع فایل امکان‌پذیر نیست</p>
            <a href="${fileUrl}" download class="action-btn view" style="display: inline-flex; margin-top: 16px;">
                <i class="fas fa-download"></i> دانلود فایل
            </a>
        </div>`;
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeFileModal(event) {
    const modal = document.getElementById('fileModal');
    if (event && event.target !== modal && !event.target.classList.contains('file-modal-close')) {
        return;
    }
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    document.getElementById('fileModalBody').innerHTML = '<div class="file-loading">در حال بارگذاری...</div>';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFileModal();
});
</script>

<!-- مودال فایل -->
<div id="fileModal" class="file-modal" onclick="closeFileModal(event)">
    <div class="file-modal-content" onclick="event.stopPropagation()">
        <span class="file-modal-close" onclick="closeFileModal()">&times;</span>
        <div id="fileModalBody" class="file-modal-body">
            <div class="file-loading">در حال بارگذاری...</div>
        </div>
    </div>
</div>

<style>
    /* استایل مودال (مشابه inbox.php) */
    .file-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.85);
        backdrop-filter: blur(5px);
    }
    
    .file-modal-content {
        position: relative;
        background-color: #fff;
        margin: 3% auto;
        padding: 0;
        width: 90%;
        max-width: 1000px;
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        animation: modalFadeIn 0.3s ease-out;
        overflow: hidden;
    }
    
    @keyframes modalFadeIn {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }
    
    .file-modal-close {
        position: absolute;
        top: 12px;
        right: 20px;
        color: #fff;
        font-size: 32px;
        font-weight: bold;
        cursor: pointer;
        z-index: 10;
        background: rgba(0,0,0,0.5);
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    
    .file-modal-close:hover {
        background: #e74c3c;
        transform: rotate(90deg);
    }
    
    .file-modal-body {
        padding: 20px;
        background: #f1f5f9;
        min-height: 400px;
        max-height: 85vh;
        overflow-y: auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .file-modal-body img {
        max-width: 100%;
        max-height: 75vh;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .file-modal-body iframe {
        width: 100%;
        height: 75vh;
        border: none;
        border-radius: 8px;
    }
    
    .file-loading {
        text-align: center;
        padding: 40px;
        color: #64748b;
        font-size: 14px;
    }
    
    .file-loading:after {
        content: '';
        display: inline-block;
        width: 20px;
        height: 20px;
        margin-right: 10px;
        border: 2px solid #3b82f6;
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        vertical-align: middle;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .action-btn.file {
        background: #f8f9fa;
        border: 1px solid #e2e8f0;
    }
    
    .action-btn.file:hover {
        background: #e2e8f0;
        transform: translateY(-1px);
    }
    
    @media (max-width: 768px) {
        .file-modal-content {
            width: 95%;
            margin: 10% auto;
        }
        .file-modal-body {
            padding: 12px;
        }
        .file-modal-body img,
        .file-modal-body iframe {
            max-height: 60vh;
        }
    }
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>