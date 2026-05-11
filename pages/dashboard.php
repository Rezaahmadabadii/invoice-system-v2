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

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'کاربر';
$full_name = $_SESSION['full_name'] ?? $username;

// دریافت آمار واقعی از دیتابیس
$stats = [];

// کل فاکتورها
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice'");
$stats['total_invoices'] = $stmt->fetchColumn() ?: 0;

// کل بارنامه‌ها
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill'");
$stats['total_waybills'] = $stmt->fetchColumn() ?: 0;

// کل اسناد مالیاتی
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'tax'");
$stats['total_tax'] = $stmt->fetchColumn() ?: 0;

// در انتظار تایید (فاکتورها)
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status = 'pending'");
$stats['pending'] = $stmt->fetchColumn() ?: 0;

// تایید شده
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status = 'approved'");
$stats['approved'] = $stmt->fetchColumn() ?: 0;

// ارسال به مودیان
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'tax' AND tax_status = 'sent'");
$stats['tax_sent'] = $stmt->fetchColumn() ?: 0;

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
    LIMIT 5
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
    LIMIT 5
");
$stmt->execute();
$recent_waybills = $stmt->fetchAll();

// آخرین اسناد مالیاتی
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name
    FROM documents d 
    LEFT JOIN companies c ON d.company_id = c.id 
    WHERE d.type = 'tax'
    ORDER BY d.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_tax = $stmt->fetchAll();

// تاریخ شمسی امروز
$today_date = jdate('l، j F Y');
$today_number = jdate('Y/m/d');

$page_title = 'داشبورد هلدینگ';
ob_start();
?>

<style>
    /* ========== استایل اختصاصی داشبورد ========== */
    
    .welcome-header {
        margin-bottom: 28px;
    }
    
    .welcome-header h1 {
        font-size: 26px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 6px;
    }
    
    .welcome-header p {
        color: #64748b;
        font-size: 14px;
    }
    
    .date-badge {
        display: inline-block;
        background: linear-gradient(135deg, #e0f2fe, #dbeafe);
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 13px;
        color: #1e40af;
        margin-top: 8px;
    }
    
    /* گرید آمار */
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
        gap: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        transition: all 0.3s ease;
        border: 1px solid #f1f5f9;
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.08);
        border-color: #e2e8f0;
    }
    
    .stat-icon {
        width: 55px;
        height: 55px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: white;
    }
    
    .stat-icon.blue { background: linear-gradient(145deg, #3b82f6, #2563eb); }
    .stat-icon.green { background: linear-gradient(145deg, #10b981, #059669); }
    .stat-icon.orange { background: linear-gradient(145deg, #f59e0b, #d97706); }
    .stat-icon.purple { background: linear-gradient(145deg, #8b5cf6, #7c3aed); }
    
    .stat-info h3 {
        font-size: 13px;
        font-weight: 500;
        color: #64748b;
        margin-bottom: 6px;
    }
    
    .stat-info .value {
        font-size: 28px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 5px;
    }
    
    .stat-change {
        font-size: 11px;
        display: flex;
        align-items: center;
        gap: 3px;
    }
    
    .stat-change.positive { color: #10b981; }
    .stat-change.negative { color: #ef4444; }
    
    /* گرید محتوا */
    .content-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
    }
    
    /* کارت‌های شیشه‌ای */
    .glass-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(8px);
        border-radius: 24px;
        border: 1px solid rgba(255, 255, 255, 0.5);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .glass-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 18px 22px;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .card-header h2 {
        font-size: 16px;
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .card-header h2 i {
        color: #3b82f6;
        font-size: 18px;
    }
    
    .btn-link {
        background: #f1f5f9;
        color: #3b82f6;
        padding: 6px 14px;
        border-radius: 30px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .btn-link:hover {
        background: #3b82f6;
        color: white;
    }
    
    /* جدول */
    .table-container {
        overflow-x: auto;
        padding: 0 20px 18px 20px;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .data-table th {
        text-align: right;
        padding: 12px 8px;
        color: #64748b;
        font-weight: 500;
        font-size: 12px;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .data-table td {
        padding: 12px 8px;
        color: #334155;
        font-size: 13px;
        border-bottom: 1px solid #f8fafc;
    }
    
    .data-table tr:last-child td {
        border-bottom: none;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 11px;
        font-weight: 500;
    }
    
    .status-badge.success { background: #d1fae5; color: #059669; }
    .status-badge.pending { background: #fed7aa; color: #c2410c; }
    .status-badge.warning { background: #fef3c7; color: #d97706; }
    .status-badge.info { background: #dbeafe; color: #2563eb; }
    
    /* لیست آیتم‌ها */
    .items-list {
        padding: 8px 20px 18px 20px;
    }
    
    .item-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .item-row:last-child {
        border-bottom: none;
    }
    
    .item-icon {
        width: 44px;
        height: 44px;
        background: #eef2ff;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #3b82f6;
        font-size: 20px;
    }
    
    .item-info {
        flex: 1;
    }
    
    .item-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
    }
    
    .item-info p {
        font-size: 11px;
        color: #64748b;
    }
    
    /* عملیات سریع */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        padding: 18px 22px;
    }
    
    .action-card {
        background: #f8fafc;
        border-radius: 16px;
        padding: 14px;
        text-align: center;
        text-decoration: none;
        transition: all 0.2s;
        border: 1px solid #f1f5f9;
    }
    
    .action-card:hover {
        background: linear-gradient(145deg, #3b82f6, #2563eb);
        transform: translateY(-2px);
    }
    
    .action-card:hover i,
    .action-card:hover span {
        color: white;
    }
    
    .action-card i {
        font-size: 24px;
        color: #3b82f6;
        margin-bottom: 8px;
        display: block;
    }
    
    .action-card span {
        font-size: 12px;
        font-weight: 500;
        color: #334155;
    }
    
    /* وضعیت سامانه مودیان */
    .tax-stats {
        padding: 16px 22px;
    }
    
    .tax-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .tax-item:last-child {
        border-bottom: none;
    }
    
    .tax-label {
        font-size: 13px;
        color: #475569;
    }
    
    .tax-value {
        font-size: 18px;
        font-weight: 700;
        color: #1e293b;
    }
    
    .tax-progress {
        height: 6px;
        background: #e2e8f0;
        border-radius: 10px;
        margin-top: 8px;
        overflow: hidden;
    }
    
    .tax-progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #3b82f6, #8b5cf6);
        border-radius: 10px;
        width: 0%;
    }
    
    @media (max-width: 1000px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .content-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .quick-actions {
            grid-template-columns: 1fr;
        }
    }
</style>

    <!-- هدر خوش‌آمدگویی -->
    <div class="welcome-header">
        <h1>👋 خوش آمدید، <?php echo htmlspecialchars($full_name); ?></h1>
        <p>خلاصه فعالیت شرکت‌های زیرمجموعه</p>
        <div class="date-badge">
            <i class="fas fa-calendar-alt"></i> <?php echo $today_date; ?> - <?php echo $today_number; ?>
        </div>
    </div>

    <!-- کارت‌های آمار -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-file-invoice"></i></div>
            <div class="stat-info">
                <h3>کل فاکتورها</h3>
                <div class="value"><?php echo number_format($stats['total_invoices']); ?></div>
                <span class="stat-change positive"><i class="fas fa-arrow-up"></i> 12%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-truck"></i></div>
            <div class="stat-info">
                <h3>بارنامه‌ها</h3>
                <div class="value"><?php echo number_format($stats['total_waybills']); ?></div>
                <span class="stat-change positive"><i class="fas fa-arrow-up"></i> 8%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <h3>در انتظار تایید</h3>
                <div class="value"><?php echo number_format($stats['pending']); ?></div>
                <span class="stat-change negative"><i class="fas fa-arrow-down"></i> 5%</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-cloud-upload-alt"></i></div>
            <div class="stat-info">
                <h3>ارسال به مودیان</h3>
                <div class="value"><?php echo number_format($stats['tax_sent']); ?></div>
                <span class="stat-change positive"><i class="fas fa-arrow-up"></i> 18%</span>
            </div>
        </div>
    </div>

    <!-- گرید محتوا -->
    <div class="content-grid">
        
        <!-- آخرین فاکتورها -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="fas fa-file-invoice"></i> آخرین فاکتورها</h2>
                <a href="inbox.php" class="btn-link">مشاهده همه <i class="fas fa-arrow-left"></i></a>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>شماره</th>
                            <th>شرکت</th>
                            <th>فروشنده</th>
                            <th>مبلغ</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_invoices)): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 30px;">هیچ فاکتوری یافت نشد</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_invoices as $inv): 
                                $status_class = 'pending';
                                $status_text = 'در انتظار';
                                if ($inv['status'] == 'approved') { $status_class = 'success'; $status_text = 'تایید شده'; }
                                elseif ($inv['status'] == 'rejected') { $status_class = 'pending'; $status_text = 'رد شده'; }
                                elseif ($inv['status'] == 'forwarded') { $status_class = 'info'; $status_text = 'ارسال شده'; }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($inv['document_number'] ?? '#' . $inv['id']); ?></td>
                                <td><?php echo htmlspecialchars($inv['company_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($inv['vendor_name'] ?? '-'); ?></td>
                                <td><?php echo number_format($inv['amount'] ?? 0); ?> تومان</td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
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
                <a href="tax.php" class="btn-link">مشاهده همه <i class="fas fa-arrow-left"></i></a>
            </div>
            <div class="tax-stats">
                <?php
                $total_tax_docs = $stats['total_tax'];
                $sent_percent = $total_tax_docs > 0 ? round(($stats['tax_sent'] / $total_tax_docs) * 100) : 0;
                ?>
                <div class="tax-item">
                    <span class="tax-label">کل اسناد مالیاتی</span>
                    <span class="tax-value"><?php echo number_format($total_tax_docs); ?></span>
                </div>
                <div class="tax-item">
                    <span class="tax-label">ارسال شده به مودیان</span>
                    <span class="tax-value" style="color: #10b981;"><?php echo number_format($stats['tax_sent']); ?></span>
                </div>
                <div class="tax-progress">
                    <div class="tax-progress-bar" style="width: <?php echo $sent_percent; ?>%;"></div>
                </div>
                <div class="tax-item">
                    <span class="tax-label">در انتظار ارسال</span>
                    <span class="tax-value" style="color: #f59e0b;"><?php echo number_format($total_tax_docs - $stats['tax_sent']); ?></span>
                </div>
            </div>
        </div>

        <!-- آخرین بارنامه‌ها -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="fas fa-truck"></i> آخرین بارنامه‌ها</h2>
                <a href="waybills.php" class="btn-link">مشاهده همه <i class="fas fa-arrow-left"></i></a>
            </div>
            <div class="items-list">
                <?php if (empty($recent_waybills)): ?>
                    <div style="text-align: center; padding: 30px; color: #94a3b8;">هیچ بارنامه‌ای یافت نشد</div>
                <?php else: ?>
                    <?php foreach ($recent_waybills as $way): ?>
                    <div class="item-row">
                        <div class="item-icon"><i class="fas fa-truck"></i></div>
                        <div class="item-info">
                            <h4><?php echo htmlspecialchars($way['title']); ?></h4>
                            <p><?php echo htmlspecialchars($way['company_name'] ?? '-'); ?> • <?php echo htmlspecialchars($way['vendor_name'] ?? '-'); ?></p>
                        </div>
                        <div>
                            <span class="status-badge <?php echo ($way['status'] == 'approved') ? 'success' : 'pending'; ?>">
                                <?php echo ($way['status'] == 'approved') ? 'تکمیل شده' : 'در حال انجام'; ?>
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
                <a href="invoice-create.php" class="action-card">
                    <i class="fas fa-file-invoice"></i>
                    <span>فاکتور جدید</span>
                </a>
                <a href="waybill-create.php" class="action-card">
                    <i class="fas fa-truck"></i>
                    <span>بارنامه جدید</span>
                </a>
                <a href="tax-create.php" class="action-card">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>سند مالیاتی جدید</span>
                </a>
                <a href="manage.php?tab=companies" class="action-card">
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