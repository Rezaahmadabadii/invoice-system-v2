<?php
use App\Core\Session;

$title = 'در انتظار تأیید';
ob_start();
?>

<div class="page-header">
    <h1>فاکتورهای در انتظار تأیید</h1>
</div>

<?php if (Session::has('flash_success')): ?>
    <div class="alert alert-success">
        <?php echo Session::getFlash('success'); ?>
    </div>
<?php endif; ?>

<?php if (Session::has('flash_error')): ?>
    <div class="alert alert-error">
        <?php echo Session::getFlash('error'); ?>
    </div>
<?php endif; ?>

<?php if (empty($invoices)): ?>
    <div class="empty-state">
        <i class="fas fa-check-circle"></i>
        <h3>هیچ فاکتوری در انتظار تأیید نیست</h3>
        <p>همه فاکتورها بررسی شده‌اند</p>
    </div>
<?php else: ?>
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>شماره فاکتور</th>
                    <th>مشتری</th>
                    <th>عنوان</th>
                    <th>مرحله</th>
                    <th>مبلغ (تومان)</th>
                    <th>تاریخ</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?php echo $invoice['invoice_number']; ?></td>
                        <td><?php echo htmlspecialchars($invoice['customer_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($invoice['title']); ?></td>
                        <td>
                            <span class="badge badge-info">
                                <?php echo htmlspecialchars($invoice['step_name']); ?>
                            </span>
                        </td>
                        <td class="amount"><?php echo number_format($invoice['total'] ?? $invoice['amount']); ?></td>
                        <td><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></td>
                        <td>
                            <a href="/approvals/show/<?php echo $invoice['approval_id']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i>
                                بررسی
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<style>
.page-header {
    margin-bottom: 20px;
}

.page-header h1 {
    font-size: 24px;
    color: var(--text-primary);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--card-bg);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}

.empty-state i {
    font-size: 64px;
    color: var(--success);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--text-primary);
    margin-bottom: 10px;
}

.empty-state p {
    color: var(--text-secondary);
}

.table-container {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
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
    border-bottom: 2px solid var(--border-color);
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

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge-info {
    background: var(--info);
    color: white;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}
</style>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>