<?php
namespace App\Models;

use App\Core\Database;

class User
{
    public $id;
    public $username;
    public $password;
    public $email;
    public $full_name;
    public $avatar;
    public $role;
    public $last_login;
    public $last_ip;
    public $created_at;

    /**
     * پیدا کردن کاربر با نام کاربری یا ایمیل
     */
    public static function findByUsername($username)
    {
        $data = Database::fetch(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$username, $username]
        );

        if ($data) {
            return self::hydrate($data);
        }

        return null;
    }

    /**
     * پیدا کردن کاربر با ID
     */
    public static function find($id)
    {
        $data = Database::fetch("SELECT * FROM users WHERE id = ?", [$id]);

        if ($data) {
            return self::hydrate($data);
        }

        return null;
    }

    /**
     * پیدا کردن کاربر با توکن
     */
    public static function findByRememberToken($token)
    {
        $data = Database::fetch("SELECT * FROM users WHERE remember_token = ?", [$token]);

        if ($data) {
            return self::hydrate($data);
        }

        return null;
    }

    /**
     * تبدیل آرایه به آبجکت User
     */
    protected static function hydrate($data)
    {
        $user = new self();
        foreach ($data as $key => $value) {
            $user->$key = $value;
        }
        return $user;
    }

    /**
     * به‌روزرسانی آخرین ورود
     */
    public function updateLastLogin($ip)
    {
        $this->last_login = date('Y-m-d H:i:s');
        $this->last_ip = $ip;
        
        Database::update(
            'users', 
            [
                'last_login' => $this->last_login,
                'last_ip' => $ip
            ], 
            'id = ?', 
            [$this->id]
        );
    }

    /**
     * بررسی رمز عبور
     */
    public function verifyPassword($password)
    {
        return password_verify($password, $this->password);
    }

    /**
     * ذخیره تغییرات کاربر
     */
    public function save()
    {
        $data = [
            'username' => $this->username,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'avatar' => $this->avatar,
            'role' => $this->role
        ];

        Database::update('users', $data, 'id = ?', [$this->id]);
    }
}