<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!isAdmin()) {
    header('Location: dashboard.php');
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

$filter_type = $_GET['filter_type'] ?? 'all';
$message = '';
$error = '';

// افزودن نقش جدید
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_role'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $role_type = $_POST['role_type'] ?? 'normal'; // department, normal
    
    $is_department = ($role_type == 'department') ? 1 : 0;
    
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO roles (name, description, is_department, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt->execute([$name, $description, $is_department])) {
            $message = 'نقش جدید با موفقیت اضافه شد';
            logActivity($_SESSION['user_id'], 'add_role', "نقش جدید اضافه شد: $name");
        } else {
            $error = 'خطا در افزودن نقش';
        }
    }
}

// ویرایش نقش
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_role'])) {
    $id = $_POST['role_id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $role_type = $_POST['role_type'] ?? 'normal';
    $is_department = ($role_type == 'department') ? 1 : 0;
    
    $check = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $check->execute([$id]);
    $role = $check->fetch();
    
    if ($role && $role['name'] == 'super_admin') {
        $error = 'نقش super_admin قابل ویرایش نیست';
    } elseif (!empty($name)) {
        $stmt = $pdo->prepare("UPDATE roles SET name = ?, description = ?, is_department = ? WHERE id = ?");
        if ($stmt->execute([$name, $description, $is_department, $id])) {
            $message = 'نقش با موفقیت ویرایش شد';
            logActivity($_SESSION['user_id'], 'edit_role', "نقش ویرایش شد: $name");
        } else {
            $error = 'خطا در ویرایش نقش';
        }
    }
}

// حذف نقش
if (isset($_GET['delete']) && isSuperAdmin()) {
    $id = $_GET['delete'];
    
    $check = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $check->execute([$id]);
    $role = $check->fetch();
    
    if ($role && $role['name'] == 'super_admin') {
        $error = 'نمی‌توان نقش super_admin را حذف کرد';
    } else {
        $userCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
        $userCheck->execute([$id]);
        $userCount = $userCheck->fetchColumn();
        
        if ($userCount > 0) {
            $error = 'نمی‌توان این نقش را حذف کرد، زیرا ' . $userCount . ' کاربر به آن متصل هستند.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'نقش با موفقیت حذف شد';
                logActivity($_SESSION['user_id'], 'delete_role', "نقش حذف شد: " . $role['name']);
            } else {
                $error = 'خطا در حذف نقش';
            }
        }
    }
}

// دریافت نقش‌ها
if ($filter_type == 'departments') {
    $sql = "SELECT * FROM roles WHERE is_department = 1 ORDER BY name";
} elseif ($filter_type == 'roles') {
    $sql = "SELECT * FROM roles WHERE is_department = 0 ORDER BY name";
} else {
    $sql = "SELECT * FROM roles ORDER BY is_department DESC, name";
}
$roles = $pdo->query($sql)->fetchAll();

$page_title = 'مدیریت نقش‌ها';
ob_start();
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    .filter-buttons {
        display: flex;
        gap: 12px;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }
    .filter-btn {
        padding: 10px 24px;
        border-radius: 40px;
        text-decoration: none;
        background: #f0f2f5;
        color: #2c3e50;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .filter-btn.active {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: white;
        box-shadow: 0 4px 12px rgba(52,152,219,0.3);
    }
    .filter-btn:hover { transform: translateY(-2px); }
    
    .header-with-smiley {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }
    .smiley-emoji {
        font-size: 32px;
        cursor: pointer;
        display: inline-block;
        transition: all 0.3s ease;
        filter: drop-shadow(0 2px 5px rgba(0,0,0,0.1));
    }
    /* انیمیشن‌های اسمایل - حرکت به چپ و برگشت */
    @keyframes smiley-slide-left {
        0% { transform: translateX(0) rotate(0deg); opacity: 1; }
        30% { transform: translateX(-80px) rotate(-15deg); opacity: 0.8; }
        60% { transform: translateX(-120px) rotate(-25deg); opacity: 0.4; }
        85% { transform: translateX(-60px) rotate(-10deg); opacity: 0.7; }
        100% { transform: translateX(0) rotate(0deg); opacity: 1; }
    }
    @keyframes smiley-slide-left-fade {
        0% { transform: translateX(0) rotate(0deg); opacity: 1; }
        40% { transform: translateX(-100px) rotate(-20deg); opacity: 0.5; }
        70% { transform: translateX(-150px) rotate(-30deg); opacity: 0; }
        100% { transform: translateX(0) rotate(0deg); opacity: 1; }
    }
    @keyframes smiley-wiggle {
        0%, 100% { transform: translateX(0) rotate(0deg); }
        25% { transform: translateX(-8px) rotate(-5deg); }
        75% { transform: translateX(5px) rotate(3deg); }
    }
    @keyframes smiley-spin-left {
        0% { transform: translateX(0) rotate(0deg); opacity: 1; }
        50% { transform: translateX(-80px) rotate(180deg); opacity: 0.5; }
        100% { transform: translateX(0) rotate(360deg); opacity: 1; }
    }
    @keyframes smiley-bounce-left {
        0%, 100% { transform: translateX(0) translateY(0); }
        30% { transform: translateX(-40px) translateY(-15px); }
        60% { transform: translateX(-80px) translateY(-5px); }
        85% { transform: translateX(-30px) translateY(5px); }
    }
    .smiley-slide-left { animation: smiley-slide-left 1.2s ease-in-out; }
    .smiley-slide-left-fade { animation: smiley-slide-left-fade 1.5s ease-out; }
    .smiley-wiggle { animation: smiley-wiggle 0.5s ease-in-out; }
    .smiley-spin-left { animation: smiley-spin-left 1s ease-in-out; }
    .smiley-bounce-left { animation: smiley-bounce-left 0.8s ease-in-out; }
    
    .add-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        border: 1px solid rgba(52,152,219,0.1);
    }
    .add-title {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .add-form {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    .add-form .field {
        flex: 1;
        min-width: 180px;
    }
    .add-form .field label {
        display: block;
        font-size: 12px;
        color: #7f8c8d;
        margin-bottom: 6px;
    }
    .add-form .field input, .add-form .field select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    .add-form .field input:focus, .add-form .field select:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
    }
    .add-btn {
        background: linear-gradient(135deg, #27ae60, #219a52);
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }
    .add-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(39,174,96,0.3);
    }
    
    .roles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .role-card {
        background: white;
        border-radius: 20px;
        padding: 18px 12px;
        text-align: center;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #eef2f5;
    }
    .role-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.12);
        border-color: #3498db20;
    }
    .role-icon {
        font-size: 42px;
        margin-bottom: 10px;
    }
    .role-name {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 4px;
        font-size: 14px;
    }
    .role-desc {
        font-size: 11px;
        color: #7f8c8d;
        margin-bottom: 10px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .role-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 500;
        margin-bottom: 10px;
    }
    .badge-admin { background: #e74c3c20; color: #e74c3c; }
    .badge-department { background: #3498db20; color: #3498db; }
    .badge-role { background: #95a5a620; color: #95a5a6; }
    
    .role-actions {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #f0f0f0;
    }
    .role-action {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 16px;
        padding: 5px;
        border-radius: 8px;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .role-action.permission { color: #3498db; }
    .role-action.edit { color: #f39c12; }
    .role-action.delete { color: #e74c3c; }
    .role-action:hover { background: #f8f9fa; transform: scale(1.05); }
    
    .edit-form {
        display: none;
        margin-top: 12px;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 12px;
    }
    .edit-form.show { display: block; }
    .edit-form form {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .edit-form input, .edit-form select {
        padding: 6px 10px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 12px;
        width: 100%;
    }
    .edit-form button {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12px;
    }
    .edit-buttons {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-top: 5px;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .alert-error {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    @media (max-width: 768px) {
        .roles-grid { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
        .add-form { flex-direction: column; }
        .add-form .field { width: 100%; }
    }
</style>

<div class="header-with-smiley">
    <div style="display: flex; align-items: center; gap: 15px;">
        <h1 style="margin: 0; color: #2c3e50;">👥 مدیریت نقش‌ها</h1>
        <div class="smiley-emoji" id="smileyEmoji">😊</div>
    </div>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 10px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($message): ?>
    <div class="alert-success"><?php echo $message; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="filter-buttons">
    <a href="?filter_type=all" class="filter-btn <?php echo $filter_type == 'all' ? 'active' : ''; ?>">
        <i class="fas fa-list"></i> همه
    </a>
    <a href="?filter_type=departments" class="filter-btn <?php echo $filter_type == 'departments' ? 'active' : ''; ?>">
        <i class="fas fa-building"></i> بخش‌ها
    </a>
    <a href="?filter_type=roles" class="filter-btn <?php echo $filter_type == 'roles' ? 'active' : ''; ?>">
        <i class="fas fa-users"></i> نقش‌های عادی
    </a>
</div>

<div class="add-card">
    <div class="add-title">
        <i class="fas fa-plus-circle" style="color: #27ae60;"></i>
        افزودن نقش جدید
    </div>
    <form method="POST" class="add-form">
        <div class="field">
            <label>نام نقش</label>
            <input type="text" name="name" placeholder="مثال: مدیر مالی" required>
        </div>
        <div class="field">
            <label>توضیحات (اختیاری)</label>
            <input type="text" name="description" placeholder="توضیحات">
        </div>
        <div class="field">
            <label>نوع نقش</label>
            <select name="role_type">
                <option value="department">🏢 بخش/واحد سازمانی</option>
                <option value="normal">👤 نقش عادی</option>
            </select>
        </div>
        <button type="submit" name="add_role" class="add-btn">
            <i class="fas fa-save"></i> افزودن
        </button>
    </form>
</div>

<div class="roles-grid">
    <?php foreach ($roles as $role): 
        $is_super_admin = ($role['name'] == 'super_admin');
        $is_department = ($role['is_department'] == 1);
        
        if ($is_super_admin) {
            $icon = '👑';
            $badge_class = 'badge-admin';
            $badge_text = 'مدیر کل';
        } elseif ($is_department) {
            $icon = '🏢';
            $badge_class = 'badge-department';
            $badge_text = 'بخش';
        } else {
            $icon = '👤';
            $badge_class = 'badge-role';
            $badge_text = 'نقش عادی';
        }
    ?>
        <div class="role-card">
            <div class="role-icon"><?php echo $icon; ?></div>
            <div class="role-name"><?php echo htmlspecialchars($role['name']); ?></div>
            <div class="role-desc"><?php echo htmlspecialchars($role['description'] ?? '-'); ?></div>
            <div class="role-badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></div>
            <div class="role-actions">
                <?php if (!$is_department && !$is_super_admin): ?>
                    <a href="role-permissions.php?id=<?php echo $role['id']; ?>" class="role-action permission" title="دسترسی‌ها">
                        <i class="fas fa-key"></i>
                    </a>
                <?php endif; ?>
                
                <?php if (!$is_super_admin): ?>
                    <button onclick="toggleEditForm(<?php echo $role['id']; ?>)" class="role-action edit" title="ویرایش">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if (isSuperAdmin()): ?>
                        <a href="?delete=<?php echo $role['id']; ?>&filter_type=<?php echo $filter_type; ?>" class="role-action delete" title="حذف" onclick="return confirm('آیا از حذف این نقش اطمینان دارید؟')">
                            <i class="fas fa-trash-alt"></i>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div id="edit-form-<?php echo $role['id']; ?>" class="edit-form">
                <form method="POST">
                    <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                    <input type="text" name="name" value="<?php echo htmlspecialchars($role['name']); ?>" required>
                    <input type="text" name="description" value="<?php echo htmlspecialchars($role['description'] ?? ''); ?>">
                    <select name="role_type">
                        <option value="department" <?php echo $is_department ? 'selected' : ''; ?>>🏢 بخش/واحد سازمانی</option>
                        <option value="normal" <?php echo !$is_department ? 'selected' : ''; ?>>👤 نقش عادی</option>
                    </select>
                    <div class="edit-buttons">
                        <button type="submit" name="edit_role" style="background:#27ae60; color:white; border:none; padding:6px 12px; border-radius:8px; cursor:pointer;">💾 ذخیره</button>
                        <button type="button" onclick="toggleEditForm(<?php echo $role['id']; ?>)" style="background:#95a5a6; color:white; border:none; padding:6px 12px; border-radius:8px; cursor:pointer;">❌ انصراف</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
function toggleEditForm(id) {
    var form = document.getElementById('edit-form-' + id);
    form.classList.toggle('show');
}

// انیمیشن‌های اسمایل (حرکت به چپ و برگشت)
const smiley = document.getElementById('smileyEmoji');
const animations = ['smiley-slide-left', 'smiley-slide-left-fade', 'smiley-wiggle', 'smiley-spin-left', 'smiley-bounce-left'];

function playRandomAnimation() {
    animations.forEach(anim => smiley.classList.remove(anim));
    const randomAnim = animations[Math.floor(Math.random() * animations.length)];
    smiley.classList.add(randomAnim);
    setTimeout(() => {
        smiley.classList.remove(randomAnim);
    }, 1500);
}

// اجرای هر 4 تا 8 ثانیه
setInterval(() => {
    playRandomAnimation();
}, 5000 + Math.random() * 4000);

setTimeout(() => playRandomAnimation(), 1000);
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>