<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('edit_invoice')) {
    header('Location: inbox.php');
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

$id = $_GET['id'] ?? 0;

// دریافت اطلاعات فاکتور
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'invoice'");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: inbox.php');
    exit;
}

// فقط پیش‌نویس و توسط سازنده قابل ویرایش است
if ($invoice['status'] != 'draft' || $invoice['created_by'] != $_SESSION['user_id']) {
    header('Location: invoice-view.php?id=' . $id);
    exit;
}

// دریافت لیست‌ها
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name, contract_number FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT * FROM workshops ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_invoice'])) {
    
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = trim($_POST['invoice_date'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $department_id = $_POST['department_id'] ?? '';
    $vendor_id = $_POST['vendor_id'] ?? '';
    $workshop_id = $_POST['workshop_id'] ?? '';
    $has_contract = isset($_POST['has_contract']) ? 1 : 0;
    $contract_number = trim($_POST['contract_number'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $vat = isset($_POST['vat']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');
    
    $errors = [];
    
    if (empty($invoice_number)) $errors[] = 'شماره فاکتور الزامی است';
    if (empty($invoice_date)) $errors[] = 'تاریخ فاکتور الزامی است';
    if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (empty($department_id)) $errors[] = 'انتخاب بخش ارجاع الزامی است';
    if (empty($vendor_id)) $errors[] = 'انتخاب فروشنده الزامی است';
    if (empty($amount) || $amount <= 0) $errors[] = 'مبلغ باید بزرگتر از صفر باشد';
    
    // آپلود فایل جدید اگر انتخاب شده
    $file_path = $invoice['file_path'];
    $file_name = $invoice['file_name'];
    
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 5 * 1024 * 1024;
        
        if (in_array($_FILES['invoice_file']['type'], $allowed_types) && $_FILES['invoice_file']['size'] <= $max_size) {
            $upload_dir = __DIR__ . '/../uploads/invoices/';
            $file_ext = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
            $new_file_name = time() . '_' . $invoice_number . '.' . $file_ext;
            $new_file_path = 'uploads/invoices/' . $new_file_name;
            
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $new_file_name)) {
                // حذف فایل قدیمی
                $old_file = __DIR__ . '/../' . $invoice['file_path'];
                if (file_exists($old_file)) unlink($old_file);
                
                $file_path = $new_file_path;
                $file_name = $_FILES['invoice_file']['name'];
            }
        } else {
            $errors[] = 'فایل نامعتبر است';
        }
    }
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE documents SET 
                document_number = ?,
                document_date = ?,
                company_id = ?,
                department_id = ?,
                vendor_id = ?,
                workshop_id = ?,
                has_contract = ?,
                contract_number = ?,
                amount = ?,
                vat = ?,
                vat_amount = ?,
                description = ?,
                file_path = ?,
                file_name = ?
            WHERE id = ?
        ");
        
        $vat_amount = $vat ? ($amount * 0.1) : 0;
        
        if ($stmt->execute([
            $invoice_number,
            $invoice_date,
            $company_id,
            $department_id,
            $vendor_id,
            $workshop_id ?: null,
            $has_contract,
            $contract_number ?: null,
            $amount,
            $vat,
            $vat_amount,
            $description,
            $file_path,
            $file_name,
            $id
        ])) {
            logActivity($_SESSION['user_id'], 'edit_invoice', "ویرایش فاکتور: $invoice_number", $id);
            $success = 'فاکتور با موفقیت ویرایش شد';
        } else {
            $errors[] = 'خطا در ویرایش فاکتور';
        }
    }
}

$page_title = 'ویرایش فاکتور';
ob_start();
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .select2-container--default .select2-selection--single {
        height: 42px;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 5px;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">ویرایش فاکتور</h1>
    <a href="invoice-view.php?id=<?php echo $id; ?>" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
        <ul><?php foreach ($errors as $err) echo "<li>$err</li>"; ?></ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
<?php endif; ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST" enctype="multipart/form-data">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">شماره فاکتور</label>
                <input type="text" name="invoice_number" value="<?php echo htmlspecialchars($invoice['document_number']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">تاریخ فاکتور</label>
                <input type="text" name="invoice_date" value="<?php echo htmlspecialchars($invoice['document_date']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">شرکت</label>
                <select name="company_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['id']; ?>" <?php echo $company['id'] == $invoice['company_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($company['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">بخش ارجاع</label>
                <select name="department_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo $dept['id'] == $invoice['department_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">کارگاه/بخش پروژه</label>
            <select name="workshop_id" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <option value="">ندارد</option>
                <?php foreach ($workshops as $ws): ?>
                    <option value="<?php echo $ws['id']; ?>" <?php echo $ws['id'] == $invoice['workshop_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ws['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">فروشنده</label>
            <select name="vendor_id" id="vendorSelect" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <?php foreach ($vendors as $vendor): ?>
                    <option value="<?php echo $vendor['id']; ?>" data-contract="<?php echo $vendor['contract_number'] ?? ''; ?>" <?php echo $vendor['id'] == $invoice['vendor_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($vendor['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <input type="checkbox" name="has_contract" id="hasContract" <?php echo $invoice['has_contract'] ? 'checked' : ''; ?>>
                <label for="hasContract">دارای قرارداد</label>
            </div>
            <div id="contractField" style="display: <?php echo $invoice['has_contract'] ? 'block' : 'none'; ?>;">
                <input type="text" name="contract_number" value="<?php echo htmlspecialchars($invoice['contract_number'] ?? ''); ?>" placeholder="شماره قرارداد" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">مبلغ (ریال)</label>
                <input type="text" name="amount" id="amountInput" onkeyup="formatNumber(this)" value="<?php echo number_format($invoice['amount']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div style="display: flex; align-items: center; gap: 10px; margin-top: 30px;">
                <input type="checkbox" name="vat" id="vatCheckbox" <?php echo $invoice['vat'] ? 'checked' : ''; ?>>
                <label for="vatCheckbox">مشمول ارزش افزوده (۱۰٪)</label>
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">فایل فاکتور</label>
            <input type="file" name="invoice_file" accept=".jpg,.jpeg,.png,.pdf" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <?php if ($invoice['file_path']): ?>
                <small style="color: #666;">فایل فعلی: <?php echo $invoice['file_name']; ?></small>
            <?php endif; ?>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">توضیحات</label>
            <textarea name="description" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;"><?php echo htmlspecialchars($invoice['description'] ?? ''); ?></textarea>
        </div>

        <button type="submit" name="update_invoice" style="background: #f39c12; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer;">ذخیره تغییرات</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$('#vendorSelect').select2({ width: '100%' });

function formatNumber(input) {
    let value = input.value.replace(/,/g, '');
    if (!isNaN(value) && value !== '') {
        input.value = Number(value).toLocaleString();
    }
}

document.getElementById('hasContract').addEventListener('change', function() {
    document.getElementById('contractField').style.display = this.checked ? 'block' : 'none';
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>