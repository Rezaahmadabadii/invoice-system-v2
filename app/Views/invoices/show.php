<?php
use App\Core\Session;

$title = 'جزئیات فاکتور ' . $invoice['invoice_number'];
ob_start();
?>

<div class="page-header">
    <h1>فاکتور <?php echo $invoice['invoice_number']; ?></h1>
    <div class="header-actions">
        <a href="/invoices" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i>
            بازگشت
        </a>
        <?php if ($invoice['status'] === 'draft'): ?>
            <a href="/invoices/<?php echo $invoice['id']; ?>/edit" class="btn btn-primary">
                <i class="fas fa-edit"></i>
                ویرایش
            </a>
            <form method="POST" action="/invoices/<?php echo $invoice['id']; ?>/submit" style="display:inline;">
                <button type="submit" class="btn btn-success" 
                        onclick="return confirm('آیا برای ارسال به تأیید اطمینان دارید؟');">
                    <i class="fas fa-paper-plane"></i>
                    ارسال برای تأیید
                </button>
            </form>
        <?php endif; ?>
        <?php if ($invoice['status'] === 'pending' && Session::get('user_role') === 'supervisor'): ?>
            <button class="btn btn-success" onclick="showApproveModal()">
                <i class="fas fa-check"></i>
                تأیید
            </button>
            <button class="btn btn-danger" onclick="showRejectModal()">
                <i class="fas fa-times"></i>
                رد
            </button>
        <?php endif; ?>
    </div>
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

<div class="invoice-details">
    <!-- وضعیت فاکتور -->
    <div class="status-bar">
        <div class="status-badge status-<?php 
            echo $invoice['status'] === 'draft' ? 'secondary' : 
                ($invoice['status'] === 'pending' ? 'warning' : 
                ($invoice['status'] === 'approved' ? 'success' : 
                ($invoice['status'] === 'rejected' ? 'danger' : 'info'))); 
        ?>">
            <?php echo $invoice['status'] === 'draft' ? 'پیش‌نویس' : 
                ($invoice['status'] === 'pending' ? 'در انتظار تأیید' : 
                ($invoice['status'] === 'approved' ? 'تأیید شده' : 
                ($invoice['status'] === 'rejected' ? 'رد شده' : 
                ($invoice['status'] === 'paid' ? 'پرداخت شده' : $invoice['status'])))); ?>
        </div>
        <div class="date-info">
            <span>تاریخ ایجاد: <?php echo jdate('d F Y', strtotime($invoice['created_at'])); ?></span>
        </div>
    </div>

    <!-- اطلاعات فاکتور -->
    <div class="info-section">
        <div class="info-box">
            <h3>اطلاعات فاکتور</h3>
            <div class="info-row">
                <span class="label">شماره فاکتور:</span>
                <span class="value"><?php echo $invoice['invoice_number']; ?></span>
            </div>
            <div class="info-row">
                <span class="label">عنوان:</span>
                <span class="value"><?php echo htmlspecialchars($invoice['title']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">توضیحات:</span>
                <span class="value"><?php echo nl2br(htmlspecialchars($invoice['description'] ?? '-')); ?></span>
            </div>
            <div class="info-row">
                <span class="label">ایجاد کننده:</span>
                <span class="value"><?php echo htmlspecialchars($invoice['creator_name'] ?? 'نامشخص'); ?></span>
            </div>
        </div>

        <div class="info-box">
            <h3>اطلاعات مشتری</h3>
            <div class="info-row">
                <span class="label">نام مشتری:</span>
                <span class="value"><?php echo htmlspecialchars($invoice['customer_name'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">تلفن:</span>
                <span class="value"><?php echo htmlspecialchars($invoice['customer_phone'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">آدرس:</span>
                <span class="value"><?php echo nl2br(htmlspecialchars($invoice['customer_address'] ?? '-')); ?></span>
            </div>
        </div>
    </div>

    <!-- آیتم‌های فاکتور -->
    <div class="items-section">
        <h3>آیتم‌های فاکتور</h3>
        <table class="items-table">
            <thead>
                <tr>
                    <th>ردیف</th>
                    <th>شرح</th>
                    <th>تعداد</th>
                    <th>قیمت واحد (تومان)</th>
                    <th>مالیات</th>
                    <th>تخفیف</th>
                    <th>جمع کل (تومان)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="7" class="text-center">آیتمی یافت نشد</td>
                    </tr>
                <?php else: ?>
                    <?php $row = 1; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo $row++; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td class="amount"><?php echo number_format($item['price']); ?></td>
                            <td><?php echo $item['tax'] ?? 0; ?>%</td>
                            <td><?php echo $item['discount'] ?? 0; ?>%</td>
                            <td class="amount"><?php echo number_format($item['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" class="text-left">جمع کل:</td>
                    <td class="amount total"><?php echo number_format($invoice['total'] ?? $invoice['amount']); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- تاریخچه تأیید -->
    <?php if (!empty($approvals)): ?>
        <div class="approval-section">
            <h3>تاریخچه تأیید</h3>
            <div class="timeline">
                <?php foreach ($approvals as $approval): ?>
                    <div class="timeline-item <?php echo $approval['status']; ?>">
                        <div class="timeline-icon">
                            <?php if ($approval['status'] === 'approved'): ?>
                                <i class="fas fa-check-circle"></i>
                            <?php elseif ($approval['status'] === 'rejected'): ?>
                                <i class="fas fa-times-circle"></i>
                            <?php else: ?>
                                <i class="fas fa-clock"></i>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-content">
                            <h4><?php echo htmlspecialchars($approval['step_name']); ?></h4>
                            <p>تأیید کننده: <?php echo htmlspecialchars($approval['approver_name']); ?></p>
                            <?php if ($approval['notes']): ?>
                                <p class="notes"><?php echo nl2br(htmlspecialchars($approval['notes'])); ?></p>
                            <?php endif; ?>
                            <span class="timeline-date">
                                <?php echo jdate('d F Y H:i', strtotime($approval['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- مودال تأیید -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <h3>تأیید فاکتور</h3>
        <form method="POST" action="/approvals/<?php echo $invoice['id']; ?>/approve">
            <div class="form-group">
                <label for="approve_notes">یادداشت (اختیاری)</label>
                <textarea id="approve_notes" name="notes" rows="3" placeholder="یادداشت خود را وارد کنید..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-success">تأیید</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal()">انصراف</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال رد -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <h3>رد فاکتور</h3>
        <form method="POST" action="/approvals/<?php echo $invoice['id']; ?>/reject">
            <div class="form-group">
                <label for="reject_notes">دلیل رد (الزامی)</label>
                <textarea id="reject_notes" name="notes" rows="3" required placeholder="دلیل رد را وارد کنید..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-danger">رد</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal()">انصراف</button>
            </div>
        </form>
    </div>
</div>

<style>
.invoice-details {
    max-width: 1200px;
    margin: 0 auto;
}

.status-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 15px 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.status-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
}

.status-secondary { background: var(--secondary); color: white; }
.status-warning { background: var(--warning); color: white; }
.status-success { background: var(--success); color: white; }
.status-danger { background: var(--danger); color: white; }
.status-info { background: var(--info); color: white; }

.info-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.info-box {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
}

.info-box h3 {
    font-size: 16px;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.info-row {
    display: flex;
    margin-bottom: 10px;
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

.items-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.items-section h3 {
    margin-bottom: 15px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    background: var(--glass-bg);
    padding: 10px;
    text-align: right;
    font-size: 13px;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
}

.items-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
}

.items-table tfoot td {
    font-weight: bold;
    font-size: 16px;
    border-top: 2px solid var(--border-color);
}

.amount {
    font-family: monospace;
    direction: ltr;
    text-align: right;
}

.total {
    color: var(--primary);
}

.approval-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.timeline {
    position: relative;
    padding-right: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border-color);
}

.timeline-item {
    position: relative;
    padding-right: 30px;
    padding-bottom: 30px;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-icon {
    position: absolute;
    right: -13px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--card-bg);
    border: 2px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
}

.timeline-item.approved .timeline-icon {
    border-color: var(--success);
    color: var(--success);
}

.timeline-item.rejected .timeline-icon {
    border-color: var(--danger);
    color: var(--danger);
}

.timeline-content {
    background: var(--glass-bg);
    border-radius: var(--radius-sm);
    padding: 15px;
}

.timeline-content h4 {
    font-size: 14px;
    margin-bottom: 5px;
    color: var(--text-primary);
}

.timeline-content p {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 5px;
}

.timeline-content .notes {
    background: rgba(0,0,0,0.05);
    padding: 8px;
    border-radius: var(--radius-sm);
    margin: 10px 0;
}

.timeline-date {
    font-size: 12px;
    color: var(--text-secondary);
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 30px;
    max-width: 500px;
    width: 90%;
}

.modal-content h3 {
    margin-bottom: 20px;
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.text-center {
    text-align: center;
}

.text-left {
    text-align: left;
}
</style>

<script>
function showApproveModal() {
    document.getElementById('approveModal').classList.add('show');
}

function showRejectModal() {
    document.getElementById('rejectModal').classList.add('show');
}

function hideModal() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.classList.remove('show');
    });
}

// بستن مودال با کلیک خارج از آن
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        hideModal();
    }
});
</script>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>