<?php
session_start();

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

// دریافت لیست بخش‌ها
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_document'])) {
    $type = $_POST['type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $department_id = $_POST['department_id'] ?? $_SESSION['department_id'] ?? 1;
    
    // فیلدهای اختصاصی
    $contract_number = $_POST['contract_number'] ?? '';
    $vendor_name = $_POST['vendor_name'] ?? '';
    $vendor_economic_code = $_POST['vendor_economic_code'] ?? '';
    
    // فیلدهای بارنامه
    $waybill_number = $_POST['waybill_number'] ?? '';
    $sender_name = $_POST['sender_name'] ?? '';
    $receiver_name = $_POST['receiver_name'] ?? '';
    $cargo_description = $_POST['cargo_description'] ?? '';
    $loading_origin = $_POST['loading_origin'] ?? '';
    $discharge_destination = $_POST['discharge_destination'] ?? '';
    $driver1_name = $_POST['driver1_name'] ?? '';
    $driver2_name = $_POST['driver2_name'] ?? '';
    $vehicle_plate = $_POST['vehicle_plate'] ?? '';
    $quantity = $_POST['quantity'] ?? '';
    $weight = $_POST['weight'] ?? '';
    $carrier_responsible = $_POST['carrier_responsible'] ?? '';
    $insurance_company = $_POST['insurance_company'] ?? '';
    $waybill_notes = $_POST['waybill_notes'] ?? '';
    
    // فیلدهای مودیان
    $tax_id = $_POST['tax_id'] ?? '';
    $tax_invoice_number = $_POST['tax_invoice_number'] ?? '';
    
    if (empty($type) || empty($title)) {
        $error = 'نوع سند و عنوان الزامی است';
    } else {
        // تولید شماره سند
        $prefix = match($type) {
            'invoice_with_contract' => 'INV-C',
            'invoice_without_contract' => 'INV',
            'waybill' => 'WBL',
            'tax' => 'TAX',
            default => 'DOC'
        };
        $doc_number = $prefix . '-' . date('Ymd') . '-' . rand(100, 999);
        
        $data = [
            'document_number' => $doc_number,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'amount' => $amount ?: null,
            'created_by' => $_SESSION['user_id'],
            'department_id' => $department_id,
            'document_date' => date('Y-m-d'),
            'status' => 'draft',
            'contract_number' => $contract_number,
            'vendor_name' => $vendor_name,
            'vendor_economic_code' => $vendor_economic_code,
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
            'waybill_notes' => $waybill_notes,
            'tax_id' => $tax_id,
            'tax_invoice_number' => $tax_invoice_number
        ];
        
        $fields = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO documents ($fields) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($data)) {
            $success = 'سند با موفقیت ایجاد شد. شماره پیگیری: ' . $doc_number;
        } else {
            $error = 'خطا در ایجاد سند';
        }
    }
}

$page_title = 'ایجاد سند جدید';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">ایجاد سند جدید</h1>
    <a href="inbox.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
<?php endif; ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST" id="documentForm">
        <!-- اطلاعات پایه -->
        <div style="margin-bottom: 30px;">
            <h3 style="color: #2c3e50; margin-bottom: 15px;">اطلاعات پایه</h3>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px;">نوع سند *</label>
                    <select name="type" id="docType" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <option value="">انتخاب کنید</option>
                        <option value="invoice_with_contract">📄 فاکتور با قرارداد</option>
                        <option value="invoice_without_contract">📄 فاکتور بدون قرارداد</option>
                        <option value="waybill">📦 بارنامه</option>
                        <option value="tax">🏛️ سامانه مودیان</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px;">ارجاع به بخش</label>
                    <select name="department_id" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label style="display: block; margin-bottom: 5px;">عنوان سند *</label>
                <input type="text" name="title" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            
            <div style="margin-top: 15px;">
                <label style="display: block; margin-bottom: 5px;">توضیحات</label>
                <textarea name="description" rows="3" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;"></textarea>
            </div>
        </div>

        <!-- فیلدهای فاکتور -->
        <div id="invoiceFields" style="display: none; margin-bottom: 30px;">
            <h3 style="color: #2c3e50; margin-bottom: 15px;">اطلاعات فاکتور</h3>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px;">شماره قرارداد</label>
                    <input type="text" name="contract_number" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px;">نام پیمانکار</label>
                    <input type="text" name="vendor_name" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px;">کد اقتصادی</label>
                    <input type="text" name="vendor_economic_code" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
                <div style="grid-column: span 3;">
                    <label style="display: block; margin-bottom: 5px;">مبلغ (تومان)</label>
                    <input type="text" name="amount" id="amountInput" onkeyup="formatNumber(this)" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
            </div>
        </div>

        <!-- فیلدهای بارنامه -->
        <div id="waybillFields" style="display: none; margin-bottom: 30px;">
            <h3 style="color: #2c3e50; margin-bottom: 15px;">اطلاعات بارنامه</h3>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                <input type="text" name="waybill_number" placeholder="شماره بارنامه" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="sender_name" placeholder="نام فرستنده" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="receiver_name" placeholder="نام گیرنده" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="cargo_description" placeholder="نام محموله" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="loading_origin" placeholder="مبدا بارگیری" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="discharge_destination" placeholder="مقصد تخلیه" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="driver1_name" placeholder="راننده اول" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="driver2_name" placeholder="راننده دوم" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="vehicle_plate" placeholder="شماره پلاک" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="quantity" placeholder="تعداد" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="weight" placeholder="وزن" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="carrier_responsible" placeholder="مسئول حمل" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="insurance_company" placeholder="شرکت بیمه" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <textarea name="waybill_notes" placeholder="توضیحات" rows="2" style="grid-column: span 3; padding: 10px; border: 1px solid #ddd; border-radius: 5px;"></textarea>
            </div>
        </div>

        <!-- فیلدهای مودیان -->
        <div id="taxFields" style="display: none; margin-bottom: 30px;">
            <h3 style="color: #2c3e50; margin-bottom: 15px;">اطلاعات سامانه مودیان</h3>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <input type="text" name="tax_id" placeholder="شناسه مالیاتی" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <input type="text" name="tax_invoice_number" placeholder="شماره صورتحساب" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>

        <button type="submit" name="create_document" style="background: #3498db; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px;">
            ایجاد سند
        </button>
    </form>
</div>

<script>
document.getElementById('docType').addEventListener('change', function() {
    document.getElementById('invoiceFields').style.display = 'none';
    document.getElementById('waybillFields').style.display = 'none';
    document.getElementById('taxFields').style.display = 'none';
    
    if (this.value === 'invoice_with_contract' || this.value === 'invoice_without_contract') {
        document.getElementById('invoiceFields').style.display = 'block';
    } else if (this.value === 'waybill') {
        document.getElementById('waybillFields').style.display = 'block';
    } else if (this.value === 'tax') {
        document.getElementById('taxFields').style.display = 'block';
    }
});

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