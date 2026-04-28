<?php
use App\Core\Session;

$title = 'بررسی فاکتور ' . $approval['invoice_number'];
ob_start();
?>

<div class="page-header">
    <h1>بررسی فاکتور</h1>
    <div class="header-actions">
        <a href="/approvals/pending" class="btn btn-secondary">
            <i class="fas fa-arrow-right"></i>
            بازگشت
        </a>
    </div>
</div>

<div class="approval-container">
    <!-- اطلاعات فاکتور -->
    <div class="info-section">
        <div class="info-card">
            <div class="info-header">
                <i class="fas fa-file-invoice"></i>
                <h2>اطلاعات فاکتور</h2>
            </div>
            <div class="info-content">
                <div class="info-row">
                    <span class="label">شماره فاکتور:</span>
                    <span class="value"><?php echo $approval['invoice_number']; ?></span>
                </div>
                <div class="info-row">
                    <span class="label">عنوان:</span>
                    <span class="value"><?php echo htmlspecialchars($approval['title']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">مشتری:</span>
                    <span class="value"><?php echo htmlspecialchars($approval['customer_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">مرحله تأیید:</span>
                    <span class="value badge badge-info"><?php echo htmlspecialchars($approval['step_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">مبلغ کل:</span>
                    <span class="value amount"><?php echo number_format($approval['total'] ?? $approval['amount']); ?> تومان</span>
                </div>
                <div class="info-row">
                    <span class="label">توضیحات:</span>
                    <span class="value"><?php echo nl2br(htmlspecialchars($approval['description'] ?? '-')); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- آیتم‌های فاکتور -->
    <?php if (!empty($items)): ?>
        <div class="items-section">
            <h3>آیتم‌های فاکتور</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>شرح</th>
                        <th>تعداد</th>
                        <th>قیمت واحد</th>
                        <th>جمع کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $row = 1; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo $row++; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td class="amount"><?php echo number_format($item['price']); ?></td>
                            <td class="amount"><?php echo number_format($item['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- فرم تأیید/رد -->
    <div class="action-section">
        <div class="action-card">
            <h3>ثبت نتیجه بررسی</h3>
            
            <div class="action-buttons">
                <button class="btn btn-success btn-lg" onclick="showApproveModal()">
                    <i class="fas fa-check"></i>
                    تأیید فاکتور
                </button>
                <button class="btn btn-danger btn-lg" onclick="showRejectModal()">
                    <i class="fas fa-times"></i>
                    رد فاکتور
                </button>
            </div>
        </div>
    </div>
</div>

<!-- مودال تأیید -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <h3>تأیید فاکتور</h3>
        <form method="POST" action="/approvals/approve/<?php echo $approval['id']; ?>">
            <div class="form-group">
                <label for="approve_notes">یادداشت (اختیاری)</label>
                <textarea id="approve_notes" name="notes" rows="3" 
                          placeholder="یادداشت خود را وارد کنید..."></textarea>
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
        <form method="POST" action="/approvals/reject/<?php echo $approval['id']; ?>">
            <div class="form-group">
                <label for="reject_notes">دلیل رد <span class="required">*</span></label>
                <textarea id="reject_notes" name="notes" rows="3" required 
                          placeholder="دلیل رد را وارد کنید..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-danger">رد</button>
                <button type="button" class="btn btn-secondary" onclick="hideModal()">انصراف</button>
            </div>
        </form>
    </div>
</div>

<style>
.approval-container {
    max-width: 1000px;
    margin: 0 auto;
}

.info-section {
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
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.info-row {
    padding: 8px 0;
}

.info-row .label {
    display: block;
    font-size: 12px;
    color: var(--text-secondary);
    margin-bottom: 3px;
}

.info-row .value {
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 500;
}

.items-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: var(--shadow);
}

.items-section h3 {
    margin-bottom: 20px;
    font-size: 16px;
    color: var(--text-primary);
}

.items-table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    text-align: right;
    padding: 10px;
    font-size: 13px;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
}

.items-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
}

.action-section {
    margin-bottom: 30px;
}

.action-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 25px;
    box-shadow: var(--shadow);
    text-align: center;
}

.action-card h3 {
    margin-bottom: 20px;
    color: var(--text-primary);
}

.action-buttons {
    display: flex;
    gap: 20px;
    justify-content: center;
}

.btn-lg {
    padding: 15px 40px;
    font-size: 16px;
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

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-secondary);
}

.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-family: inherit;
}

.required {
    color: var(--danger);
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
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
}

.badge-info {
    background: var(--info);
    color: white;
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