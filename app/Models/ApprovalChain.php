<?php
namespace App\Models;

use App\Core\Database;

class ApprovalChain
{
    public $id;
    public $name;
    public $description;
    public $is_default;
    public $created_by;
    public $created_at;

    /**
     * پیدا کردن زنجیره با ID
     */
    public static function find($id)
    {
        $data = Database::fetch("SELECT * FROM approval_chains WHERE id = ?", [$id]);
        if ($data) {
            return self::hydrate($data);
        }
        return null;
    }

    /**
     * دریافت همه زنجیره‌ها
     */
    public static function all()
    {
        $data = Database::fetchAll("SELECT * FROM approval_chains ORDER BY is_default DESC, name");
        $chains = [];
        foreach ($data as $row) {
            $chains[] = self::hydrate($row);
        }
        return $chains;
    }

    /**
     * دریافت زنجیره پیش‌فرض
     */
    public static function getDefault()
    {
        $data = Database::fetch("SELECT * FROM approval_chains WHERE is_default = 1 LIMIT 1");
        if ($data) {
            return self::hydrate($data);
        }
        return null;
    }

    /**
     * دریافت مراحل زنجیره
     */
    public function steps()
    {
        return Database::fetchAll(
            "SELECT * FROM approval_steps WHERE chain_id = ? ORDER BY step_order",
            [$this->id]
        );
    }

    /**
     * تبدیل آرایه به آبجکت
     */
    protected static function hydrate($data)
    {
        $chain = new self();
        foreach ($data as $key => $value) {
            $chain->$key = $value;
        }
        return $chain;
    }
}