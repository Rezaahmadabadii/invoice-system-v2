<?php
session_start();

// بررسی لاگین بودن
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'لطفا وارد سیستم شوید']);
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در اتصال به پایگاه داده']);
    exit;
}

$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'];

// دریافت داده‌ها (هم فرم POST و هم JSON)
$data = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['document_id'])) {
        // فرم معمولی
        $document_id = $_POST['document_id'] ?? 0;
        $status = $_POST['status'] ?? '';
        $comment = $_POST['comment'] ?? '';
    } else {
        // JSON
        $input = json_decode(file_get_contents('php://input'), true);
        $document_id = $input['document_id'] ?? 0;
        $status = $input['status'] ?? '';
        $comment = $input['comment'] ?? '';
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'متود نامعتبر']);
    exit;
}

// اعتبارسنجی
if (!$document_id || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'اطلاعات ناقص است']);
    exit;
}

// بررسی وجود سند و دسترسی
$stmt = $pdo->prepare("
    SELECT d.*, u.full_name as creator_name 
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = ?
");
$stmt->execute([$document_id]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'سند یافت نشد']);
    exit;
}

// بررسی دسترسی برای تغییر وضعیت
$can_change = (
    $document['department_id'] == $department_id || 
    $document['created_by'] == $user_id
);

if (!$can_change) {
    // بررسی دسترسی مستقیم
    $access = $pdo->prepare("SELECT id FROM document_access WHERE document_id = ? AND user_id = ? AND access_type = 'approve'");
    $access->execute([$document_id, $user_id]);
    $can_change = $access->fetch();
}

if (!$can_change) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'شما اجازه تغییر وضعیت این سند را ندارید']);
    exit;
}

// اعتبارسنجی وضعیت
$valid_statuses = ['draft', 'submitted', 'approved', 'rejected'];
if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'وضعیت نامعتبر است']);
    exit;
}

// به‌روزرسانی وضعیت
$update = $pdo->prepare("UPDATE documents SET status = ? WHERE id = ?");
if ($update->execute([$status, $document_id])) {
    
    // ثبت در تاریخچه
    $status_text = [
        'draft' => 'پیش‌نویس',
        'submitted' => 'ارسال شده',
        'approved' => 'تایید شده',
        'rejected' => 'رد شده'
    ];
    
    $description = "وضعیت سند به " . $status_text[$status] . " تغییر یافت";
    if ($comment) {
        $description .= " - توضیحات: " . $comment;
    }
    
    $history = $pdo->prepare("INSERT INTO document_history (document_id, user_id, action, description) VALUES (?, ?, 'status_change', ?)");
    $history->execute([$document_id, $user_id, $description]);
    
    // اگر وضعیت "ارسال شده" است، به بخش مربوطه اطلاع داده شود
    if ($status == 'submitted') {
        // می‌توانید نوتیفیکیشن یا اعلان اضافه کنید
    }
    
    // اگر وضعیت "تایید شده" است و سند از نوع مالیاتی است
    if ($status == 'approved' && $document['type'] == 'tax') {
        // می‌توانید به سامانه مودیان متصل شوید
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'وضعیت سند با موفقیت تغییر کرد',
        'status' => $status,
        'status_text' => $status_text[$status]
    ]);
    
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در تغییر وضعیت']);
}
exit;