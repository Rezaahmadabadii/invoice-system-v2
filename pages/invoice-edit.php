<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
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

// دریافت اطلاعات فاکتور
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'invoice'");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: inbox.php');
    exit;
}

// فقط ایجادکننده می‌تواند ویرایش کند
if ($invoice['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = 'شما مجاز به ویرایش این فاکتور نیستید.';
    header('Location: invoice-view.php?id=' . $id);
    exit;
}

// دریافت لیست‌های مورد نیاز
$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name, contract_number FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT id, name FROM workshops ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

$error = '';
$success = '';
$duplicate_warning = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_invoice'])) {
    // دریافت مقادیر فرم
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = trim($_POST['invoice_date'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $vendor_id = $_POST['vendor_id'] ?? '';
    $workshop_id = $_POST['workshop_id'] ?? '';
    $contract_number = trim($_POST['contract_number'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $discount = str_replace(',', '', $_POST['discount'] ?? 0);
    $total = str_replace(',', '', $_POST['total'] ?? 0);
    $vat = isset($_POST['vat']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');
    $force_save = isset($_POST['force_save']) ? 1 : 0;
    
    // فیلدهای ارجاع
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';
    
    $errors = [];
    
    // اعتبارسنجی پایه
    if (empty($invoice_number)) $errors[] = 'شماره فاکتور الزامی است';
    if (empty($invoice_date)) $errors[] = 'تاریخ فاکتور الزامی است';
    if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (empty($vendor_id)) $errors[] = 'انتخاب فروشنده الزامی است';
    if (empty($amount) || $amount <= 0) $errors[] = 'مبلغ باید بزرگتر از صفر باشد';
    if (empty($forward_to_department) && empty($forward_to_user)) {
        $errors[] = 'لطفاً حداقل یکی از فیلدهای "ارجاع به بخش" یا "ارجاع به شخص" را انتخاب کنید';
    }
    
    // بررسی آپلود فایل جدید (اختیاری)
    $file_path = $invoice['file_path'];
    $file_name = $invoice['file_name'];
    
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 5 * 1024 * 1024;
        if (!in_array($_FILES['invoice_file']['type'], $allowed_types)) {
            $errors[] = 'نوع فایل باید JPEG، PNG یا PDF باشد';
        }
        if ($_FILES['invoice_file']['size'] > $max_size) {
            $errors[] = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد';
        }
        
        if (empty($errors)) {
            $upload_dir = __DIR__ . '/../uploads/invoices/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_ext = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
            
            // نام فایل جدید با مخفف شرکت و شماره فاکتور
            $companyInfo = $pdo->prepare("SELECT short_name FROM companies WHERE id = ?");
            $companyInfo->execute([$company_id]);
            $companyData = $companyInfo->fetch();
            $short_name = $companyData['short_name'] ?? '';
            $final_invoice_number = !empty($short_name) ? $short_name . '-' . $invoice_number : $invoice_number;
            
            $file_name = $final_invoice_number . '.' . $file_ext;
            $file_path = 'uploads/invoices/' . $file_name;
            
            // حذف فایل قبلی
            if ($invoice['file_path'] && file_exists(__DIR__ . '/../' . $invoice['file_path'])) {
                unlink(__DIR__ . '/../' . $invoice['file_path']);
            }
            
            if (!move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $file_name)) {
                $errors[] = 'خطا در آپلود فایل';
            }
        }
    }
    
    // بررسی تکراری بودن شماره فاکتور
    $companyInfo = $pdo->prepare("SELECT short_name FROM companies WHERE id = ?");
    $companyInfo->execute([$company_id]);
    $companyData = $companyInfo->fetch();
    $short_name = $companyData['short_name'] ?? '';
    $final_invoice_number = !empty($short_name) ? $short_name . '-' . $invoice_number : $invoice_number;
    
    if (empty($errors) || $force_save) {
        $check = $pdo->prepare("SELECT id FROM documents WHERE document_number = ? AND type = 'invoice' AND id != ?");
        $check->execute([$final_invoice_number, $id]);
        $existing = $check->fetch();
        if ($existing && !$force_save) {
            $duplicate_warning = 'این شماره فاکتور قبلاً ثبت شده است. آیا مطمئن هستید؟';
        }
    }
    
    // ذخیره نهایی
    if (empty($errors) && (empty($duplicate_warning) || $force_save)) {
        // محاسبه مبلغ نهایی
        $amount_clean = floatval(str_replace(',', '', $amount));
        $discount_clean = floatval(str_replace(',', '', $discount));
        $final_amount = $amount_clean - $discount_clean;
        if ($vat) {
            $final_amount = $final_amount + ($amount_clean * 0.1);
        }
        
        $data = [
            'document_number' => $final_invoice_number,
            'title' => 'فاکتور شماره ' . $invoice_number,
            'description' => $description,
            'amount' => $total > 0 ? $total : $final_amount,
            'company_id' => $company_id,
            'workshop_id' => $workshop_id ?: null,
            'vendor_id' => $vendor_id ?: null,
            'document_date' => $invoice_date,
            'contract_number' => $contract_number ?: null,
            'vat' => $vat,
            'vat_amount' => $vat ? ($amount_clean * 0.1) : 0,
            'file_path' => $file_path,
            'file_name' => $_FILES['invoice_file']['name'] ?? $invoice['file_name'],
            'current_holder_department_id' => $forward_to_department ?: null,
            'current_holder_user_id' => $forward_to_user ?: null,
            'status' => 'forwarded'
        ];
        
        $fields = [];
        $placeholders = [];
        $updateParams = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $updateParams[] = $value;
        }
        $updateParams[] = $id;
        
        $sql = "UPDATE documents SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($updateParams)) {
            // ثبت در تاریخچه ارجاع اگر changed
            if ($forward_to_department != $invoice['current_holder_department_id'] || $forward_to_user != $invoice['current_holder_user_id']) {
                $forward_stmt = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action) VALUES (?, ?, ?, ?, 'forward')");
                $forward_stmt->execute([$id, $_SESSION['user_id'], $forward_to_department ?: null, $forward_to_user ?: null]);
            }
            logActivity($_SESSION['user_id'], 'edit_invoice', "فاکتور ویرایش شد: $invoice_number", $id);
            $success = 'فاکتور با موفقیت ویرایش شد. شماره فاکتور: ' . $final_invoice_number;
        } else {
            $errors[] = 'خطا در ویرایش فاکتور';
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'ویرایش فاکتور';
ob_start();
?>

<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
    .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    .row-4col { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    .btn-submit { 
        background: #27ae60; 
        color: white; 
        border: none; 
        padding: 12px 30px; 
        border-radius: 5px; 
        cursor: pointer; 
        font-size: 16px; 
        display: inline-flex; 
        align-items: center; 
        gap: 12px;
        transition: all 0.3s ease;
    }
    .btn-submit:hover {
        background: #219a52;
        transform: translateY(-2px);
    }
    .btn-back { background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; }
    .total-box { background: #e8f4f8; padding: 15px; border-radius: 8px; text-align: center; }
    .total-box .label { font-size: 14px; color: #2c3e50; }
    .total-box .value { font-size: 20px; font-weight: bold; color: #27ae60; }
    .forward-section { background: #f0f8ff; padding: 20px; border-radius: 10px; margin: 20px 0; }
    .forward-title { font-size: 18px; font-weight: bold; margin-bottom: 15px; color: #2c3e50; }
    .file-preview { margin-top: 15px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; padding: 10px; background: #f9f9f9; border-radius: 8px; }
    .file-preview img { max-width: 150px; max-height: 150px; border: 1px solid #ddd; border-radius: 5px; padding: 5px; background: white; }
    .file-preview .file-info { font-size: 12px; color: #7f8c8d; }
    
    .company-short-name {
        font-size: 11px;
        color: #7f8c8d;
        margin-right: 5px;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="margin: 0;">✏️ ویرایش فاکتور</h1>
    <a href="invoice-view.php?id=<?php echo $id; ?>" class="btn-back">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class="error"><?php echo $error; ?></div>
<?php endif; ?>
<?php if ($duplicate_warning): ?>
    <div class="warning">
        <p><?php echo $duplicate_warning; ?></p>
        <form method="POST" enctype="multipart/form-data" style="margin-top: 10px;">
            <?php foreach ($_POST as $key => $value): if (is_array($value)) continue; ?>
                <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="force_save" value="1">
            <button type="submit" name="edit_invoice" style="background: #f39c12; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">بله، ثبت شود</button>
            <a href="invoice-edit.php?id=<?php echo $id; ?>" style="background: #95a5a6; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none; margin-left: 10px;">خیر، انصراف</a>
        </form>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="success-toast" id="successToast">
        <span style="font-size: 24px;">✅</span>
        <span><?php echo $success; ?></span>
    </div>
    <script>
        setTimeout(function() {
            var toast = document.getElementById('successToast');
            if (toast) toast.style.display = 'none';
        }, 4000);
    </script>
<?php endif; ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST" enctype="multipart/form-data" id="invoiceForm">
        <!-- ردیف اول: شماره فاکتور + تاریخ فاکتور + شماره قرارداد -->
        <div class="row-3col">
            <div class="form-group">
                <label>شماره فاکتور <span style="color: red;">*</span></label>
                <input type="text" name="invoice_number" required placeholder="مثال: 1234" value="<?php echo htmlspecialchars($_POST['invoice_number'] ?? preg_replace('/^[^-]+-/', '', $invoice['document_number'])); ?>">
                <small>شماره فاکتور به صورت خودکار با مخفف شرکت ترکیب می‌شود (مثال: kyhn-1234)</small>
            </div>
            <div class="form-group">
                <label>تاریخ فاکتور (شمسی) <span style="color: red;">*</span></label>
                <input type="text" name="invoice_date" value="<?php echo htmlspecialchars($_POST['invoice_date'] ?? $invoice['document_date']); ?>" required placeholder="مثال: ۱۴۰۴/۱۲/۰۷">
                <small>مثال: 1404/12/07</small>
            </div>
            <div class="form-group">
                <label>📄 شماره قرارداد</label>
                <input type="text" name="contract_number" placeholder="اختیاری" value="<?php echo htmlspecialchars($_POST['contract_number'] ?? $invoice['contract_number']); ?>">
                <small>در صورت وجود قرارداد، شماره آن را وارد کنید</small>
            </div>
        </div>

        <!-- ردیف دوم: انتخاب شرکت + کارگاه + فروشنده -->
        <div class="row-3col">
            <div class="form-group">
                <label>انتخاب شرکت <span style="color: red;">*</span></label>
                <select name="company_id" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo $comp['id']; ?>" data-short="<?php echo $comp['short_name']; ?>" <?php echo (($_POST['company_id'] ?? $invoice['company_id']) == $comp['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($comp['name']); ?>
                            <?php if ($comp['short_name']): ?>
                                <span class="company-short-name">(مخفف: <?php echo htmlspecialchars($comp['short_name']); ?>)</span>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>مخفف شرکت در شماره فاکتور استفاده می‌شود</small>
            </div>
            <div class="form-group">
                <label>کارگاه/بخش پروژه</label>
                <select name="workshop_id">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($workshops as $ws): ?>
                        <option value="<?php echo $ws['id']; ?>" <?php echo (($_POST['workshop_id'] ?? $invoice['workshop_id']) == $ws['id']) ? 'selected' : ''; ?>><?php echo $ws['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>فروشنده/فروشگاه <span style="color: red;">*</span></label>
                <select name="vendor_id" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>" <?php echo (($_POST['vendor_id'] ?? $invoice['vendor_id']) == $vendor['id']) ? 'selected' : ''; ?>><?php echo $vendor['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ردیف سوم: مبلغ پایه + تخفیف + جمع کل + ارزش افزوده -->
        <div class="row-4col">
            <div class="form-group">
                <label>مبلغ پایه (ریال) <span style="color: red;">*</span></label>
                <input type="text" name="amount" id="amountInput" onkeyup="formatNumber(this); calculateTotal();" value="<?php echo htmlspecialchars($_POST['amount'] ?? (isset($invoice['subtotal']) ? number_format($invoice['subtotal']) : number_format($invoice['amount']))); ?>" required>
            </div>
            <div class="form-group">
                <label>تخفیف (ریال)</label>
                <input type="text" name="discount" id="discountInput" onkeyup="formatNumber(this); calculateTotal();" value="<?php echo htmlspecialchars($_POST['discount'] ?? number_format($invoice['discount'] ?? 0)); ?>">
            </div>
            <div class="total-box">
                <div class="label">جمع کل</div>
                <div class="value" id="totalDisplay">0</div>
                <input type="hidden" name="total" id="totalInput" value="0">
            </div>
            <div class="form-group" style="display: flex; align-items: center; justify-content: center;">
                <label style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="vat" <?php echo (($_POST['vat'] ?? $invoice['vat']) ? 'checked' : ''); ?> id="vatCheckbox" onchange="calculateTotal();"> 
                    ارزش افزوده (۱۰٪)
                </label>
            </div>
        </div>

        <!-- ارجاع -->
        <div class="forward-section">
            <div class="forward-title">🔁 ارجاع سند (حداقل یکی)</div>
            <div class="row-2col" style="margin-top: 15px;">
                <div class="form-group">
                    <label>ارجاع به بخش</label>
                    <select name="forward_to_department">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo (($_POST['forward_to_department'] ?? $invoice['current_holder_department_id']) == $dept['id']) ? 'selected' : ''; ?>><?php echo $dept['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>ارجاع به شخص</label>
                    <select name="forward_to_user">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo (($_POST['forward_to_user'] ?? $invoice['current_holder_user_id']) == $user['id']) ? 'selected' : ''; ?>><?php echo $user['full_name']; ?> (<?php echo $user['username']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="selectionWarning" style="color: #e74c3c; font-size: 13px; margin-top: 10px; display: none;">
                ⚠️ لطفاً حداقل یکی از گزینه‌های ارجاع (بخش یا شخص) را انتخاب کنید
            </div>
        </div>

        <!-- آپلود فایل با پیش‌نمایش -->
        <div class="form-group">
            <label>آپلود فایل فاکتور (اختیاری - در صورت آپلود، فایل قبلی جایگزین می‌شود)</label>
            <input type="file" name="invoice_file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf">
            <small>فرمت‌های مجاز: jpg, jpeg, png, pdf | حداکثر حجم: ۵ مگابایت</small>
            <?php if ($invoice['file_path']): ?>
                <div class="file-preview" style="margin-top: 10px; display: flex;">
                    <div class="file-info">📎 فایل فعلی: <a href="/invoice-system-v2/<?php echo $invoice['file_path']; ?>" target="_blank"><?php echo htmlspecialchars($invoice['file_name']); ?></a></div>
                </div>
            <?php endif; ?>
            <div id="filePreview" class="file-preview" style="display: none;"></div>
        </div>

        <div class="form-group">
            <label>توضیحات</label>
            <textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? $invoice['description']); ?></textarea>
        </div>

        <button type="submit" name="edit_invoice" class="btn-submit" id="submitBtn">
            <span class="btn-icon">✏️</span>
            ذخیره تغییرات
        </button>
    </form>
</div>

<script>
function formatNumber(input) {
    let value = input.value.replace(/,/g, '');
    if (!isNaN(value) && value !== '') input.value = Number(value).toLocaleString();
}

function calculateTotal() {
    let amount = parseFloat(document.getElementById('amountInput').value.replace(/,/g, '')) || 0;
    let discount = parseFloat(document.getElementById('discountInput').value.replace(/,/g, '')) || 0;
    let vat = document.getElementById('vatCheckbox').checked;
    
    let total = amount - discount;
    if (vat) {
        total = total + (amount * 0.1);
    }
    
    document.getElementById('totalDisplay').innerHTML = total.toLocaleString() + ' ریال';
    document.getElementById('totalInput').value = total;
}

// اعتبارسنجی ارجاع
document.getElementById('submitBtn').addEventListener('click', function(e) {
    let dept = document.querySelector('select[name="forward_to_department"]').value;
    let user = document.querySelector('select[name="forward_to_user"]').value;
    let warning = document.getElementById('selectionWarning');
    
    if (dept === '' && user === '') {
        e.preventDefault();
        warning.style.display = 'block';
        setTimeout(() => { warning.style.display = 'none'; }, 3000);
    } else {
        warning.style.display = 'none';
    }
});

// پیش‌نمایش فایل
document.getElementById('fileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const previewDiv = document.getElementById('filePreview');
    
    if (file) {
        const fileSize = (file.size / 1024).toFixed(1);
        let html = '';
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                html = '<img src="' + e.target.result + '" alt="پیش‌نمایش">';
                html += '<div class="file-info">' + file.name + ' (' + fileSize + ' KB)</div>';
                previewDiv.innerHTML = html;
                previewDiv.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        } else {
            html = '<div class="file-info">📄 ' + file.name + ' (' + fileSize + ' KB)</div>';
            previewDiv.innerHTML = html;
            previewDiv.style.display = 'flex';
        }
    } else { 
        previewDiv.style.display = 'none'; 
    }
});

calculateTotal();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>