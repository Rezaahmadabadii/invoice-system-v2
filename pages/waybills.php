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

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();

$filter_company = $_GET['company'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$base_sql = "SELECT d.*, c.name as company_name, c.short_name, v.name as vendor_name 
             FROM documents d 
             LEFT JOIN companies c ON d.company_id = c.id 
             LEFT JOIN vendors v ON d.vendor_id = v.id 
             WHERE d.type = 'waybill'";
$params = [];

if ($filter_company) {
    $base_sql .= " AND d.company_id = ?";
    $params[] = $filter_company;
}
if ($filter_status) {
    $base_sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($search) {
    $base_sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.cargo_description LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$count_sql = str_replace("SELECT d.*, c.name as company_name, c.short_name, v.name as vendor_name", "SELECT COUNT(*) as total", $base_sql);
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_count = $stmt->fetchColumn();
$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;

$sql = $base_sql . " ORDER BY d.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$waybills = $stmt->fetchAll();

$total_waybills = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill'")->fetchColumn();
$completed_waybills = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill' AND status = 'completed'")->fetchColumn();
$pending_waybills = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill' AND status = 'pending'")->fetchColumn();

$status_colors = [
    'pending' => '#fef9e6',      // زرد بسیار کم‌رنگ (متمایل به کرم)
    'forwarded' => '#f39c12',    // نارنجی زرد
    'approved' => '#2ecc71',     // سبز
    'rejected' => '#e74c3c',     // قرمز
    'draft' => '#95a5a6',        // خاکستری
    'cancelled' => '#e74c3c'     // قرمز
];

$status_texts = [
    'pending' => '⏳ در انتظار اقدام',
    'forwarded' => '🔄 ارسال شده',
    'approved' => '✅ تایید شده',
    'rejected' => '❌ رد شده',
    'draft' => '📝 پیش‌نویس',
    'cancelled' => '🚫 لغو شده'
];

// تابع تولید شماره بارنامه با فرمت جدید
function formatWaybillNumber($document_number, $short_name) {
    // اگر شماره قبلاً با فرمت جدید ذخیره شده باشد
    if (strpos($document_number, '-B/L-') !== false) {
        return $document_number;
    }
    // تبدیل به فرمت جدید: kyhn-B/L-1234
    $short = !empty($short_name) ? strtolower($short_name) : 'wbl';
    $number = preg_replace('/[^0-9]/', '', $document_number);
    if (empty($number)) {
        $number = substr($document_number, -6);
    }
    return $short . '-B/L-' . $number;
}

$page_title = 'مدیریت بارنامه‌ها';
ob_start();
?>
<style>
    .truck-animation-area {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        overflow: visible;
        min-height: 80px;
    }
    .truck-icon {
        font-size: 64px;
        display: inline-block;
        transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 0.8s ease-out;
        transform: scaleX(-1);
    }
    .truck-slide-right {
        transform: translateX(400px) scaleX(-1);
        opacity: 0;
    }
    .btn-create-wrapper {
        text-align: center;
    }
    
    /* آمار */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        padding: 20px;
        border-radius: 12px;
        color: white;
    }
    
    /* فیلترها */
    .filters-container {
        background: white;
        border-radius: 12px;
        padding: 15px 20px;
        margin-bottom: 25px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .search-box {
        flex: 2;
        min-width: 200px;
        position: relative;
    }
    .search-box input {
        width: 100%;
        padding: 10px 15px 10px 40px;
        border: 1px solid #ddd;
        border-radius: 25px;
        font-size: 14px;
    }
    .search-box i {
        position: absolute;
        right: 15px;
        top: 12px;
        color: #7f8c8d;
    }
    .status-filter {
        min-width: 150px;
    }
    .status-filter select {
        width: 100%;
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 25px;
        background: white;
    }
    .filter-btn {
        background: #3498db;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 25px;
        cursor: pointer;
    }
    .filter-btn.reset {
        background: #95a5a6;
    }
    
    /* لیست کارت‌های طولی */
    .cards-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .waybill-card {
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: all 0.2s ease;
        border: 1px solid #eef2f5;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 20px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .waybill-card:hover {
        transform: translateX(-3px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        border-color: #3498db20;
    }
    
    /* رنگ پس زمینه کارت بر اساس وضعیت */
    .card-status-pending {
        background: #fef9e6;
        border-right: 4px solid #f39c12;
    }
    .card-status-pending:hover {
        background: #fef5e0;
    }
    .card-status-forwarded {
        background: #fff3cd;
        border-right: 4px solid #f39c12;
    }
    .card-status-forwarded:hover {
        background: #ffeaa7;
    }
    .card-status-approved {
        background: #d4edda;
        border-right: 4px solid #2ecc71;
    }
    .card-status-approved:hover {
        background: #c3e6cb;
    }
    .card-status-rejected {
        background: #f8d7da;
        border-right: 4px solid #e74c3c;
    }
    .card-status-rejected:hover {
        background: #f5c6cb;
    }
    .card-status-draft {
        background: #f5f5f5;
        border-right: 4px solid #95a5a6;
    }
    .card-status-draft:hover {
        background: #eeeeee;
    }
    .card-status-cancelled {
        background: #f8d7da;
        border-right: 4px solid #e74c3c;
    }
    
    .card-info {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
        flex: 1;
    }
    .doc-number {
        font-weight: bold;
        font-size: 13px;
        background: linear-gradient(135deg, #3498db10, #3498db20);
        padding: 5px 12px;
        border-radius: 20px;
        color: #2c3e50;
        direction: ltr;
        font-family: monospace;
    }
    .doc-number i {
        color: #3498db;
        margin-left: 5px;
    }
    .title {
        font-size: 14px;
        font-weight: 500;
        color: #2c3e50;
        min-width: 150px;
    }
    .company {
        font-size: 12px;
        color: #7f8c8d;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .vendor {
        font-size: 12px;
        color: #7f8c8d;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .amount {
        font-size: 13px;
        color: #27ae60;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .route {
        font-size: 12px;
        color: #7f8c8d;
        display: flex;
        align-items: center;
        gap: 5px;
        background: #f8f9fa;
        padding: 4px 10px;
        border-radius: 20px;
    }
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        color: white;
        min-width: 70px;
        text-align: center;
    }
    .card-actions {
        display: flex;
        gap: 5px;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12px;
        text-decoration: none;
        transition: all 0.2s;
    }
    .action-btn.view {
        background: #e8f4fd;
        color: #3498db;
    }
    .action-btn.view:hover {
        background: #3498db;
        color: white;
    }
    .action-btn.edit {
        background: #fef5e8;
        color: #f39c12;
    }
    .action-btn.edit:hover {
        background: #f39c12;
        color: white;
    }
    .action-btn.delete {
        background: #fee8e8;
        color: #e74c3c;
    }
    .action-btn.delete:hover {
        background: #e74c3c;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 16px;
        color: #95a5a6;
    }
    .empty-state i {
        font-size: 64px;
        margin-bottom: 20px;
        display: block;
    }
    
    @media (max-width: 1200px) {
        .card-info { gap: 12px; }
        .vendor, .amount { display: none; }
    }
    @media (max-width: 992px) {
        .card-info { gap: 10px; }
        .title { min-width: 120px; font-size: 13px; }
        .company { min-width: 80px; }
    }
    @media (max-width: 768px) {
        .waybill-card { flex-direction: column; align-items: flex-start; }
        .card-info { width: 100%; }
        .card-actions { width: 100%; justify-content: flex-end; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters-container { flex-direction: column; }
        .filters-container > * { width: 100%; }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <h1 style="color: #2c3e50; margin: 0;">📦 مدیریت بارنامه‌ها</h1>
    <div class="btn-create-wrapper">
        <div class="truck-animation-area">
            <div class="truck-icon" id="truckIcon">🚛</div>
        </div>
        <button type="button" id="createWaybillBtn" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 5px; border: none; cursor: pointer;">
            <i class="fas fa-plus"></i> بارنامه جدید
        </button>
        <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9);">
        <div style="font-size: 14px;">کل بارنامه‌ها</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_waybills); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #2ecc71, #27ae60);">
        <div style="font-size: 14px;">تکمیل شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($completed_waybills); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
        <div style="font-size: 14px;">در حال انجام</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($pending_waybills); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
        <div style="font-size: 14px;">لغو شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_waybills - $completed_waybills - $pending_waybills); ?></div>
    </div>
</div>

<div class="filters-container">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="جستجوی شماره، عنوان، شرکت..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="status-filter">
        <select id="statusFilter">
            <option value="">همه وضعیت‌ها</option>
            <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>پیش‌نویس</option>
            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>در حال انجام</option>
            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>تکمیل شده</option>
            <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>لغو شده</option>
        </select>
    </div>
    <div class="status-filter">
        <select id="companyFilter">
            <option value="">همه شرکت‌ها</option>
            <?php foreach ($companies as $comp): ?>
                <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>><?php echo $comp['name']; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="filter-btn" id="applyFilterBtn">اعمال فیلتر</button>
    <a href="waybills.php" class="filter-btn reset">پاک کردن</a>
</div>

<?php if (empty($waybills)): ?>
    <div class="empty-state">
        <i class="fas fa-truck"></i>
        <p>هیچ بارنامه‌ای یافت نشد</p>
        <a href="waybill-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; margin-top: 15px;">
            <i class="fas fa-plus"></i> ایجاد بارنامه جدید
        </a>
    </div>
<?php else: ?>
    <div class="cards-list">
        <?php foreach ($waybills as $wb): 
            $display_number = formatWaybillNumber($wb['document_number'], $wb['short_name']);
        ?>
        <div class="waybill-card card-status-<?php echo $wb['status']; ?>">
            <div class="card-info">
                <span class="doc-number">
                    <i class="fas fa-file-alt"></i> <?php echo $display_number; ?>
                </span>
                <span class="title"><?php echo htmlspecialchars($wb['title']); ?></span>
                <span class="company"><i class="fas fa-building"></i> <?php echo htmlspecialchars($wb['company_name'] ?? '-'); ?></span>
                <span class="vendor"><i class="fas fa-store"></i> <?php echo htmlspecialchars($wb['vendor_name'] ?? '-'); ?></span>
                <span class="amount"><i class="fas fa-money-bill-wave"></i> <?php echo number_format($wb['amount'] ?? 0); ?> تومان</span>
                <span class="route"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($wb['loading_origin'] ?? '-'); ?> → <?php echo htmlspecialchars($wb['discharge_destination'] ?? '-'); ?></span>
            </div>
            <span class="status-badge" style="background: <?php echo $status_colors[$wb['status']] ?? '#95a5a6'; ?>; color: <?php echo in_array($wb['status'], ['pending', 'draft']) ? '#7f8c8d' : 'white'; ?>;">
                <?php echo $status_texts[$wb['status']] ?? $wb['status']; ?>
            </span>
            <div class="card-actions">
                <a href="waybill-view.php?id=<?php echo $wb['id']; ?>" class="action-btn view" title="مشاهده">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($wb['created_by'] == $_SESSION['user_id']): ?>
                    <a href="waybill-edit.php?id=<?php echo $wb['id']; ?>" class="action-btn edit" title="ویرایش">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="waybill-delete.php?id=<?php echo $wb['id']; ?>" class="action-btn delete" title="حذف" onclick="return confirm('حذف شود؟')">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
        <a href="?page=1<?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;"><<</a>
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;"><</a>
        <?php endif; ?>
        <span style="padding: 8px 15px; background: #2c3e50; color: white; border-radius: 5px;"><?php echo $page; ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">></a>
        <?php endif; ?>
        <a href="?page=<?php echo $total_pages; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">>></a>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
function animateTruckAndRedirect() {
    var truckIcon = document.getElementById('truckIcon');
    if (truckIcon) {
        if (truckIcon.classList.contains('truck-slide-right')) return;
        truckIcon.classList.add('truck-slide-right');
        setTimeout(function() { window.location.href = 'waybill-create.php'; }, 800);
    } else {
        window.location.href = 'waybill-create.php';
    }
}
document.getElementById('createWaybillBtn')?.addEventListener('click', function(e) {
    e.preventDefault();
    animateTruckAndRedirect();
});

// فیلترها
document.getElementById('applyFilterBtn')?.addEventListener('click', function() {
    let search = document.getElementById('searchInput').value;
    let status = document.getElementById('statusFilter').value;
    let company = document.getElementById('companyFilter').value;
    let url = 'waybills.php?';
    if (search) url += 'search=' + encodeURIComponent(search);
    if (status) url += (search ? '&' : '') + 'status=' + encodeURIComponent(status);
    if (company) url += (search || status ? '&' : '') + 'company=' + encodeURIComponent(company);
    window.location.href = url;
});

document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') document.getElementById('applyFilterBtn').click();
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>