<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سیستم مدیریت فاکتورها</title>
    <style>
        *{margin:0; padding:0; box-sizing:border-box; font-family:Tahoma, Arial, sans-serif;}
        body{background:linear-gradient(135deg, #667eea, #764ba2); min-height:100vh; display:flex; align-items:center; justify-content:center;}
        .container{width:100%; max-width:1200px; padding:20px; text-align:center;}
        h1{color:white; font-size:48px; margin-bottom:20px; text-shadow:0 2px 10px rgba(0,0,0,0.2);}
        p{color:rgba(255,255,255,0.9); font-size:18px; margin-bottom:40px;}
        .buttons{display:flex; gap:20px; justify-content:center;}
        .btn{display:inline-block; padding:15px 40px; border-radius:50px; text-decoration:none; font-size:18px; font-weight:bold; transition:all 0.3s;}
        .btn-primary{background:white; color:#667eea;}
        .btn-primary:hover{transform:translateY(-3px); box-shadow:0 10px 30px rgba(0,0,0,0.2);}
        .btn-secondary{background:transparent; color:white; border:2px solid white;}
        .btn-secondary:hover{background:white; color:#667eea;}
        .features{display:grid; grid-template-columns:repeat(3,1fr); gap:30px; margin-top:80px;}
        .feature{background:rgba(255,255,255,0.1); padding:30px; border-radius:10px; color:white;}
        .feature h3{font-size:24px; margin-bottom:10px;}
    </style>
</head>
<body>
    <div class="container">
        <h1>سیستم مدیریت فاکتورها</h1>
        <p>مدیریت آسان فاکتورها، تایید چندمرحله‌ای و گزارش‌گیری پیشرفته</p>
        
        <div class="buttons">
            <a href="login.php" class="btn btn-primary">ورود</a>
            <a href="register.php" class="btn btn-secondary">ثبت نام</a>
        </div>

        <div class="features">
            <div class="feature">
                <h3>مدیریت فاکتورها</h3>
                <p>ایجاد، ویرایش و حذف فاکتورها به سادگی</p>
            </div>
            <div class="feature">
                <h3>تایید چندمرحله‌ای</h3>
                <p>سیستم تایید پیشرفته با قابلیت تعریف زنجیره تایید</p>
            </div>
            <div class="feature">
                <h3>گزارش‌گیری</h3>
                <p>گزارش‌های متنوع با قابلیت فیلتر و خروجی</p>
            </div>
        </div>
    </div>
</body>
</html>