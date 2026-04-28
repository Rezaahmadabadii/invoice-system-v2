<?php
namespace App\Controllers;

use App\Core\Session;

class AuthController
{
    public function showLogin()
    {
        echo "<h1>صفحه ورود</h1>";
        echo "<p><a href='/'>بازگشت به صفحه اصلی</a></p>";
    }

    public function login()
    {
        echo "فرم ورود ارسال شد";
    }

    public function showRegister()
    {
        echo "<h1>صفحه ثبت نام</h1>";
        echo "<p><a href='/'>بازگشت به صفحه اصلی</a></p>";
    }

    public function register()
    {
        echo "فرم ثبت نام ارسال شد";
    }

    public function logout()
    {
        header('Location: /');
    }
}