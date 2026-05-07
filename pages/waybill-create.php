<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('create_waybill')) {
    header('Location: waybills.php');
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
$users = $pdo->query("SELECT id, full_name, username, department_id FROM users ORDER BY full_name")->fetchAll();

$error = '';
$success = '';
$is_draft = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['create_waybill']) || isset($_POST['save_draft']))) {
    $is_draft = isset($_POST['save_draft']);
    
    $title = trim($_POST['title'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $vendor_id = $_POST['vendor_id'] ?? '';
    $waybill_number = trim($_POST['waybill_number'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
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
    $description = trim($_POST['description'] ?? '');
    
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';
    
    $errors = [];
    
    // اعتبارسنجی
    if (empty($title)) $errors[] = 'عنوان بارنامه الزامی است';
    if (!$is_draft && empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (!$is_draft && empty($vendor_id)) $errors[] = 'انتخاب فروشنده الزامی است';
    if (!$is_draft && empty($waybill_number)) $errors[] = 'شماره بارنامه الزامی است';
    if (!$is_draft && empty($sender_name)) $errors[] = 'نام فرستنده الزامی است';
    if (!$is_draft && empty($receiver_name)) $errors[] = 'نام گیرنده الزامی است';
    if (!$is_draft && empty($cargo_description)) $errors[] = 'نام محموله الزامی است';
    if (!$is_draft && empty($loading_origin)) $errors[] = 'مبدا بارگیری الزامی است';
    if (!$is_draft && empty($discharge_destination)) $errors[] = 'مقصد تخلیه الزامی است';
    if (!$is_draft && empty($forward_to_department) && empty($forward_to_user)) {
        $errors[] = 'لطفاً حداقل یکی از فیلدهای ارجاع را انتخاب کنید';
    }
    
    // آپلود فایل (در حالت پیش‌نویس اختیاری است)
    $file_path = null;
    $file_name = null;
    
    if (isset($_FILES['waybill_file']) && $_FILES['waybill_file']['error'] == UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 5 * 1024 * 1024;
        
        if (in_array($_FILES['waybill_file']['type'], $allowed_types) && $_FILES['waybill_file']['size'] <= $max_size) {
            $upload_dir = __DIR__ . '/../uploads/waybills/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $file_ext = pathinfo($_FILES['waybill_file']['name'], PATHINFO_EXTENSION);
            $file_name = time() . '_' . $waybill_number . '.' . $file_ext;
            $file_path = 'uploads/waybills/' . $file_name;
            
            if (!move_uploaded_file($_FILES['waybill_file']['tmp_name'], $upload_dir . $file_name)) {
                $errors[] = 'خطا در آپلود فایل';
            }
        } elseif (!$is_draft) {
            $errors[] = 'فرمت فایل باید JPEG، PNG یا PDF باشد و حداکثر حجم ۵ مگابایت';
        }
    }
    
    if (empty($errors)) {
        // اولویت ارجاع
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
        
        // دریافت مخفف شرکت برای فرمت بارنامه
        $companyInfo = $pdo->prepare("SELECT short_name FROM companies WHERE id = ?");
        $companyInfo->execute([$company_id]);
        $companyData = $companyInfo->fetch();
        $short_name = $companyData['short_name'] ?? 'wbl';
        
        // تولید شماره بارنامه با فرمت: kyhn-B/L-1234
        $short = !empty($short_name) ? strtolower($short_name) : 'wbl';
        $clean_number = preg_replace('/[^0-9]/', '', $waybill_number);
        if (empty($clean_number)) $clean_number = rand(1000, 9999);
        $formatted_waybill_number = $short . '-B/L-' . $clean_number;
        
        $doc_number = $is_draft ? 'DRAFT-' . $formatted_waybill_number : $formatted_waybill_number;
        $status = $is_draft ? 'draft' : 'pending';
        
        $data = [
            'document_number' => $doc_number,
            'type' => 'waybill',
            'title' => $title,
            'description' => $description,
            'amount' => $amount ?: null,
            'created_by' => $_SESSION['user_id'],
            'company_id' => $company_id,
            'vendor_id' => $vendor_id,
            'department_id' => null,
            'document_date' => jdate('Y/m/d'),
            'status' => $status,
            'waybill_number' => $formatted_waybill_number,
            'sender_name' => $sender_name,
            'receiver_name' => $receiver_name,
            'cargo_description' => $cargo_description,
            'loading_origin' => $loading_origin,
            'discharge_destination' => $discharge_destination,
            'driver1_name' => $driver1_name,
            'driver2_name' => $driver2_name,
            'vehicle_plate' => $vehicle_plate,
            'quantity' => $quantity,
            'weight' => $weight,
            'carrier_responsible' => $carrier_responsible,
            'insurance_company' => $insurance_company,
            'waybill_notes' => null,
            'file_path' => $file_path,
            'file_name' => $_FILES['waybill_file']['name'] ?? null,
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
                $forward->execute([
                    $doc_id,
                    $_SESSION['user_id'],
                    $current_holder_department_id,
                    $current_holder_user_id,
                    'بارنامه جدید ایجاد و ارجاع شد'
                ]);
            }
            
            logActivity($_SESSION['user_id'], $is_draft ? 'create_waybill_draft' : 'create_waybill', 
                      ($is_draft ? 'پیش‌نویس بارنامه: ' : 'بارنامه جدید: ') . $title, $doc_id);
            
            if ($is_draft) {
                $_SESSION['message'] = 'پیش‌نویس بارنامه با موفقیت ذخیره شد.';
                $_SESSION['message_type'] = 'success';
                header('Location: waybills.php');
                exit;
            } else {
                $success = 'بارنامه با موفقیت ثبت شد. شماره بارنامه: ' . $formatted_waybill_number;
                echo '<script>setTimeout(function() { window.location.href = "waybills.php"; }, 2000);</script>';
            }
        } else {
            $errors[] = 'خطا در ثبت بارنامه';
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'ایجاد بارنامه جدید';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #0284c7;
        --primary-light: #e0f2fe;
        --secondary: #0d9488;
        --secondary-light: #f0fdfa;
        --accent: #d97706;
        --accent-light: #fffbeb;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --danger: #ef4444;
        --success: #10b981;
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
        background: linear-gradient(135deg, var(--primary), var(--secondary));
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
    .card-secondary .card-header { background: var(--secondary-light); }
    .card-secondary .card-header i { color: var(--secondary); }
    .card-accent .card-header { background: var(--accent-light); }
    .card-accent .card-header i { color: var(--accent); }
    
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
        box-shadow: 0 0 0 2px rgba(2,132,199,0.1);
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
        background: var(--accent-light);
        border-radius: 16px;
        padding: 16px;
    }
    
    .radio-group {
        display: flex;
        gap: 24px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px dashed #fde68a;
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
        accent-color: var(--accent);
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
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        box-shadow: 0 2px 8px rgba(2,132,199,0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(2,132,199,0.4);
    }
    
    .btn-draft {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white;
    }
    
    .btn-draft:hover {
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
        <h1 class="page-title"><i class="fas fa-truck" style="color: var(--primary);"></i> ایجاد بارنامه جدید</h1>
        <a href="waybills.php" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" id="waybillForm">
        <!-- ردیف اول: اطلاعات اصلی بارنامه + شرکت و فروشنده (2 ستون) -->
        <div class="two-columns">
            
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-file-alt"></i>
                    <h3>اطلاعات اصلی بارنامه</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>عنوان بارنامه <span class="required">*</span></label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" placeholder="مثال: حمل بار فروردین 1404">
                    </div>
                    <div class="form-group">
                        <label>شماره بارنامه <span class="required">*</span></label>
                        <input type="text" name="waybill_number" required value="<?php echo htmlspecialchars($_POST['waybill_number'] ?? ''); ?>" placeholder="مثال: 1234">
                        <small class="help-text">با مخفف شرکت ترکیب می‌شود (مثال: kyhn-B/L-1234)</small>
                    </div>
                    <div class="form-group">
                        <label>مبلغ بارنامه (تومان)</label>
                        <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" placeholder="۰">
                    </div>
                </div>
            </div>
            
            <div class="form-card card-secondary">
                <div class="card-header">
                    <i class="fas fa-building"></i>
                    <h3>شرکت و فروشنده</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>انتخاب شرکت <span class="required">*</span></label>
                        <select name="company_id" id="companySelect" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?php echo $comp['id']; ?>" data-short="<?php echo $comp['short_name']; ?>" <?php echo ($_POST['company_id'] ?? '') == $comp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($comp['name']); ?>
                                    <?php if ($comp['short_name']): ?>
                                        (<?php echo htmlspecialchars($comp['short_name']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>فروشنده/فروشگاه <span class="required">*</span></label>
                        <select name="vendor_id" id="vendorSelect" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?php echo $vendor['id']; ?>" <?php echo ($_POST['vendor_id'] ?? '') == $vendor['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($vendor['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف دوم: فرستنده و گیرنده و محموله (2 ستون با چیدمان خاص) -->
        <div class="two-columns">
            
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-user-tie"></i>
                    <h3>اطلاعات فرستنده و گیرنده</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>نام فرستنده <span class="required">*</span></label>
                        <input type="text" name="sender_name" required value="<?php echo htmlspecialchars($_POST['sender_name'] ?? ''); ?>" placeholder="نام فرستنده">
                    </div>
                    <div class="form-group">
                        <label>نام گیرنده <span class="required">*</span></label>
                        <input type="text" name="receiver_name" required value="<?php echo htmlspecialchars($_POST['receiver_name'] ?? ''); ?>" placeholder="نام گیرنده">
                    </div>
                    <div class="form-group">
                        <label>نام محموله <span class="required">*</span></label>
                        <input type="text" name="cargo_description" required value="<?php echo htmlspecialchars($_POST['cargo_description'] ?? ''); ?>" placeholder="مثال: مصالح ساختمانی">
                    </div>
                </div>
            </div>
            
            <div class="form-card card-secondary">
                <div class="card-header">
                    <i class="fas fa-map-marker-alt"></i>
                    <h3>مبدا و مقصد</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>مبدا بارگیری <span class="required">*</span></label>
                        <input type="text" name="loading_origin" required value="<?php echo htmlspecialchars($_POST['loading_origin'] ?? ''); ?>" placeholder="مبدا">
                    </div>
                    <div class="form-group">
                        <label>مقصد تخلیه <span class="required">*</span></label>
                        <input type="text" name="discharge_destination" required value="<?php echo htmlspecialchars($_POST['discharge_destination'] ?? ''); ?>" placeholder="مقصد">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف سوم: راننده و وسیله نقلیه -->
        <div class="two-columns">
            
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-users"></i>
                    <h3>رانندگان</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>راننده اول</label>
                        <input type="text" name="driver1_name" value="<?php echo htmlspecialchars($_POST['driver1_name'] ?? ''); ?>" placeholder="نام راننده اول">
                    </div>
                    <div class="form-group">
                        <label>راننده دوم</label>
                        <input type="text" name="driver2_name" value="<?php echo htmlspecialchars($_POST['driver2_name'] ?? ''); ?>" placeholder="نام راننده دوم">
                    </div>
                </div>
            </div>
            
            <div class="form-card card-secondary">
                <div class="card-header">
                    <i class="fas fa-truck-moving"></i>
                    <h3>وسیله نقلیه</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>شماره پلاک</label>
                        <input type="text" name="vehicle_plate" value="<?php echo htmlspecialchars($_POST['vehicle_plate'] ?? ''); ?>" placeholder="مثال: ۱۲۳-۴۵۶-۷۸۹">
                    </div>
                    <div class="form-group">
                        <label>تعداد</label>
                        <input type="number" name="quantity" value="<?php echo $_POST['quantity'] ?? ''; ?>" placeholder="تعداد محموله">
                    </div>
                    <div class="form-group">
                        <label>وزن (کیلوگرم)</label>
                        <input type="number" name="weight" value="<?php echo $_POST['weight'] ?? ''; ?>" placeholder="وزن محموله">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف چهارم: مسئول حمل + شرکت بیمه + فایل ضمیمه + توضیحات (3 ستون) -->
        <div class="two-columns">
            
            <div class="form-card card-accent">
                <div class="card-header">
                    <i class="fas fa-shield-alt"></i>
                    <h3>مسئول حمل و بیمه</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>مسئول حمل</label>
                        <input type="text" name="carrier_responsible" value="<?php echo htmlspecialchars($_POST['carrier_responsible'] ?? ''); ?>" placeholder="نام مسئول حمل">
                    </div>
                    <div class="form-group">
                        <label>شرکت بیمه</label>
                        <input type="text" name="insurance_company" value="<?php echo htmlspecialchars($_POST['insurance_company'] ?? ''); ?>" placeholder="شرکت بیمه">
                    </div>
                </div>
            </div>
            
            <div class="form-card card-accent">
                <div class="card-header">
                    <i class="fas fa-paperclip"></i>
                    <h3>فایل و توضیحات</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>آپلود فایل بارنامه</label>
                        <input type="file" name="waybill_file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="help-text">فرمت‌های مجاز: jpg, jpeg, png, pdf | حداکثر حجم: ۵ مگابایت</small>
                        <div id="filePreview" class="file-preview" style="display: none;"></div>
                    </div>
                    <div class="form-group">
                        <label>توضیحات</label>
                        <textarea name="description" rows="3" placeholder="توضیحات اضافی..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- بخش ارجاع -->
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
                            <small class="help-text">بارنامه برای همه کاربران این بخش قابل مشاهده است</small>
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
                            <small class="help-text">بارنامه فقط برای این شخص قابل مشاهده است</small>
                        </div>
                    </div>
                    
                    <input type="hidden" name="forward_to_department" id="hiddenDept" value="<?php echo htmlspecialchars($_POST['forward_to_department'] ?? ''); ?>">
                    <input type="hidden" name="forward_to_user" id="hiddenUser" value="<?php echo htmlspecialchars($_POST['forward_to_user'] ?? ''); ?>">
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="save_draft" value="1" class="btn btn-draft" id="draftBtn">
                <i class="fas fa-save"></i> ذخیره پیش‌نویس
            </button>
            <button type="submit" name="create_waybill" value="1" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-check-circle"></i> ثبت نهایی
            </button>
            <a href="waybills.php" class="btn btn-outline">انصراف</a>
        </div>
    </form>
</div>

<script>
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
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
    if (forwardDeptRadio && forwardDeptRadio.checked) {
        departmentSelectDiv.style.display = 'block';
        userSelectDiv.style.display = 'none';
        forwardDepartment.disabled = false;
        forwardUser.disabled = true;
        forwardUser.value = '';
        hiddenUser.value = '';
        hiddenDept.value = forwardDepartment.value;
    } else if (forwardUserRadio && forwardUserRadio.checked) {
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

if (forwardDeptRadio) forwardDeptRadio.addEventListener('change', updateForwardFields);
if (forwardUserRadio) forwardUserRadio.addEventListener('change', updateForwardFields);
if (forwardDepartment) forwardDepartment.addEventListener('change', () => { hiddenDept.value = forwardDepartment.value; });
if (forwardUser) forwardUser.addEventListener('change', () => { hiddenUser.value = forwardUser.value; });
updateForwardFields();

// پیش‌نمایش فایل
document.getElementById('fileInput').addEventListener('change', function(e) {
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

// اعتبارسنجی ارجاع
document.getElementById('submitBtn').addEventListener('click', function(e) {
    const forwardType = document.querySelector('input[name="forward_type"]:checked');
    if (!forwardType) {
        e.preventDefault();
        alert('لطفاً روش ارجاع را انتخاب کنید (بخش یا شخص)');
        return false;
    }
    
    if (forwardType.value === 'department') {
        const dept = document.getElementById('forwardDepartment').value;
        if (!dept) {
            e.preventDefault();
            alert('لطفاً بخش مقصد را انتخاب کنید');
            return false;
        }
    } else if (forwardType.value === 'user') {
        const user = document.getElementById('forwardUser').value;
        if (!user) {
            e.preventDefault();
            alert('لطفاً شخص مقصد را انتخاب کنید');
            return false;
        }
    }
});

// حذف required برای فایل و ارجاع در حالت پیش‌نویس
document.getElementById('draftBtn').addEventListener('click', function() {
    document.getElementById('fileInput').removeAttribute('required');
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>