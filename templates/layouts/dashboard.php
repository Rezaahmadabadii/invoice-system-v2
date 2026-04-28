<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'پنل مدیریت هلدینگ'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0; padding:0; box-sizing:border-box; font-family:Tahoma, Arial, sans-serif;}
        body{background:#f5f7fa; display:flex;}
        
        /* سایدبار */
        .sidebar{
            width:280px; background:linear-gradient(135deg, #2c3e50, #3498db); color:white; 
            min-height:100vh; padding:20px; position:fixed; right:0; top:0;
            box-shadow:-5px 0 20px rgba(0,0,0,0.1);
        }
        
        .sidebar-header{
            display:flex; justify-content:space-between; align-items:center; 
            padding-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.2);
        }
        
        .logo{display:flex; align-items:center; gap:10px; font-size:20px; font-weight:bold;}
        .close-btn{display:none; background:none; border:none; color:white; font-size:20px; cursor:pointer;}
        
        .sidebar-nav ul{list-style:none; margin-top:20px;}
        .sidebar-nav li{margin-bottom:5px;}
        .sidebar-nav a{
            display:flex; align-items:center; gap:12px; padding:12px 15px; 
            color:white; text-decoration:none; border-radius:8px; transition:all 0.3s;
        }
        .sidebar-nav a:hover, .sidebar-nav .active>a{background:rgba(255,255,255,0.2);}
        
        .dropdown-toggle .arrow{margin-right:auto; transition:transform 0.3s;}
        .dropdown.open .dropdown-toggle .arrow{transform:rotate(-180deg);}
        .dropdown-menu{
            list-style:none; max-height:0; overflow:hidden; transition:max-height 0.3s;
            margin-right:20px;
        }
        .dropdown.open .dropdown-menu{max-height:300px;}
        .dropdown-menu a{padding:10px 15px 10px 30px; font-size:13px;}
        
        .badge{
            background:#e74c3c; color:white; padding:2px 6px; border-radius:10px; 
            font-size:11px; margin-right:auto;
        }
        
        .sidebar-footer{
            display:flex; align-items:center; gap:10px; margin-top:20px; 
            padding-top:20px; border-top:1px solid rgba(255,255,255,0.2);
        }
        
        .user-profile{display:flex; align-items:center; gap:10px; flex:1;}
        .user-profile img{width:40px; height:40px; border-radius:50%;}
        .user-info h4{font-size:14px; margin-bottom:3px;}
        .user-info p{font-size:12px; opacity:0.8;}
        
        .logout-btn{
            background:rgba(255,255,255,0.2); border:none; color:white; 
            width:36px; height:36px; border-radius:50%; cursor:pointer;
        }
        
        /* محتوای اصلی */
        .main-content{flex:1; margin-right:280px; padding:20px;}
        
        /* هدر */
        .top-header{
            background:white; padding:15px 25px; border-radius:12px; 
            display:flex; align-items:center; gap:20px; margin-bottom:25px;
            box-shadow:0 2px 10px rgba(0,0,0,0.05);
        }
        
        .menu-toggle{display:none; background:none; border:none; font-size:20px; cursor:pointer;}
        
        .search-box{
            flex:1; display:flex; align-items:center; background:#f5f7fa;
            padding:8px 15px; border-radius:30px; max-width:400px;
        }
        .search-box i{color:#999; margin-left:10px;}
        .search-box input{flex:1; border:none; background:none; outline:none;}
        
        .header-actions{display:flex; gap:15px;}
        .header-btn{
            background:none; border:none; font-size:18px; color:#666; 
            position:relative; width:36px; height:36px; border-radius:50%; cursor:pointer;
        }
        .header-btn:hover{background:#f5f7fa;}
        .notification-dot{
            position:absolute; top:5px; left:10px; width:8px; height:8px;
            background:#e74c3c; border-radius:50%;
        }
        
        /* عنوان صفحه */
        .page-title{margin-bottom:25px;}
        .page-title h1{font-size:28px; color:#2c3e50; margin-bottom:5px;}
        .page-title p{color:#7f8c8d;}
        
        /* کارت‌های آمار */
        .stats-grid{
            display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:30px;
        }
        
        .stat-card{
            background:white; border-radius:12px; padding:20px; display:flex;
            align-items:center; gap:15px; box-shadow:0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-icon{
            width:60px; height:60px; border-radius:12px; display:flex;
            align-items:center; justify-content:center; font-size:24px; color:white;
        }
        .stat-icon.blue{background:linear-gradient(135deg,#3498db,#2980b9);}
        .stat-icon.green{background:linear-gradient(135deg,#2ecc71,#27ae60);}
        .stat-icon.orange{background:linear-gradient(135deg,#f39c12,#e67e22);}
        .stat-icon.purple{background:linear-gradient(135deg,#9b59b6,#8e44ad);}
        
        .stat-info h3{font-size:14px; color:#7f8c8d; margin-bottom:5px;}
        .stat-info p{font-size:24px; font-weight:bold; color:#2c3e50; margin-bottom:5px;}
        
        .stat-change{font-size:12px; display:flex; align-items:center; gap:3px;}
        .stat-change.positive{color:#2ecc71;}
        .stat-change.negative{color:#e74c3c;}
        
        /* گرید محتوا */
        .content-grid{
            display:grid; grid-template-columns:repeat(auto-fit,minmax(400px,1fr)); gap:20px;
        }
        
        /* کارت‌های شیشه‌ای */
        .glass-card{
            background:white; border-radius:12px; padding:20px; 
            box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:20px;
        }
        
        .card-header{
            display:flex; justify-content:space-between; align-items:center; 
            margin-bottom:20px; padding-bottom:10px; border-bottom:1px solid #ecf0f1;
        }
        
        .card-header h2{font-size:16px; color:#2c3e50; display:flex; align-items:center; gap:8px;}
        .btn-secondary{
            background:#ecf0f1; border:none; padding:6px 12px; border-radius:6px;
            color:#2c3e50; font-size:12px; cursor:pointer;
        }
        
        /* جدول */
        .table-container{overflow-x:auto;}
        .data-table{width:100%; border-collapse:collapse;}
        .data-table th{
            text-align:right; padding:12px; background:#f8f9fa; 
            color:#2c3e50; font-size:12px; font-weight:600;
        }
        .data-table td{padding:12px; border-bottom:1px solid #ecf0f1; font-size:13px;}
        
        .status{
            display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;
        }
        .status.success{background:#d4edda; color:#155724;}
        .status.pending{background:#fff3cd; color:#856404;}
        .status.cancelled{background:#f8d7da; color:#721c24;}
        
        /* محصولات */
        .products-list{display:flex; flex-direction:column; gap:10px;}
        .product-item{
            display:flex; align-items:center; gap:10px; padding:10px;
            background:#f8f9fa; border-radius:8px;
        }
        .product-item img{width:50px; height:50px; border-radius:8px;}
        .product-info{flex:1;}
        .product-info h4{font-size:14px; margin-bottom:3px;}
        .product-info p{font-size:12px; color:#7f8c8d;}
        
        /* عملیات سریع */
        .quick-actions{display:grid; grid-template-columns:repeat(2,1fr); gap:10px;}
        .action-btn{
            display:flex; flex-direction:column; align-items:center; gap:5px;
            padding:15px; background:#f8f9fa; border:none; border-radius:8px;
            cursor:pointer; transition:all 0.3s;
        }
        .action-btn:hover{background:#3498db; color:white;}
        .action-btn i{font-size:20px;}
        
        /* فعالیت‌ها */
        .activities-list{display:flex; flex-direction:column; gap:10px;}
        .activity-item{display:flex; align-items:center; gap:10px; padding:10px; background:#f8f9fa; border-radius:8px;}
        .activity-icon{
            width:36px; height:36px; border-radius:8px; display:flex;
            align-items:center; justify-content:center; color:white; font-size:14px;
        }
        .activity-icon.blue{background:#3498db;}
        .activity-icon.green{background:#2ecc71;}
        .activity-icon.orange{background:#f39c12;}
        .activity-icon.purple{background:#9b59b6;}
        
        .activity-info h4{font-size:13px; margin-bottom:3px;}
        .activity-info p{font-size:12px; color:#7f8c8d;}
        
        /* چارت */
        .stats-chart{padding:20px 0;}
        .chart-bars{display:flex; align-items:flex-end; gap:15px; height:150px;}
        .chart-bar-item{flex:1; display:flex; flex-direction:column; align-items:center; gap:5px;}
        .chart-bar{width:100%; background:linear-gradient(to top,#3498db,#2980b9); border-radius:5px 5px 0 0;}
        
        /* مشتریان */
        .customers-list{display:flex; flex-direction:column; gap:10px;}
        .customer-item{display:flex; align-items:center; gap:10px; padding:10px; background:#f8f9fa; border-radius:8px;}
        .customer-item img{width:40px; height:40px; border-radius:50%;}
        .customer-info{flex:1;}
        .customer-info h4{font-size:13px; margin-bottom:3px;}
        .customer-info p{font-size:11px; color:#7f8c8d;}
        .customer-badge{
            padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600;
        }
        .customer-badge i{color:#f1c40f;}
        
        /* اعلان‌ها */
        .notification-count{background:#e74c3c; color:white; padding:2px 8px; border-radius:10px; font-size:12px;}
        .notifications-list{display:flex; flex-direction:column; gap:10px;}
        .notification-item{
            display:flex; align-items:flex-start; gap:10px; padding:10px;
            background:#f8f9fa; border-radius:8px; opacity:0.8;
        }
        .notification-item.unread{opacity:1; background:#e8f4fd;}
        .notification-icon{
            width:32px; height:32px; border-radius:8px; display:flex;
            align-items:center; justify-content:center; color:white; font-size:14px;
        }
        .notification-info{flex:1;}
        .notification-info h4{font-size:12px; margin-bottom:3px;}
        .notification-info p{font-size:11px; color:#7f8c8d; margin-bottom:3px;}
        .notification-time{font-size:10px; color:#95a5a6;}
        
        /* خلاصه درآمد */
        .revenue-summary{display:flex; flex-direction:column; gap:10px;}
        .revenue-item{padding:10px; background:#f8f9fa; border-radius:8px;}
        .revenue-label{font-size:12px; color:#7f8c8d; margin-bottom:3px;}
        .revenue-value{font-size:16px; font-weight:bold; color:#2c3e50;}
        .revenue-change{font-size:11px; margin-top:3px;}
        .revenue-change.positive{color:#2ecc71;}
        
        /* اورلی */
        .overlay{display:none;}
        
        @media(max-width:1200px){
            .stats-grid{grid-template-columns:repeat(2,1fr);}
        }
        
        @media(max-width:992px){
            .sidebar{transform:translateX(100%);}
            .sidebar.active{transform:translateX(0);}
            .close-btn{display:block;}
            .menu-toggle{display:block;}
            .main-content{margin-right:0;}
        }
        
        @media(max-width:768px){
            .stats-grid{grid-template-columns:1fr;}
            .content-grid{grid-template-columns:1fr;}
            .search-box{display:none;}
        }
    </style>
</head>
<body>
    <!-- سایدبار -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-building"></i>
                <span>کیهان راه شرق</span>
            </div>
            <button class="close-btn" id="closeSidebar">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <nav class="sidebar-nav">
            <ul>
                <li class="active">
                    <a href="/invoice-system-v2/pages/dashboard.php">
                        <i class="fas fa-home"></i>
                        <span>داشبورد</span>
                    </a>
                </li>
                
                <!-- شرکت‌ها -->
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-building"></i>
                        <span>شرکت‌ها</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/invoice-system-v2/pages/manage.php?tab=companies"><i class="fas fa-list"></i> لیست شرکت‌ها</a></li>
                        <li><a href="/invoice-system-v2/pages/manage.php?tab=companies"><i class="fas fa-plus"></i> شرکت جدید</a></li>
                    </ul>
                </li>

                <!-- فروشندگان -->
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-users"></i>
                        <span>فروشندگان</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/invoice-system-v2/pages/manage.php?tab=vendors"><i class="fas fa-list"></i> لیست فروشندگان</a></li>
                        <li><a href="/invoice-system-v2/pages/manage.php?tab=vendors"><i class="fas fa-user-plus"></i> فروشنده جدید</a></li>
                    </ul>
                </li>

                <!-- کارگاه‌ها -->
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-hard-hat"></i>
                        <span>کارگاه‌ها</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/invoice-system-v2/pages/manage.php?tab=workshops"><i class="fas fa-list"></i> لیست کارگاه‌ها</a></li>
                        <li><a href="/invoice-system-v2/pages/manage.php?tab=workshops"><i class="fas fa-plus"></i> کارگاه جدید</a></li>
                    </ul>
                </li>

                <!-- فاکتورها -->
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-file-invoice"></i>
                        <span>فاکتورها</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/invoice-system-v2/pages/inbox.php"><i class="fas fa-list"></i> همه فاکتورها</a></li>
                        <li><a href="/invoice-system-v2/pages/invoice-create.php"><i class="fas fa-plus"></i> فاکتور جدید</a></li>
                        <li><a href="/invoice-system-v2/pages/pending-invoices.php"><i class="fas fa-clock"></i> در انتظار تایید</a></li>
                        <li><a href="/invoice-system-v2/pages/approved-invoices.php"><i class="fas fa-check"></i> تایید شده</a></li>
                    </ul>
                </li>

                <!-- بارنامه‌ها -->
                <li>
                    <a href="/invoice-system-v2/pages/waybills.php">
                        <i class="fas fa-truck"></i>
                        <span>بارنامه‌ها</span>
                        <span class="badge">5</span>
                    </a>
                </li>

                <!-- سامانه مودیان -->
                <li>
                    <a href="/invoice-system-v2/pages/tax.php">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>سامانه مودیان</span>
                        <span class="badge" style="background:#f39c12;">12</span>
                    </a>
                </li>

                <!-- گزارش‌ها -->
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle">
                        <i class="fas fa-chart-line"></i>
                        <span>گزارش‌ها</span>
                        <i class="fas fa-chevron-down arrow"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/invoice-system-v2/pages/reports.php"><i class="fas fa-chart-pie"></i> گزارش مالی</a></li>
                        <li><a href="/invoice-system-v2/pages/inbox.php"><i class="fas fa-file-invoice"></i> گزارش فاکتورها</a></li>
                        <li><a href="/invoice-system-v2/pages/tax-reports.php"><i class="fas fa-percent"></i> گزارش مالیات</a></li>
                    </ul>
                </li>

                <!-- مدیریت (چک مستقیم از session) -->
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
            <div class="user-profile">
                <img src="https://i.pravatar.cc/150?img=33" alt="پروفایل">
                <div class="user-info">
                    <h4><?php echo $_SESSION['username'] ?? 'کاربر'; ?></h4>
                    <p><?php echo isset($_SESSION['user_roles']) ? implode('، ', $_SESSION['user_roles']) : 'کاربر'; ?></p>
                </div>
            </div>
            <a href="/invoice-system-v2/pages/logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </aside>

    <!-- محتوای اصلی -->
    <main class="main-content">
        <!-- هدر بالا -->
        <header class="top-header">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="جستجوی فاکتور، پیمانکار، شرکت...">
            </div>

            <div class="header-actions">
                <button class="header-btn">
                    <i class="fas fa-bell"></i>
                    <span class="notification-dot"></span>
                </button>
                <button class="header-btn">
                    <i class="fas fa-envelope"></i>
                </button>
                <button class="header-btn">
                    <i class="fas fa-user-circle"></i>
                </button>
            </div>
        </header>

        <?php echo $content; ?>
    </main>

    <!-- اورلی برای موبایل -->
    <div class="overlay" id="overlay"></div>

    <script>
        // سایدبار
        document.getElementById('menuToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('active');
            document.getElementById('overlay').classList.add('active');
        });

        document.getElementById('closeSidebar')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('active');
            document.getElementById('overlay').classList.remove('active');
        });

        document.getElementById('overlay')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.remove('active');
            document.getElementById('overlay').classList.remove('active');
        });

        // دراپ‌داون
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                const parent = toggle.closest('.dropdown');
                parent.classList.toggle('open');
            });
        });
    </script>
</body>
</html>