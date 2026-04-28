<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'ورود و ثبت‌نام'; ?></title>
    
    <!-- استفاده از CDN Font Awesome به جای فایل محلی -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/invoice-system-v2/assets/css/vazirmatn.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Vazirmatn', Tahoma, 'Segoe UI', sans-serif;
            direction: rtl;
            background: linear-gradient(135deg, #1a2332 0%, #243447 50%, #2d3e50 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: hidden;
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
            width: 420px;
            overflow: hidden;
        }

        .auth-panels {
            position: relative;
            width: 100%;
            transition: height 0.4s ease;
        }

        .auth-panels .panel {
            width: 100%;
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.5s ease;
            padding: 0;
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
            top: 0;
            right: 0;
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

        .error-msg {
            display: none;
            font-size: 12px;
            color: #ff6b6b;
            margin-top: 6px;
            margin-right: 4px;
        }

        .input-group input.invalid {
            border-color: #ff6b6b;
            box-shadow: 0 0 10px rgba(255, 107, 107, 0.3);
        }

        .input-group {
            margin-bottom: 25px;
        }

        .input-wrap {
            position: relative;
        }

        .input-group input {
            width: 100%;
            padding: 12px 15px 12px 44px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 25px;
            color: #fff;
            font-size: 15px;
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            outline: none;
            transition: all 0.3s ease;
        }

        .input-group input:focus {
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
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            width: 20px;
            height: 20px;
        }

        .input-icon i, .input-icon svg {
            color: inherit;
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
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 180, 160, 0.45);
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .login-btn:active::before {
            width: 300px;
            height: 300px;
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

        small {
            font-size: 11px;
            color: rgba(255,255,255,0.4);
            display: block;
            margin-top: 5px;
        }
		
		/* استایل مخصوص select option */
        .input-group select option {
            background-color: #1a2332 !important;
            color: #fff !important;
            padding: 10px;
        } 

        .input-group select option:hover {
            background-color: #00b4a0 !important;
            color: #fff !important;
        }
    </style>
</head>
<body>
    <?php echo $content; ?>

    <script>
        (function () {
            const authPanels = document.getElementById('authPanels');
            const goSignup = document.getElementById('goSignup');
            const goLogin = document.getElementById('goLogin');

            if (goSignup) {
                goSignup.addEventListener('click', function (e) {
                    e.preventDefault();
                    authPanels.classList.add('show-signup');
                    window.location.hash = 'signup';
                });
            }
            if (goLogin) {
                goLogin.addEventListener('click', function (e) {
                    e.preventDefault();
                    authPanels.classList.remove('show-signup');
                    window.location.hash = '';
                });
            }
            if (window.location.hash === '#signup') {
                authPanels.classList.add('show-signup');
            }

            document.querySelectorAll('.toggle-password').forEach(function (el) {
                el.addEventListener('click', function () {
                    const id = this.getAttribute('data-target');
                    const input = document.getElementById(id);
                    if (!input) return;
                    const type = input.getAttribute('type');
                    input.setAttribute('type', type === 'password' ? 'text' : 'password');
                    
                    const icon = this.querySelector('i');
                    if (icon) {
                        if (type === 'password') {
                            icon.classList.remove('fa-lock');
                            icon.classList.add('fa-lock-open');
                        } else {
                            icon.classList.remove('fa-lock-open');
                            icon.classList.add('fa-lock');
                        }
                    }
                });
            });

            var messages = {
                required: 'این فیلد الزامی است.',
                email: 'ایمیل معتبر وارد کنید.',
                minLength: 'حداقل ۶ کاراکتر وارد کنید.',
                passwordMatch: 'رمز عبور و تکرار آن یکسان نیستند.',
                nameMin: 'نام باید حداقل ۲ کاراکتر باشد.'
            };

            function showError(id, msg) {
                var el = document.getElementById(id);
                if (el) { 
                    el.textContent = msg; 
                    el.style.display = msg ? 'block' : 'none'; 
                }
            }
            
            function setInvalid(input, invalid) {
                if (input) {
                    input.classList.toggle('invalid', !!invalid);
                }
            }

            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', function (e) {
                    var email = document.getElementById('loginEmail');
                    var password = document.getElementById('loginPassword');
                    var emailVal = email ? (email.value || '').trim() : '';
                    var passVal = password ? password.value : '';
                    var ok = true;

                    showError('loginEmailError', '');
                    showError('loginPasswordError', '');
                    if (email) setInvalid(email, false);
                    if (password) setInvalid(password, false);

                    if (!emailVal) { 
                        showError('loginEmailError', messages.required); 
                        if (email) setInvalid(email, true); 
                        ok = false; 
                    }
                    if (!passVal) { 
                        showError('loginPasswordError', messages.required); 
                        if (password) setInvalid(password, true); 
                        ok = false; 
                    }

                    if (!ok) {
                        e.preventDefault();
                    }
                });
            }

            const signupForm = document.getElementById('signupForm');
            if (signupForm) {
                signupForm.addEventListener('submit', function (e) {
                    var name = document.getElementById('signupName');
                    var email = document.getElementById('signupEmail');
                    var password = document.getElementById('signupPassword');
                    var confirm = document.getElementById('signupPasswordConfirm');
                    var nameVal = name ? (name.value || '').trim() : '';
                    var emailVal = email ? (email.value || '').trim() : '';
                    var passVal = password ? password.value : '';
                    var confVal = confirm ? confirm.value : '';
                    var ok = true;

                    showError('signupNameError', '');
                    showError('signupEmailError', '');
                    showError('signupPasswordError', '');
                    showError('signupPasswordConfirmError', '');
                    
                    if (name) setInvalid(name, false);
                    if (email) setInvalid(email, false);
                    if (password) setInvalid(password, false);
                    if (confirm) setInvalid(confirm, false);

                    if (!nameVal) { 
                        showError('signupNameError', messages.required); 
                        if (name) setInvalid(name, true); 
                        ok = false; 
                    } else if (nameVal.length < 3) { 
                        showError('signupNameError', 'نام باید حداقل ۳ کاراکتر باشد'); 
                        if (name) setInvalid(name, true); 
                        ok = false; 
                    }
                    
                    if (!emailVal) { 
                        showError('signupEmailError', messages.required); 
                        if (email) setInvalid(email, true); 
                        ok = false; 
                    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) { 
                        showError('signupEmailError', messages.email); 
                        if (email) setInvalid(email, true); 
                        ok = false; 
                    }
                    
                    if (!passVal) { 
                        showError('signupPasswordError', messages.required); 
                        if (password) setInvalid(password, true); 
                        ok = false; 
                    } else if (passVal.length < 6) { 
                        showError('signupPasswordError', messages.minLength); 
                        if (password) setInvalid(password, true); 
                        ok = false; 
                    }
                    
                    if (passVal !== confVal) { 
                        showError('signupPasswordConfirmError', messages.passwordMatch); 
                        if (confirm) setInvalid(confirm, true); 
                        ok = false; 
                    }

                    if (!ok) {
                        e.preventDefault();
                    }
                });
            }

            var circleContainer = document.getElementById('circleContainer');
            if (circleContainer) {
                var numBars = 50;
                var activeBars = 0;
                for (var i = 0; i < numBars; i++) {
                    var bar = document.createElement('div');
                    bar.className = 'bar';
                    bar.style.transform = 'rotate(' + (360 / numBars) * i + 'deg) translateY(-170px)';
                    circleContainer.appendChild(bar);
                }
                setInterval(function () {
                    var bars = document.querySelectorAll('.bar');
                    if (bars.length > 0) {
                        bars[activeBars % numBars].classList.add('active');
                        if (activeBars > 8) bars[(activeBars - 8) % numBars].classList.remove('active');
                        activeBars++;
                    }
                }, 100);
            }
        })();
    </script>
</body>
</html>