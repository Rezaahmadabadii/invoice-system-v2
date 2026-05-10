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
    $_SESSION['error'] = 'فاکتور مورد نظر یافت نشد.';
    header('Location: inbox.php');
    exit;
}

// بررسی دسترسی برای حذف
$user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['user_roles'] ?? [];
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);
$is_creator = ($invoice['created_by'] == $user_id);
$is_holder = ($invoice['current_holder_user_id'] == $user_id) || 
             ($invoice['current_holder_department_id'] == ($_SESSION['user_department_id'] ?? null));

// وضعیت‌هایی که قابل حذف هستند
$deletable_statuses = ['draft', 'forwarded', 'pending'];

// بررسی آیا گیرنده اقدامی انجام داده است (برای فاکتورهای ارجاع شده)
$has_action = false;
if ($invoice['status'] == 'forwarded') {
    $action_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM forwarding_history 
        WHERE document_id = ? AND action NOT IN ('forward', 'cancel_forward', 'return_to_sender')
    ");
    $action_stmt->execute([$id]);
    $has_action = $action_stmt->fetchColumn() > 0;
}

// ========== بررسی مجوز حذف ==========
$can_delete = false;
$error_message = '';
$need_password = false;

if ($is_admin) {
    // ادمین می‌تواند هر فاکتوری را حذف کند (بدون محدودیت وضعیت)
    $can_delete = true;
    $need_password = true; // ادمین همیشه برای حذف نیاز به رمز دارد
} 
elseif ($is_creator && in_array($invoice['status'], $deletable_statuses)) {
    // ایجادکننده (غیر ادمین) فقط فاکتورهای با وضعیت مجاز را می‌تواند حذف کند
    $can_delete = true;
    $need_password = false;
} 
else {
    $error_message = 'شما مجاز به حذف این فاکتور نیستید.';
    $can_delete = false;
}

// ========== نمایش فرم رمز برای ادمین ==========
if ($need_password && !isset($_POST['password'])) {
    ?>
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>تأیید رمز عبور</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f1f5f9;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .confirm-box {
                background: white;
                padding: 30px;
                border-radius: 20px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                text-align: center;
                max-width: 400px;
                width: 90%;
            }
            .confirm-box h2 {
                color: #ef4444;
                margin-bottom: 20px;
            }
            .confirm-box p {
                color: #334155;
                margin-bottom: 20px;
            }
            .confirm-box input {
                width: 100%;
                padding: 12px;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                margin-bottom: 20px;
                font-size: 16px;
                text-align: center;
            }
            .confirm-box button {
                background: #ef4444;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 10px;
                cursor: pointer;
                font-size: 14px;
            }
            .confirm-box .cancel {
                background: #64748b;
                color: white;
                padding: 10px 20px;
                border-radius: 10px;
                text-decoration: none;
                margin-left: 10px;
                display: inline-block;
            }
            .error {
                color: #ef4444;
                margin-bottom: 15px;
                font-size: 13px;
            }
        </style>
    </head>
    <body>
        <div class="confirm-box">
            <h2>⚠️ تأیید امنیتی</h2>
            <p>شما به عنوان مدیر در حال حذف فاکتور <strong><?php echo htmlspecialchars($invoice['document_number']); ?></strong> هستید.<br>
            این عمل غیرقابل بازگشت است.</p>
            <p style="color: #d97706;">لطفاً رمز عبور خود را وارد کنید:</p>
            <form method="POST">
                <input type="password" name="password" placeholder="رمز عبور" autofocus required>
                <button type="submit">🗑️ تأیید و حذف</button>
                <a href="invoice-view.php?id=<?php echo $id; ?>" class="cancel">انصراف</a>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== بررسی رمز وارد شده برای ادمین ==========
if ($need_password && isset($_POST['password'])) {
    $password_entered = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if (!password_verify($password_entered, $user_data['password'])) {
        $_SESSION['error'] = 'رمز عبور اشتباه است.';
        header('Location: invoice-view.php?id=' . $id);
        exit;
    }
}

// ========== ادامه حذف در صورت عدم مجوز ==========
if (!$can_delete) {
    $_SESSION['error'] = $error_message ?: 'شما مجاز به حذف این فاکتور نیستید.';
    header('Location: invoice-view.php?id=' . $id);
    exit;
}

// ========== حذف فایل فیزیکی ==========
if ($invoice['file_path']) {
    $file_path = __DIR__ . '/../' . $invoice['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}

// ========== حذف از دیتابیس ==========
try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM forwarding_history WHERE document_id = ?")->execute([$id]);
    $delete = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $delete->execute([$id]);
    $pdo->commit();
    
    logActivity($_SESSION['user_id'], 'delete_invoice', "فاکتور حذف شد: " . $invoice['document_number'], $id);
    
    $_SESSION['message'] = 'فاکتور با موفقیت حذف شد.';
    $_SESSION['message_type'] = 'success';
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'خطا در حذف فاکتور: ' . $e->getMessage();
}

header('Location: inbox.php');
exit;
?>