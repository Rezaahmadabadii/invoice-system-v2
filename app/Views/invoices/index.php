<?php
use App\Core\Session;

$title = 'لیست فاکتورها';
ob_start();
?>

<div class="page-header">
    <h1>مدیریت فاکتورها</h1>
    <div class="header-actions">
        <a href="/invoices/create" class="btn btn-primary">
            <i class="fas fa-plus"></i>
            فاکتور جدید
        </a>
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

<!-- فیلترها -->
<div class="filters-card">
    <form method="GET" action="/invoices" class="filters-form">
        <div class="filter-group">
            <input type="text" name="search" placeholder="جستجو..." 
                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
        </div>
        <div class="filter-group">
            <select name="status">
                <option value="">همه وضعیت‌ها</option>
                <option value="draft" <?php echo ($_GET['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>پیش‌نویس</option>
                <option value="pending" <?php echo ($_GET['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>در انتظار تأیید</option>
                <option value="approved" <?php echo ($_GET['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>تأیید شده</option>
                <option value="rejected" <?php echo ($_GET['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>رد شده</option>
                <option value="paid" <?php echo ($_GET['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>پرداخت شده</option>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit" class="btn btn-secondary">اعمال فیلتر</button>
        </div>
    </form>
</div>

<!-- لیست فاکتورها -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>شماره فاکتور</th>
                <th>مشتری</th>
                <th>عنوان</th>
                <th>تاریخ</th>
                <th>مبلغ (تومان)</th>
                <th>وضعیت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="7" class="text-center">هیچ فاکتوری یافت نشد</td>
                </tr>
            <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?php echo $invoice['invoice_number']; ?></td>
                        <td><?php echo htmlspecialchars($invoice['customer_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($invoice['title']); ?></td>
                        <td><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></td>
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
                                    ($invoice['status'] === 'rejected' ? 'رد شده' : 
                                    ($invoice['status'] === 'paid' ? 'پرداخت شده' : $invoice['status'])))); ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <a href="/invoices/<?php echo $invoice['id']; ?>" class="btn-icon" title="مشاهده">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($invoice['status'] === 'draft'): ?>
                                    <a href="/invoices/<?php echo $invoice['id']; ?>/edit" class="btn-icon" title="ویرایش">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="/invoices/<?php echo $invoice['id']; ?>/delete" 
                                          style="display:inline;" 
                                          onsubmit="return confirm('آیا از حذف این فاکتور اطمینان دارید؟');">
                                        <button type="submit" class="btn-icon" title="حذف">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    <a href="/invoices/<?php echo $invoice['id']; ?>/submit" class="btn-icon" title="ارسال برای تأیید">
                                        <i class="fas fa-paper-plane"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- صفحه‌بندی -->
<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="/invoices?page=<?php echo $i; ?>" 
               class="page-link <?php echo ($page == $i) ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.page-header h1 {
    font-size: 24px;
    color: var(--text-primary);
}

.filters-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.filters-form {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-family: inherit;
}

.amount {
    font-family: monospace;
    direction: ltr;
    text-align: right;
}

.actions {
    display: flex;
    gap: 5px;
}

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

.btn-icon.danger:hover {
    background: var(--danger);
    border-color: var(--danger);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 20px;
}

.page-link {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    color: var(--text-primary);
    text-decoration: none;
    transition: all 0.3s;
}

.page-link:hover,
.page-link.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.text-center {
    text-align: center;
}
</style>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>