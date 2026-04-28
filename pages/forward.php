<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('forward_to_others')) {
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

$document_id = $_GET['doc_id'] ?? 0;

// دریافت اطلاعات سند
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name,
           v.name as vendor_name
    FROM documents d 
    LEFT JOIN companies c ON d.company_id = c.id 
    LEFT JOIN vendors v ON d.vendor_id = v.id 
    WHERE d.id = ?
");
$stmt->execute([$document_id]);
$document = $stmt->fetch();

if (!$document) {
    header('Location: inbox.php');
    exit;
}

// دریافت لیست نقش‌ها برای ارجاع
$roles = $pdo->query("SELECT id, name, description FROM roles WHERE name != 'super_admin' ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forward'])) {
    $selected_roles = $_POST['roles'] ?? [];
    $deadline_days = (int)($_POST['deadline_days'] ?? 1);
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($selected_roles)) {
        $error = 'حداقل یک نقش را انتخاب کنید';
    } else {
        $deadline = date('Y-m-d H:i:s', strtotime("+$deadline_days days"));
        
        foreach ($selected_roles as $role_id) {
            $stmt = $pdo->prepare("
                INSERT INTO forwarding (document_id, from_user, to_role, deadline, notes, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$document_id, $_SESSION['user_id'], $role_id, $deadline, $notes]);
        }
        
        logActivity($_SESSION['user_id'], 'forward', "سند #{$document['document_number']} به " . count($selected_roles) . " نقش ارجاع شد");
        $success = 'ارجاع با موفقیت انجام شد';
    }
}

$page_title = 'ارجاع سند';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">ارجاع سند</h1>
    <a href="invoice-view.php?id=<?php echo $document_id; ?>" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <h3 style="margin-bottom: 15px;">اطلاعات سند</h3>
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
        <div>
            <small style="color: #7f8c8d;">شماره</small>
            <div><?php echo htmlspecialchars($document['document_number']); ?></div>
        </div>
        <div>
            <small style="color: #7f8c8d;">عنوان</small>
            <div><?php echo htmlspecialchars($document['title']); ?></div>
        </div>
        <div>
            <small style="color: #7f8c8d;">شرکت</small>
            <div><?php echo htmlspecialchars($document['company_name'] ?? '-'); ?></div>
        </div>
        <div>
            <small style="color: #7f8c8d;">فروشنده</small>
            <div><?php echo htmlspecialchars($document['vendor_name'] ?? '-'); ?></div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
<?php endif; ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST">
        <div style="margin-bottom: 20px;">
            <h4 style="margin-bottom: 15px;">ارجاع به:</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                <?php foreach ($roles as $role): ?>
                    <label style="display: flex; align-items: flex-start; gap: 10px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>">
                        <div>
                            <strong><?php echo htmlspecialchars($role['name']); ?></strong>
                            <?php if ($role['description']): ?>
                                <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars($role['description']); ?></small>
                            <?php endif; ?>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="display: block; margin-bottom: 5px;">مهلت (روز)</label>
                <select name="deadline_days" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <option value="1">۱ روز</option>
                    <option value="2">۲ روز</option>
                    <option value="3">۳ روز</option>
                    <option value="5">۵ روز</option>
                    <option value="7">۱ هفته</option>
                    <option value="14">۲ هفته</option>
                </select>
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px;">توضیحات</label>
                <textarea name="notes" rows="2" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" placeholder="توضیحات اضافی..."></textarea>
            </div>
        </div>
        
        <div style="text-align: left;">
            <button type="submit" name="forward" style="background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px;" onclick="return confirm('آیا از ارجاع این سند اطمینان دارید؟')">
                <i class="fas fa-share"></i> ارجاع
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>