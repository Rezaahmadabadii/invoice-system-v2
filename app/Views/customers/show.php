<?php
use App\Core\Session;

$title = 'مشاهده مشتری - ' . $customer['name'];
ob_start();
?>

<div class="page-header">
    <h1>مشاهده مشتری</h1>
    <div class="header-actions">
        <a href="/customers" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i>
            بازگشت
        </a>
        <a href="/customers/<?php echo $customer['id']; ?>/edit" class="btn btn-primary">
            <i class="fas fa-edit"></i>
            ویرایش
        </a>
    </div>
</div>

<?php if (Session::has('flash_success')): ?>
    <div class="alert alert-success">
        <?php echo Session::getFlash('success'); ?>
    </div>
<?php endif; ?>

<div class="customer-details">
    <!-- اطلاعات مشتری -->
    <div class="info-section">
        <div class="info-card">
            <div class="info-header">
                <i class="fas fa-building"></i>
                <h2>اطلاعات مشتری</h2>
            </div>
            <div class="info-content">
                <div class="info-row">
                    <span class="label">کد مشتری:</span>
                    <span class="value"><?php echo $customer['code'] ?? '-'; ?></span>
                </div>
                <div class="info-row">
                    <span class="label">نام مشتری:</span>
                    <span class="value"><?php echo htmlspecialchars($customer['name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">تلفن:</span>
                    <span class="value"><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">ایمیل:</span>
                    <span class="value"><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">آدرس:</span>
                    <span class="value"><?php echo nl2br(htmlspecialchars($customer['address'] ?? '-')); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">کد اقتصادی:</span>
                    <span class="value"><?php echo htmlspecialchars($customer['economic_code'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">شناسه ملی:</span>
                    <span class="value"><?php echo htmlspecialchars($customer['national_id'] ?? '-'); ?></span>
                </div>
            </div>
        </div>

        <!-- آمار مشتری -->
        <div class="stats-card">
            <div class="stats-header">
                <i class="fas fa-chart-line"></i>
                <h2>آمار و ارقام</h2>
            </div>
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-label">تعداد فاکتورها</span>
                    <span class="stat-value"><?php echo $stats['total_invoices'] ?? 0; ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">جمع کل</span>
                    <span class="stat-value"><?php echo number_format($stats['total_amount'] ?? 0); ?></span>
                    <small>تومان</small>
                </div>
                <div class="stat-item">
                    <span class="stat-label">پرداخت شده</span>
                    <span class="stat-value"><?php echo number_format($stats['paid_amount'] ?? 0); ?></span>
                    <small>تومان</small>
                </div>
                <div class="stat-item">
                    <span class="stat-label">در انتظار</span>
                    <span class="stat-value"><?php echo number_format($stats['pending_amount'] ?? 0); ?></span>
                    <small>تومان</small>
                </div>
            </div>
        </div>
    </div>

    <!-- فاکتورهای اخیر -->
    <div class="invoices-section">
        <div class="section-header">
            <h2>آخرین فاکتورها</h2>
            <a href="/invoices?customer=<?php echo $customer['id']; ?>" class="btn-link">مشاهده همه</a>
        </div>

        <?php if (empty($invoices)): ?>
            <div class="empty-state">
                <p>هیچ فاکتوری برای این مشتری یافت نشد</p>
                <a href="/invoices/create?customer=<?php echo $customer['id']; ?>" class="btn btn-primary">
                    ایجاد فاکتور جدید
                </a>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>شماره فاکتور</th>
                        <th>تاریخ</th>
                        <th>عنوان</th>
                        <th>مبلغ (تومان)</th>
                        <th>وضعیت</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><?php echo $invoice['invoice_number']; ?></td>
                            <td><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($invoice['title']); ?></td>
                            <td class="amount"><?php echo number_format($invoice['total'] ?? $invoice['amount']); ?></td>
                            <td>
                                <span class="status status-<?php 
                                    echo $invoice['status'] === 'draft' ? 'secondary' : 
                                        ($invoice['status'] === 'pending' ? 'warning' : 
                                        ($invoice['status'] === 'approved' ? 'success' : 
                                        ($invoice['status'] === 'rejected' ? 'danger' : 'info'))); 
                                ?>">
                                    <?php echo $invoice['status'] === 'draft' ? 'پیش‌نویس' : 
                                        ($invoice['status'] === 'pending' ? 'در انتظار' : 
                                        ($invoice['status'] === 'approved' ? 'تأیید شده' : 
                                        ($invoice['status'] === 'rejected' ? 'رد شده' : $invoice['status']))); ?>
                                </span>
                            </td>
                            <td>
                                <a href="/invoices/<?php echo $invoice['id']; ?>" class="btn-icon">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.customer-details {
    max-width: 1200px;
    margin: 0 auto;
}

.info-section {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.info-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 25px;
    box-shadow: var(--shadow);
}

.info-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.info-header i {
    font-size: 24px;
    color: var(--primary);
}

.info-header h2 {
    font-size: 18px;
    color: var(--text-primary);
}

.info-content {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.info-row {
    display: flex;
    padding: 8px 0;
    border-bottom: 1px dashed var(--border-color);
}

.info-row:last-child {
    border-bottom: none;
}

.info-row .label {
    width: 120px;
    color: var(--text-secondary);
    font-weight: 500;
}

.info-row .value {
    flex: 1;
    color: var(--text-primary);
}

.stats-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 25px;
    box-shadow: var(--shadow);
}

.stats-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.stats-header i {
    font-size: 24px;
    color: var(--primary);
}

.stats-header h2 {
    font-size: 18px;
    color: var(--text-primary);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: var(--glass-bg);
    border-radius: var(--radius-sm);
}

.stat-label {
    display: block;
    font-size: 14px;
    color: var(--text-secondary);
    margin-bottom: 5px;
}

.stat-value {
    display: block;
    font-size: 20px;
    font-weight: bold;
    color: var(--primary);
    margin-bottom: 5px;
}

.stat-item small {
    font-size: 12px;
    color: var(--text-secondary);
}

.invoices-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 25px;
    box-shadow: var(--shadow);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.section-header h2 {
    font-size: 18px;
    color: var(--text-primary);
}

.btn-link {
    color: var(--primary);
    text-decoration: none;
    font-size: 14px;
}

.btn-link:hover {
    text-decoration: underline;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: right;
    padding: 12px;
    font-size: 13px;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
}

.amount {
    font-family: monospace;
    direction: ltr;
    text-align: right;
}

.status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-secondary { background: var(--secondary); color: white; }
.status-warning { background: var(--warning); color: white; }
.status-success { background: var(--success); color: white; }
.status-danger { background: var(--danger); color: white; }
.status-info { background: var(--info); color: white; }

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--border-color);
    background: var(--glass-bg);
    color: var(--text-primary);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.3s;
}

.btn-icon:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.empty-state {
    text-align: center;
    padding: 40px;
}

.empty-state p {
    color: var(--text-secondary);
    margin-bottom: 20px;
}
</style>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>