<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// اگر کاربر لاگین است، به داشبورد هدایت شود
if(isLoggedIn()) {
    if($_SESSION['user_role'] == 'admin') {
        redirect('admin-panel.php');
    } elseif($_SESSION['user_role'] == 'supervisor') {
        redirect('supervisor-dashboard.php');
    } else {
        redirect('dashboard.php');
    }
}

$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if(empty($username) || empty($password)) {
        $error = 'لطفا نام کاربری و رمز عبور را وارد کنید';
    } else {
        $result = loginUser($username, $password);
        
        if($result['success']) {
            $_SESSION['user_id'] = $result['user']['id'];
            $_SESSION['username'] = $result['user']['username'];
            $_SESSION['user_role'] = $result['user']['role'];
            $_SESSION['user_name'] = $result['user']['full_name'] ?? $result['user']['username'];
            
            if($result['user']['role'] == 'admin') {
                redirect('admin-panel.php');
            } elseif($result['user']['role'] == 'supervisor') {
                redirect('supervisor-dashboard.php');
            } else {
                redirect('dashboard.php');
            }
        } else {
            $error = $result['message'];
        }
    }
}

// نمایش ویو
require_once 'app/Views/auth/login.php';
?>