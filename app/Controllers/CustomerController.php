<?php
namespace App\Controllers;

use App\Core\Session;
use App\Core\Database;

class CustomerController
{
    public function __construct()
    {
        // بررسی لاگین بودن کاربر
        if (!Session::has('user_id')) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * نمایش لیست مشتریان
     */
    public function index()
    {
        $search = $_GET['search'] ?? '';
        
        if (!empty($search)) {
            $customers = Database::fetchAll(
                "SELECT * FROM customers 
                 WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? OR code LIKE ?
                 ORDER BY name",
                ["%$search%", "%$search%", "%$search%", "%$search%"]
            );
        } else {
            $customers = Database::fetchAll("SELECT * FROM customers ORDER BY name");
        }
        
        require_once app_path('Views/customers/index.php');
    }

    /**
     * نمایش فرم ایجاد مشتری جدید
     */
    public function create()
    {
        // تولید کد مشتری جدید
        $code = $this->generateCustomerCode();
        require_once app_path('Views/customers/create.php');
    }

    /**
     * ذخیره مشتری جدید
     */
    public function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /customers/create');
            return;
        }

        $data = [
            'code' => $_POST['code'] ?? '',
            'name' => $_POST['name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'address' => $_POST['address'] ?? '',
            'economic_code' => $_POST['economic_code'] ?? '',
            'national_id' => $_POST['national_id'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];

        // اعتبارسنجی
        if (empty($data['name'])) {
            Session::setFlash('error', 'نام مشتری الزامی است');
            header('Location: /customers/create');
            return;
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Session::setFlash('error', 'ایمیل معتبر نیست');
            header('Location: /customers/create');
            return;
        }

        // بررسی تکراری نبودن کد مشتری
        if (!empty($data['code'])) {
            $exists = Database::fetch("SELECT id FROM customers WHERE code = ?", [$data['code']]);
            if ($exists) {
                Session::setFlash('error', 'این کد مشتری قبلاً استفاده شده است');
                header('Location: /customers/create');
                return;
            }
        }

        $customerId = Database::insert('customers', $data);

        if ($customerId) {
            Session::setFlash('success', 'مشتری با موفقیت ایجاد شد');
            header('Location: /customers');
        } else {
            Session::setFlash('error', 'خطا در ایجاد مشتری');
            header('Location: /customers/create');
        }
    }

    /**
     * نمایش جزئیات مشتری
     */
    public function show($id)
    {
        $customer = Database::fetch("SELECT * FROM customers WHERE id = ?", [$id]);

        if (!$customer) {
            Session::setFlash('error', 'مشتری یافت نشد');
            header('Location: /customers');
            return;
        }

        // دریافت فاکتورهای مشتری
        $invoices = Database::fetchAll(
            "SELECT * FROM invoices WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10",
            [$id]
        );

        // آمار مشتری
        $stats = Database::fetch(
            "SELECT 
                COUNT(*) as total_invoices,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status = 'pending' THEN total ELSE 0 END) as pending_amount
             FROM invoices 
             WHERE customer_id = ?",
            [$id]
        );

        require_once app_path('Views/customers/show.php');
    }

    /**
     * نمایش فرم ویرایش مشتری
     */
    public function edit($id)
    {
        $customer = Database::fetch("SELECT * FROM customers WHERE id = ?", [$id]);

        if (!$customer) {
            Session::setFlash('error', 'مشتری یافت نشد');
            header('Location: /customers');
            return;
        }

        require_once app_path('Views/customers/edit.php');
    }

    /**
     * به‌روزرسانی مشتری
     */
    public function update($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /customers/' . $id);
            return;
        }

        $customer = Database::fetch("SELECT * FROM customers WHERE id = ?", [$id]);

        if (!$customer) {
            Session::setFlash('error', 'مشتری یافت نشد');
            header('Location: /customers');
            return;
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'address' => $_POST['address'] ?? '',
            'economic_code' => $_POST['economic_code'] ?? '',
            'national_id' => $_POST['national_id'] ?? ''
        ];

        if (empty($data['name'])) {
            Session::setFlash('error', 'نام مشتری الزامی است');
            header('Location: /customers/' . $id . '/edit');
            return;
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Session::setFlash('error', 'ایمیل معتبر نیست');
            header('Location: /customers/' . $id . '/edit');
            return;
        }

        Database::update('customers', $data, 'id = ?', [$id]);

        Session::setFlash('success', 'مشتری با موفقیت به‌روزرسانی شد');
        header('Location: /customers/' . $id);
    }

    /**
     * حذف مشتری
     */
    public function delete($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /customers');
            return;
        }

        // بررسی وجود فاکتور برای این مشتری
        $hasInvoice = Database::fetch("SELECT id FROM invoices WHERE customer_id = ? LIMIT 1", [$id]);

        if ($hasInvoice) {
            Session::setFlash('error', 'این مشتری دارای فاکتور است و قابل حذف نیست');
            header('Location: /customers');
            return;
        }

        Database::delete('customers', 'id = ?', [$id]);

        Session::setFlash('success', 'مشتری با موفقیت حذف شد');
        header('Location: /customers');
    }

    /**
     * جستجوی مشتریان (برای API)
     */
    public function search()
    {
        $term = $_GET['term'] ?? '';
        
        if (empty($term)) {
            $this->json([]);
            return;
        }

        $customers = Database::fetchAll(
            "SELECT id, code, name, phone, email 
             FROM customers 
             WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? OR code LIKE ?
             ORDER BY name LIMIT 10",
            ["%$term%", "%$term%", "%$term%", "%$term%"]
        );

        $this->json($customers);
    }

    /**
     * تولید کد مشتری جدید
     */
    private function generateCustomerCode()
    {
        $year = jdate('Y');
        $last = Database::fetch(
            "SELECT code FROM customers WHERE code LIKE ? ORDER BY id DESC LIMIT 1",
            ["CUS-{$year}-%"]
        );

        if ($last) {
            $parts = explode('-', $last['code']);
            $number = (int)end($parts) + 1;
        } else {
            $number = 1;
        }

        return "CUS-{$year}-" . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * پاسخ JSON
     */
    private function json($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}