<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';

session_start();

// ========== بخش AJAX باید قبل از هر خروجی دیگری اجرا شود ==========
if (isset($_GET['get_invoices']) && isset($_GET['company_id'])) {
    // هدر JSON را قبل از هر چیز تنظیم کن
    header('Content-Type: application/json');
    
    try {
        $host = 'localhost';
        $dbname = 'invoice_system';
        $username_db = 'root';
        $password_db = '';
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $company_id = (int)$_GET['company_id'];
        $stmt_inv = $pdo->prepare("SELECT id, document_number, title FROM documents WHERE type = 'invoice' AND company_id = ? ORDER BY created_at DESC");
        $stmt_inv->execute([$company_id]);
        $invoices = $stmt_inv->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($invoices);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit; // مهم: بعد از خروجی JSON، اجرای اسکریپت متوقف شود
}
// ================================================================

if (!isset($_SESSION['user_id']) || !function_exists('hasPermission') || !hasPermission('edit_tax')) {
    header('Location: tax.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
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

// دریافت اطلاعات سند
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'tax'");
$stmt->execute([$id]);
$tax = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tax) {
    header('Location: tax.php');
    exit;
}

// فقط ایجادکننده می‌تواند ویرایش کند
if ($tax['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = 'شما مجاز به ویرایش این سند نیستید.';
    header('Location: tax-view.php?id=' . $id);
    exit;
}

// دریافت لیست‌ها
$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT id, full_name, username, department_id FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// ==================== محاسبه صحیح مهلت پیگیری ====================
$current_followup_days = 0;
if (!empty($tax['approval_deadline']) && !empty($tax['document_date'])) {
    try {
        $doc_date_parts = explode('/', $tax['document_date']);
        if (count($doc_date_parts) == 3) {
            list($g_year, $g_month, $g_day) = jDateTime::toGregorian($doc_date_parts[0], $doc_date_parts[1], $doc_date_parts[2]);
            $doc_timestamp = mktime(0, 0, 0, $g_month, $g_day, $g_year);
        } else {
            $doc_timestamp = strtotime($tax['document_date']);
        }
        
        $deadline_timestamp = strtotime($tax['approval_deadline']);
        
        if ($doc_timestamp && $deadline_timestamp && $deadline_timestamp > $doc_timestamp) {
            $diff_seconds = $deadline_timestamp - $doc_timestamp;
            $current_followup_days = floor($diff_seconds / (60 * 60 * 24));
            if ($diff_seconds > 0 && $current_followup_days == 0) {
                $current_followup_days = 1;
            }
        }
    } catch (Exception $e) {
        $current_followup_days = 0;
    }
}
// ========================================================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_tax'])) {
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
        $error = 'لطفاً حداقل یکی از فیلدهای ارجاع را انتخاب کنید';
    } else {
        if (!empty($forward_to_user)) {
            $new_holder_user_id = $forward_to_user;
            $new_holder_department_id = null;
        } else {
            $new_holder_user_id = null;
            $new_holder_department_id = $forward_to_department;
        }
        
        $deadline = null;
        if ($followup_days > 0) {
            $date_parts = explode('/', $record_date);
            if (count($date_parts) == 3) {
                list($g_year, $g_month, $g_day) = jDateTime::toGregorian($date_parts[0], $date_parts[1], $date_parts[2]);
                $base_timestamp = mktime(0, 0, 0, $g_month, $g_day, $g_year);
            } else {
                $base_timestamp = strtotime($record_date);
            }
            $deadline_timestamp = strtotime("+{$followup_days} days", $base_timestamp);
            $deadline = date('Y-m-d H:i:s', $deadline_timestamp);
        }
        
        // آپلود فایل
        $file_path = $tax['file_path'];
        $file_name = $tax['file_name'];
        
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $max_size = 5 * 1024 * 1024;
            if (in_array($_FILES['attachment']['type'], $allowed) && $_FILES['attachment']['size'] <= $max_size) {
                $upload_dir = __DIR__ . '/../uploads/tax/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $new_file_name = time() . '_tax_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $new_file_name)) {
                    if ($tax['file_path'] && file_exists(__DIR__ . '/../' . $tax['file_path'])) {
                        unlink(__DIR__ . '/../' . $tax['file_path']);
                    }
                    $file_path = 'uploads/tax/' . $new_file_name;
                    $file_name = $_FILES['attachment']['name'];
                } else {
                    $error = 'آپلود فایل با شکست مواجه شد.';
                }
            } else {
                $error = 'فرمت فایل نامعتبر یا حجم بیش از حد مجاز (۵ مگابایت)';
            }
        }
        
        if (empty($error)) {
            $update_sql = "
                UPDATE documents SET 
                    title = ?,
                    company_id = ?,
                    amount = ?,
                    tax_detail_code = ?,
                    tax_id = ?,
                    to_invoice_number = ?,
                    document_date = ?,
                    approval_deadline = ?,
                    description = ?,
                    current_holder_department_id = ?,
                    current_holder_user_id = ?,
                    file_path = ?,
                    file_name = ?
                WHERE id = ?
            ";
            
            $update = $pdo->prepare($update_sql);
            $result = $update->execute([
                $title, $company_id, $amount, $tax_detail_code, $tax_id,
                $to_invoice_number, $record_date, $deadline, $description,
                $new_holder_department_id, $new_holder_user_id, $file_path, $file_name, $id
            ]);
            
            if ($result) {
                $old_holder_dept = $tax['current_holder_department_id'];
                $old_holder_user = $tax['current_holder_user_id'];
                if ($old_holder_dept != $new_holder_department_id || $old_holder_user != $new_holder_user_id) {
                    $forward = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'reassign', ?)");
                    $forward->execute([$id, $_SESSION['user_id'], $new_holder_department_id, $new_holder_user_id, 'تغییر ارجاع در ویرایش سند']);
                }
                
                if (function_exists('logActivity')) {
                    logActivity($_SESSION['user_id'], 'edit_tax', "ویرایش سند مالیاتی: $title", $id);
                }
                $success = 'سند مالیاتی با موفقیت ویرایش شد.';
            } else {
                $error = 'خطا در ویرایش سند';
            }
        }
    }
}

$page_title = 'ویرایش سند مالیاتی';
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
    .forward-section { background: #f0f8ff; padding: 20px; border-radius: 10px; margin: 20px 0; }
    .current-file { background: #e8f0fe; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
    .current-file img { max-width: 150px; max-height: 150px; border: 1px solid #ddd; border-radius: 5px; }
    .file-preview { margin-top: 15px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; padding: 10px; background: #f9f9f9; border-radius: 8px; }
    .file-preview img { max-width: 150px; border: 1px solid #ddd; border-radius: 5px; }
    .invoice-select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; background: white; }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>✏️ ویرایش سند مالیاتی</h1>
    <a href="tax-view.php?id=<?php echo $id; ?>" class="btn-back">بازگشت</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <script>setTimeout(function() { window.location.href = 'tax-view.php?id=<?php echo $id; ?>'; }, 1500);</script>
<?php endif; ?>

<?php if (!$success): ?>
<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST" enctype="multipart/form-data">
        <div class="row-2col">
            <div class="form-group">
                <label>عنوان سند <span class="required">*</span></label>
                <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? $tax['title']); ?>">
            </div>
            <div class="form-group">
                <label>انتخاب شرکت <span class="required">*</span></label>
                <select name="company_id" id="companySelect" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo (($_POST['company_id'] ?? $tax['company_id']) == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row-2col">
            <div class="form-group">
                <label>مبلغ (تومان) <span class="required">*</span></label>
                <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? number_format($tax['amount'])); ?>" required>
            </div>
            <div class="form-group">
                <label>تاریخ ثبت (شمسی)</label>
                <input type="text" name="record_date" id="record_date" value="<?php echo htmlspecialchars($_POST['record_date'] ?? $tax['document_date']); ?>">
            </div>
        </div>

        <div class="row-3col">
            <div class="form-group">
                <label>کد تفصیل حساب</label>
                <input type="text" name="tax_detail_code" value="<?php echo htmlspecialchars($_POST['tax_detail_code'] ?? $tax['tax_detail_code']); ?>">
            </div>
            <div class="form-group">
                <label>شناسه مالیاتی <span class="required">*</span></label>
                <input type="text" name="tax_id" required value="<?php echo htmlspecialchars($_POST['tax_id'] ?? $tax['tax_id']); ?>">
            </div>
            <div class="form-group">
                <label>📄 به شماره فاکتور</label>
                <select name="to_invoice_number" id="toInvoiceSelect" class="invoice-select">
                    <option value="">ابتدا شرکت را انتخاب کنید</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>مهلت پیگیری (روز)</label>
            <input type="number" name="followup_days" id="followup_days" min="0" value="<?php echo htmlspecialchars($_POST['followup_days'] ?? $current_followup_days); ?>">
            <small class="help-text">مهلت فعلی: <?php echo $current_followup_days > 0 ? $current_followup_days . ' روز' : 'بدون مهلت'; ?></small>
        </div>

        <!-- نمایش فایل فعلی با بررسی وجود فایل -->
        <div class="current-file">
            <strong>📎 فایل ضمیمه فعلی:</strong>
            <?php 
            // بررسی صحیح وجود فایل
            $file_full_path = __DIR__ . '/../' . $tax['file_path'];
            if (!empty($tax['file_path']) && file_exists($file_full_path)):
                $file_url = '/invoice-system-v2/' . $tax['file_path']; // مسیر کامل از روت پروژه
                $file_ext = strtolower(pathinfo($tax['file_path'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                    <div style="margin-top: 10px;">
                        <img src="<?php echo $file_url; ?>" alt="پیش‌نمایش" style="max-width: 150px; border: 1px solid #ddd; border-radius: 5px;">
                        <div class="help-text"><?php echo htmlspecialchars($tax['file_name'] ?? basename($tax['file_path'])); ?></div>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 10px;">
                        <a href="<?php echo $file_url; ?>" target="_blank">📄 <?php echo htmlspecialchars($tax['file_name'] ?? basename($tax['file_path'])); ?></a>
                    </div>
                <?php endif;
            else: ?>
                <div class="help-text">هیچ فایلی آپلود نشده است (فایل قبلی وجود ندارد یا مسیر آن حذف شده)</div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>ضمیمه جدید (عکس یا PDF)</label>
            <input type="file" name="attachment" id="fileInput" accept=".jpg,.jpeg,.png,.pdf">
            <div id="filePreview" class="file-preview" style="display: none;"></div>
        </div>

        <div class="form-group">
            <label>توضیحات</label>
            <textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? $tax['description']); ?></textarea>
        </div>

        <div class="forward-section">
            <div class="forward-title">🔁 ارجاع سند <span class="required">*</span></div>
            <div class="row-2col">
                <div class="form-group">
                    <label>📋 ارجاع به بخش</label>
                    <select name="forward_to_department">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo (($_POST['forward_to_department'] ?? $tax['current_holder_department_id']) == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>👤 ارجاع به شخص</label>
                    <select name="forward_to_user">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo (($_POST['forward_to_user'] ?? $tax['current_holder_user_id']) == $user['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <button type="submit" name="edit_tax" class="btn-submit">💾 ذخیره تغییرات</button>
    </form>
</div>

<script>
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}

// ========== بارگذاری فاکتورها با مسیر مطلق ==========
document.addEventListener('DOMContentLoaded', function() {
    const companySelect = document.getElementById('companySelect');
    const invoiceSelect = document.getElementById('toInvoiceSelect');
    const currentInvoice = "<?php echo htmlspecialchars($tax['to_invoice_number'] ?? ''); ?>";
    
    function loadInvoices(companyId) {
        if (!companyId) {
            invoiceSelect.innerHTML = '<option value="">ابتدا شرکت را انتخاب کنید</option>';
            return;
        }
        
        invoiceSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
        
        // استفاده از مسیر مطلق برای فراخوانی
        const ajaxUrl = window.location.pathname + '?get_invoices=1&company_id=' + companyId;
        
        fetch(ajaxUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                let options = '<option value="">انتخاب کنید (اختیاری)</option>';
                if (data && !data.error && Array.isArray(data) && data.length > 0) {
                    for (let i = 0; i < data.length; i++) {
                        const selected = (currentInvoice === data[i].document_number) ? 'selected' : '';
                        options += `<option value="${escapeHtml(data[i].document_number)}" ${selected}>${escapeHtml(data[i].document_number)} - ${escapeHtml(data[i].title)}</option>`;
                    }
                } else if (data && data.error) {
                    options = '<option value="">خطا: ' + escapeHtml(data.error) + '</option>';
                    console.error('Server error:', data.error);
                } else {
                    options += '<option value="">هیچ فاکتوری برای این شرکت یافت نشد</option>';
                }
                invoiceSelect.innerHTML = options;
            })
            .catch(error => {
                console.error('Fetch error:', error);
                invoiceSelect.innerHTML = '<option value="">خطا در ارتباط با سرور</option>';
            });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    if (companySelect.value) {
        loadInvoices(companySelect.value);
    }
    
    companySelect.addEventListener('change', function() {
        loadInvoices(this.value);
    });
});

// پیش‌نمایش فایل جدید
document.getElementById('fileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('filePreview');
    if (file) {
        const fileSize = (file.size / 1024).toFixed(1);
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                preview.innerHTML = `<img src="${ev.target.result}" style="max-width:150px;"><div class="help-text">${file.name} (${fileSize} KB)</div>`;
                preview.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = `<div>📎 ${file.name} (${fileSize} KB)</div>`;
            preview.style.display = 'flex';
        }
    } else {
        preview.style.display = 'none';
        preview.innerHTML = '';
    }
});

// اعتبارسنجی ارجاع
document.querySelector('form').addEventListener('submit', function(e) {
    const dept = document.querySelector('select[name="forward_to_department"]').value;
    const user = document.querySelector('select[name="forward_to_user"]').value;
    if (!dept && !user) {
        e.preventDefault();
        alert('لطفاً حداقل یکی از گزینه‌های ارجاع (بخش یا شخص) را انتخاب کنید.');
    }
});
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>