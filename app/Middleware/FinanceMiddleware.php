<?php
namespace App\Middleware;

use App\Core\Session;

class FinanceMiddleware
{
    public function handle()
    {
        if (!Session::has('user_id')) {
            header('Location: /login');
            exit;
        }
        
        $role = Session::get('user_role');
        if (!in_array($role, ['admin', 'finance'])) {
            http_response_code(403);
            require_once __DIR__ . '/../Views/errors/403.php';
            exit;
        }
    }
}