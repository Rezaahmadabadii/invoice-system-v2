<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
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

// دریافت لیست شرکت‌ها برای فیلتر
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

// فیلترها
$filter_company = $_GET['company'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// ساخت کوئری پایه
$base_sql = "SELECT d.*, c.name as company_name, v.name as vendor_name 
             FROM documents d 
             LEFT JOIN companies c ON d.company_id = c.id 
             LEFT JOIN vendors v ON d.vendor_id = v.id 
             WHERE d.type = 'waybill'";
$params = [];

if ($filter_company) {
    $base_sql .= " AND d.company_id = ?";
    $params[] = $filter_company;
}
if ($filter_status) {
    $base_sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if ($search) {
    $base_sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR d.cargo_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// کوئری شمارش تعداد کل
$count_sql = str_replace("SELECT d.*, c.name as company_name, v.name as vendor_name", "SELECT COUNT(*) as total", $base_sql);
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_count = $stmt->fetchColumn();
$total_pages = $total_count > 0 ? ceil($total_count / $per_page) : 1;

// کوئری اصلی با LIMIT و OFFSET
$sql = $base_sql . " ORDER BY d.created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$waybills = $stmt->fetchAll();

// آمار کلی
$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill'");
$total_waybills = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill' AND status = 'completed'");
$completed_waybills = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE type = 'waybill' AND status = 'pending'");
$pending_waybills = $stmt->fetchColumn();

$page_title = 'مدیریت بارنامه‌ها';
ob_start();
?>

<style>
    /* استایل برای انیمیشن کامیون - حرکت طولانی به راست و محو تدریجی */
    .truck-animation-area {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        overflow: visible;
        min-height: 80px;
    }
    .truck-icon {
        font-size: 64px;
        display: inline-block;
        transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 0.8s ease-out;
        transform: scaleX(-1);
    }
    .truck-slide-right {
        transform: translateX(400px) scaleX(-1);
        opacity: 0;
    }
    .btn-create-wrapper {
        text-align: center;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">📦 مدیریت بارنامه‌ها</h1>
    <div class="btn-create-wrapper">
        <!-- آیکون کامیون بالای دکمه -->
        <div class="truck-animation-area">
            <div class="truck-icon" id="truckIcon">🚛</div>
        </div>
        <!-- دکمه ایجاد بارنامه جدید -->
        <button type="button" id="createWaybillBtn" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; margin-left: 10px; border: none; cursor: pointer; font-size: 14px;">
            <i class="fas fa-plus"></i> بارنامه جدید
        </button>
        <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<!-- کارت‌های آمار -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px;">
    <div style="background: linear-gradient(135deg, #3498db, #2980b9); padding: 20px; border-radius: 10px; color: white;">
        <div style="font-size: 14px;">کل بارنامه‌ها</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_waybills); ?></div>
    </div>
    <div style="background: linear-gradient(135deg, #2ecc71, #27ae60); padding: 20px; border-radius: 10px; color: white;">
        <div style="font-size: 14px;">تکمیل شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($completed_waybills); ?></div>
    </div>
    <div style="background: linear-gradient(135deg, #f39c12, #e67e22); padding: 20px; border-radius: 10px; color: white;">
        <div style="font-size: 14px;">در حال انجام</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($pending_waybills); ?></div>
    </div>
    <div style="background: linear-gradient(135deg, #e74c3c, #c0392b); padding: 20px; border-radius: 10px; color: white;">
        <div style="font-size: 14px;">لغو شده</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo number_format($total_waybills - $completed_waybills - $pending_waybills); ?></div>
    </div>
</div>

<!-- فرم فیلتر -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <h3 style="margin-bottom: 15px;">🔍 فیلترها</h3>
    <form method="GET" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; align-items: end;">
        <div>
            <label style="display: block; margin-bottom: 5px;">شرکت</label>
            <select name="company" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <option value="">همه شرکت‌ها</option>
                <?php foreach ($companies as $comp): ?>
                    <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($comp['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display: block; margin-bottom: 5px;">وضعیت</label>
            <select name="status" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <option value="">همه</option>
                <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>پیش‌نویس</option>
                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>در حال انجام</option>
                <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>تکمیل شده</option>
                <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>لغو شده</option>
            </select>
        </div>
        <div>
            <label style="display: block; margin-bottom: 5px;">جستجو</label>
            <input type="text" name="search" placeholder="شماره، عنوان..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
                <i class="fas fa-filter"></i> اعمال
            </button>
            <a href="waybills.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
                <i class="fas fa-times"></i> پاک کردن
            </a>
        </div>
    </form>
</div>

<!-- لیست بارنامه‌ها -->
<div style="background: white; border-radius: 10px; padding: 20px;">
    <?php if (empty($waybills)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-truck" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
            <p>هیچ بارنامه‌ای یافت نشد</p>
            <a href="waybill-create.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin-top: 10px;">
                <i class="fas fa-plus"></i> ایجاد بارنامه جدید
            </a>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="padding: 12px;">شماره</th>
                        <th style="padding: 12px;">عنوان</th>
                        <th style="padding: 12px;">شرکت</th>
                        <th style="padding: 12px;">فرستنده</th>
                        <th style="padding: 12px;">گیرنده</th>
                        <th style="padding: 12px;">مبدا</th>
                        <th style="padding: 12px;">مقصد</th>
                        <th style="padding: 12px;">تاریخ</th>
                        <th style="padding: 12px;">وضعیت</th>
                        <th style="padding: 12px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($waybills as $wb): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['document_number']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['title']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['company_name'] ?? '-'); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['sender_name'] ?? '-'); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['receiver_name'] ?? '-'); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['loading_origin'] ?? '-'); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($wb['discharge_destination'] ?? '-'); ?></td>
                        <td style="padding: 10px;"><?php echo jdate('Y/m/d', strtotime($wb['created_at'])); ?></td>
                        <td style="padding: 10px;">
                            <?php
                            $status_colors = [
                                'draft' => '#95a5a6',
                                'pending' => '#f39c12',
                                'completed' => '#2ecc71',
                                'cancelled' => '#e74c3c'
                            ];
                            $status_texts = [
                                'draft' => 'پیش‌نویس',
                                'pending' => 'در حال انجام',
                                'completed' => 'تکمیل شده',
                                'cancelled' => 'لغو شده'
                            ];
                            ?>
                            <span style="background: <?php echo $status_colors[$wb['status']] ?? '#95a5a6'; ?>; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px;">
                                <?php echo $status_texts[$wb['status']] ?? $wb['status']; ?>
                            </span>
                        </td>
                        <td style="padding: 10px;">
                            <a href="waybill-view.php?id=<?php echo $wb['id']; ?>" style="color: #3498db; text-decoration: none; margin-left: 10px;" title="مشاهده">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($wb['status'] == 'draft' && $wb['created_by'] == $_SESSION['user_id']): ?>
                                <a href="waybill-edit.php?id=<?php echo $wb['id']; ?>" style="color: #f39c12; text-decoration: none;" title="ویرایش">
                                    <i class="fas fa-edit"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- صفحه‌بندی -->
        <?php if ($total_pages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
            <a href="?page=1<?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;"><<</a>
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;"><</a>
            <?php endif; ?>
            <span style="padding: 8px 15px; background: #2c3e50; color: white; border-radius: 5px;"><?php echo $page; ?></span>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">></a>
            <?php endif; ?>
            <a href="?page=<?php echo $total_pages; ?><?php echo $filter_company ? "&company=$filter_company" : ''; ?><?php echo $filter_status ? "&status=$filter_status" : ''; ?><?php echo $search ? "&search=" . urlencode($search) : ''; ?>" style="background: #3498db; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none;">>></a>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    // تابع انیمیشن کامیون
    function animateTruckAndRedirect() {
        var truckIcon = document.getElementById('truckIcon');
        
        if (truckIcon) {
            if (truckIcon.classList.contains('truck-slide-right')) {
                return;
            }
            
            truckIcon.classList.add('truck-slide-right');
            
            setTimeout(function() {
                window.location.href = 'waybill-create.php';
            }, 800);
        } else {
            window.location.href = 'waybill-create.php';
        }
    }
    
    var createBtn = document.getElementById('createWaybillBtn');
    if (createBtn) {
        createBtn.addEventListener('click', function(e) {
            e.preventDefault();
            animateTruckAndRedirect();
        });
    }
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>