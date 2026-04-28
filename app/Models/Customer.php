<?php
namespace App\Models;

use App\Core\Database;

class Customer
{
    public $id;
    public $code;
    public $name;
    public $phone;
    public $email;
    public $address;
    public $economic_code;
    public $national_id;
    public $created_at;

    /**
     * پیدا کردن مشتری با ID
     */
    public static function find($id)
    {
        $data = Database::fetch("SELECT * FROM customers WHERE id = ?", [$id]);
        if ($data) {
            return self::hydrate($data);
        }
        return null;
    }

    /**
     * دریافت همه مشتریان
     */
    public static function all()
    {
        $data = Database::fetchAll("SELECT * FROM customers ORDER BY name");
        $customers = [];
        foreach ($data as $row) {
            $customers[] = self::hydrate($row);
        }
        return $customers;
    }

    /**
     * جستجوی مشتریان
     */
    public static function search($term)
    {
        $term = "%$term%";
        $data = Database::fetchAll(
            "SELECT * FROM customers 
             WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? OR code LIKE ?
             ORDER BY name",
            [$term, $term, $term, $term]
        );
        
        $customers = [];
        foreach ($data as $row) {
            $customers[] = self::hydrate($row);
        }
        return $customers;
    }

    /**
     * دریافت فاکتورهای مشتری
     */
    public function invoices()
    {
        return Database::fetchAll(
            "SELECT * FROM invoices WHERE customer_id = ? ORDER BY created_at DESC",
            [$this->id]
        );
    }

    /**
     * مجموع فاکتورهای مشتری
     */
    public function totalInvoices()
    {
        $result = Database::fetch(
            "SELECT COUNT(*) as count, SUM(total) as total 
             FROM invoices WHERE customer_id = ?",
            [$this->id]
        );
        return $result;
    }

    /**
     * تبدیل آرایه به آبجکت
     */
    protected static function hydrate($data)
    {
        $customer = new self();
        foreach ($data as $key => $value) {
            $customer->$key = $value;
        }
        return $customer;
    }
}