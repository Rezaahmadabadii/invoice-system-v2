<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'ورود به سیستم'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --main-bg-color: #9c27b0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            height: 100%;
            font-size: 65.2%;
            box-sizing: border-box;
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            direction: rtl;
        }

        body {
            height: 100%;
            background: linear-gradient(135deg, #1a2332 0%, #243447 50%, #2d3e50 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Vazirmatn', Tahoma, sans-serif;
        }

        .container {
            position: relative;
            width: 340px;
            height: 340px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .circle-container {
            position: absolute;
            width: 100%;
            height: 100%;
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .bar {
            position: absolute;
            width: 8px;
            height: 35px;
            background: #3d5269;
            border-radius: 4px;
            top: 0;
            left: 50%;
            transform-origin: center 170px;
            transition: background 0.3s ease;
        }

        .bar.active {
            background: linear-gradient(180deg, #00b4a0, #008c7a);
            box-shadow: 0 0 20px rgba(0, 180, 160, 0.7);
        }

        .login-box {
            position: relative;
            z-index: 10;
            background: rgba(26, 35, 50, 0.96);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            width: 450px;
            overflow: hidden;
        }

        .auth-panels {
            position: relative;
            width: 100%;
        }

        .auth-panels .panel {
            width: 100%;
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.5s ease;
        }

        .auth-panels .panel-login {
            position: relative;
        }

        .auth-panels .panel-signup {
            position: absolute;
            top: 0;
            right: 0;
            transform: translateX(100%);
            opacity: 0;
            pointer-events: none;
        }

        .auth-panels.show-signup .panel-login {
            position: absolute;
            transform: translateX(-100%);
            opacity: 0;
            pointer-events: none;
        }

        .auth-panels.show-signup .panel-signup {
            position: relative;
            transform: translateX(0);
            opacity: 1;
            pointer-events: auto;
        }

        h2 {
            text-align: center;
            color: #00b4a0;
            margin-bottom: 30px;
            font-size: 32px;
            font-weight: 600;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-wrap {
            position: relative;
        }

        .input-group input,
        .input-group select {
            width: 100%;
            padding: 12px 15px 12px 44px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 25px;
            color: #fff;
            font-size: 14px;
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            outline: none;
            transition: all 0.3s ease;
        }

        .input-group select option {
            background: #1a2332;
            color: #fff;
        }

        .input-group input:focus,
        .input-group select:focus {
            border-color: #00b4a0;
            box-shadow: 0 0 15px rgba(0, 180, 160, 0.35);
        }

        .input-group input::placeholder {
            color: rgba(255, 255, 255, 0.45);
        }

        .input-wrap .input-icon {
            position: absolute;
            right: auto;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
            font-size: 16px;
        }

        .forgot-password {
            text-align: left;
            margin-top: -15px;
            margin-bottom: 25px;
        }

        .forgot-password a {
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 13px;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #00b4a0;
        }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(90deg, #008c7a, #00b4a0);
            border: none;
            border-radius: 25px;
            color: #fff;
            font-size: 17px;
            font-weight: 600;
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 180, 160, 0.45);
        }

        .social-login {
            margin-top: 25px;
            text-align: center;
        }

        .social-login p {
            color: rgba(255, 255, 255, 0.55);
            font-size: 13px;
            margin-bottom: 15px;
        }

        .social-icons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .social-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 18px;
            color: #fff;
        }

        .social-icon.facebook { background: #3b5998; }
        .social-icon.twitter { background: #000; }
        .social-icon.google { background: #db4437; }

        .social-icon:hover {
            transform: translateY(-5px) scale(1.1);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
        }

        .signup-link {
            text-align: center;
            margin-top: 25px;
        }

        .signup-link a {
            color: #00b4a0;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .signup-link a:hover {
            color: #00d4b8;
            text-decoration: underline;
        }

        .toggle-password {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php echo $content; ?>
</body>
</html>