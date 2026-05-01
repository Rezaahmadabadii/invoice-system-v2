<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
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

// دریافت اطلاعات بارنامه
$stmt = $pdo->prepare("
    SELECT d.*, c.name as company_name, v.name as vendor_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    WHERE d.id = ? AND d.type = 'waybill'
");
$stmt->execute([$id]);
$waybill = $stmt->fetch();

if (!$waybill) {
    header('Location: waybills.php');
    exit;
}

// بررسی دسترسی: فقط ایجادکننده و در وضعیت‌های قابل ویرایش
$can_edit = ($waybill['created_by'] == $_SESSION['user_id']) && in_array($waybill['status'], ['draft', 'pending']);
if (!$can_edit) {
    $_SESSION['error'] = 'شما مجاز به ویرایش این بارنامه نیستید.';
    header('Location: waybill-view.php?id=' . $id);
    exit;
}

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_waybill'])) {
    
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
    
    $errors = [];
    
    if (empty($title)) $errors[] = 'عنوان بارنامه الزامی است';
    if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (empty($vendor_id)) $errors[] = 'انتخاب فروشنده الزامی است';
    if (empty($waybill_number)) $errors[] = 'شماره بارنامه الزامی است';
    if (empty($sender_name)) $errors[] = 'نام فرستنده الزامی است';
    if (empty($receiver_name)) $errors[] = 'نام گیرنده الزامی است';
    if (empty($cargo_description)) $errors[] = 'نام محموله الزامی است';
    if (empty($loading_origin)) $errors[] = 'مبدا بارگیری الزامی است';
    if (empty($discharge_destination)) $errors[] = 'مقصد تخلیه الزامی است';
    
    if (empty($errors)) {
        // به‌روزرسانی اطلاعات
        $update = $pdo->prepare("
            UPDATE documents SET 
                title = ?,
                company_id = ?,
                vendor_id = ?,
                waybill_number = ?,
                amount = ?,
                sender_name = ?,
                receiver_name = ?,
                cargo_description = ?,
                loading_origin = ?,
                discharge_destination = ?,
                driver1_name = ?,
                driver2_name = ?,
                vehicle_plate = ?,
                quantity = ?,
                weight = ?,
                carrier_responsible = ?,
                insurance_company = ?,
                description = ?
            WHERE id = ?
        ");
        
        $result = $update->execute([
            $title,
            $company_id,
            $vendor_id,
            $waybill_number,
            $amount,
            $sender_name,
            $receiver_name,
            $cargo_description,
            $loading_origin,
            $discharge_destination,
            $driver1_name,
            $driver2_name,
            $vehicle_plate,
            $quantity,
            $weight,
            $carrier_responsible,
            $insurance_company,
            $description,
            $id
        ]);
        
        if ($result) {
            logActivity($_SESSION['user_id'], 'edit_waybill', "بارنامه ویرایش شد: $title", $id);
            $success = 'بارنامه با موفقیت ویرایش شد.';
        } else {
            $errors[] = 'خطا در ویرایش بارنامه';
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'ویرایش بارنامه';
ob_start();
?>

<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row-3col { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
    .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; }
    .btn-back { background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; }
    .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .alert-danger { background: #f8d7da; color: #721c24; }
    .alert-success { background: #d4edda; color: #155724; }
    .required { color: red; }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">✏️ ویرایش بارنامه</h1>
    <a href="waybill-view.php?id=<?php echo $id; ?>" class="btn-back">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <script>
        setTimeout(function() { window.location.href = 'waybill-view.php?id=<?php echo $id; ?>'; }, 1500);
    </script>
<?php endif; ?>

<?php if (!$success): ?>
<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST">
        <div class="row-2col">
            <div class="form-group">
                <label>عنوان بارنامه <span class="required">*</span></label>
                <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? $waybill['title']); ?>">
            </div>
            <div class="form-group">
                <label>شماره بارنامه <span class="required">*</span></label>
                <input type="text" name="waybill_number" required value="<?php echo htmlspecialchars($_POST['waybill_number'] ?? $waybill['waybill_number']); ?>">
            </div>
        </div>

        <div class="row-2col">
            <div class="form-group">
                <label>مبلغ بارنامه (تومان)</label>
                <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? number_format($waybill['amount'] ?? 0)); ?>">
            </div>
            <div class="form-group">
                <label>فروشنده <span class="required">*</span></label>
                <select name="vendor_id" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>" <?php echo (($_POST['vendor_id'] ?? $waybill['vendor_id']) == $vendor['id']) ? 'selected' : ''; ?>><?php echo $vendor['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row-2col">
            <div class="form-group">
                <label>انتخاب شرکت <span class="required">*</span></label>
                <select name="company_id" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo $comp['id']; ?>" <?php echo (($_POST['company_id'] ?? $waybill['company_id']) == $comp['id']) ? 'selected' : ''; ?>><?php echo $comp['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div></div>
        </div>

        <div class="row-3col">
            <div class="form-group">
                <label>نام فرستنده <span class="required">*</span></label>
                <input type="text" name="sender_name" required value="<?php echo htmlspecialchars($_POST['sender_name'] ?? $waybill['sender_name']); ?>">
            </div>
            <div class="form-group">
                <label>نام گیرنده <span class="required">*</span></label>
                <input type="text" name="receiver_name" required value="<?php echo htmlspecialchars($_POST['receiver_name'] ?? $waybill['receiver_name']); ?>">
            </div>
            <div class="form-group">
                <label>نام محموله <span class="required">*</span></label>
                <input type="text" name="cargo_description" required value="<?php echo htmlspecialchars($_POST['cargo_description'] ?? $waybill['cargo_description']); ?>">
            </div>
        </div>

        <div class="row-2col">
            <div class="form-group">
                <label>مبدا بارگیری <span class="required">*</span></label>
                <input type="text" name="loading_origin" required value="<?php echo htmlspecialchars($_POST['loading_origin'] ?? $waybill['loading_origin']); ?>">
            </div>
            <div class="form-group">
                <label>مقصد تخلیه <span class="required">*</span></label>
                <input type="text" name="discharge_destination" required value="<?php echo htmlspecialchars($_POST['discharge_destination'] ?? $waybill['discharge_destination']); ?>">
            </div>
        </div>

        <div class="row-3col">
            <div class="form-group">
                <label>راننده اول</label>
                <input type="text" name="driver1_name" value="<?php echo htmlspecialchars($_POST['driver1_name'] ?? $waybill['driver1_name']); ?>">
            </div>
            <div class="form-group">
                <label>راننده دوم</label>
                <input type="text" name="driver2_name" value="<?php echo htmlspecialchars($_POST['driver2_name'] ?? $waybill['driver2_name']); ?>">
            </div>
            <div class="form-group">
                <label>شماره پلاک</label>
                <input type="text" name="vehicle_plate" value="<?php echo htmlspecialchars($_POST['vehicle_plate'] ?? $waybill['vehicle_plate']); ?>">
            </div>
        </div>

        <div class="row-3col">
            <div class="form-group">
                <label>تعداد</label>
                <input type="number" name="quantity" value="<?php echo $_POST['quantity'] ?? $waybill['quantity']; ?>">
            </div>
            <div class="form-group">
                <label>وزن</label>
                <input type="number" name="weight" value="<?php echo $_POST['weight'] ?? $waybill['weight']; ?>">
            </div>
            <div class="form-group">
                <label>مسئول حمل</label>
                <input type="text" name="carrier_responsible" value="<?php echo htmlspecialchars($_POST['carrier_responsible'] ?? $waybill['carrier_responsible']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label>شرکت بیمه</label>
            <input type="text" name="insurance_company" value="<?php echo htmlspecialchars($_POST['insurance_company'] ?? $waybill['insurance_company']); ?>">
        </div>

        <div class="form-group">
            <label>توضیحات</label>
            <textarea name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? $waybill['description']); ?></textarea>
        </div>

        <button type="submit" name="edit_waybill" class="btn-submit">💾 ذخیره تغییرات</button>
    </form>
</div>
<?php endif; ?>

<script>
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>