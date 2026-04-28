<?php
namespace App\Controllers;

use App\Core\Session;
use App\Core\Database;
use App\Models\Invoice;
use App\Models\Customer;

class InvoiceController
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
     * نمایش لیست فاکتورها
     */
    public function index()
    {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        
        // دریافت فاکتورها با صفحه‌بندی
        $invoices = Database::fetchAll(
            "SELECT i.*, c.name as customer_name 
             FROM invoices i 
             LEFT JOIN customers c ON i.customer_id = c.id 
             ORDER BY i.created_at DESC 
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
        
        // تعداد کل فاکتورها
        $total = Database::fetch("SELECT COUNT(*) as count FROM invoices")['count'];
        $totalPages = ceil($total / $perPage);
        
        require_once app_path('Views/invoices/index.php');
    }

    /**
     * نمایش فرم ایجاد فاکتور جدید
     */
    public function create()
    {
        // دریافت لیست مشتریان برای انتخاب
        $customers = Database::fetchAll("SELECT * FROM customers ORDER BY name");
        
        // دریافت شماره فاکتور جدید
        $invoiceNumber = $this->generateInvoiceNumber();
        
        require_once app_path('Views/invoices/create.php');
    }

    /**
     * ذخیره فاکتور جدید
     */
    public function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /invoices/create');
            return;
        }

        $data = [
            'invoice_number' => $_POST['invoice_number'] ?? '',
            'customer_id' => $_POST['customer_id'] ?? null,
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'amount' => str_replace(',', '', $_POST['amount'] ?? 0),
            'tax' => $_POST['tax'] ?? 0,
            'discount' => $_POST['discount'] ?? 0,
            'status' => 'draft',
            'created_by' => Session::get('user_id'),
            'created_at' => date('Y-m-d H:i:s')
        ];

        // اعتبارسنجی
        if (empty($data['title']) || empty($data['amount'])) {
            Session::setFlash('error', 'عنوان و مبلغ فاکتور الزامی است');
            header('Location: /invoices/create');
            return;
        }

        // محاسبه مبلغ نهایی
        $data['total'] = $data['amount'] + $data['tax'] - $data['discount'];

        // ذخیره در دیتابیس
        $invoiceId = Database::insert('invoices', $data);

        if ($invoiceId) {
            Session::setFlash('success', 'فاکتور با موفقیت ایجاد شد');
            header('Location: /invoices/' . $invoiceId);
        } else {
            Session::setFlash('error', 'خطا در ایجاد فاکتور');
            header('Location: /invoices/create');
        }
    }

    /**
     * نمایش جزئیات فاکتور
     */
    public function show($id)
    {
        $invoice = Database::fetch(
            "SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                    u.full_name as creator_name
             FROM invoices i 
             LEFT JOIN customers c ON i.customer_id = c.id 
             LEFT JOIN users u ON i.created_by = u.id 
             WHERE i.id = ?",
            [$id]
        );

        if (!$invoice) {
            Session::setFlash('error', 'فاکتور یافت نشد');
            header('Location: /invoices');
            return;
        }

        // دریافت آیتم‌های فاکتور
        $items = Database::fetchAll(
            "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id",
            [$id]
        );

        // دریافت تاریخچه تأیید
        $approvals = Database::fetchAll(
            "SELECT a.*, u.full_name as approver_name, u.role as approver_role,
                    s.name as step_name
             FROM invoice_approvals a
             LEFT JOIN users u ON a.approver_id = u.id
             LEFT JOIN approval_steps s ON a.step_id = s.id
             WHERE a.invoice_id = ?
             ORDER BY a.created_at DESC",
            [$id]
        );

        require_once app_path('Views/invoices/show.php');
    }

    /**
     * نمایش فرم ویرایش فاکتور
     */
    public function edit($id)
    {
        $invoice = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$id]);

        if (!$invoice) {
            Session::setFlash('error', 'فاکتور یافت نشد');
            header('Location: /invoices');
            return;
        }

        // فقط فاکتورهای پیش‌نویس قابل ویرایش هستند
        if ($invoice['status'] !== 'draft') {
            Session::setFlash('error', 'این فاکتور قابل ویرایش نیست');
            header('Location: /invoices/' . $id);
            return;
        }

        $customers = Database::fetchAll("SELECT * FROM customers ORDER BY name");
        $items = Database::fetchAll("SELECT * FROM invoice_items WHERE invoice_id = ?", [$id]);

        require_once app_path('Views/invoices/edit.php');
    }

    /**
     * به‌روزرسانی فاکتور
     */
    public function update($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /invoices/' . $id);
            return;
        }

        $invoice = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$id]);

        if (!$invoice || $invoice['status'] !== 'draft') {
            Session::setFlash('error', 'این فاکتور قابل ویرایش نیست');
            header('Location: /invoices/' . $id);
            return;
        }

        $data = [
            'customer_id' => $_POST['customer_id'] ?? null,
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'amount' => str_replace(',', '', $_POST['amount'] ?? 0),
            'tax' => $_POST['tax'] ?? 0,
            'discount' => $_POST['discount'] ?? 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if (empty($data['title']) || empty($data['amount'])) {
            Session::setFlash('error', 'عنوان و مبلغ فاکتور الزامی است');
            header('Location: /invoices/' . $id . '/edit');
            return;
        }

        Database::update('invoices', $data, 'id = ?', [$id]);
        
        // حذف آیتم‌های قبلی و درج مجدد
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            Database::delete('invoice_items', 'invoice_id = ?', [$id]);
            
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description']) && !empty($item['amount'])) {
                    Database::insert('invoice_items', [
                        'invoice_id' => $id,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'] ?? 1,
                        'price' => $item['price'] ?? 0,
                        'total' => ($item['quantity'] ?? 1) * ($item['price'] ?? 0)
                    ]);
                }
            }
        }

        Session::setFlash('success', 'فاکتور با موفقیت به‌روزرسانی شد');
        header('Location: /invoices/' . $id);
    }

    /**
     * حذف فاکتور
     */
    public function delete($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /invoices');
            return;
        }

        $invoice = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$id]);

        if (!$invoice) {
            Session::setFlash('error', 'فاکتور یافت نشد');
            header('Location: /invoices');
            return;
        }

        // فقط فاکتورهای پیش‌نویس قابل حذف هستند
        if ($invoice['status'] !== 'draft') {
            Session::setFlash('error', 'این فاکتور قابل حذف نیست');
            header('Location: /invoices/' . $id);
            return;
        }

        // حذف آیتم‌های مرتبط
        Database::delete('invoice_items', 'invoice_id = ?', [$id]);
        
        // حذف فاکتور
        Database::delete('invoices', 'id = ?', [$id]);

        Session::setFlash('success', 'فاکتور با موفقیت حذف شد');
        header('Location: /invoices');
    }

    /**
     * ارسال فاکتور برای تأیید
     */
    public function submitForApproval($id)
    {
        $invoice = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$id]);

        if (!$invoice) {
            Session::setFlash('error', 'فاکتور یافت نشد');
            header('Location: /invoices');
            return;
        }

        if ($invoice['status'] !== 'draft') {
            Session::setFlash('error', 'این فاکتور قبلاً ارسال شده است');
            header('Location: /invoices/' . $id);
            return;
        }

        // به‌روزرسانی وضعیت
        Database::update('invoices', 
            ['status' => 'pending'], 
            'id = ?', 
            [$id]
        );

        // ایجاد رکورد تأیید
        $this->createApprovalSteps($id);

        Session::setFlash('success', 'فاکتور با موفقیت برای تأیید ارسال شد');
        header('Location: /invoices/' . $id);
    }

    /**
     * ایجاد مراحل تأیید برای فاکتور
     */
    private function createApprovalSteps($invoiceId)
    {
        // دریافت زنجیره تأیید پیش‌فرض
        $chain = Database::fetch("SELECT * FROM approval_chains WHERE is_default = 1");
        
        if ($chain) {
            $steps = Database::fetchAll(
                "SELECT * FROM approval_steps WHERE chain_id = ? ORDER BY step_order",
                [$chain['id']]
            );

            foreach ($steps as $step) {
                Database::insert('invoice_approvals', [
                    'invoice_id' => $invoiceId,
                    'step_id' => $step['id'],
                    'approver_id' => $step['approver_id'],
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * تولید شماره فاکتور جدید
     */
    private function generateInvoiceNumber()
    {
        $year = jdate('Y');
        $month = jdate('m');
        
        // آخرین شماره فاکتور امسال
        $last = Database::fetch(
            "SELECT invoice_number FROM invoices 
             WHERE invoice_number LIKE ? 
             ORDER BY id DESC LIMIT 1",
            ["INV-{$year}{$month}-%"]
        );

        if ($last) {
            $parts = explode('-', $last['invoice_number']);
            $number = (int)end($parts) + 1;
        } else {
            $number = 1;
        }

        return "INV-{$year}{$month}-" . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}