<?php
use App\Core\Session;

$title = 'داشبورد مدیریت فاکتورها';
ob_start();
?>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-file-invoice"></i>
            <span>سامانه فاکتور</span>
        </div>
        <button class="close-btn" id="closeSidebar">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li class="active">
                <a href="/dashboard">
                    <i class="fas fa-home"></i>
                    <span>داشبورد</span>
                </a>
            </li>
            
            <!-- منوی فاکتورها -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-file-invoice"></i>
                    <span>مدیریت فاکتورها</span>
                    <i class="fas fa-chevron-down arrow"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="/invoices"><i class="fas fa-list"></i> لیست فاکتورها</a></li>
                    <li><a href="/invoices/create"><i class="fas fa-plus-circle"></i> ایجاد فاکتور جدید</a></li>
                    <li><a href="/invoices/draft"><i class="fas fa-pen"></i> پیش‌نویس‌ها</a></li>
                    <li><a href="/invoices/archive"><i class="fas fa-archive"></i> آرشیو فاکتورها</a></li>
                </ul>
            </li>

            <!-- منوی فرآیند تأیید -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-check-double"></i>
                    <span>فرآیند تأیید</span>
                    <i class="fas fa-chevron-down arrow"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="/approvals/pending"><i class="fas fa-clock"></i> در انتظار تأیید <span class="badge">۳</span></a></li>
                    <li><a href="/approvals/history"><i class="fas fa-history"></i> تاریخچه تأییدها</a></li>
                    <li><a href="/approval-chains"><i class="fas fa-link"></i> زنجیره‌های تأیید</a></li>
                    <li><a href="/approval-steps"><i class="fas fa-steps"></i> مراحل تأیید</a></li>
                </ul>
            </li>

            <!-- منوی مشتریان -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-building"></i>
                    <span>مشتریان</span>
                    <i class="fas fa-chevron-down arrow"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="/customers"><i class="fas fa-list"></i> لیست مشتریان</a></li>
                    <li><a href="/customers/create"><i class="fas fa-user-plus"></i> افزودن مشتری جدید</a></li>
                    <li><a href="/customers/categories"><i class="fas fa-tags"></i> دسته‌بندی مشتریان</a></li>
                </ul>
            </li>

            <!-- منوی مالی -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-chart-line"></i>
                    <span>گزارش‌های مالی</span>
                    <i class="fas fa-chevron-down arrow"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="/reports/monthly"><i class="fas fa-calendar"></i> گزارش ماهانه</a></li>
                    <li><a href="/reports/customers"><i class="fas fa-users"></i> گزارش مشتریان</a></li>
                    <li><a href="/reports/tax"><i class="fas fa-percent"></i> گزارش مالیات</a></li>
                    <li><a href="/reports/payments"><i class="fas fa-credit-card"></i> گزارش پرداخت‌ها</a></li>
                </ul>
            </li>

            <!-- منوی تنظیمات -->
            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class="fas fa-cog"></i>
                    <span>تنظیمات</span>
                    <i class="fas fa-chevron-down arrow"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="/settings/company"><i class="fas fa-building"></i> اطلاعات شرکت</a></li>
                    <li><a href="/settings/tax"><i class="fas fa-percent"></i> تنظیمات مالیات</a></li>
                    <li><a href="/settings/approval"><i class="fas fa-check-circle"></i> تنظیمات تأیید</a></li>
                    <li><a href="/settings/users"><i class="fas fa-users-cog"></i> مدیریت کاربران</a></li>
                </ul>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile">
            <?php if (Session::get('user_avatar')): ?>
                <img src="/uploads/avatars/<?php echo Session::get('user_avatar'); ?>" alt="پروفایل">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode(Session::get('user_name') ?? Session::get('username')); ?>&background=007AFF&color=fff&size=100" alt="پروفایل">
            <?php endif; ?>
            <div class="user-info">
                <h4><?php echo Session::get('user_name') ?? Session::get('username'); ?></h4>
                <p><?php 
                    $roles = [
                        'admin' => 'مدیر سیستم',
                        'supervisor' => 'ناظر',
                        'finance' => 'مدیر مالی',
                        'user' => 'کاربر'
                    ];
                    echo $roles[Session::get('user_role')] ?? Session::get('user_role');
                ?></p>
            </div>
        </div>
        <a href="/logout" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</aside>

<!-- Main Content -->
<main class="main-content">
    <!-- Top Header -->
    <header class="top-header">
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="جستجوی فاکتور، مشتری یا شماره فاکتور...">
        </div>

        <div class="header-actions">
            <button class="header-btn">
                <i class="fas fa-bell"></i>
                <span class="notification-dot"></span>
            </button>
            <button class="header-btn">
                <i class="fas fa-envelope"></i>
            </button>
            <a href="/profile" class="header-btn">
                <i class="fas fa-user-circle"></i>
            </a>
        </div>
    </header>

    <!-- Dashboard Content -->
    <div class="dashboard-content">
        <div class="page-title">
            <h1>داشبورد مدیریت فاکتورها</h1>
            <p><?php echo jdate('l d F Y'); ?> - آخرین به‌روزرسانی: <?php echo jdate('H:i'); ?></p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-info">
                    <h3>۲۴۸</h3>
                    <p>کل فاکتورها</p>
                    <span class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> ۱۲+ این ماه
                    </span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3>۱۸۶</h3>
                    <p>تأیید شده</p>
                    <span class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> ۷۵٪
                    </span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3>۳۲</h3>
                    <p>در انتظار تأیید</p>
                    <span class="stat-change negative">
                        <i class="fas fa-arrow-down"></i> ۳ نیاز به بررسی
                    </span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info">
                    <h3>۳,۸۴۰,۰۰۰,۰۰۰</h3>
                    <p>مبلغ کل (تومان)</p>
                    <span class="stat-change positive">
                        <i class="fas fa-arrow-up"></i> ۱۸٪ رشد
                    </span>
                </div>
            </div>
        </div>

        <!-- Charts and Tables Section -->
        <div class="content-grid">
            <!-- Recent Invoices -->
            <div class="glass-card">
                <div class="card-header">
                    <h2>آخرین فاکتورها</h2>
                    <a href="/invoices" class="btn-secondary">مشاهده همه</a>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>شماره فاکتور</th>
                                <th>مشتری</th>
                                <th>تاریخ</th>
                                <th>مبلغ (تومان)</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr onclick="window.location='/invoices/2401'">
                                <td>#F-۱۴۰۴-۰۰۱</td>
                                <td>شرکت ساختمانی آوا</td>
                                <td><?php echo jdate('Y/m/d', strtotime('-5 days')); ?></td>
                                <td>۱۲۵,۰۰۰,۰۰۰</td>
                                <td><span class="status success">تأیید شده</span></td>
                            </tr>
                            <tr onclick="window.location='/invoices/2402'">
                                <td>#F-۱۴۰۴-۰۰۲</td>
                                <td>پیمانکاری راه‌سازان</td>
                                <td><?php echo jdate('Y/m/d', strtotime('-4 days')); ?></td>
                                <td>۸۵,۰۰۰,۰۰۰</td>
                                <td><span class="status pending">در انتظار</span></td>
                            </tr>
                            <tr onclick="window.location='/invoices/2403'">
                                <td>#F-۱۴۰۴-۰۰۳</td>
                                <td>تجهیزات صنعتی پارس</td>
                                <td><?php echo jdate('Y/m/d', strtotime('-3 days')); ?></td>
                                <td>۲۳۰,۰۰۰,۰۰۰</td>
                                <td><span class="status pending">در انتظار</span></td>
                            </tr>
                            <tr onclick="window.location='/invoices/2404'">
                                <td>#F-۱۴۰۴-۰۰۴</td>
                                <td>خدمات فنی مهر</td>
                                <td><?php echo jdate('Y/m/d', strtotime('-2 days')); ?></td>
                                <td>۴۲,۵۰۰,۰۰۰</td>
                                <td><span class="status success">تأیید شده</span></td>
                            </tr>
                            <tr onclick="window.location='/invoices/2405'">
                                <td>#F-۱۴۰۴-۰۰۵</td>
                                <td>عمران و توسعه</td>
                                <td><?php echo jdate('Y/m/d', strtotime('-1 day')); ?></td>
                                <td>۳۱۵,۰۰۰,۰۰۰</td>
                                <td><span class="status cancelled">رد شده</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pending Approvals -->
            <div class="glass-card">
                <div class="card-header">
                    <h2>در انتظار تأیید شما</h2>
                    <a href="/approvals/pending" class="btn-secondary">مشاهده همه</a>
                </div>
                <div class="pending-list">
                    <div class="pending-item">
                        <div class="pending-icon orange">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="pending-info">
                            <h4>فاکتور #F-۱۴۰۴-۰۰۲</h4>
                            <p>پیمانکاری راه‌سازان • ۸۵,۰۰۰,۰۰۰ تومان</p>
                            <small>ارسال شده در <?php echo jdate('Y/m/d', strtotime('-4 days')); ?></small>
                        </div>
                        <div class="pending-actions">
                            <button class="btn-icon success" onclick="approveInvoice(2402)"><i class="fas fa-check"></i></button>
                            <button class="btn-icon danger" onclick="rejectInvoice(2402)"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="pending-item">
                        <div class="pending-icon orange">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="pending-info">
                            <h4>فاکتور #F-۱۴۰۴-۰۰۳</h4>
                            <p>تجهیزات صنعتی پارس • ۲۳۰,۰۰۰,۰۰۰ تومان</p>
                            <small>ارسال شده در <?php echo jdate('Y/m/d', strtotime('-3 days')); ?></small>
                        </div>
                        <div class="pending-actions">
                            <button class="btn-icon success" onclick="approveInvoice(2403)"><i class="fas fa-check"></i></button>
                            <button class="btn-icon danger" onclick="rejectInvoice(2403)"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="pending-item">
                        <div class="pending-icon orange">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="pending-info">
                            <h4>فاکتور #F-۱۴۰۴-۰۰۷</h4>
                            <p>ساختمانی نگین • ۱۵۶,۰۰۰,۰۰۰ تومان</p>
                            <small>ارسال شده در <?php echo jdate('Y/m/d', strtotime('-2 days')); ?></small>
                        </div>
                        <div class="pending-actions">
                            <button class="btn-icon success" onclick="approveInvoice(2407)"><i class="fas fa-check"></i></button>
                            <button class="btn-icon danger" onclick="rejectInvoice(2407)"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="glass-card">
                <div class="card-header">
                    <h2>عملیات سریع</h2>
                </div>
                <div class="quick-actions">
                    <a href="/invoices/create" class="action-btn">
                        <i class="fas fa-plus-circle"></i>
                        <span>ایجاد فاکتور جدید</span>
                    </a>
                    <a href="/invoices/draft" class="action-btn">
                        <i class="fas fa-pen"></i>
                        <span>ادامه پیش‌نویس</span>
                    </a>
                    <a href="/approvals/pending" class="action-btn">
                        <i class="fas fa-check-double"></i>
                        <span>تأیید فاکتورها</span>
                    </a>
                    <a href="/reports/monthly" class="action-btn">
                        <i class="fas fa-chart-line"></i>
                        <span>گزارش ماهانه</span>
                    </a>
                </div>
            </div>

            <!-- Monthly Stats -->
            <div class="glass-card">
                <div class="card-header">
                    <h2>آمار فاکتورهای ماه جاری</h2>
                    <select class="select-dropdown">
                        <option><?php echo jdate('F Y'); ?></option>
                        <option><?php echo jdate('F Y', strtotime('-1 month')); ?></option>
                        <option><?php echo jdate('F Y', strtotime('-2 months')); ?></option>
                    </select>
                </div>
                <div class="stats-chart">
                    <div class="chart-bars">
                        <div class="chart-bar-item">
                            <div class="chart-bar" style="height: 45%"></div>
                            <span>هفته ۱</span>
                        </div>
                        <div class="chart-bar-item">
                            <div class="chart-bar" style="height: 65%"></div>
                            <span>هفته ۲</span>
                        </div>
                        <div class="chart-bar-item">
                            <div class="chart-bar" style="height: 80%"></div>
                            <span>هفته ۳</span>
                        </div>
                        <div class="chart-bar-item">
                            <div class="chart-bar" style="height: 55%"></div>
                            <span>هفته ۴</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="glass-card">
                <div class="card-header">
                    <h2>مشتریان برتر</h2>
                    <a href="/customers" class="btn-secondary">مشاهده همه</a>
                </div>
                <div class="customers-list">
                    <div class="customer-item">
                        <img src="https://ui-avatars.com/api/?name=آوا&background=007AFF&color=fff&size=50" alt="مشتری">
                        <div class="customer-info">
                            <h4>شرکت ساختمانی آوا</h4>
                            <p>۱۸ فاکتور • ۷۵۰,۰۰۰,۰۰۰ تومان</p>
                        </div>
                        <div class="customer-badge">
                            <i class="fas fa-crown"></i>
                            <span>VIP</span>
                        </div>
                    </div>
                    <div class="customer-item">
                        <img src="https://ui-avatars.com/api/?name=راه‌سازان&background=34C759&color=fff&size=50" alt="مشتری">
                        <div class="customer-info">
                            <h4>پیمانکاری راه‌سازان</h4>
                            <p>۱۲ فاکتور • ۴۲۰,۰۰۰,۰۰۰ تومان</p>
                        </div>
                        <div class="customer-badge">
                            <i class="fas fa-star"></i>
                            <span>VIP</span>
                        </div>
                    </div>
                    <div class="customer-item">
                        <img src="https://ui-avatars.com/api/?name=پارس&background=FF9500&color=fff&size=50" alt="مشتری">
                        <div class="customer-info">
                            <h4>تجهیزات صنعتی پارس</h4>
                            <p>۹ فاکتور • ۳۸۵,۰۰۰,۰۰۰ تومان</p>
                        </div>
                        <div class="customer-badge">
                            <i class="fas fa-star"></i>
                            <span>VIP</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="glass-card">
                <div class="card-header">
                    <h2>فعالیت‌های اخیر</h2>
                    <button class="btn-secondary">مشاهده همه</button>
                </div>
                <div class="activities-list">
                    <div class="activity-item">
                        <div class="activity-icon blue">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="activity-info">
                            <h4>فاکتور جدید ایجاد شد</h4>
                            <p>شماره F-۱۴۰۴-۰۰۸ توسط علی رضایی</p>
                            <small><?php echo jdate('H:i - Y/m/d', strtotime('-5 minutes')); ?></small>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon green">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="activity-info">
                            <h4>فاکتور تأیید شد</h4>
                            <p>شماره F-۱۴۰۴-۰۰۱ توسط ناظر تأیید شد</p>
                            <small><?php echo jdate('H:i - Y/m/d', strtotime('-15 minutes')); ?></small>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon orange">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="activity-info">
                            <h4>فاکتور ارسال شد</h4>
                            <p>شماره F-۱۴۰۴-۰۰۲ به مرحله بعد ارسال شد</p>
                            <small><?php echo jdate('H:i - Y/m/d', strtotime('-45 minutes')); ?></small>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon purple">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="activity-info">
                            <h4>مشتری جدید اضافه شد</h4>
                            <p>شرکت ساختمانی آوا به مشتریان اضافه شد</p>
                            <small><?php echo jdate('H:i - Y/m/d', strtotime('-2 hours')); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Overlay for mobile -->
<div class="overlay" id="overlay"></div>

<script src="/js/main.js"></script>
<script>
function approveInvoice(id) {
    if (confirm('آیا از تأیید این فاکتور اطمینان دارید؟')) {
        window.location.href = '/approvals/approve/' + id;
    }
}

function rejectInvoice(id) {
    if (confirm('آیا از رد این فاکتور اطمینان دارید؟')) {
        window.location.href = '/approvals/reject/' + id;
    }
}
</script>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>