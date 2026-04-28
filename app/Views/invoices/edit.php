<?php
use App\Core\Session;

$title = 'ویرایش فاکتور ' . $invoice['invoice_number'];
ob_start();
?>

<div class="page-header">
    <h1>ویرایش فاکتور <?php echo $invoice['invoice_number']; ?></h1>
    <a href="/invoices/<?php echo $invoice['id']; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-right"></i>
        بازگشت
    </a>
</div>

<?php if (Session::has('flash_error')): ?>
    <div class="alert alert-error">
        <?php echo Session::getFlash('error'); ?>
    </div>
<?php endif; ?>

<form method="POST" action="/invoices/<?php echo $invoice['id']; ?>/update" class="invoice-form" id="invoiceForm">
    <!-- اطلاعات اصلی فاکتور -->
    <div class="form-section">
        <h2>اطلاعات اصلی</h2>
        
        <div class="form-row">
            <div class="form-group">
                <label for="invoice_number">شماره فاکتور</label>
                <input type="text" id="invoice_number" value="<?php echo $invoice['invoice_number']; ?>" readonly class="readonly">
            </div>

            <div class="form-group">
                <label for="customer_id">مشتری</label>
                <select id="customer_id" name="customer_id" required>
                    <option value="">انتخاب مشتری</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                            <?php echo $customer['id'] == $invoice['customer_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="title">عنوان فاکتور</label>
            <input type="text" id="title" name="title" required 
                   value="<?php echo htmlspecialchars($invoice['title']); ?>">
        </div>

        <div class="form-group">
            <label for="description">توضیحات</label>
            <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($invoice['description'] ?? ''); ?></textarea>
        </div>
    </div>

    <!-- آیتم‌های فاکتور -->
    <div class="form-section">
        <div class="section-header">
            <h2>آیتم‌های فاکتور</h2>
            <button type="button" class="btn btn-secondary" id="addItem">
                <i class="fas fa-plus"></i>
                افزودن آیتم
            </button>
        </div>

        <div class="items-table">
            <table>
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>شرح</th>
                        <th>تعداد</th>
                        <th>قیمت واحد (تومان)</th>
                        <th>مالیات (%)</th>
                        <th>تخفیف (%)</th>
                        <th>جمع کل (تومان)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="itemsContainer">
                    <?php $row = 1; ?>
                    <?php foreach ($items as $item): ?>
                        <tr id="row_<?php echo $row; ?>">
                            <td><?php echo $row; ?></td>
                            <td><input type="text" name="items[<?php echo $row; ?>][description]" 
                                       value="<?php echo htmlspecialchars($item['description']); ?>" required></td>
                            <td><input type="number" name="items[<?php echo $row; ?>][quantity]" 
                                       value="<?php echo $item['quantity']; ?>" min="1" onchange="calculateRow(<?php echo $row; ?>)"></td>
                            <td><input type="text" name="items[<?php echo $row; ?>][price]" 
                                       value="<?php echo number_format($item['price']); ?>" 
                                       onkeyup="formatNumber(this); calculateRow(<?php echo $row; ?>)"></td>
                            <td><input type="number" name="items[<?php echo $row; ?>][tax]" 
                                       value="<?php echo $item['tax'] ?? 0; ?>" min="0" max="100" onchange="calculateRow(<?php echo $row; ?>)"></td>
                            <td><input type="number" name="items[<?php echo $row; ?>][discount]" 
                                       value="<?php echo $item['discount'] ?? 0; ?>" min="0" max="100" onchange="calculateRow(<?php echo $row; ?>)"></td>
                            <td class="amount" id="rowTotal<?php echo $row; ?>"><?php echo number_format($item['total']); ?></td>
                            <td><button type="button" class="item-remove" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php $row++; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-left">جمع کل:</td>
                        <td class="amount" id="totalAmount"><?php echo number_format($invoice['total'] ?? $invoice['amount']); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- خلاصه و دکمه‌ها -->
    <div class="form-section summary-section">
        <div class="summary-box">
            <div class="summary-row">
                <span>جمع آیتم‌ها:</span>
                <span id="subtotal">0</span>
            </div>
            <div class="summary-row">
                <span>مالیات کل:</span>
                <span id="totalTax">0</span>
            </div>
            <div class="summary-row">
                <span>تخفیف کل:</span>
                <span id="totalDiscount">0</span>
            </div>
            <div class="summary-row total">
                <span>مبلغ نهایی:</span>
                <span id="finalTotal">0</span>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                ذخیره تغییرات
            </button>
            <a href="/invoices/<?php echo $invoice['id']; ?>" class="btn btn-secondary">انصراف</a>
        </div>
    </div>
</form>

<style>
.invoice-form {
    max-width: 1200px;
    margin: 0 auto;
}

.form-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.form-section h2 {
    font-size: 18px;
    color: var(--text-primary);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 15px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-secondary);
    font-weight: 500;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,122,255,0.1);
}

.form-group input.readonly {
    background: var(--glass-bg);
    cursor: not-allowed;
}

.items-table {
    overflow-x: auto;
}

.items-table table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    background: var(--glass-bg);
    padding: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
}

.items-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border-color);
}

.items-table input {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
}

.item-remove {
    background: none;
    border: none;
    color: var(--danger);
    cursor: pointer;
    font-size: 18px;
}

.summary-section {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    align-items: start;
}

.summary-box {
    background: var(--glass-bg);
    border-radius: var(--radius-sm);
    padding: 20px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.summary-row.total {
    font-weight: bold;
    font-size: 18px;
    color: var(--primary);
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.amount {
    font-family: monospace;
    direction: ltr;
    text-align: right;
}

.text-left {
    text-align: left;
}
</style>

<script>
let itemCount = <?php echo count($items); ?>;

document.getElementById('addItem').addEventListener('click', function() {
    itemCount++;
    const container = document.getElementById('itemsContainer');
    const row = document.createElement('tr');
    row.id = `row_${itemCount}`;
    row.innerHTML = `
        <td>${itemCount}</td>
        <td><input type="text" name="items[${itemCount}][description]" required placeholder="شرح آیتم"></td>
        <td><input type="number" name="items[${itemCount}][quantity]" value="1" min="1" onchange="calculateRow(${itemCount})"></td>
        <td><input type="text" name="items[${itemCount}][price]" onkeyup="formatNumber(this); calculateRow(${itemCount})" placeholder="۰"></td>
        <td><input type="number" name="items[${itemCount}][tax]" value="0" min="0" max="100" onchange="calculateRow(${itemCount})"></td>
        <td><input type="number" name="items[${itemCount}][discount]" value="0" min="0" max="100" onchange="calculateRow(${itemCount})"></td>
        <td class="amount" id="rowTotal${itemCount}">0</td>
        <td><button type="button" class="item-remove" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>
    `;
    container.appendChild(row);
    calculateAll();
});

function removeRow(btn) {
    if (confirm('آیا از حذف این آیتم اطمینان دارید؟')) {
        btn.closest('tr').remove();
        calculateAll();
    }
}

function formatNumber(input) {
    let value = input.value.replace(/,/g, '');
    if (!isNaN(value) && value !== '') {
        input.value = Number(value).toLocaleString();
    }
}

function calculateRow(rowNum) {
    const row = document.getElementById(`row_${rowNum}`);
    if (!row) return;
    
    const quantity = row.querySelector(`input[name="items[${rowNum}][quantity]"]`).value || 1;
    let price = row.querySelector(`input[name="items[${rowNum}][price]"]`).value.replace(/,/g, '') || 0;
    const tax = row.querySelector(`input[name="items[${rowNum}][tax]"]`).value || 0;
    const discount = row.querySelector(`input[name="items[${rowNum}][discount]"]`).value || 0;
    
    price = parseFloat(price) || 0;
    const subtotal = quantity * price;
    const taxAmount = subtotal * (tax / 100);
    const discountAmount = subtotal * (discount / 100);
    const total = subtotal + taxAmount - discountAmount;
    
    document.getElementById(`rowTotal${rowNum}`).textContent = Math.round(total).toLocaleString();
    calculateAll();
}

function calculateAll() {
    let subtotal = 0;
    let totalTax = 0;
    let totalDiscount = 0;
    
    document.querySelectorAll('#itemsContainer tr').forEach((row, index) => {
        const rowNum = index + 1;
        const quantity = row.querySelector(`input[name="items[${rowNum}][quantity]"]`)?.value || 1;
        let price = row.querySelector(`input[name="items[${rowNum}][price]"]`)?.value.replace(/,/g, '') || 0;
        const tax = row.querySelector(`input[name="items[${rowNum}][tax]"]`)?.value || 0;
        const discount = row.querySelector(`input[name="items[${rowNum}][discount]"]`)?.value || 0;
        
        price = parseFloat(price) || 0;
        const rowSubtotal = quantity * price;
        const rowTax = rowSubtotal * (tax / 100);
        const rowDiscount = rowSubtotal * (discount / 100);
        
        subtotal += rowSubtotal;
        totalTax += rowTax;
        totalDiscount += rowDiscount;
    });
    
    const finalTotal = subtotal + totalTax - totalDiscount;
    
    document.getElementById('subtotal').textContent = Math.round(subtotal).toLocaleString();
    document.getElementById('totalTax').textContent = Math.round(totalTax).toLocaleString();
    document.getElementById('totalDiscount').textContent = Math.round(totalDiscount).toLocaleString();
    document.getElementById('finalTotal').textContent = Math.round(finalTotal).toLocaleString();
    document.getElementById('totalAmount').textContent = Math.round(finalTotal).toLocaleString();
}

// محاسبه اولیه
document.addEventListener('DOMContentLoaded', function() {
    calculateAll();
});
</script>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>