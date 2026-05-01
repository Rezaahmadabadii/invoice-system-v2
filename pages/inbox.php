<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role_ids = $_SESSION['user_role_ids'] ?? [];

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

// دریافت لیست شرکت‌ها برای فیلتر
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// فیلترها
$filter_company = $_GET['company'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

// شرط نمایش فاکتورها بر اساس دسترسی کاربر
$sql = "
    SELECT d.*, 
           c.name as company_name,
           c.short_name,
           v.name as vendor_name,
           u.full_name as creator_name,
           holder_dep.name as holder_department_name,
           holder_user.full_name as holder_user_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
    LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
    WHERE d.type = 'invoice'
    AND (
        d.created_by = ?
        OR d.current_holder_user_id = ?
        OR d.current_holder_department_id IN (" . implode(',', array_fill(0, count($user_role_ids), '?')) . ")
    )
";

$params = array_merge([$user_id, $user_id], $user_role_ids);

if ($filter_company) {
    $sql .= " AND d.company_id = ?";
    $params[] = $filter_company;
}
if ($filter_status) {
    $sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($search) {
    $sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY d.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// آمار کلی
$total_invoices = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice'")->fetchColumn();
$approved_invoices = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status = 'approved'")->fetchColumn();
$pending_invoices = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status IN ('pending', 'forwarded')")->fetchColumn();
$rejected_invoices = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status = 'rejected'")->fetchColumn();

$status_texts = [
    'draft' => '📝 پیش‌نویس',
    'pending' => '⏳ در انتظار',
    'forwarded' => '📨 ارسال شده',
    'viewed' => '👁️ مشاهده شده',
    'under_review' => '🔍 در حال بررسی',
    'approved' => '✅ تایید شده',
    'rejected' => '❌ رد شده',
    'completed' => '✔️ بسته شده'
];

$status_colors = [
    'draft' => '#95a5a6',
    'pending' => '#fef9e6',
    'forwarded' => '#fff3cd',
    'viewed' => '#e8f4fd',
    'under_review' => '#fef5e8',
    'approved' => '#d4edda',
    'rejected' => '#f8d7da',
    'completed' => '#c3e6cb'
];

$page_title = 'همه فاکتورها';
ob_start();
?>

<style>
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
    .invoice-card {
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 20px;
        flex-wrap: wrap;
        gap: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: all 0.2s ease;
        border: 1px solid #eef2f5;
    }
    .invoice-card:hover {
        transform: translateX(-3px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.1);
    }
    
    /* رنگ پس زمینه کارت بر اساس وضعیت */
    .card-status-draft { background: #f5f5f5; border-right: 4px solid #95a5a6; }
    .card-status-pending { background: #fef9e6; border-right: 4px solid #f39c12; }
    .card-status-forwarded { background: #fff3cd; border-right: 4px solid #f39c12; }
    .card-status-viewed { background: #e8f4fd; border-right: 4px solid #3498db; }
    .card-status-under_review { background: #fef5e8; border-right: 4px solid #f39c12; }
    .card-status-approved { background: #d4edda; border-right: 4px solid #2ecc71; }
    .card-status-rejected { background: #f8d7da; border-right: 4px solid #e74c3c; }
    .card-status-completed { background: #c3e6cb; border-right: 4px solid #27ae60; }
    
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
    .company, .vendor, .amount, .holder {
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
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        min-width: 100px;
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
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 20px;
    }
    .pagination a {
        background: #3498db;
        color: white;
        padding: 8px 15px;
        border-radius: 5px;
        text-decoration: none;
    }
    .pagination span {
        padding: 8px 15px;
        background: #2c3e50;
        color: white;
        border-radius: 5px;
    }
    
    @media (max-width: 992px) {
        .card-info { gap: 12px; }
        .vendor, .amount, .holder { display: none; }
        .title { min-width: 120px; }
    }
    @media (max-width: 768px) {
        .invoice-card { flex-direction: column; align-items: flex-start; }
        .card-info { width: 100%; }
        .card-actions { width: 100%; justify-content: flex-end; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters-container { flex-direction: column; }
        .filters-container > * { width: 100%; }
    }
</style>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="margin: 0;">📄 همه فاکتورها</h1>
    <a href="invoice-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
        <i class="fas fa-plus"></i> فاکتور جدید
    </a>
</div>

<div class="stats-grid">
    <div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9);">
        <div style="font-size: 14px;">📋 کل فاکتورها</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_invoices); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #2ecc71, #27ae60);">
        <div style="font-size: 14px;">✅ تایید شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($approved_invoices); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
        <div style="font-size: 14px;">⏳ در انتظار</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($pending_invoices); ?></div>
    </div>
    <div class="stat-card" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
        <div style="font-size: 14px;">❌ رد شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($rejected_invoices); ?></div>
    </div>
</div>

<div class="filters-container">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="جستجوی شماره، عنوان، شرکت..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="status-filter">
        <select id="statusFilter">
            <option value="">🔍 همه وضعیت‌ها</option>
            <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>📝 پیش‌نویس</option>
            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>⏳ در انتظار</option>
            <option value="forwarded" <?php echo $filter_status == 'forwarded' ? 'selected' : ''; ?>>📨 ارسال شده</option>
            <option value="viewed" <?php echo $filter_status == 'viewed' ? 'selected' : ''; ?>>👁️ مشاهده شده</option>
            <option value="under_review" <?php echo $filter_status == 'under_review' ? 'selected' : ''; ?>>🔍 در حال بررسی</option>
            <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>✅ تایید شده</option>
            <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>❌ رد شده</option>
        </select>
    </div>
    <div class="status-filter">
        <select id="companyFilter">
            <option value="">🏢 همه شرکت‌ها</option>
            <?php foreach ($companies as $comp): ?>
                <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>><?php echo $comp['name']; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="filter-btn" id="applyFilterBtn">✅ اعمال فیلتر</button>
    <a href="inbox.php" class="filter-btn reset">🗑️ پاک کردن</a>
</div>

<?php if (empty($invoices)): ?>
    <div class="empty-state">
        <i class="fas fa-file-invoice"></i>
        <p>📄 هیچ فاکتوری یافت نشد</p>
        <a href="invoice-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; margin-top: 15px;">
            <i class="fas fa-plus"></i> ایجاد فاکتور جدید
        </a>
    </div>
<?php else: ?>
    <div class="cards-list">
        <?php foreach ($invoices as $inv): 
            $status_display = $status_texts[$inv['status']] ?? $inv['status'];
            $holder_display = '';
            if ($inv['holder_user_name']) {
                $holder_display = '👤 ' . htmlspecialchars($inv['holder_user_name']);
            } elseif ($inv['holder_department_name']) {
                $holder_display = '🏢 ' . htmlspecialchars($inv['holder_department_name']);
            } else {
                $holder_display = '-';
            }
            
            // بررسی وجود اقدام در تاریخچه
            $action_stmt = $pdo->prepare("SELECT COUNT(*) FROM forwarding_history WHERE document_id = ?");
            $action_stmt->execute([$inv['id']]);
            $action_count = $action_stmt->fetchColumn();
            
            // شرط: ایجادکننده باشد و هیچ اقدامی روی فاکتور نشده باشد
            $can_edit_delete = ($inv['created_by'] == $user_id && $action_count == 0);
        ?>
        <div class="invoice-card card-status-<?php echo $inv['status']; ?>">
            <div class="card-info">
                <span class="doc-number">
                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($inv['document_number']); ?>
                </span>
                <span class="title"><?php echo htmlspecialchars($inv['title']); ?></span>
                <span class="company"><i class="fas fa-building"></i> <?php echo htmlspecialchars($inv['company_name'] ?? '-'); ?></span>
                <span class="vendor"><i class="fas fa-store"></i> <?php echo htmlspecialchars($inv['vendor_name'] ?? '-'); ?></span>
                <span class="amount"><i class="fas fa-money-bill-wave"></i> <?php echo number_format($inv['amount']); ?> تومان</span>
                <span class="holder">📌 <?php echo $holder_display; ?></span>
            </div>
            <span class="status-badge">
                <?php echo $status_display; ?>
            </span>
            <div class="card-actions">
                <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" class="action-btn view" title="مشاهده">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($can_edit_delete): ?>
                    <a href="invoice-edit.php?id=<?php echo $inv['id']; ?>" class="action-btn edit" title="ویرایش">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="invoice-delete.php?id=<?php echo $inv['id']; ?>" class="action-btn delete" title="حذف" onclick="return confirm('حذف شود؟')">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
// فیلترها
document.getElementById('applyFilterBtn')?.addEventListener('click', function() {
    let search = document.getElementById('searchInput').value;
    let status = document.getElementById('statusFilter').value;
    let company = document.getElementById('companyFilter').value;
    let url = 'inbox.php?';
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