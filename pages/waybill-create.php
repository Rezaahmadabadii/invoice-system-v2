 <?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('create_waybill')) {
    header('Location: waybills.php');
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

// دریافت لیست شرکت‌ها
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// دریافت لیست فروشندگان
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_waybill'])) {
    
    // دریافت مقادیر فرم
    $title = trim($_POST['title'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $vendor_id = $_POST['vendor_id'] ?? '';
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
    $description = trim($_POST['description'] ?? '');
    
    $errors = [];
    
    if (empty($title)) $errors[] = 'عنوان بارنامه الزامی است';
    if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (empty($waybill_number)) $errors[] = 'شماره بارنامه الزامی است';
    if (empty($sender_name)) $errors[] = 'نام فرستنده الزامی است';
    if (empty($receiver_name)) $errors[] = 'نام گیرنده الزامی است';
    if (empty($cargo_description)) $errors[] = 'نام محموله الزامی است';
    if (empty($loading_origin)) $errors[] = 'مبدا بارگیری الزامی است';
    if (empty($discharge_destination)) $errors[] = 'مقصد تخلیه الزامی است';
    
    // بررسی آپلود فایل
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
        } else {
            $errors[] = 'فرمت فایل باید JPEG، PNG یا PDF باشد و حداکثر حجم ۵ مگابایت';
        }
    }
    
    if (empty($errors)) {
        $doc_number = 'WBL-' . date('Ymd') . '-' . rand(100, 999);
        
        $data = [
            'document_number' => $doc_number,
            'type' => 'waybill',
            'title' => $title,
            'description' => $description,
            'amount' => null,
            'created_by' => $_SESSION['user_id'],
            'company_id' => $company_id,
            'vendor_id' => $vendor_id ?: null,
            'department_id' => 1,
            'document_date' => jdate('Y/m/d'),
            'status' => 'draft',
            'waybill_number' => $waybill_number,
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
            'file_name' => $_FILES['waybill_file']['name'] ?? null
        ];
        
        $fields = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO documents ($fields) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($data)) {
            logActivity($_SESSION['user_id'], 'create_waybill', "بارنامه جدید: $title", $pdo->lastInsertId());
            $success = 'بارنامه با موفقیت ثبت شد. شماره پیگیری: ' . $doc_number;
        } else {
            $errors[] = 'خطا در ثبت بارنامه';
        }
    }
}

$page_title = 'ایجاد بارنامه جدید';
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
    <h1 style="color: #2c3e50;">📦 ایجاد بارنامه جدید</h1>
    <a href="waybills.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
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
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">عنوان بارنامه *</label>
                <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">شماره بارنامه *</label>
                <input type="text" name="waybill_number" required value="<?php echo htmlspecialchars($_POST['waybill_number'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">انتخاب شرکت *</label>
                <select name="company_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo $comp['id']; ?>" <?php echo ($_POST['company_id'] ?? '') == $comp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($comp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px;">انتخاب فروشنده</label>
                <select name="vendor_id" id="vendorSelect" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>" <?php echo ($_POST['vendor_id'] ?? '') == $vendor['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($vendor['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">نام فرستنده *</label>
                <input type="text" name="sender_name" required value="<?php echo htmlspecialchars($_POST['sender_name'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">نام گیرنده *</label>
                <input type="text" name="receiver_name" required value="<?php echo htmlspecialchars($_POST['receiver_name'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">نام محموله *</label>
                <input type="text" name="cargo_description" required value="<?php echo htmlspecialchars($_POST['cargo_description'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">مبدا بارگیری *</label>
                <input type="text" name="loading_origin" required value="<?php echo htmlspecialchars($_POST['loading_origin'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">مقصد تخلیه *</label>
                <input type="text" name="discharge_destination" required value="<?php echo htmlspecialchars($_POST['discharge_destination'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px;">راننده اول</label>
                <input type="text" name="driver1_name" value="<?php echo htmlspecialchars($_POST['driver1_name'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px;">راننده دوم</label>
                <input type="text" name="driver2_name" value="<?php echo htmlspecialchars($_POST['driver2_name'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px;">شماره پلاک</label>
                <input type="text" name="vehicle_plate" value="<?php echo htmlspecialchars($_POST['vehicle_plate'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px;">تعداد</label>
                <input type="number" name="quantity" value="<?php echo $_POST['quantity'] ?? ''; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px;">وزن</label>
                <input type="number" name="weight" value="<?php echo $_POST['weight'] ?? ''; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px;">مسئول حمل</label>
                <input type="text" name="carrier_responsible" value="<?php echo htmlspecialchars($_POST['carrier_responsible'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px;">شرکت بیمه</label>
            <input type="text" name="insurance_company" value="<?php echo htmlspecialchars($_POST['insurance_company'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px;">آپلود فایل بارنامه (عکس یا PDF)</label>
            <input type="file" name="waybill_file" accept=".jpg,.jpeg,.png,.pdf" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <small style="color: #666;">فرمت‌های مجاز: jpg, jpeg, png, pdf | حداکثر حجم: ۵ مگابایت</small>
            
            <div id="filePreview" style="margin-top: 15px; display: none;">
                <img id="imagePreview" src="#" style="max-width: 200px; max-height: 200px; border: 1px solid #ddd; border-radius: 5px; padding: 5px;">
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px;">توضیحات</label>
            <textarea name="description" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
        </div>

        <button type="submit" name="create_waybill" style="background: #3498db; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer;">
            ثبت بارنامه
        </button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#vendorSelect').select2({ width: '100%' });
});

document.querySelector('input[name="waybill_file"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('filePreview');
    const imagePreview = document.getElementById('imagePreview');
    
    if (file && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            imagePreview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>