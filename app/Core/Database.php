<?php
namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $connection;
    private $config;

    private function __construct()
    {
        $configPath = __DIR__ . '/../../config/database.php';
        if (file_exists($configPath)) {
            $this->config = require $configPath;
        } else {
            $this->config = [
                'driver' => 'mysql',
                'host' => 'localhost',
                'database' => 'invoice_system',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_persian_ci',
                'prefix' => ''
            ];
        }
        $this->connect();
    }

    private function connect()
    {
        try {
            $dsn = "mysql:host={$this->config['host']};dbname={$this->config['database']};charset={$this->config['charset']}";
            
            $this->connection = new PDO(
                $dsn, 
                $this->config['username'], 
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['charset']} COLLATE {$this->config['collation']}"
                ]
            );
        } catch (PDOException $e) {
            die("خطا در اتصال به پایگاه داده: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->connection;
    }

    /**
     * اجرای کوئری با پارامترهای position (?
     */
    public static function query($sql, $params = [])
    {
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage() . " - SQL: " . $sql);
            throw $e;
        }
    }

    /**
     * دریافت یک رکورد
     */
    public static function fetch($sql, $params = [])
    {
        return self::query($sql, $params)->fetch();
    }

    /**
     * دریافت چند رکورد
     */
    public static function fetchAll($sql, $params = [])
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * درج رکورد جدید (با پارامترهای named)
     */
    public static function insert($table, $data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        
        self::query($sql, array_values($data));
        return self::getInstance()->lastInsertId();
    }

    /**
     * به‌روزرسانی رکورد (با پارامترهای position)
     */
    public static function update($table, $data, $where, $whereParams = [])
    {
        $sets = [];
        foreach (array_keys($data) as $key) {
            $sets[] = "{$key} = ?";
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$where}";
        
        // ترکیب مقادیر data و whereParams
        $params = array_merge(array_values($data), $whereParams);
        
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * حذف رکورد
     */
    public static function delete($table, $where, $params = [])
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * شروع تراکنش
     */
    public static function beginTransaction()
    {
        self::getInstance()->beginTransaction();
    }

    /**
     * تایید تراکنش
     */
    public static function commit()
    {
        self::getInstance()->commit();
    }

    /**
     * برگرداندن تراکنش
     */
    public static function rollBack()
    {
        self::getInstance()->rollBack();
    }
}