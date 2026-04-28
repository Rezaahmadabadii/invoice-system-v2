<?php
namespace App\Middleware;

use App\Core\Session;

class AdminMiddleware
{
    public function handle()
    {
        if (!Session::has('user_id')) {
            header('Location: /login');
            exit;
        }
        
        if (Session::get('user_role') !== 'admin') {
            http_response_code(403);
            require_once __DIR__ . '/../Views/errors/403.php';
            exit;
        }
    }
}