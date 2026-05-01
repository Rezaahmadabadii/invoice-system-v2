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

// دریافت فیلترها
$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? '';

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

if (!empty($filter_status)) {
    $sql .= " AND d.status = ?";
    $params[] = $filter_status;
}

if (!empty($search)) {
    $sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// وضعیت‌های مجاز برای ویرایش/حذف توسط ایجادکننده
$editable_statuses = ['draft', 'forwarded']; // فقط زمانی که هنوز مشاهده نشده باشد

$page_title = 'همه فاکتورها';
ob_start();
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }
    .page-header h1 {
        margin: 0;
        font-size: 24px;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
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
    
    /* کارت‌ها */
    .invoices-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 20px;
    }
    .invoice-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        border: 1px solid #eef2f5;
    }
    .invoice-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    }
    .card-header {
        background: linear-gradient(135deg, #f8f9fa, #fff);
        padding: 15px 20px;
        border-bottom: 1px solid #eef2f5;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .doc-number {
        font-weight: bold;
        font-size: 16px;
        color: #2c3e50;
        background: #eef2f5;
        padding: 4px 12px;
        border-radius: 20px;
    }
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    .status-draft { background: #95a5a6; color: white; }
    .status-forwarded { background: #f39c12; color: white; }
    .status-viewed { background: #3498db; color: white; }
    .status-under_review { background: #9b59b6; color: white; }
    .status-approved { background: #27ae60; color: white; }
    .status-rejected { background: #e74c3c; color: white; }
    .status-completed { background: #1abc9c; color: white; }
    
    .card-body {
        padding: 15px 20px;
    }
    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .card-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 12px;
    }
    .detail-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #7f8c8d;
    }
    .detail-item i {
        width: 20px;
        color: #3498db;
    }
    .detail-item strong {
        color: #2c3e50;
        font-weight: 500;
    }
    .holder-info {
        background: #e8f4f8;
        border-radius: 10px;
        padding: 10px;
        margin-top: 12px;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .card-actions {
        display: flex;
        gap: 8px;
        padding: 12px 20px;
        border-top: 1px solid #eef2f5;
        background: #fafbfc;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 13px;
        text-decoration: none;
        transition: all 0.2s;
    }
    .action-btn.view {
        background: #3498db;
        color: white;
    }
    .action-btn.view:hover {
        background: #2980b9;
    }
    .action-btn.edit {
        background: #f39c12;
        color: white;
    }
    .action-btn.edit:hover {
        background: #e67e22;
    }
    .action-btn.delete {
        background: #e74c3c;
        color: white;
    }
    .action-btn.delete:hover {
        background: #c0392b;
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
    
    @media (max-width: 768px) {
        .invoices-grid {
            grid-template-columns: 1fr;
        }
        .filters-container {
            flex-direction: column;
        }
        .filters-container > * {
            width: 100%;
        }
    }
</style>

<div class="page-header">
    <h1>
        <i class="fas fa-file-invoice" style="color: #3498db;"></i>
        همه فاکتورها
        <span style="font-size: 14px; background: #eef2f5; padding: 2px 10px; border-radius: 20px; color: #7f8c8d;">
            <?php echo count($invoices); ?> فاکتور
        </span>
    </h1>
    <a href="invoice-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
        <i class="fas fa-plus"></i> فاکتور جدید
    </a>
</div>

<!-- =============== کد نمایش پیام‌ها  =============== -->
<?php if (isset($_SESSION['message'])): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-right: 4px solid #27ae60;">
        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
    </div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-right: 4px solid #e74c3c;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>
<!-- ============================================================= -->
<!-- فیلترها -->
<div class="filters-container">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="جستجوی شماره، عنوان یا شرکت..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="status-filter">
        <select id="statusFilter">
            <option value="">همه وضعیت‌ها</option>
            <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>پیش‌نویس</option>
            <option value="forwarded" <?php echo $filter_status == 'forwarded' ? 'selected' : ''; ?>>ارسال شده</option>
            <option value="viewed" <?php echo $filter_status == 'viewed' ? 'selected' : ''; ?>>مشاهده شده</option>
            <option value="under_review" <?php echo $filter_status == 'under_review' ? 'selected' : ''; ?>>در حال بررسی</option>
            <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>تایید شده</option>
            <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>رد شده</option>
            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>بسته شده</option>
        </select>
    </div>
    <button class="filter-btn" id="applyFilterBtn">اعمال فیلتر</button>
    <a href="inbox.php" class="filter-btn reset">پاک کردن</a>
</div>

<!-- لیست فاکتورها به صورت کارت -->
<div class="invoices-grid">
    <?php if (empty($invoices)): ?>
        <div class="empty-state" style="grid-column: 1/-1;">
            <i class="fas fa-inbox"></i>
            <p>هیچ فاکتوری یافت نشد</p>
            <a href="invoice-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; margin-top: 15px;">
                <i class="fas fa-plus"></i> ایجاد فاکتور جدید
            </a>
        </div>
    <?php else: ?>
        <?php foreach ($invoices as $inv): 
            $status_class = 'status-' . ($inv['status'] ?? 'draft');
            $status_text = [
                'draft' => 'پیش‌نویس',
                'forwarded' => 'ارسال شده',
                'viewed' => 'مشاهده شده',
                'under_review' => 'در حال بررسی',
                'approved' => 'تایید شده',
                'rejected' => 'رد شده',
                'completed' => 'بسته شده'
            ][$inv['status']] ?? $inv['status'];
            
            // شرط نمایش دکمه ویرایش/حذف: فقط اگر کاربر ایجادکننده باشد و فاکتور هنوز مشاهده نشده باشد
            $can_edit = ($inv['created_by'] == $user_id && in_array($inv['status'], $editable_statuses));
            // ادمین می‌تواند در هر شرایطی که فاکتور بسته نشده باشد ویرایش/حذف کند (اختیاری)
            $is_admin = in_array('admin', $_SESSION['user_roles'] ?? []) || in_array('super_admin', $_SESSION['user_roles'] ?? []);
        ?>
        <div class="invoice-card">
            <div class="card-header">
                <span class="doc-number">
                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($inv['document_number']); ?>
                </span>
                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
            </div>
            <div class="card-body">
                <div class="card-title">
                    <i class="fas fa-tag" style="color: #3498db;"></i>
                    <?php echo htmlspecialchars($inv['title']); ?>
                </div>
                <div class="card-details">
                    <div class="detail-item">
                        <i class="fas fa-building"></i>
                        <span><?php echo htmlspecialchars($inv['company_name'] ?? '-'); ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-store"></i>
                        <span><?php echo htmlspecialchars($inv['vendor_name'] ?? '-'); ?></span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-money-bill-wave"></i>
                        <strong><?php echo number_format($inv['amount']); ?></strong> <span>تومان</span>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-calendar"></i>
                        <span><?php echo jdate('Y/m/d', strtotime($inv['created_at'])); ?></span>
                    </div>
                </div>
                <div class="holder-info">
                    <i class="fas fa-user-check"></i>
                    <span>در دست:</span>
                    <strong>
                        <?php
                        if ($inv['holder_user_name']) {
                            echo htmlspecialchars($inv['holder_user_name']);
                        } elseif ($inv['holder_department_name']) {
                            echo htmlspecialchars($inv['holder_department_name']) . ' (بخش)';
                        } else {
                            echo '-';
                        }
                        ?>
                    </strong>
                </div>
            </div>
            <div class="card-actions">
                <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" class="action-btn view">
                    <i class="fas fa-eye"></i> مشاهده
                </a>
                <?php if ($can_edit || $is_admin): ?>
                    <a href="invoice-edit.php?id=<?php echo $inv['id']; ?>" class="action-btn edit">
                        <i class="fas fa-edit"></i> ویرایش
                    </a>
                    <a href="invoice-delete.php?id=<?php echo $inv['id']; ?>" class="action-btn delete" onclick="return confirm('آیا از حذف این فاکتور اطمینان دارید؟')">
                        <i class="fas fa-trash-alt"></i> حذف
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// فیلتر با جاوااسکریپت
document.getElementById('applyFilterBtn').addEventListener('click', function() {
    let search = document.getElementById('searchInput').value;
    let status = document.getElementById('statusFilter').value;
    let url = 'inbox.php?';
    if (search) url += 'search=' + encodeURIComponent(search);
    if (status) url += (search ? '&' : '') + 'status=' + encodeURIComponent(status);
    window.location.href = url;
});

// Enter در جستجو
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('applyFilterBtn').click();
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>