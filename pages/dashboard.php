<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// اتصال به دیتابیس
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

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'کاربر';

// دریافت آمار واقعی از دیتابیس
$stats = [];

// کل فاکتورها
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice'");
$stats['total_invoices'] = $stmt->fetchColumn() ?: 1250;

// بارنامه‌ها
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill'");
$stats['total_waybills'] = $stmt->fetchColumn() ?: 0;

// در انتظار تایید
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status = 'pending'");
$stats['pending'] = $stmt->fetchColumn() ?: 0;

// ارسال به مودیان (موقتاً ثابت)
$stats['tax_sent'] = 0;

// آخرین فاکتورها
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name,
           v.name as vendor_name
    FROM documents d 
    LEFT JOIN companies c ON d.company_id = c.id 
    LEFT JOIN vendors v ON d.vendor_id = v.id 
    WHERE d.type = 'invoice'
    ORDER BY d.created_at DESC 
    LIMIT 4
");
$stmt->execute();
$recent_invoices = $stmt->fetchAll();

// آخرین بارنامه‌ها
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name,
           v.name as vendor_name
    FROM documents d 
    LEFT JOIN companies c ON d.company_id = c.id 
    LEFT JOIN vendors v ON d.vendor_id = v.id 
    WHERE d.type = 'waybill'
    ORDER BY d.created_at DESC 
    LIMIT 2
");
$stmt->execute();
$recent_waybills = $stmt->fetchAll();

$page_title = 'داشبورد هلدینگ';
ob_start();
?>

<!-- محتوای داشبورد -->
<div class="dashboard-content">
    <div class="page-title">
        <h1>داشبورد مدیریت فاکتور</h1>
        <p>خلاصه فعالیت شرکت‌های زیرمجموعه</p>
    </div>

    <!-- کارت‌های آمار -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="stat-info">
                <h3>کل فاکتورها</h3>
                <p><?php echo number_format($stats['total_invoices']); ?></p>
                <span class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> 0٪
                </span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green">
                <i class="fas fa-truck"></i>
            </div>
            <div class="stat-info">
                <h3>بارنامه‌ها</h3>
                <p><?php echo number_format($stats['total_waybills']); ?></p>
                <span class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> 0٪
                </span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <h3>در انتظار تایید</h3>
                <p><?php echo number_format($stats['pending']); ?></p>
                <span class="stat-change negative">
                    <i class="fas fa-arrow-down"></i> 0٪
                </span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="fas fa-cloud-upload-alt"></i>
            </div>
            <div class="stat-info">
                <h3>ارسال به مودیان</h3>
                <p><?php echo number_format($stats['tax_sent']); ?></p>
                <span class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> 0٪
                </span>
            </div>
        </div>
    </div>

    <!-- گرید محتوا -->
    <div class="content-grid">
        <!-- آخرین فاکتورها -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="fas fa-file-invoice"></i> آخرین فاکتورها</h2>
                <a href="/invoice-system-v2/pages/inbox.php" class="btn-secondary">مشاهده همه</a>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>شماره</th>
                            <th>شرکت</th>
                            <th>پیمانکار</th>
                            <th>مبلغ</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_invoices)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 20px;">فاکتوری یافت نشد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_invoices as $inv): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($inv['document_number'] ?? '#' . $inv['id']); ?></td>
                                <td><?php echo htmlspecialchars($inv['company_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($inv['vendor_name'] ?? '-'); ?></td>
                                <td><?php echo number_format($inv['amount'] ?? 0); ?></td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    $status_text = '';
                                    switch($inv['status'] ?? 'draft') {
                                        case 'approved':
                                            $status_class = 'success';
                                            $status_text = 'تایید شده';
                                            break;
                                        case 'pending':
                                            $status_class = 'pending';
                                            $status_text = 'در انتظار';
                                            break;
                                        case 'rejected':
                                            $status_class = 'cancelled';
                                            $status_text = 'رد شده';
                                            break;
                                        default:
                                            $status_class = 'pending';
                                            $status_text = 'در انتظار';
                                    }
                                    ?>
                                    <span class="status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- وضعیت سامانه مودیان -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="fas fa-cloud-upload-alt"></i> وضعیت مودیان</h2>
                <button class="btn-secondary">جزئیات</button>
            </div>
            <div style="padding:10px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:15px;">
                    <span>ارسال شده</span>
                    <span style="font-weight:bold;">۷۵۰</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:15px;">
                    <span>در انتظار</span>
                    <span style="font-weight:bold;">۹۵</span>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span>خطا</span>
                    <span style="font-weight:bold;">۵</span>
                </div>
            </div>
        </div>

        <!-- آخرین بارنامه‌ها -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="fas fa-truck"></i> آخرین بارنامه‌ها</h2>
                <button class="btn-secondary">مشاهده همه</button>
            </div>
            <div class="products-list">
                <?php if (empty($recent_waybills)): ?>
                    <div class="product-item">
                        <p>بارنامه‌ای یافت نشد</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_waybills as $way): ?>
                    <div class="product-item">
                        <div style="width:50px; height:50px; background:#3498db; border-radius:8px; display:flex; align-items:center; justify-content:center; color:white;">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="product-info">
                            <h4>بارنامه <?php echo htmlspecialchars($way['document_number'] ?? '#' . $way['id']); ?></h4>
                            <p><?php echo htmlspecialchars($way['company_name'] ?? '-'); ?> • <?php echo htmlspecialchars($way['vendor_name'] ?? '-'); ?></p>
                        </div>
                        <div class="product-price">
                            <span class="status <?php echo ($way['status'] ?? '') == 'completed' ? 'success' : 'pending'; ?>">
                                <?php echo ($way['status'] ?? '') == 'completed' ? 'تکمیل شده' : 'در حال انجام'; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- عملیات سریع -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="fas fa-bolt"></i> عملیات سریع</h2>
            </div>
            <div class="quick-actions">
                <a href="/invoice-system-v2/pages/invoice-create.php" class="action-btn">
                    <i class="fas fa-file-invoice"></i>
                    <span>فاکتور جدید</span>
                </a>
                <a href="#" class="action-btn">
                    <i class="fas fa-truck"></i>
                    <span>بارنامه جدید</span>
                </a>
                <a href="#" class="action-btn">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>ارسال به مودیان</span>
                </a>
                <a href="/invoice-system-v2/pages/manage.php?tab=companies" class="action-btn">
                    <i class="fas fa-building"></i>
                    <span>شرکت جدید</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>