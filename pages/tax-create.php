<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
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

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name, national_id FROM vendors ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, department_id FROM users ORDER BY full_name")->fetchAll();

// تابع تولید شناسه مالیاتی فرمت‌شده
function generateFormattedTaxId($companyShortName, $taxIdNumber) {
    $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $companyShortName));
    if (empty($code)) $code = 'TAX';
    $numericPart = preg_replace('/[^0-9]/', '', $taxIdNumber);
    $lastSix = substr(str_pad($numericPart, 6, '0', STR_PAD_LEFT), -6);
    if (empty($lastSix)) $lastSix = '000001';
    return 'Tax-' . $code . '-' . $lastSix;
}

$error = '';
$success = '';
$is_draft = false;

// AJAX handlers
if (isset($_GET['get_invoices']) && isset($_GET['company_id'])) {
    $company_id = $_GET['company_id'];
    $stmt = $pdo->prepare("
        SELECT d.id, d.document_number, d.title, d.amount, d.vendor_id, v.name as vendor_name 
        FROM documents d 
        LEFT JOIN vendors v ON d.vendor_id = v.id
        WHERE d.type = 'invoice' AND d.company_id = ? 
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$company_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($invoices);
    exit;
}

if (isset($_GET['get_invoice_data']) && isset($_GET['invoice_number'])) {
    $invoice_number = $_GET['invoice_number'];
    $stmt = $pdo->prepare("
        SELECT d.amount, d.vendor_id, v.name as vendor_name, v.national_id as vendor_national_id
        FROM documents d 
        LEFT JOIN vendors v ON d.vendor_id = v.id
        WHERE d.document_number = ? AND d.type = 'invoice'
        LIMIT 1
    ");
    $stmt->execute([$invoice_number]);
    $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($invoice_data ?: []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $is_draft = isset($_POST['save_draft']);
    $title = trim($_POST['title'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $vendor_id = $_POST['vendor_id'] ?? '';
    $vendor_national_id = trim($_POST['vendor_national_id'] ?? '');
    $tax_detail_code = trim($_POST['tax_detail_code'] ?? '');
    $original_tax_id = trim($_POST['tax_id'] ?? '');
    $to_invoice_number = trim($_POST['to_invoice_number'] ?? '');
    $record_date = trim($_POST['record_date'] ?? jdate('Y/m/d'));
    $followup_days = (int)($_POST['followup_days'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';
    
    // اعتبارسنجی
    if (empty($title)) {
        $error = 'لطفاً عنوان سند را وارد کنید';
    } elseif (!$is_draft && empty($company_id)) {
        $error = 'لطفاً شرکت را انتخاب کنید';
    } elseif (!$is_draft && (empty($amount) || $amount <= 0)) {
        $error = 'لطفاً مبلغ معتبر وارد کنید';
    } elseif (!$is_draft && empty($original_tax_id)) {
        $error = 'لطفاً شناسه مالیاتی را وارد کنید';
    } elseif (!$is_draft && empty($forward_to_department) && empty($forward_to_user)) {
        $error = 'لطفاً حداقل یکی از فیلدهای ارجاع را انتخاب کنید';
    } else {
        // تعیین دارنده سند
        if (!empty($forward_to_department)) {
            $current_holder_department_id = $forward_to_department;
            $current_holder_user_id = null;
        } elseif (!empty($forward_to_user)) {
            $current_holder_user_id = $forward_to_user;
            $current_holder_department_id = null;
        } else {
            $current_holder_department_id = null;
            $current_holder_user_id = null;
        }
        
        // محاسبه ددلاین
        $deadline = null;
        if (!$is_draft && $followup_days > 0) {
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
            } elseif (!$is_draft) {
                $error = 'فرمت فایل نامعتبر یا حجم بیش از حد مجاز (۵ مگابایت)';
            }
        }
        
        if (empty($error)) {
            // دریافت short_name شرکت برای تولید شناسه فرمت شده
            $company_short = '';
            foreach ($companies as $c) {
                if ($c['id'] == $company_id) {
                    $company_short = $c['short_name'];
                    break;
                }
            }
            
            // تولید شناسه مالیاتی فرمت شده
            $formatted_tax_id = generateFormattedTaxId($company_short, $original_tax_id);
            
            $doc_number = $is_draft ? 'DRAFT-' . time() . '-' . rand(100, 999) : 'TAX-' . date('Ymd') . '-' . rand(100, 999);
            $status = $is_draft ? 'draft' : 'pending';
            
            $data = [
                'document_number' => $doc_number,
                'type' => 'tax',
                'title' => $title,
                'description' => $description,
                'amount' => $amount,
                'created_by' => $_SESSION['user_id'],
                'company_id' => $company_id ?: null,
                'vendor_id' => $vendor_id ?: null,
                'vendor_national_id' => $vendor_national_id,
                'document_date' => $record_date,
                'status' => $status,
                'tax_detail_code' => $tax_detail_code,
                'tax_id' => $formatted_tax_id,  // ذخیره شناسه فرمت شده
                'original_tax_id' => $original_tax_id,  // ذخیره شناسه اصلی برای استفاده بعدی
                'to_invoice_number' => $to_invoice_number ?: null,
                'tax_status' => 'pending',
                'approval_deadline' => $deadline,
                'file_path' => $file_path,
                'file_name' => $_FILES['attachment']['name'] ?? null,
                'current_holder_department_id' => $current_holder_department_id,
                'current_holder_user_id' => $current_holder_user_id,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $data = array_filter($data, fn($v) => $v !== null);
            
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO documents ($fields) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute($data)) {
                $doc_id = $pdo->lastInsertId();
                
                if (!$is_draft && ($current_holder_department_id || $current_holder_user_id)) {
                    $forward = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
                    $forward->execute([$doc_id, $_SESSION['user_id'], $current_holder_department_id, $current_holder_user_id, 'سند جدید ایجاد و ارجاع شد']);
                }
                
                logActivity($_SESSION['user_id'], $is_draft ? 'create_tax_draft' : 'create_tax', 
                          ($is_draft ? 'پیش‌نویس سند مالیاتی: ' : 'سند مالیاتی جدید: ') . $title, $doc_id);
                
                if ($is_draft) {
                    $_SESSION['message'] = 'پیش‌نویس سند با موفقیت ذخیره شد.';
                    $_SESSION['message_type'] = 'success';
                    header('Location: tax.php');
                    exit;
                } else {
                    $_SESSION['message'] = 'سند مالیاتی با موفقیت ثبت شد. شماره: ' . $formatted_tax_id;
                    $_SESSION['message_type'] = 'success';
                    header('Location: tax.php');
                    exit;
                }
            } else {
                $error = 'خطا در ثبت سند';
            }
        }
    }
}

$page_title = 'ایجاد سند مالیاتی جدید';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #3b82f6;
        --primary-light: #eff6ff;
        --purple-light: #f5f3ff;
        --green-light: #ecfdf5;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --danger: #ef4444;
        --success: #10b981;
        --warning: #f59e0b;
    }
    
    .form-wrapper {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px 24px;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .page-title {
        font-size: 24px;
        font-weight: 700;
        background: linear-gradient(135deg, var(--text-main), var(--primary));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        margin: 0;
    }
    
    .back-link {
        color: var(--text-muted);
        text-decoration: none;
        padding: 8px 20px;
        background: white;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.2s;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .back-link:hover {
        background: var(--primary);
        color: white;
    }
    
    .two-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .form-card {
        background: var(--card-bg);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border: 1px solid var(--border);
        height: fit-content;
    }
    
    .card-header {
        padding: 14px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .card-header i {
        font-size: 18px;
    }
    
    .card-header h3 {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-main);
        margin: 0;
    }
    
    .card-body {
        padding: 18px 20px;
    }
    
    .card-primary .card-header { background: var(--primary-light); }
    .card-primary .card-header i { color: var(--primary); }
    .card-purple .card-header { background: var(--purple-light); }
    .card-purple .card-header i { color: #8b5cf6; }
    .card-green .card-header { background: var(--green-light); }
    .card-green .card-header i { color: var(--success); }
    
    .form-group {
        margin-bottom: 14px;
    }
    
    .form-group label {
        display: block;
        font-size: 12px;
        font-weight: 500;
        color: var(--text-main);
        margin-bottom: 4px;
    }
    
    .form-group label .required {
        color: var(--danger);
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
        font-family: inherit;
        transition: all 0.2s;
        background: white;
    }
    
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(59,130,246,0.1);
    }
    
    .help-text {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 4px;
        display: block;
    }
    
    textarea {
        resize: vertical;
        min-height: 70px;
    }
    
    .forward-section {
        background: var(--green-light);
        border-radius: 16px;
        padding: 16px;
    }
    
    .radio-group {
        display: flex;
        gap: 24px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px dashed #a7f3d0;
    }
    
    .radio-group label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
    }
    
    .radio-group input[type="radio"] {
        width: 16px;
        height: 16px;
        margin: 0;
    }
    
    .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 16px;
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        font-family: inherit;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--success), #059669);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16,185,129,0.3);
    }
    
    .btn-warning {
        background: linear-gradient(135deg, var(--warning), #d97706);
        color: white;
    }
    
    .btn-warning:hover {
        transform: translateY(-1px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-muted);
    }
    
    .btn-outline:hover {
        background: #f8fafc;
        border-color: var(--primary);
        color: var(--primary);
    }
    
    .file-preview {
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px;
        background: var(--bg-page);
        border-radius: 10px;
    }
    
    .file-preview img {
        width: 50px;
        height: 50px;
        border-radius: 8px;
        object-fit: cover;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 500;
    }
    
    .alert-danger {
        background: #fee8e8;
        color: var(--danger);
        border-right: 3px solid var(--danger);
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border-right: 3px solid var(--success);
    }
    
    @media (max-width: 900px) {
        .two-columns {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .form-wrapper {
            padding: 16px;
        }
        .form-actions {
            flex-direction: column;
        }
        .btn {
            width: 100%;
            text-align: center;
        }
    }
    
    @media (max-width: 480px) {
        .radio-group {
            flex-direction: column;
            gap: 10px;
        }
        .card-body {
            padding: 14px;
        }
    }
</style>

<div class="form-wrapper">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-file-invoice-dollar" style="color: var(--primary);"></i> ایجاد سند مالیاتی</h1>
        <a href="tax.php" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data">
        <div class="two-columns">
            
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-file-alt"></i>
                    <h3>اطلاعات اصلی سند</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>عنوان سند <span class="required">*</span></label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>شرکت <span class="required">*</span></label>
                        <select name="company_id" id="companySelect" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['id']; ?>" data-short="<?php echo $c['short_name']; ?>" <?php echo ($_POST['company_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>مبلغ (تومان) <span class="required">*</span></label>
                        <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>تاریخ ثبت (شمسی)</label>
                        <input type="text" name="record_date" value="<?php echo htmlspecialchars($_POST['record_date'] ?? jdate('Y/m/d')); ?>">
                    </div>
                    <div class="form-group">
                        <label>مهلت پیگیری (روز)</label>
                        <input type="number" name="followup_days" min="0" value="<?php echo htmlspecialchars($_POST['followup_days'] ?? 0); ?>">
                        <small class="help-text">۰ = بدون مهلت</small>
                    </div>
                </div>
            </div>
            
            <div class="form-card card-purple">
                <div class="card-header">
                    <i class="fas fa-building"></i>
                    <h3>اطلاعات مالیاتی و فروشنده</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>شناسه مالیاتی <span class="required">*</span></label>
                        <input type="text" name="tax_id" id="taxIdInput" value="<?php echo htmlspecialchars($_POST['tax_id'] ?? ''); ?>" placeholder="مثال: 12345678901">
                        <small class="help-text">شناسه 11 رقمی سازمان امور مالیاتی</small>
                    </div>
                    <div class="form-group">
                        <label>شناسه ملی فروشنده</label>
                        <input type="text" name="vendor_national_id" id="vendorNationalId" value="<?php echo htmlspecialchars($_POST['vendor_national_id'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>نام فروشنده/فروشگاه</label>
                        <select name="vendor_id" id="vendorSelect">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?php echo $v['id']; ?>" data-national="<?php echo $v['national_id']; ?>" <?php echo ($_POST['vendor_id'] ?? '') == $v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>کد تفصیل حساب</label>
                        <input type="text" name="tax_detail_code" value="<?php echo htmlspecialchars($_POST['tax_detail_code'] ?? ''); ?>">
                        <small class="help-text">اختیاری</small>
                    </div>
                    <div class="form-group">
                        <label>به شماره فاکتور</label>
                        <select name="to_invoice_number" id="toInvoiceSelect">
                            <option value="">ابتدا شرکت را انتخاب کنید</option>
                        </select>
                        <small class="help-text">بارگذاری خودکار اطلاعات</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="two-columns">
            
            <div class="form-card card-green">
                <div class="card-header">
                    <i class="fas fa-paperclip"></i>
                    <h3>ضمیمه و توضیحات</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>ضمیمه (عکس یا PDF)</label>
                        <input type="file" name="attachment" id="fileInput" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="help-text">حداکثر ۵ مگابایت</small>
                        <div id="filePreview" class="file-preview" style="display: none;"></div>
                    </div>
                    <div class="form-group">
                        <label>توضیحات</label>
                        <textarea name="description" rows="3" placeholder="توضیحات اضافی..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="form-card">
                <div class="card-header">
                    <i class="fas fa-share-alt"></i>
                    <h3>ارجاع سند <span class="required">*</span></h3>
                </div>
                <div class="card-body">
                    <div class="forward-section">
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="forward_type" value="department" id="forwardDeptRadio" <?php echo (!empty($_POST['forward_to_department']) && empty($_POST['forward_to_user'])) ? 'checked' : ''; ?>>
                                <i class="fas fa-building"></i> ارجاع به بخش
                            </label>
                            <label>
                                <input type="radio" name="forward_type" value="user" id="forwardUserRadio" <?php echo (!empty($_POST['forward_to_user']) && empty($_POST['forward_to_department'])) ? 'checked' : ''; ?>>
                                <i class="fas fa-user"></i> ارجاع به شخص
                            </label>
                        </div>
                        
                        <div id="departmentSelect" style="display: <?php echo (!empty($_POST['forward_to_department']) && empty($_POST['forward_to_user'])) ? 'block' : 'none'; ?>;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <select name="forward_to_department" id="forwardDepartment" style="width: 100%;">
                                    <option value="">--- انتخاب بخش ---</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo ($_POST['forward_to_department'] ?? '') == $dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="help-text">سند برای همه کاربران این بخش قابل مشاهده است</small>
                            </div>
                        </div>
                        
                        <div id="userSelect" style="display: <?php echo (!empty($_POST['forward_to_user']) && empty($_POST['forward_to_department'])) ? 'block' : 'none'; ?>;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <select name="forward_to_user" id="forwardUser" style="width: 100%;">
                                    <option value="">--- انتخاب شخص ---</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo ($_POST['forward_to_user'] ?? '') == $user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="help-text">سند فقط برای این شخص قابل مشاهده است</small>
                            </div>
                        </div>
                        
                        <input type="hidden" name="forward_to_department" id="hiddenDept" value="<?php echo htmlspecialchars($_POST['forward_to_department'] ?? ''); ?>">
                        <input type="hidden" name="forward_to_user" id="hiddenUser" value="<?php echo htmlspecialchars($_POST['forward_to_user'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="save_draft" value="1" class="btn btn-warning"><i class="fas fa-save"></i> ذخیره پیش‌نویس</button>
            <button type="submit" name="create_tax" value="1" class="btn btn-primary"><i class="fas fa-check-circle"></i> ثبت نهایی</button>
            <a href="tax.php" class="btn btn-outline">انصراف</a>
        </div>
    </form>
</div>

<script>
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}

function loadInvoices(companyId) {
    const invoiceSelect = document.getElementById('toInvoiceSelect');
    if (!companyId) {
        invoiceSelect.innerHTML = '<option value="">ابتدا شرکت را انتخاب کنید</option>';
        return;
    }
    invoiceSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
    fetch('tax-create.php?get_invoices=1&company_id=' + companyId)
        .then(response => response.json())
        .then(data => {
            let options = '<option value="">انتخاب کنید (اختیاری)</option>';
            if (data && data.length > 0) {
                for (let i = 0; i < data.length; i++) {
                    options += `<option value="${data[i].document_number}" data-amount="${data[i].amount}" data-vendor-id="${data[i].vendor_id || ''}" data-vendor-name="${data[i].vendor_name || ''}">${data[i].document_number} - ${data[i].title}</option>`;
                }
            } else {
                options += '<option value="">هیچ فاکتوری یافت نشد</option>';
            }
            invoiceSelect.innerHTML = options;
        })
        .catch(() => { invoiceSelect.innerHTML = '<option value="">خطا در بارگذاری</option>'; });
}

function loadInvoiceData(invoiceNumber) {
    if (!invoiceNumber) return;
    fetch('tax-create.php?get_invoice_data=1&invoice_number=' + encodeURIComponent(invoiceNumber))
        .then(response => response.json())
        .then(data => {
            if (data && data.amount) {
                document.getElementById('amount').value = Number(data.amount).toLocaleString();
                if (data.vendor_national_id) document.getElementById('vendorNationalId').value = data.vendor_national_id;
                if (data.vendor_id) {
                    const vendorSelect = document.getElementById('vendorSelect');
                    for (let i = 0; i < vendorSelect.options.length; i++) {
                        if (vendorSelect.options[i].value == data.vendor_id) {
                            vendorSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
        })
        .catch(error => console.error(error));
}

// ارجاع انحصاری
const forwardDeptRadio = document.getElementById('forwardDeptRadio');
const forwardUserRadio = document.getElementById('forwardUserRadio');
const departmentSelectDiv = document.getElementById('departmentSelect');
const userSelectDiv = document.getElementById('userSelect');
const forwardDepartment = document.getElementById('forwardDepartment');
const forwardUser = document.getElementById('forwardUser');
const hiddenDept = document.getElementById('hiddenDept');
const hiddenUser = document.getElementById('hiddenUser');

function updateForwardFields() {
    if (forwardDeptRadio.checked) {
        departmentSelectDiv.style.display = 'block';
        userSelectDiv.style.display = 'none';
        forwardDepartment.disabled = false;
        forwardUser.disabled = true;
        forwardUser.value = '';
        hiddenUser.value = '';
        hiddenDept.value = forwardDepartment.value;
    } else if (forwardUserRadio.checked) {
        departmentSelectDiv.style.display = 'none';
        userSelectDiv.style.display = 'block';
        forwardDepartment.disabled = true;
        forwardUser.disabled = false;
        forwardDepartment.value = '';
        hiddenDept.value = '';
        hiddenUser.value = forwardUser.value;
    } else {
        departmentSelectDiv.style.display = 'none';
        userSelectDiv.style.display = 'none';
    }
}

forwardDeptRadio?.addEventListener('change', updateForwardFields);
forwardUserRadio?.addEventListener('change', updateForwardFields);
forwardDepartment?.addEventListener('change', () => { hiddenDept.value = forwardDepartment.value; });
forwardUser?.addEventListener('change', () => { hiddenUser.value = forwardUser.value; });
updateForwardFields();

// رویدادها
document.getElementById('companySelect')?.addEventListener('change', function() { loadInvoices(this.value); });
document.getElementById('toInvoiceSelect')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt && opt.value) loadInvoiceData(opt.value);
});
document.getElementById('vendorSelect')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.national) document.getElementById('vendorNationalId').value = opt.dataset.national;
});
if (document.getElementById('companySelect')?.value) loadInvoices(document.getElementById('companySelect').value);

// پیش‌نمایش فایل
document.getElementById('fileInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('filePreview');
    if (file) {
        const size = (file.size / 1024).toFixed(1);
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = ev => { preview.innerHTML = `<img src="${ev.target.result}"><span style="font-size:11px;">${file.name} (${size} KB)</span>`; preview.style.display = 'flex'; };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = `<span>📎 ${file.name} (${size} KB)</span>`;
            preview.style.display = 'flex';
        }
    } else { preview.style.display = 'none'; }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>