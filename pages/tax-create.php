<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('send_to_tax')) {
    header('Location: tax.php');
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

// دریافت لیست شرکت‌ها
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// دریافت لیست بخش‌ها
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();

// دریافت لیست همه کاربران
$users = $pdo->query("SELECT id, full_name, department_id FROM users ORDER BY full_name")->fetchAll();

$error = '';
$success = '';

// دریافت فاکتورها بر اساس شرکت (برای AJAX)
if (isset($_GET['get_invoices']) && isset($_GET['company_id'])) {
    $company_id = $_GET['company_id'];
    $stmt = $pdo->prepare("SELECT id, document_number, title FROM documents WHERE type = 'invoice' AND company_id = ? ORDER BY created_at DESC");
    $stmt->execute([$company_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($invoices);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_tax'])) {
    $title = trim($_POST['title'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $tax_detail_code = trim($_POST['tax_detail_code'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $to_invoice_number = trim($_POST['to_invoice_number'] ?? '');
    $record_date = trim($_POST['record_date'] ?? jdate('Y/m/d'));
    $followup_days = (int)($_POST['followup_days'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';
    
    if (empty($title)) {
        $error = 'لطفاً عنوان سند را وارد کنید';
    } elseif (empty($company_id)) {
        $error = 'لطفاً شرکت را انتخاب کنید';
    } elseif (empty($amount) || $amount <= 0) {
        $error = 'لطفاً مبلغ معتبر وارد کنید';
    } elseif (empty($tax_id)) {
        $error = 'لطفاً شناسه مالیاتی را وارد کنید';
    } elseif (empty($forward_to_department) && empty($forward_to_user)) {
        $error = 'لطفاً حداقل یکی از فیلدهای ارجاع (بخش یا شخص) را انتخاب کنید';
    } else {
        if (!empty($forward_to_user)) {
            $current_holder_user_id = $forward_to_user;
            $current_holder_department_id = null;
        } elseif (!empty($forward_to_department)) {
            $current_holder_user_id = null;
            $current_holder_department_id = $forward_to_department;
        } else {
            $current_holder_user_id = null;
            $current_holder_department_id = null;
        }
        
        $deadline = null;
        if ($followup_days > 0) {
            $deadline = date('Y-m-d H:i:s', strtotime('+' . $followup_days . ' days'));
        }
        
        $file_path = null;
        $file_name = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $max_size = 5 * 1024 * 1024;
            if (in_array($_FILES['attachment']['type'], $allowed) && $_FILES['attachment']['size'] <= $max_size) {
                $upload_dir = __DIR__ . '/../uploads/tax/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $file_name = time() . '_tax_' . rand(1000, 9999) . '.' . $ext;
                $file_path = 'uploads/tax/' . $file_name;
                move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $file_name);
            } else {
                $error = 'فرمت فایل نامعتبر یا حجم بیش از حد مجاز (۵ مگابایت)';
            }
        }
        
        if (!isset($error)) {
            $doc_number = 'TAX-' . date('Ymd') . '-' . rand(100, 999);
            
            $data = [
                'document_number' => $doc_number,
                'type' => 'tax',
                'title' => $title,
                'description' => $description,
                'amount' => $amount,
                'created_by' => $_SESSION['user_id'],
                'company_id' => $company_id,
                'document_date' => $record_date,
                'status' => 'pending',
                'tax_detail_code' => $tax_detail_code,
                'tax_id' => $tax_id,
                'to_invoice_number' => $to_invoice_number ?: null,
                'tax_status' => 'pending',
                'approval_deadline' => $deadline,
                'file_path' => $file_path,
                'file_name' => $_FILES['attachment']['name'] ?? null,
                'current_holder_department_id' => $current_holder_department_id,
                'current_holder_user_id' => $current_holder_user_id,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO documents ($fields) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute($data)) {
                $doc_id = $pdo->lastInsertId();
                
                $forward = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
                $forward->execute([
                    $doc_id, 
                    $_SESSION['user_id'], 
                    $current_holder_department_id, 
                    $current_holder_user_id,
                    'سند جدید ایجاد و ارجاع شد'
                ]);
                
                logActivity($_SESSION['user_id'], 'create_tax', "سند مالیاتی جدید: $title", $doc_id);
                $success = 'سند مالیاتی با موفقیت ثبت و ارسال شد. شماره: ' . $doc_number;
                $_POST = [];
            } else {
                $error = 'خطا در ثبت سند: ' . implode(' ', $stmt->errorInfo());
            }
        }
    }
}

$page_title = 'ایجاد سند مالیاتی جدید';
ob_start();
?>

<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; }
    .btn-back { background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; }
    .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .alert-danger { background: #f8d7da; color: #721c24; }
    .alert-success { background: #d4edda; color: #155724; }
    .required { color: red; }
    .help-text { font-size: 12px; color: #7f8c8d; margin-top: 5px; display: block; }
    .forward-section {
        background: #f0f8ff; 
        padding: 20px; 
        border-radius: 10px; 
        margin: 20px 0;
    }
    .forward-title {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 15px;
        color: #2c3e50;
    }
    .file-preview {
        margin-top: 15px;
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
        padding: 10px;
        background: #f9f9f9;
        border-radius: 8px;
    }
    .file-preview img {
        max-width: 150px;
        max-height: 150px;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 5px;
        background: white;
    }
    .file-preview .file-info {
        font-size: 12px;
        color: #7f8c8d;
    }
    .invoice-select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        background: white;
    }
</style>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1>ایجاد سند مالیاتی جدید</h1>
    <a href="tax.php" class="btn-back">بازگشت</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <script>
        setTimeout(function() { window.location.href = 'tax.php'; }, 2000);
    </script>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <div class="row-2col">
        <div class="form-group">
            <label>عنوان سند <span class="required">*</span></label>
            <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>انتخاب شرکت <span class="required">*</span></label>
            <select name="company_id" id="companySelect" required>
                <option value="">انتخاب کنید</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo ($_POST['company_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>><?php echo $c['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row-2col">
        <div class="form-group">
            <label>مبلغ (تومان) <span class="required">*</span></label>
            <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label>تاریخ ثبت (شمسی)</label>
            <input type="text" name="record_date" value="<?php echo htmlspecialchars($_POST['record_date'] ?? jdate('Y/m/d')); ?>" placeholder="۱۴۰۴/۱۲/۰۷">
        </div>
    </div>

    <div class="row-3col">
        <div class="form-group">
            <label>کد تفصیل حساب</label>
            <input type="text" name="tax_detail_code" value="<?php echo htmlspecialchars($_POST['tax_detail_code'] ?? ''); ?>">
            <small class="help-text">اختیاری</small>
        </div>
        <div class="form-group">
            <label>شناسه مالیاتی <span class="required">*</span></label>
            <input type="text" name="tax_id" value="<?php echo htmlspecialchars($_POST['tax_id'] ?? ''); ?>" required>
            <small class="help-text">اجباری</small>
        </div>
        <div class="form-group">
            <label>📄 به شماره فاکتور</label>
            <select name="to_invoice_number" id="toInvoiceSelect" class="invoice-select">
                <option value="">ابتدا شرکت را انتخاب کنید</option>
            </select>
            <small class="help-text">اختیاری - پس از انتخاب شرکت، فاکتورهای مرتبط نمایش داده می‌شوند</small>
        </div>
    </div>

    <div class="form-group">
        <label>مهلت پیگیری (روز)</label>
        <input type="number" name="followup_days" min="0" value="<?php echo htmlspecialchars($_POST['followup_days'] ?? 0); ?>">
        <small class="help-text">تعداد روز تا مهلت پیگیری (۰ = بدون مهلت)</small>
    </div>

    <div class="form-group">
        <label>ضمیمه (عکس یا PDF)</label>
        <input type="file" name="attachment" id="fileInput" accept=".jpg,.jpeg,.png,.pdf">
        <small class="help-text">حداکثر حجم ۵ مگابایت</small>
        <div id="filePreview" class="file-preview" style="display: none;"></div>
    </div>

    <div class="form-group">
        <label>توضیحات</label>
        <textarea name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
    </div>

    <!-- بخش ارجاع -->
    <div class="forward-section">
        <div class="forward-title">🔁 ارجاع سند <span class="required">*</span></div>
        <small class="help-text" style="margin-bottom: 15px; display: block;">حداقل یکی از گزینه‌های زیر باید انتخاب شود</small>
        
        <div class="row-2col">
            <div class="form-group">
                <label>📋 ارجاع به بخش</label>
                <select name="forward_to_department">
                    <option value="">--- انتخاب کنید ---</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo ($_POST['forward_to_department'] ?? '') == $dept['id'] ? 'selected' : ''; ?>>
                            🏢 <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="help-text">در صورت انتخاب، سند برای <strong>همه کاربران این بخش</strong> قابل مشاهده خواهد بود</small>
            </div>
            
            <div class="form-group">
                <label>👤 ارجاع به شخص</label>
                <select name="forward_to_user">
                    <option value="">--- انتخاب کنید ---</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo ($_POST['forward_to_user'] ?? '') == $user['id'] ? 'selected' : ''; ?>>
                            👤 <?php echo htmlspecialchars($user['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="help-text">در صورت انتخاب، سند <strong>فقط برای این شخص خاص</strong> قابل مشاهده خواهد بود</small>
            </div>
        </div>
        
        <div id="selection-warning" style="color: #e74c3c; font-size: 13px; margin-top: 15px; display: none; background: #fef0e0; padding: 10px; border-radius: 5px;">
            ⚠️ لطفاً حداقل یکی از گزینه‌های ارجاع (بخش یا شخص) را انتخاب کنید
        </div>
    </div>

    <button type="submit" name="create_tax" class="btn-submit" id="submitBtn">ثبت و ارسال سند</button>
</form>

<script>
// فرمت اعداد
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}

// بارگذاری فاکتورها با Fetch API (بدون jQuery)
document.getElementById('companySelect').addEventListener('change', function() {
    var companyId = this.value;
    var invoiceSelect = document.getElementById('toInvoiceSelect');
    
    if (companyId) {
        invoiceSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
        
        fetch(window.location.href + '?get_invoices=1&company_id=' + companyId)
            .then(response => response.json())
            .then(data => {
                var options = '<option value="">انتخاب کنید (اختیاری)</option>';
                if (data && data.length > 0) {
                    for (var i = 0; i < data.length; i++) {
                        options += '<option value="' + data[i].document_number + '">' + data[i].document_number + ' - ' + data[i].title + '</option>';
                    }
                } else {
                    options += '<option value="">هیچ فاکتوری برای این شرکت یافت نشد</option>';
                }
                invoiceSelect.innerHTML = options;
            })
            .catch(error => {
                console.error('Error:', error);
                invoiceSelect.innerHTML = '<option value="">خطا در بارگذاری فاکتورها</option>';
            });
    } else {
        invoiceSelect.innerHTML = '<option value="">ابتدا شرکت را انتخاب کنید</option>';
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
        } else if (file.type === 'application/pdf') {
            html = '<div class="file-info">📄 PDF: ' + file.name + ' (' + fileSize + ' KB)</div>';
            previewDiv.innerHTML = html;
            previewDiv.style.display = 'flex';
        } else {
            html = '<div class="file-info">📎 ' + file.name + ' (' + fileSize + ' KB)</div>';
            previewDiv.innerHTML = html;
            previewDiv.style.display = 'flex';
        }
    } else { 
        previewDiv.style.display = 'none'; 
    }
});

// اعتبارسنجی ارجاع
document.getElementById('submitBtn').addEventListener('click', function(e) {
    var dept = document.querySelector('select[name="forward_to_department"]').value;
    var user = document.querySelector('select[name="forward_to_user"]').value;
    var warning = document.getElementById('selection-warning');
    
    if (dept === '' && user === '') {
        e.preventDefault();
        warning.style.display = 'block';
        warning.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => { warning.style.display = 'none'; }, 4000);
    } else {
        warning.style.display = 'none';
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>