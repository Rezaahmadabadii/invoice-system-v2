<?php
session_start();

// بررسی لاگین بودن
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

$document_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// دریافت اطلاعات سند
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND created_by = ?");
$stmt->execute([$document_id, $user_id]);
$document = $stmt->fetch();

if (!$document) {
    header('Location: inbox.php');
    exit;
}

// فقط پیش‌نویس قابل ویرایش است
if ($document['status'] != 'draft') {
    $_SESSION['error'] = 'این سند قابل ویرایش نیست';
    header('Location: document-view.php?id=' . $document_id);
    exit;
}

// دریافت لیست بخش‌ها
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_document'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $document_date = $_POST['document_date'] ?? date('Y-m-d');
    $department_id = $_POST['department_id'] ?? $document['department_id'];
    
    if (empty($title)) {
        $error = 'عنوان سند الزامی است';
    } else {
        $data = [
            'title' => $title,
            'description' => $description,
            'amount' => $amount ?: null,
            'department_id' => $department_id,
            'document_date' => $document_date
        ];
        
        // فیلدهای اختصاصی فاکتور
        if ($document['type'] == 'invoice_with_contract' || $document['type'] == 'invoice_without_contract') {
            $data['contract_number'] = $_POST['contract_number'] ?? null;
            $data['vendor_name'] = $_POST['vendor_name'] ?? null;
            $data['vendor_economic_code'] = $_POST['vendor_economic_code'] ?? null;
        }
        
        // فیلدهای اختصاصی بارنامه
        if ($document['type'] == 'waybill') {
            $waybill_fields = [
                'waybill_number', 'sender_name', 'receiver_name', 'cargo_description',
                'loading_origin', 'discharge_destination', 'driver1_name', 'driver2_name',
                'vehicle_plate', 'quantity', 'weight', 'carrier_responsible',
                'insurance_company', 'waybill_notes'
            ];
            foreach ($waybill_fields as $field) {
                $data[$field] = $_POST[$field] ?? null;
            }
        }
        
        // فیلدهای اختصاصی مودیان
        if ($document['type'] == 'tax') {
            $data['tax_id'] = $_POST['tax_id'] ?? null;
            $data['tax_invoice_number'] = $_POST['tax_invoice_number'] ?? null;
        }
        
        // ساختار SQL
        $sets = [];
        foreach ($data as $key => $value) {
            $sets[] = "$key = :$key";
        }
        $sql = "UPDATE documents SET " . implode(', ', $sets) . " WHERE id = :id";
        $data['id'] = $document_id;
        
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($data)) {
            // ثبت در تاریخچه
            $history = $pdo->prepare("INSERT INTO document_history (document_id, user_id, action, description) VALUES (?, ?, 'edit', 'سند ویرایش شد')");
            $history->execute([$document_id, $user_id]);
            
            $success = 'سند با موفقیت ویرایش شد';
        } else {
            $error = 'خطا در ویرایش سند';
        }
    }
}

$page_title = 'ویرایش سند';
ob_start();
?>

<div class="dashboard-header" style="justify-content: space-between;">
    <h1><i class="fas fa-edit"></i> ویرایش سند</h1>
    <a href="document-view.php?id=<?php echo $document_id; ?>" class="btn-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
</div>

<?php if ($error): ?>
    <div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px;"><?php echo $success; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" id="editForm">
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; color: #fff;">نوع سند</label>
                    <input type="text" value="<?php 
                        $types = [
                            'invoice_with_contract' => 'فاکتور با قرارداد',
                            'invoice_without_contract' => 'فاکتور بدون قرارداد',
                            'waybill' => 'بارنامه',
                            'tax' => 'سامانه مودیان'
                        ];
                        echo $types[$document['type']] ?? $document['type'];
                    ?>" readonly disabled style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 8px; color: #fff;">شماره سند</label>
                    <input type="text" value="<?php echo $document['document_number']; ?>" readonly disabled style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 8px; color: #fff;">تاریخ سند</label>
                    <input type="date" name="document_date" value="<?php echo $document['document_date']; ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 8px; color: #fff;">ارجاع به بخش</label>
                    <select name="department_id" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $dept['id'] == $document['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="grid-column: span 2;">
                    <label style="display: block; margin-bottom: 8px; color: #fff;">عنوان سند *</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($document['title']); ?>" required style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                </div>
                
                <div style="grid-column: span 2;">
                    <label style="display: block; margin-bottom: 8px; color: #fff;">توضیحات</label>
                    <textarea name="description" rows="3" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;"><?php echo htmlspecialchars($document['description']); ?></textarea>
                </div>
            </div>
            
            <!-- فیلدهای اختصاصی فاکتور -->
            <?php if ($document['type'] == 'invoice_with_contract' || $document['type'] == 'invoice_without_contract'): ?>
            <div style="margin-bottom: 30px; padding: 20px; background:rgba(255,255,255,0.05); border-radius:10px;">
                <h3 style="color:#00b4a0; margin-bottom:20px;">اطلاعات فاکتور</h3>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <?php if ($document['type'] == 'invoice_with_contract'): ?>
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #fff;">شماره قرارداد</label>
                        <input type="text" name="contract_number" value="<?php echo htmlspecialchars($document['contract_number'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    </div>
                    <?php endif; ?>
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #fff;">نام پیمانکار</label>
                        <input type="text" name="vendor_name" value="<?php echo htmlspecialchars($document['vendor_name'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; color: #fff;">کد اقتصادی</label>
                        <input type="text" name="vendor_economic_code" value="<?php echo htmlspecialchars($document['vendor_economic_code'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    </div>
                    <div style="grid-column: span 3;">
                        <label style="display: block; margin-bottom: 8px; color: #fff;">مبلغ (تومان)</label>
                        <input type="text" name="amount" value="<?php echo $document['amount'] ? number_format($document['amount']) : ''; ?>" onkeyup="formatNumber(this)" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- فیلدهای اختصاصی بارنامه -->
            <?php if ($document['type'] == 'waybill'): ?>
            <div style="margin-bottom: 30px; padding: 20px; background:rgba(255,255,255,0.05); border-radius:10px;">
                <h3 style="color:#00b4a0; margin-bottom:20px;">اطلاعات بارنامه</h3>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                    <input type="text" name="waybill_number" placeholder="شماره بارنامه *" value="<?php echo htmlspecialchars($document['waybill_number'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="sender_name" placeholder="نام فرستنده *" value="<?php echo htmlspecialchars($document['sender_name'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="receiver_name" placeholder="نام گیرنده *" value="<?php echo htmlspecialchars($document['receiver_name'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="cargo_description" placeholder="نام محموله *" value="<?php echo htmlspecialchars($document['cargo_description'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="loading_origin" placeholder="مبدا بارگیری *" value="<?php echo htmlspecialchars($document['loading_origin'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="discharge_destination" placeholder="مقصد تخلیه *" value="<?php echo htmlspecialchars($document['discharge_destination'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="driver1_name" placeholder="راننده اول" value="<?php echo htmlspecialchars($document['driver1_name'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="driver2_name" placeholder="راننده دوم" value="<?php echo htmlspecialchars($document['driver2_name'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="vehicle_plate" placeholder="شماره پلاک" value="<?php echo htmlspecialchars($document['vehicle_plate'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="number" name="quantity" placeholder="تعداد" value="<?php echo $document['quantity']; ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="number" name="weight" placeholder="وزن" value="<?php echo $document['weight']; ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="carrier_responsible" placeholder="مسئول حمل" value="<?php echo htmlspecialchars($document['carrier_responsible'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="insurance_company" placeholder="شرکت بیمه" value="<?php echo htmlspecialchars($document['insurance_company'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <textarea name="waybill_notes" placeholder="توضیحات" rows="2" style="grid-column: span 3; width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;"><?php echo htmlspecialchars($document['waybill_notes'] ?? ''); ?></textarea>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- فیلدهای اختصاصی مودیان -->
            <?php if ($document['type'] == 'tax'): ?>
            <div style="margin-bottom: 30px; padding: 20px; background:rgba(255,255,255,0.05); border-radius:10px;">
                <h3 style="color:#00b4a0; margin-bottom:20px;">اطلاعات سامانه مودیان</h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <input type="text" name="tax_id" placeholder="شناسه مالیاتی" value="<?php echo htmlspecialchars($document['tax_id'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                    <input type="text" name="tax_invoice_number" placeholder="شماره صورتحساب" value="<?php echo htmlspecialchars($document['tax_invoice_number'] ?? ''); ?>" style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:8px; color:#fff;">
                </div>
            </div>
            <?php endif; ?>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <a href="document-view.php?id=<?php echo $document_id; ?>" style="padding:12px 25px; background:#6c757d; color:white; border:none; border-radius:8px; text-decoration:none;">انصراف</a>
                <button type="submit" name="update_document" class="login-btn" style="width:auto; padding:12px 25px;">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<script>
function formatNumber(input) {
    let value = input.value.replace(/,/g, '');
    if (!isNaN(value) && value !== '') {
        input.value = Number(value).toLocaleString();
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>