<?php
namespace App\Models;

use App\Core\Database;

abstract class Model
{
    protected $table;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $hidden = ['password'];
    protected $timestamps = true;

    public function __construct($data = [])
    {
        $this->fill($data);
    }

    public function fill($data)
    {
        foreach ($this->fillable as $field) {
            if (isset($data[$field])) {
                $this->$field = $data[$field];
            }
        }
    }

    public static function find($id)
    {
        $model = new static();
        $sql = "SELECT * FROM {$model->table} WHERE {$model->primaryKey} = ?";
        $data = Database::fetch($sql, [$id]);
        
        if ($data) {
            $instance = new static();
            foreach ($data as $key => $value) {
                $instance->$key = $value;
            }
            return $instance;
        }
        
        return null;
    }

    public static function where($conditions = [], $orderBy = '', $limit = '')
    {
        $model = new static();
        $sql = "SELECT * FROM {$model->table}";
        
        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $where[] = "$key = :$key";
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY $orderBy";
        }
        
        if ($limit) {
            $sql .= " LIMIT $limit";
        }
        
        return Database::fetchAll($sql, $conditions);
    }

    public static function all($orderBy = 'id DESC')
    {
        $model = new static();
        $sql = "SELECT * FROM {$model->table} ORDER BY $orderBy";
        return Database::fetchAll($sql);
    }

    public function save()
    {
        $data = [];
        foreach ($this->fillable as $field) {
            if (isset($this->$field)) {
                $data[$field] = $this->$field;
            }
        }

        if ($this->timestamps && !isset($this->created_at)) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        if (isset($this->{$this->primaryKey})) {
            // Update
            Database::update($this->table, $data, "{$this->primaryKey} = ?", [$this->{$this->primaryKey}]);
            return $this->{$this->primaryKey};
        } else {
            // Insert
            $id = Database::insert($this->table, $data);
            $this->{$this->primaryKey} = $id;
            return $id;
        }
    }

    public function delete()
    {
        if (isset($this->{$this->primaryKey})) {
            Database::delete($this->table, "{$this->primaryKey} = ?", [$this->{$this->primaryKey}]);
            return true;
        }
        return false;
    }

    public function toArray()
    {
        $data = [];
        foreach ($this as $key => $value) {
            if (!in_array($key, $this->hidden)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }
}