<?php
namespace App\Controllers;

use App\Core\Session;

class DashboardController
{
    public function __construct()
    {
        // بررسی لاگین بودن کاربر
        if (!Session::has('user_id')) {
            header('Location: /login');
            exit;
        }
    }

    public function index()
    {
        $userData = [
            'username' => Session::get('username'),
            'user_name' => Session::get('user_name'),
            'user_role' => Session::get('user_role')
        ];
        
        require_once app_path('Views/dashboard/index.php');
    }
}