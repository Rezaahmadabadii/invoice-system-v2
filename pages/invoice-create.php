<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('create_invoice')) {
    header('Location: inbox.php');
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

// دریافت لیست‌های مورد نیاز
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name, contract_number FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT id, name FROM workshops ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

$error = '';
$success = '';
$duplicate_warning = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_invoice'])) {
    // دریافت مقادیر فرم
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = trim($_POST['invoice_date'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $new_company = trim($_POST['new_company'] ?? '');
    $vendor_id = $_POST['vendor_id'] ?? '';
    $new_vendor = trim($_POST['new_vendor'] ?? '');
    $workshop_id = $_POST['workshop_id'] ?? '';
    $new_workshop = trim($_POST['new_workshop'] ?? '');
    $has_contract = isset($_POST['has_contract']) ? 1 : 0;
    $contract_number = trim($_POST['contract_number'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $vat = isset($_POST['vat']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');
    $force_save = isset($_POST['force_save']) ? 1 : 0;
    
    // فیلدهای ارجاع
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';
    
    // فیلدهای بارنامه (برای نوع waybill – اگر از این فرم برای بارنامه هم استفاده می‌شود)
    $waybill_number = trim($_POST['waybill_number'] ?? '');
    $sender_name = trim($_POST['sender_name'] ?? '');
    $receiver_name = trim($_POST['receiver_name'] ?? '');
    $cargo_description = trim($_POST['cargo_description'] ?? '');
    $loading_origin = trim($_POST['loading_origin'] ?? '');
    $discharge_destination = trim($_POST['discharge_destination'] ?? '');
    $driver1_name = trim($_POST['driver1_name'] ?? '');
    $driver2_name = trim($_POST['driver2_name'] ?? '');
    $vehicle_plate = trim($_POST['vehicle_plate'] ?? '');
    $quantity = $_POST['quantity'] ?? '';
    $weight = $_POST['weight'] ?? '';
    $carrier_responsible = trim($_POST['carrier_responsible'] ?? '');
    $insurance_company = trim($_POST['insurance_company'] ?? '');
    $waybill_notes = trim($_POST['waybill_notes'] ?? '');
    
    $errors = [];
    
    // اعتبارسنجی پایه
    if (empty($invoice_number)) $errors[] = 'شماره فاکتور الزامی است';
    if (empty($invoice_date)) $errors[] = 'تاریخ فاکتور الزامی است';
    if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (empty($amount) || $amount <= 0) $errors[] = 'مبلغ باید بزرگتر از صفر باشد';
    if (empty($forward_to_department) && empty($forward_to_user)) {
        $errors[] = 'لطفاً حداقل یکی از فیلدهای "ارجاع به بخش" یا "ارجاع به شخص" را انتخاب کنید';
    }
    
    // ایجاد شرکت جدید
    if ($company_id == 'new' && !empty($new_company)) {
        $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
        if ($stmt->execute([$new_company])) {
            $company_id = $pdo->lastInsertId();
            logActivity($_SESSION['user_id'], 'add_company', "شرکت جدید: $new_company");
        } else {
            $errors[] = 'خطا در ایجاد شرکت جدید';
        }
    }
    
    // ایجاد فروشنده جدید
    if ($vendor_id == 'new' && !empty($new_vendor)) {
        $stmt = $pdo->prepare("INSERT INTO vendors (name) VALUES (?)");
        if ($stmt->execute([$new_vendor])) {
            $vendor_id = $pdo->lastInsertId();
            logActivity($_SESSION['user_id'], 'add_vendor', "فروشنده جدید: $new_vendor");
        } else {
            $errors[] = 'خطا در ایجاد فروشنده جدید';
        }
    }
    
    // ایجاد کارگاه جدید
    if ($workshop_id == 'new' && !empty($new_workshop)) {
        $stmt = $pdo->prepare("INSERT INTO workshops (name) VALUES (?)");
        if ($stmt->execute([$new_workshop])) {
            $workshop_id = $pdo->lastInsertId();
            logActivity($_SESSION['user_id'], 'add_workshop', "کارگاه جدید: $new_workshop");
        } else {
            $errors[] = 'خطا در ایجاد کارگاه جدید';
        }
    }
    
    // بررسی آپلود فایل
    if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] != UPLOAD_ERR_OK) {
        $errors[] = 'آپلود فایل فاکتور الزامی است';
    } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 5 * 1024 * 1024;
        if (!in_array($_FILES['invoice_file']['type'], $allowed_types)) {
            $errors[] = 'نوع فایل باید JPEG، PNG یا PDF باشد';
        }
        if ($_FILES['invoice_file']['size'] > $max_size) {
            $errors[] = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد';
        }
    }
    
    // بررسی تکراری بودن شماره فاکتور
    if (empty($errors) || $force_save) {
        $check = $pdo->prepare("SELECT id FROM documents WHERE document_number = ? AND type = 'invoice'");
        $check->execute([$invoice_number]);
        $existing = $check->fetch();
        if ($existing && !$force_save) {
            $duplicate_warning = 'این شماره فاکتور قبلاً ثبت شده است. آیا مطمئن هستید؟';
        }
    }
    
    // ذخیره نهایی
    if (empty($errors) && (empty($duplicate_warning) || $force_save)) {
        $upload_dir = __DIR__ . '/../uploads/invoices/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        $file_ext = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
        $file_name = time() . '_' . $invoice_number . '.' . $file_ext;
        $file_path = 'uploads/invoices/' . $file_name;
        
        if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $file_name)) {
            $doc_number = 'INV-' . date('Ymd') . '-' . rand(100, 999);
            
            $data = [
                'document_number' => $doc_number,
                'type' => 'invoice',
                'title' => 'فاکتور شماره ' . $invoice_number,
                'description' => $description,
                'amount' => $amount,
                'created_by' => $_SESSION['user_id'],
                'company_id' => $company_id,
                'workshop_id' => $workshop_id ?: null,
                'vendor_id' => $vendor_id ?: null,
                'document_date' => $invoice_date,
                'status' => 'forwarded',
                'has_contract' => $has_contract,
                'contract_number' => $contract_number ?: null,
                'vat' => $vat,
                'vat_amount' => $vat ? ($amount * 0.1) : 0,
                'file_path' => $file_path,
                'file_name' => $_FILES['invoice_file']['name'],
                'current_holder_department_id' => $forward_to_department ?: null,
                'current_holder_user_id' => $forward_to_user ?: null,
                'waybill_number' => $waybill_number ?: null,
                'sender_name' => $sender_name ?: null,
                'receiver_name' => $receiver_name ?: null,
                'cargo_description' => $cargo_description ?: null,
                'loading_origin' => $loading_origin ?: null,
                'discharge_destination' => $discharge_destination ?: null,
                'driver1_name' => $driver1_name ?: null,
                'driver2_name' => $driver2_name ?: null,
                'vehicle_plate' => $vehicle_plate ?: null,
                'quantity' => $quantity ?: null,
                'weight' => $weight ?: null,
                'carrier_responsible' => $carrier_responsible ?: null,
                'insurance_company' => $insurance_company ?: null,
                'waybill_notes' => $waybill_notes ?: null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO documents ($fields) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute($data)) {
                $doc_id = $pdo->lastInsertId();
                $forward_stmt = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action) VALUES (?, ?, ?, ?, 'forward')");
                $forward_stmt->execute([$doc_id, $_SESSION['user_id'], $forward_to_department ?: null, $forward_to_user ?: null]);
                logActivity($_SESSION['user_id'], 'create_invoice', "فاکتور جدید: $invoice_number", $doc_id);
                $success = 'فاکتور با موفقیت ثبت و ارسال شد. شماره پیگیری: ' . $doc_number;
            } else {
                $errors[] = 'خطا در ثبت فاکتور';
            }
        } else {
            $errors[] = 'خطا در آپلود فایل';
        }
    }
}

$page_title = 'ایجاد فاکتور جدید';
ob_start();
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
    .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; }
    .btn-back { background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; }
</style>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1>ایجاد فاکتور جدید</h1>
    <a href="inbox.php" class="btn-back"><i class="fas fa-arrow-right"></i> بازگشت</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="error"><ul><?php foreach ($errors as $err) echo "<li>$err</li>"; ?></ul></div>
<?php endif; ?>
<?php if ($duplicate_warning): ?>
    <div class="warning">
        <p><?php echo $duplicate_warning; ?></p>
        <form method="POST" enctype="multipart/form-data" style="margin-top: 10px;">
            <?php foreach ($_POST as $key => $value): if (is_array($value)) continue; ?>
                <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="force_save" value="1">
            <button type="submit" name="create_invoice" style="background: #f39c12; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">بله، ثبت شود</button>
            <a href="invoice-create.php" style="background: #95a5a6; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none; margin-left: 10px;">خیر، انصراف</a>
        </form>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="success"><?php echo $success; ?></div>
<?php endif; ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST" enctype="multipart/form-data" id="invoiceForm">
        <div class="row-2col">
            <div><label>شماره فاکتور <span style="color: red;">*</span></label><input type="text" name="invoice_number" required value="<?php echo htmlspecialchars($_POST['invoice_number'] ?? ''); ?>"></div>
            <div><label>تاریخ فاکتور <span style="color: red;">*</span></label><input type="text" name="invoice_date" value="<?php echo htmlspecialchars($_POST['invoice_date'] ?? jdate('Y/m/d')); ?>" required placeholder="۱۴۰۴/۱۲/۰۷"><small>مثال: ۱۴۰۴/۱۲/۰۷</small></div>
        </div>

        <div class="form-group">
            <label>انتخاب شرکت <span style="color: red;">*</span></label>
            <div style="display: flex; gap: 10px;">
                <select name="company_id" id="companySelect" required style="flex:1;">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo $comp['id']; ?>" <?php echo ($_POST['company_id'] ?? '') == $comp['id'] ? 'selected' : ''; ?>><?php echo $comp['name']; ?></option>
                    <?php endforeach; ?>
                    <option value="new">➕ ایجاد شرکت جدید</option>
                </select>
                <input type="text" name="new_company" id="newCompanyInput" placeholder="نام شرکت جدید" value="<?php echo htmlspecialchars($_POST['new_company'] ?? ''); ?>" style="display: none; flex:1;">
            </div>
        </div>

        <div class="form-group">
            <label>کارگاه/بخش پروژه</label>
            <div style="display: flex; gap: 10px;">
                <select name="workshop_id" id="workshopSelect" style="flex:1;">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($workshops as $ws): ?>
                        <option value="<?php echo $ws['id']; ?>" <?php echo ($_POST['workshop_id'] ?? '') == $ws['id'] ? 'selected' : ''; ?>><?php echo $ws['name']; ?></option>
                    <?php endforeach; ?>
                    <option value="new">➕ ایجاد کارگاه جدید</option>
                </select>
                <input type="text" name="new_workshop" id="newWorkshopInput" placeholder="نام کارگاه جدید" value="<?php echo htmlspecialchars($_POST['new_workshop'] ?? ''); ?>" style="display: none; flex:1;">
            </div>
        </div>

        <div class="form-group">
            <label>فروشنده/فروشگاه <span style="color: red;">*</span></label>
            <div style="display: flex; gap: 10px;">
                <select name="vendor_id" id="vendorSelect" style="flex:1;">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>" data-contract="<?php echo htmlspecialchars($vendor['contract_number'] ?? ''); ?>"><?php echo $vendor['name']; ?></option>
                    <?php endforeach; ?>
                    <option value="new">➕ ایجاد فروشنده جدید</option>
                </select>
                <input type="text" name="new_vendor" id="newVendorInput" placeholder="نام فروشنده جدید" value="<?php echo htmlspecialchars($_POST['new_vendor'] ?? ''); ?>" style="display: none; flex:1;">
            </div>
        </div>

        <div class="form-group">
            <label><input type="checkbox" name="has_contract" id="hasContract" <?php echo isset($_POST['has_contract']) ? 'checked' : ''; ?>> دارای قرارداد</label>
            <div id="contractField" style="display: <?php echo isset($_POST['has_contract']) ? 'block' : 'none'; ?>; margin-top:10px;">
                <input type="text" name="contract_number" placeholder="شماره قرارداد" value="<?php echo htmlspecialchars($_POST['contract_number'] ?? ''); ?>">
            </div>
        </div>

        <div class="row-2col">
            <div><label>مبلغ (ریال) <span style="color: red;">*</span></label><input type="text" name="amount" id="amountInput" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" required></div>
            <div><label><input type="checkbox" name="vat" <?php echo isset($_POST['vat']) ? 'checked' : ''; ?>> مشمول ارزش افزوده (۱۰٪)</label></div>
        </div>

        <!-- فیلدهای بارنامه (اختیاری - می‌توانند پنهان شوند تا فقط در صورت انتخاب نوع بارنامه نمایش داده شوند) -->
        <div id="waybillFields" style="display: none;">
            <h3>اطلاعات بارنامه</h3>
            <div class="row-3col">
                <input type="text" name="waybill_number" placeholder="شماره بارنامه">
                <input type="text" name="sender_name" placeholder="نام فرستنده">
                <input type="text" name="receiver_name" placeholder="نام گیرنده">
                <input type="text" name="cargo_description" placeholder="نام محموله">
                <input type="text" name="loading_origin" placeholder="مبدا بارگیری">
                <input type="text" name="discharge_destination" placeholder="مقصد تخلیه">
                <input type="text" name="driver1_name" placeholder="راننده اول">
                <input type="text" name="driver2_name" placeholder="راننده دوم">
                <input type="text" name="vehicle_plate" placeholder="شماره پلاک">
                <input type="text" name="quantity" placeholder="تعداد">
                <input type="text" name="weight" placeholder="وزن">
                <input type="text" name="carrier_responsible" placeholder="مسئول حمل">
                <input type="text" name="insurance_company" placeholder="شرکت بیمه">
                <textarea name="waybill_notes" placeholder="توضیحات بارنامه" rows="2"></textarea>
            </div>
        </div>

        <!-- فیلدهای ارجاع -->
        <div style="background: #f0f8ff; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
            <h3>🔁 ارجاع سند (حداقل یکی)</h3>
            <div class="row-2col">
                <div><label>ارجاع به بخش</label><select name="forward_to_department" id="forward_to_department"><?php foreach ($departments as $dept): ?><option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option><?php endforeach; ?></select></div>
                <div><label>ارجاع به شخص</label><select name="forward_to_user" id="forward_to_user"><?php foreach ($users as $user): ?><option value="<?php echo $user['id']; ?>"><?php echo $user['full_name']; ?> (<?php echo $user['username']; ?>)</option><?php endforeach; ?></select></div>
            </div>
        </div>

        <div class="form-group">
            <label>آپلود فایل فاکتور <span style="color: red;">*</span></label>
            <input type="file" name="invoice_file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf" required>
            <small>فرمت‌های مجاز: jpg, jpeg, png, pdf | حداکثر حجم: ۵ مگابایت</small>
            <div id="filePreview" style="margin-top: 15px; display: none;"><img id="imagePreview" src="#" style="max-width: 200px; max-height: 200px;"></div>
        </div>

        <div class="form-group"><label>توضیحات</label><textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea></div>

        <button type="submit" name="create_invoice" class="btn-submit">ثبت و ارسال فاکتور</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() { $('#vendorSelect').select2({ width: '100%', placeholder: 'جستجوی فروشنده...' }); });
document.getElementById('fileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('filePreview');
    const imagePreview = document.getElementById('imagePreview');
    if (file && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) { imagePreview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(file);
    } else { preview.style.display = 'none'; }
});
function formatNumber(input) {
    let value = input.value.replace(/,/g, '');
    if (!isNaN(value) && value !== '') input.value = Number(value).toLocaleString();
}
document.getElementById('companySelect').addEventListener('change', function() {
    const newInput = document.getElementById('newCompanyInput');
    if (this.value === 'new') { newInput.style.display = 'block'; newInput.required = true; }
    else { newInput.style.display = 'none'; newInput.required = false; }
});
document.getElementById('workshopSelect').addEventListener('change', function() {
    const newInput = document.getElementById('newWorkshopInput');
    if (this.value === 'new') { newInput.style.display = 'block'; newInput.required = true; }
    else { newInput.style.display = 'none'; newInput.required = false; }
});
document.getElementById('vendorSelect').addEventListener('change', function() {
    const newInput = document.getElementById('newVendorInput');
    if (this.value === 'new') { newInput.style.display = 'block'; newInput.required = true; }
    else { newInput.style.display = 'none'; newInput.required = false; }
});
document.getElementById('hasContract').addEventListener('change', function() {
    document.getElementById('contractField').style.display = this.checked ? 'block' : 'none';
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>