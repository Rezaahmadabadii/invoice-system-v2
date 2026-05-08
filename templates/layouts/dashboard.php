<?php
require_once __DIR__ . '/../../app/Helpers/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ========== دریافت تعداد اسناد در انتظار اقدام برای کاربر جاری ==========
$user_id = $_SESSION['user_id'] ?? 0;
$user_department_id = $_SESSION['user_department_id'] ?? null;

$invoice_count = 0;
$waybill_count = 0;
$tax_count = 0;

if ($user_id > 0) {
    try {
        $host = 'localhost';
        $dbname = 'invoice_system';
        $username_db = 'root';
        $password_db = '';
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // شمارش فاکتورها
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM documents 
            WHERE type = 'invoice' 
            AND status IN ('pending', 'forwarded')
            AND (current_holder_user_id = ? OR current_holder_department_id = ?)
        ");
        $stmt->execute([$user_id, $user_department_id]);
        $invoice_count = (int)$stmt->fetchColumn();
        
        // شمارش بارنامه‌ها
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM documents 
            WHERE type = 'waybill' 
            AND status IN ('pending', 'forwarded')
            AND (current_holder_user_id = ? OR current_holder_department_id = ?)
        ");
        $stmt->execute([$user_id, $user_department_id]);
        $waybill_count = (int)$stmt->fetchColumn();
        
        // شمارش اسناد مالیاتی
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM documents 
            WHERE type = 'tax' 
            AND status IN ('pending', 'forwarded')
            AND (current_holder_user_id = ? OR current_holder_department_id = ?)
        ");
        $stmt->execute([$user_id, $user_department_id]);
        $tax_count = (int)$stmt->fetchColumn();
    } catch(PDOException $e) {
        $invoice_count = 0;
        $waybill_count = 0;
        $tax_count = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'پنل مدیریت هلدینگ'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Vazirmatn', 'Tahoma', system-ui, sans-serif;
        }
        
        body {
            background: linear-gradient(145deg, #f0f4f8 0%, #e2e8f0 100%);
            display: flex;
            min-height: 100vh;
        }
        
        /* ========== سایدبار ========== */
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(14px);
            border-left: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.03);
            min-height: 100vh;
            padding: 20px 16px;
            position: fixed;
            right: 0;
            top: 0;
            transition: all 0.3s ease;
            z-index: 100;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(145deg, #3b82f6, #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-icon i {
            font-size: 20px;
            color: white;
        }
        
        .logo-text {
            font-size: 16px;
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b, #3b82f6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .close-btn {
            display: none;
            background: rgba(0, 0, 0, 0.05);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #64748b;
            cursor: pointer;
        }
        
        .user-profile-top {
            background: linear-gradient(135deg, #e0f2fe, #dbeafe);
            border-radius: 20px;
            padding: 16px 14px;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(145deg, #3b82f6, #8b5cf6);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px auto;
            box-shadow: 0 6px 14px rgba(59, 130, 246, 0.25);
        }
        
        .user-avatar i {
            font-size: 28px;
            color: white;
        }
        
        .user-name {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .user-role {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 12px;
        }
        
        .logout-btn-top {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .logout-btn-top:hover {
            background: #ef4444;
            color: white;
        }
        
        .sidebar-nav {
            flex: 1;
        }
        
        .sidebar-nav ul {
            list-style: none;
        }
        
        .sidebar-nav li {
            margin-bottom: 4px;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #334155;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.2s ease;
            font-size: 13px;
            font-weight: 500;
            text-align: right;
        }
        
        .sidebar-nav a i {
            width: 22px;
            font-size: 16px;
            color: #94a3b8;
            transition: all 0.2s;
            text-align: center;
        }
        
        .sidebar-nav a span {
            flex: 1;
            text-align: right;
        }
        
        .sidebar-nav a:hover {
            background: rgba(59, 130, 246, 0.08);
            color: #3b82f6;
        }
        
        .sidebar-nav a:hover i {
            color: #3b82f6;
        }
        
        .sidebar-nav .active > a {
            background: linear-gradient(95deg, rgba(59, 130, 246, 0.12), rgba(139, 92, 246, 0.06));
            color: #3b82f6;
            border-right: 3px solid #3b82f6;
        }
        
        .sidebar-nav .active > a i {
            color: #3b82f6;
        }
        
        .dropdown-toggle {
            justify-content: space-between;
        }
        
        .dropdown-toggle .arrow {
            transition: transform 0.3s ease;
            font-size: 11px;
            opacity: 0.6;
            margin-right: auto;
        }
        
        .dropdown.open .dropdown-toggle .arrow {
            transform: rotate(-180deg);
        }
        
        .dropdown-menu {
            list-style: none;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            margin-right: 36px;
        }
        
        .dropdown.open .dropdown-menu {
            max-height: 350px;
        }
        
        .dropdown-menu a {
            padding: 8px 14px;
            font-size: 12px;
        }
        
        .badge {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            margin-right: auto;
        }
        
        .badge.orange {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }
        
        .sidebar-footer {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            text-align: center;
        }
        
        .footer-logo {
            margin-bottom: 8px;
        }
        
        .footer-logo img {
            max-width: 80px;
            height: auto;
            opacity: 0.6;
            transition: opacity 0.3s;
        }
        
        .footer-logo img:hover {
            opacity: 1;
        }
        
        .copyright {
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        
        .developer {
            font-size: 10px;
            color: #cbd5e1;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid rgba(0, 0, 0, 0.04);
        }
        
        .main-content {
            flex: 1;
            margin-right: 280px;
            padding: 24px 28px;
            position: relative;
            z-index: 1;
        }
        
        .top-header {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 28px;
        }
        
        .menu-toggle {
            display: none;
            background: rgba(0, 0, 0, 0.05);
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            font-size: 20px;
            color: #334155;
            cursor: pointer;
        }
        
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(2px);
            z-index: 99;
        }
        
        .overlay.active {
            display: block;
        }
        
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .close-btn {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .top-header {
                justify-content: space-between;
            }
            .main-content {
                margin-right: 0;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="logo-text">کیهان راه شرق</div>
            </div>
            <button class="close-btn" id="closeSidebar">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="user-profile-top">
            <div class="user-avatar">
                <i class="fas fa-user-circle"></i>
            </div>
            <div class="user-name"><?php echo $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'کاربر'; ?></div>
            <div class="user-role"><?php echo isset($_SESSION['user_roles']) ? implode('، ', $_SESSION['user_roles']) : 'کاربر'; ?></div>
            <a href="/invoice-system-v2/pages/logout.php" class="logout-btn-top">
                <i class="fas fa-sign-out-alt"></i> خروج
            </a>
        </div>

        <nav class="sidebar-nav">
            <ul>
                <li class="<?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>">
                    <a href="/invoice-system-v2/pages/dashboard.php">
                        <i class="fas fa-home"></i>
                        <span>داشبورد</span>
                    </a>
                </li>
                
                <!-- مدیریت سازمانی -->
                <li class="<?php echo (basename($_SERVER['PHP_SELF']) == 'manage.php') ? 'active' : ''; ?>">
                    <a href="/invoice-system-v2/pages/manage.php">
                        <i class="fas fa-building"></i>
                        <span>مدیریت سازمانی</span>
                    </a>
                </li>

                <!-- فاکتورها -->
                <li class="<?php echo (basename($_SERVER['PHP_SELF']) == 'inbox.php' || basename($_SERVER['PHP_SELF']) == 'invoice-create.php' || basename($_SERVER['PHP_SELF']) == 'invoice-edit.php' || basename($_SERVER['PHP_SELF']) == 'invoice-view.php') ? 'active' : ''; ?>">
                    <a href="/invoice-system-v2/pages/inbox.php">
                        <i class="fas fa-file-invoice"></i>
                        <span>فاکتورها</span>
                        <?php if ($invoice_count > 0): ?>
                            <span class="badge" id="invoiceBadge"><?php echo $invoice_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- بارنامه‌ها -->
                <li class="<?php echo (basename($_SERVER['PHP_SELF']) == 'waybills.php') ? 'active' : ''; ?>">
                    <a href="/invoice-system-v2/pages/waybills.php">
                        <i class="fas fa-truck"></i>
                        <span>بارنامه‌ها</span>
                        <?php if ($waybill_count > 0): ?>
                            <span class="badge" id="waybillBadge"><?php echo $waybill_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- سامانه مودیان -->
                <li class="<?php echo (basename($_SERVER['PHP_SELF']) == 'tax.php') ? 'active' : ''; ?>">
                    <a href="/invoice-system-v2/pages/tax.php">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>سامانه مودیان</span>
                        <?php if ($tax_count > 0): ?>
                            <span class="badge orange" id="taxBadge"><?php echo $tax_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- راهنما -->
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-question-circle"></i>
                        <span>راهنما</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="#" class="guide-link" data-guide="invoice"><i class="fas fa-file-invoice"></i> راهنمای فاکتورها</a></li>
                        <li><a href="#" class="guide-link" data-guide="waybill"><i class="fas fa-truck"></i> راهنمای بارنامه‌ها</a></li>
                        <li><a href="#" class="guide-link" data-guide="tax"><i class="fas fa-cloud-upload-alt"></i> راهنمای سامانه مودیان</a></li>
                        <li><a href="#" class="guide-link" data-guide="flow"><i class="fas fa-project-diagram"></i> روند تایید و ارجاع</a></li>
                    </ul>
                </li>
                
                <?php 
                $is_admin = false;
                if (isset($_SESSION['user_roles']) && is_array($_SESSION['user_roles'])) {
                    $is_admin = in_array('super_admin', $_SESSION['user_roles']) || in_array('admin', $_SESSION['user_roles']);
                }
                if ($is_admin): 
                ?>
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-cog"></i>
                        <span>مدیریت</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/invoice-system-v2/pages/roles.php"><i class="fas fa-user-tag"></i> نقش‌ها</a></li>
                        <li><a href="/invoice-system-v2/pages/users.php"><i class="fas fa-users-cog"></i> کاربران</a></li>
                        <li><a href="/invoice-system-v2/pages/logs.php"><i class="fas fa-history"></i> گزارش فعالیت‌ها</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="footer-logo">
                <img src="/invoice-system-v2/assets/images/logo.png" alt="لوگو" onerror="this.style.display='none'">
            </div>
            <div class="copyright">© ۱۴۰۴ - کلیه حقوق محفوظ است</div>
            <div class="developer">
                <i class="fas fa-code"></i> Rezaahmadabadi
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
        </header>

        <?php echo $content; ?>
    </main>

    <div class="overlay" id="overlay"></div>

    <div id="guideModal" class="guide-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 24px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; padding: 24px; box-shadow: 0 20px 35px rgba(0,0,0,0.2);">
            <h3 id="guideTitle" style="color: #1e293b; margin-bottom: 16px; font-size: 20px; border-bottom: 2px solid #3b82f6; display: inline-block; padding-bottom: 5px;">راهنما</h3>
            <div id="guideBody" style="color: #475569; line-height: 1.7;"></div>
            <button class="guide-close" onclick="closeGuideModal()" style="background: #3b82f6; color: white; border: none; padding: 10px 24px; border-radius: 30px; cursor: pointer; margin-top: 20px;">بستن</button>
        </div>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const closeSidebar = document.getElementById('closeSidebar');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.add('active');
                overlay.classList.add('active');
            });
        }
        
        if (closeSidebar) {
            closeSidebar.addEventListener('click', () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            });
        }
        
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                const parent = toggle.closest('.dropdown');
                if (parent) {
                    parent.classList.toggle('open');
                }
            });
        });
        
        const guideContent = {
            invoice: {
                title: '📄 راهنمای فاکتورها',
                body: '<h4>ایجاد فاکتور جدید</h4><ul><li><strong>شماره فاکتور:</strong> شماره دلخواه (با مخفف شرکت ترکیب می‌شود)</li><li><strong>شرکت و فروشنده:</strong> انتخاب از لیست‌های از پیش تعریف شده</li><li><strong>مبلغ و تخفیف:</strong> وارد کردن مبلغ پایه و تخفیف</li><li><strong>فایل ضمیمه:</strong> آپلود تصویر یا PDF فاکتور</li><li><strong>ارجاع:</strong> انتخاب بخش یا شخص مقصد</li></ul><h4>وضعیت‌های فاکتور</h4><ul><li><strong>پیش‌نویس:</strong> فاکتور ذخیره شده اما هنوز ارسال نشده</li><li><strong>در انتظار:</strong> فاکتور ارسال شده و در دست بررسی</li><li><strong>تایید شده:</strong> تایید نهایی</li><li><strong>رد شده:</strong> فاکتور رد شده</li></ul>'
            },
            waybill: {
                title: '🚛 راهنمای بارنامه‌ها',
                body: '<h4>ایجاد بارنامه جدید</h4><ul><li><strong>عنوان و شماره بارنامه</strong></li><li><strong>شرکت و فروشنده</strong></li><li><strong>فرستنده و گیرنده</strong></li><li><strong>مبدا و مقصد بارگیری</strong></li><li><strong>فایل ضمیمه (اختیاری)</strong></li></ul><h4>اطلاعات تکمیلی</h4><p>می‌توانید اطلاعات راننده، پلاک، تعداد، وزن، مسئول حمل و شرکت بیمه را ثبت کنید.</p>'
            },
            tax: {
                title: '☁️ راهنمای سامانه مودیان',
                body: '<h4>ایجاد سند مالیاتی</h4><ul><li><strong>شناسه مالیاتی:</strong> به صورت خودکار با فرمت Tax-XXXX-XXXXXX ذخیره می‌شود</li><li><strong>شناسه ملی فروشنده:</strong> الزامی است</li><li><strong>ارسال به مودیان:</strong> پس از ثبت نهایی، سند در صف ارسال قرار می‌گیرد</li></ul><h4>وضعیت ارسال</h4><ul><li><strong>در انتظار:</strong> سند ثبت شده اما ارسال نشده</li><li><strong>ارسال شده:</strong> سند با موفقیت ارسال شد</li><li><strong>خطا:</strong> خطا در ارسال</li></ul>'
            },
            flow: {
                title: '🔄 روند تایید و ارجاع',
                body: '<h4>مراحل گردش کاری اسناد</h4><ol><li><strong>ایجاد:</strong> سند توسط کاربر ایجاد می‌شود</li><li><strong>ارجاع:</strong> سند به بخش یا شخص خاص ارجاع می‌شود</li><li><strong>بررسی:</strong> فرد یا بخش مقصد سند را بررسی می‌کند</li><li><strong>تایید یا رد:</strong> شخص مجاز سند را تایید یا رد می‌کند</li></ol><h4>نمایش در دست</h4><p>در کارت هر سند، وضعیت <strong>"📍 در دست"</strong> نشان می‌دهد سند در اختیار چه شخص یا بخشی است.</p>'
            }
        };
        
        function openGuideModal(type) {
            const modal = document.getElementById('guideModal');
            const title = document.getElementById('guideTitle');
            const body = document.getElementById('guideBody');
            const content = guideContent[type];
            if (content) {
                title.innerHTML = content.title;
                body.innerHTML = content.body;
                modal.style.display = 'flex';
            }
        }
        
        function closeGuideModal() {
            document.getElementById('guideModal').style.display = 'none';
        }
        
        document.querySelectorAll('.guide-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const guideType = link.getAttribute('data-guide');
                openGuideModal(guideType);
            });
        });
        
        document.getElementById('guideModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('guideModal')) {
                closeGuideModal();
            }
        });
    </script>
</body>
</html>