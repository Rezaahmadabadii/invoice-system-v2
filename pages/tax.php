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
$filter_tax_status = $_GET['tax_status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$base_sql = "SELECT d.*, c.name as company_name, c.short_name
             FROM documents d 
             LEFT JOIN companies c ON d.company_id = c.id 
             WHERE d.type = 'tax'";
$params = [];

if ($filter_company) {
    $base_sql .= " AND d.company_id = ?";
    $params[] = $filter_company;
}
if ($filter_status) {
    $base_sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($filter_tax_status) {
    $base_sql .= " AND d.tax_status = ?";
    $params[] = $filter_tax_status;
}
if ($search) {
    $base_sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.tax_id LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$count_sql = str_replace("SELECT d.*, c.name as company_name, c.short_name", "SELECT COUNT(*) as total", $base_sql);
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_count = $stmt->fetchColumn();
$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;

$sql = $base_sql . " ORDER BY d.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tax_docs = $stmt->fetchAll();

$total_tax = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'tax'")->fetchColumn();
$sent_tax = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'tax' AND tax_status = 'sent'")->fetchColumn();
$pending_tax = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'tax' AND tax_status = 'pending'")->fetchColumn();
$failed_tax = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'tax' AND tax_status = 'failed'")->fetchColumn();

$status_colors = [
    'pending' => '#fef9e6',
    'forwarded' => '#f39c12',
    'approved' => '#2ecc71',
    'rejected' => '#e74c3c',
    'draft' => '#95a5a6'
];

$status_texts = [
    'pending' => '⏳ در انتظار',
    'forwarded' => '🔄 ارسال شده',
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

$page_title = 'سامانه مودیان';
ob_start();
?>

<style>
    .coin-animation-area {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        overflow: visible;
        min-height: 80px;
    }
    .coin-icon {
        font-size: 56px;
        display: inline-block;
        transition: opacity 0.6s ease-out;
        filter: drop-shadow(0 2px 5px rgba(0,0,0,0.2));
    }
    .coin-spin {
        animation: spinAndFade 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
    }
    @keyframes spinAndFade {
        0% { transform: rotate(0deg); opacity: 1; }
        100% { transform: rotate(360deg); opacity: 0; }
    }
    .btn-tax-primary {
        background: linear-gradient(135deg, #f39c12, #e67e22, #d35400);
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 50px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        text-decoration: none;
        display: inline-block;
        box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        transition: all 0.3s ease;
    }
    .btn-tax-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
        background: linear-gradient(135deg, #e67e22, #d35400, #c0392b);
    }
    
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
    
    .cards-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .tax-card {
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
    .tax-card:hover {
        transform: translateX(-3px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.1);
        border-color: #3498db20;
    }
    
    .card-status-pending { background: #fef9e6; border-right: 4px solid #f39c12; }
    .card-status-forwarded { background: #fff3cd; border-right: 4px solid #f39c12; }
    .card-status-approved { background: #d4edda; border-right: 4px solid #2ecc71; }
    .card-status-rejected { background: #f8d7da; border-right: 4px solid #e74c3c; }
    .card-status-draft { background: #f5f5f5; border-right: 4px solid #95a5a6; }
    
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
    .company, .amount, .tax-id, .deadline-item {
        font-size: 12px;
        color: #7f8c8d;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .amount {
        color: #27ae60;
        font-weight: bold;
    }
    .deadline-item {
        padding: 2px 8px;
        border-radius: 12px;
    }
    .deadline-normal { background: #d4edda; color: #155724; }
    .deadline-warning { background: #fff3cd; color: #856404; }
    .deadline-danger { background: #f8d7da; color: #721c24; }
    
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
        .tax-id, .deadline-item { display: none; }
    }
    @media (max-width: 992px) {
        .card-info { gap: 10px; }
        .title { min-width: 120px; font-size: 13px; }
        .company { min-width: 80px; }
    }
    @media (max-width: 768px) {
        .tax-card { flex-direction: column; align-items: flex-start; }
        .card-info { width: 100%; }
        .card-actions { width: 100%; justify-content: flex-end; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters-container { flex-direction: column; }
        .filters-container > * { width: 100%; }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <h1 style="color: #2c3e50; margin: 0;">🏛️ سامانه مودیان</h1>
    <div class="btn-create-wrapper" style="text-align: center;">
        <div class="coin-animation-area">
            <div class="coin-icon" id="coinIcon">⚙️</div>
        </div>
        <button type="button" id="createTaxBtn" style="background: linear-gradient(135deg, #f39c12, #e67e22, #d35400); color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
            <i class="fas fa-plus"></i> سند مودیان جدید
        </button>
        <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; margin-left: 10px;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9);">
        <div style="font-size: 14px;">📄 کل اسناد</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_tax); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #2ecc71, #27ae60);">
        <div style="font-size: 14px;">✅ ارسال شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($sent_tax); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
        <div style="font-size: 14px;">⏳ در انتظار</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($pending_tax); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
        <div style="font-size: 14px;">❌ خطا</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($failed_tax); ?></div>
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
            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>در انتظار</option>
            <option value="forwarded" <?php echo $filter_status == 'forwarded' ? 'selected' : ''; ?>>ارسال شده</option>
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
                <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>><?php echo $comp['name']; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="filter-btn" id="applyFilterBtn">اعمال فیلتر</button>
    <a href="tax.php" class="filter-btn reset">پاک کردن</a>
</div>

<?php if (empty($tax_docs)): ?>
    <div class="empty-state">
        <i class="fas fa-cloud-upload-alt"></i>
        <p>هیچ سندی یافت نشد</p>
        <a href="tax-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; margin-top: 15px;">
            <i class="fas fa-plus"></i> ایجاد سند جدید
        </a>
    </div>
<?php else: ?>
    <div class="cards-list">
        <?php foreach ($tax_docs as $doc): 
            $remainingDays = getRemainingDays($doc['approval_deadline']);
            $deadlineClass = getDeadlineClass($remainingDays);
            $deadlineText = '';
            if ($remainingDays !== null) {
                if ($remainingDays <= 0) {
                    $deadlineText = '⛔ مهلت پیگیری به پایان رسیده';
                } elseif ($remainingDays <= 3) {
                    $deadlineText = '⚠️ مهلت پیگیری: ' . $remainingDays . ' روز باقی مانده';
                } else {
                    $deadlineText = '✅ مهلت پیگیری: ' . $remainingDays . ' روز باقی مانده';
                }
            }
        ?>
        <div class="tax-card card-status-<?php echo $doc['status']; ?>">
            <div class="card-info">
                <span class="doc-number">
                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($doc['document_number']); ?>
                </span>
                <span class="title"><?php echo htmlspecialchars($doc['title']); ?></span>
                <span class="company"><i class="fas fa-building"></i> <?php echo htmlspecialchars($doc['company_name'] ?? '-'); ?></span>
                <span class="amount"><i class="fas fa-money-bill-wave"></i> <?php echo number_format($doc['amount']); ?> تومان</span>
                <span class="tax-id"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($doc['tax_id'] ?? '-'); ?></span>
                <?php if ($deadlineText): ?>
                    <span class="deadline-item <?php echo $deadlineClass; ?>"><?php echo $deadlineText; ?></span>
                <?php endif; ?>
            </div>
            <span class="status-badge" style="background: <?php echo $doc['tax_status'] == 'sent' ? '#2ecc71' : ($doc['tax_status'] == 'pending' ? '#f39c12' : '#e74c3c'); ?>;">
                <?php echo $doc['tax_status'] == 'sent' ? 'ارسال شده' : ($doc['tax_status'] == 'pending' ? 'در انتظار' : 'خطا'); ?>
            </span>
            <div class="card-actions">
                <a href="tax-view.php?id=<?php echo $doc['id']; ?>" class="action-btn view" title="مشاهده">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($doc['created_by'] == $_SESSION['user_id'] && $doc['status'] != 'approved' && $doc['status'] != 'rejected'): ?>
                    <a href="tax-edit.php?id=<?php echo $doc['id']; ?>" class="action-btn edit" title="ویرایش">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="tax-delete.php?id=<?php echo $doc['id']; ?>" class="action-btn delete" title="حذف" onclick="return confirm('حذف شود؟')">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
        <a href="?page=1<?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_tax_status ? "&tax_status=$filter_tax_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;"><<</a>
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_tax_status ? "&tax_status=$filter_tax_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;"><</a>
        <?php endif; ?>
        <span style="padding: 8px 15px; background: #2c3e50; color: white; border-radius: 5px;"><?php echo $page; ?></span>
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_tax_status ? "&tax_status=$filter_tax_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">></a>
        <?php endif; ?>
        <a href="?page=<?php echo $total_pages; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $filter_tax_status ? "&tax_status=$filter_tax_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">>></a>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
document.getElementById('createTaxBtn').addEventListener('click', function(e) {
    e.preventDefault();
    var coinIcon = document.getElementById('coinIcon');
    if (coinIcon) {
        coinIcon.classList.add('coin-spin');
        setTimeout(function() { window.location.href = 'tax-create.php'; }, 800);
    } else {
        window.location.href = 'tax-create.php';
    }
});

document.getElementById('applyFilterBtn')?.addEventListener('click', function() {
    let search = document.getElementById('searchInput').value;
    let status = document.getElementById('statusFilter').value;
    let taxStatus = document.getElementById('taxStatusFilter').value;
    let company = document.getElementById('companyFilter').value;
    let url = 'tax.php?';
    if (search) url += 'search=' + encodeURIComponent(search);
    if (status) url += (search ? '&' : '') + 'status=' + encodeURIComponent(status);
    if (taxStatus) url += (search || status ? '&' : '') + 'tax_status=' + encodeURIComponent(taxStatus);
    if (company) url += (search || status || taxStatus ? '&' : '') + 'company=' + encodeURIComponent(company);
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