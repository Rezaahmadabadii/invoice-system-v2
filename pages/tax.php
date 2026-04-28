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
$filter_status = $_GET['status'] ?? '';
$filter_tax_status = $_GET['tax_status'] ?? '';
$search = $_GET['search'] ?? '';

// شرط نمایش اسناد مودیان (بر اساس دسترسی کاربر)
$sql = "
    SELECT d.*, 
           c.name as company_name,
           u.full_name as creator_name,
           holder_dep.name as holder_department_name,
           holder_user.full_name as holder_user_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
    LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
    WHERE d.type = 'tax'
    AND (
        d.created_by = ?
        OR d.current_holder_user_id = ?
        OR d.current_holder_department_id IN (" . implode(',', array_fill(0, count($user_role_ids), '?')) . ")
    )
";

$params = array_merge([$user_id, $user_id], $user_role_ids);

if ($filter_status) {
    $sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($filter_tax_status) {
    $sql .= " AND d.tax_status = ?";
    $params[] = $filter_tax_status;
}
if ($search) {
    $sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.tax_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tax_docs = $stmt->fetchAll();

$page_title = 'سامانه مودیان';
ob_start();
?>

<style>
    /* استایل برای انیمیشن لودینگ/چرخ دنده */
    .loading-animation-area {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        overflow: visible;
        min-height: 80px;
    }
    .loading-icon {
        font-size: 56px;
        display: inline-block;
        transition: opacity 0.6s ease-out;
    }
    .loading-spin {
        animation: spinAndFade 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
    }
    @keyframes spinAndFade {
        0% {
            transform: rotate(0deg);
            opacity: 1;
        }
        100% {
            transform: rotate(360deg);
            opacity: 0;
        }
    }
    .btn-create-wrapper {
        text-align: center;
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
    .btn-tax-primary i {
        margin-left: 8px;
    }
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>🏛️ سامانه مودیان (صورتحساب‌های مالیاتی)</h1>
    <div class="btn-create-wrapper">
        <!-- آیکون لودینگ/چرخ دنده بالای دکمه -->
        <div class="loading-animation-area">
            <div class="loading-icon" id="loadingIcon">⚙️</div>
        </div>
        <!-- دکمه سند مودیان جدید -->
        <button type="button" id="createTaxBtn" class="btn-tax-primary">
            <i class="fas fa-plus"></i> سند مودیان جدید
        </button>
    </div>
</div>

<!-- کارت‌های آمار -->
<?php
$total = count($tax_docs);
$sent = count(array_filter($tax_docs, fn($d) => $d['tax_status'] == 'sent'));
$pending = count(array_filter($tax_docs, fn($d) => $d['tax_status'] == 'pending'));
$failed = count(array_filter($tax_docs, fn($d) => $d['tax_status'] == 'failed'));
?>
<div style="display: grid; grid-template-columns: repeat(4,1fr); gap:20px; margin-bottom:30px;">
    <div class="stat-card"><div class="stat-icon blue">📄</div><div class="stat-info"><h3>کل اسناد</h3><p><?php echo $total; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon green">✅</div><div class="stat-info"><h3>ارسال شده</h3><p><?php echo $sent; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon orange">⏳</div><div class="stat-info"><h3>در انتظار</h3><p><?php echo $pending; ?></p></div></div>
    <div class="stat-card"><div class="stat-icon red">❌</div><div class="stat-info"><h3>خطا</h3><p><?php echo $failed; ?></p></div></div>
</div>

<!-- فیلترها -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(4,1fr); gap:15px;">
        <select name="status">
            <option value="">همه وضعیت‌ها</option>
            <option value="draft" <?php echo $filter_status=='draft'?'selected':''; ?>>پیش‌نویس</option>
            <option value="forwarded" <?php echo $filter_status=='forwarded'?'selected':''; ?>>ارسال شده</option>
            <option value="approved" <?php echo $filter_status=='approved'?'selected':''; ?>>تایید شده</option>
            <option value="rejected" <?php echo $filter_status=='rejected'?'selected':''; ?>>رد شده</option>
        </select>
        <select name="tax_status">
            <option value="">وضعیت ارسال</option>
            <option value="pending" <?php echo $filter_tax_status=='pending'?'selected':''; ?>>در انتظار ارسال</option>
            <option value="sent" <?php echo $filter_tax_status=='sent'?'selected':''; ?>>ارسال شده</option>
            <option value="failed" <?php echo $filter_tax_status=='failed'?'selected':''; ?>>خطا</option>
        </select>
        <input type="text" name="search" placeholder="جستجو..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-secondary">اعمال فیلتر</button>
    </form>
</div>

<!-- جدول اسناد مودیان -->
<div style="background: white; border-radius: 10px; padding: 20px; overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>شماره سند</th><th>عنوان</th><th>شرکت</th><th>شناسه مالیاتی</th>
                <th>مبلغ</th><th>تاریخ</th><th>وضعیت سند</th><th>وضعیت ارسال</th><th>در دست</th><th>عملیات</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($tax_docs)): ?>
            <tr><td colspan="10" style="text-align:center;">هیچ سندی یافت نشد</td></tr>
        <?php else: foreach($tax_docs as $doc): ?>
            <tr>
                <td><?php echo htmlspecialchars($doc['document_number']); ?></td>
                <td><?php echo htmlspecialchars($doc['title']); ?></td>
                <td><?php echo htmlspecialchars($doc['company_name']??'-'); ?></td>
                <td><?php echo htmlspecialchars($doc['tax_id']??'-'); ?></td>
                <td><?php echo number_format($doc['amount']); ?> تومان</td>
                <td><?php echo jdate('Y/m/d', strtotime($doc['created_at'])); ?></td>
                <td><?php echo $doc['status']; ?></td>
                <td>
                    <?php if($doc['tax_status']=='sent'): ?>
                        <span class="badge success">ارسال شده</span>
                    <?php elseif($doc['tax_status']=='pending'): ?>
                        <span class="badge warning">در انتظار</span>
                    <?php elseif($doc['tax_status']=='failed'): ?>
                        <span class="badge danger">خطا</span>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($doc['holder_user_name']??$doc['holder_department_name']??'-'); ?></td>
                <td>
                    <a href="tax-view.php?id=<?php echo $doc['id']; ?>" class="btn-icon">مشاهده</a>
                    <?php if($doc['status']=='draft' && $doc['created_by']==$user_id): ?>
                        <a href="tax-edit.php?id=<?php echo $doc['id']; ?>">ویرایش</a>
                        <a href="tax-delete.php?id=<?php echo $doc['id']; ?>" onclick="return confirm('حذف شود؟')">حذف</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
    // تابع انیمیشن چرخ دنده (چرخش درجا + محو شدن)
    function animateIconAndRedirect() {
        var loadingIcon = document.getElementById('loadingIcon');
        
        if (loadingIcon) {
            if (loadingIcon.classList.contains('loading-spin')) {
                return;
            }
            
            loadingIcon.classList.add('loading-spin');
            
            setTimeout(function() {
                window.location.href = 'tax-create.php';
            }, 800);
        } else {
            window.location.href = 'tax-create.php';
        }
    }
    
    var createBtn = document.getElementById('createTaxBtn');
    if (createBtn) {
        createBtn.addEventListener('click', function(e) {
            e.preventDefault();
            animateIconAndRedirect();
        });
    }
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>