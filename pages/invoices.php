<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$page_title = 'مدیریت فاکتورها';
ob_start();
?>

<div class="invoices-header">
    <h1>مدیریت فاکتورها</h1>
    <a href="invoice-create.php" class="btn-primary">+ فاکتور جدید</a>
</div>

<div class="filters">
    <select id="companyFilter">
        <option value="">همه شرکت‌ها</option>
    </select>
    <select id="statusFilter">
        <option value="">همه وضعیت‌ها</option>
        <option value="pending">در انتظار</option>
        <option value="approved">تایید شده</option>
        <option value="rejected">رد شده</option>
    </select>
    <input type="text" placeholder="جستجو...">
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>شماره</th>
                <th>شرکت</th>
                <th>پیمانکار</th>
                <th>مبلغ</th>
                <th>تاریخ</th>
                <th>وضعیت</th>
                <th>مودیان</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="8" class="empty-state">در حال بارگذاری...</td>
            </tr>
        </tbody>
    </table>
</div>

<style>
.invoices-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.btn-primary {
    background: #4361ee;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
}

.filters {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.filters select, .filters input {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
}
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>