<?php
// Minimal Craft CMS stubs for running tests without Craft installed.

namespace {

class Craft
{
    public static $aliases = [];
    public static $app;

    public static function getAlias($alias)
    {
        return self::$aliases[$alias] ?? $alias;
    }

    public static function setAlias($alias, $path)
    {
        self::$aliases[$alias] = $path;
    }

    public static function error($message, $method = null)
    {
        // No-op for tests
    }

    public static function info($message, $method = null)
    {
        // No-op for tests
    }

    public static function warning($message, $method = null)
    {
        // No-op for tests
    }
}

class CraftAppStub
{
    public $db;
    public $volumes;
    public $elements;
    public $assets;
    public $templateCaches;
    public $config;
    public $user;

    public function __construct()
    {
        $this->db = new DbStub();
        $this->volumes = new VolumesStub();
        $this->elements = new ElementsStub();
        $this->assets = new AssetsStub();
        $this->templateCaches = new TemplateCachesStub();
        $this->config = new ConfigStub();
        $this->user = new UserStub();
    }

    public function getDb()
    {
        return $this->db;
    }

    public function getVolumes()
    {
        return $this->volumes;
    }

    public function getElements()
    {
        return $this->elements;
    }

    public function getAssets()
    {
        return $this->assets;
    }

    public function getTemplateCaches()
    {
        return $this->templateCaches;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getUser()
    {
        return $this->user;
    }
}

class ConfigStub
{
    public $general;

    public function __construct()
    {
        $this->general = new GeneralConfigStub();
    }

    public function getGeneral()
    {
        return $this->general;
    }
}

class GeneralConfigStub
{
    public $allowAdminChanges = true;
}

class UserStub
{
    public $isAdmin = true;

    public function getIsAdmin()
    {
        return $this->isAdmin;
    }
}

class DbStub
{
    public $dsn = 'mysql:host=localhost;port=3306;dbname=testdb';
    public $username = 'user';
    public $password = 'pass';
    public $tables = [
        'migrationlocks' => [],
        'migration_state' => [],
    ];
    public $executedStatements = [];
    public $transactions = [];
    public $failOnSqlPattern;

    public function tableExists($table)
    {
        return true;
    }

    public function createCommand($sql = null, $params = [])
    {
        return new DbCommandStub($this, $sql, $params);
    }

    public function beginTransaction()
    {
        $transaction = new TransactionStub($this);
        $this->transactions[] = $transaction;

        return $transaction;
    }
}

class DbCommandStub
{
    private $db;
    private $sql;
    private $params;
    private $operation;
    private $table;
    private $data;
    private $where;

    public function __construct($db, $sql = null, $params = [])
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function insert($table, $data)
    {
        $this->operation = 'insert';
        $this->table = $table;
        $this->data = $data;
        return $this;
    }

    public function update($table, $data, $where)
    {
        $this->operation = 'update';
        $this->table = $table;
        $this->data = $data;
        $this->where = $where;
        return $this;
    }

    public function delete($table, $where = null)
    {
        $this->operation = 'delete';
        $this->table = $table;
        $this->where = $where;
        return $this;
    }

    public function queryOne()
    {
        if (strpos($this->sql, 'FROM {{%migrationlocks}}') !== false) {
            $lockName = $this->params[':lockName'] ?? 'migration_lock';
            return $this->db->tables['migrationlocks'][$lockName] ?? null;
        }

        return null;
    }

    public function execute()
    {
        if ($this->sql !== null) {
            $this->db->executedStatements[] = $this->sql;

            if ($this->db->failOnSqlPattern && preg_match($this->db->failOnSqlPattern, $this->sql)) {
                throw new \Exception('Simulated SQL failure');
            }

            if (stripos($this->sql, 'SET FOREIGN_KEY_CHECKS') !== false) {
                return 0;
            }
        }

        if ($this->sql && strpos($this->sql, 'DELETE FROM {{%migrationlocks}}') !== false) {
            $now = $this->params[':now'] ?? null;
            foreach ($this->db->tables['migrationlocks'] as $name => $lock) {
                if ($now && strtotime($lock['expiresAt']) < strtotime($now)) {
                    unset($this->db->tables['migrationlocks'][$name]);
                }
            }
            return 0;
        }

        if ($this->sql && strpos($this->sql, 'UPDATE {{%migrationlocks}}') !== false) {
            $lockName = $this->params[':lockName'] ?? $this->data['lockName'] ?? 'migration_lock';
            if (isset($this->db->tables['migrationlocks'][$lockName])) {
                $this->db->tables['migrationlocks'][$lockName] = array_merge(
                    $this->db->tables['migrationlocks'][$lockName],
                    [
                        'lockedAt' => $this->params[':lockedAt'] ?? $this->db->tables['migrationlocks'][$lockName]['lockedAt'],
                        'lockedBy' => $this->params[':lockedBy'] ?? $this->db->tables['migrationlocks'][$lockName]['lockedBy'],
                        'expiresAt' => $this->params[':expiresAt'] ?? $this->db->tables['migrationlocks'][$lockName]['expiresAt'],
                    ]
                );
            }
            return 1;
        }

        switch ($this->operation) {
            case 'insert':
                if ($this->table === '{{%migrationlocks}}') {
                    $lockName = $this->data['lockName'];
                    $this->db->tables['migrationlocks'][$lockName] = $this->data;
                } elseif ($this->table === '{{%migration_state}}') {
                    $this->db->tables['migration_state'][$this->data['migrationId']] = $this->data;
                }
                return 1;
            case 'update':
                if ($this->table === '{{%migrationlocks}}') {
                    $lockName = $this->where['lockName'] ?? 'migration_lock';
                    if (isset($this->db->tables['migrationlocks'][$lockName])) {
                        $this->db->tables['migrationlocks'][$lockName] = array_merge(
                            $this->db->tables['migrationlocks'][$lockName],
                            $this->data
                        );
                    }
                } elseif ($this->table === '{{%migration_state}}') {
                    $migrationId = $this->where['migrationId'] ?? null;
                    if ($migrationId && isset($this->db->tables['migration_state'][$migrationId])) {
                        $this->db->tables['migration_state'][$migrationId] = array_merge(
                            $this->db->tables['migration_state'][$migrationId],
                            $this->data
                        );
                    }
                }
                return 1;
            case 'delete':
                if ($this->table === '{{%migrationlocks}}') {
                    $lockName = $this->where['lockName'] ?? 'migration_lock';
                    unset($this->db->tables['migrationlocks'][$lockName]);
                }
                return 1;
            default:
                return 0;
        }
    }
}

class TransactionStub
{
    private $db;
    public $isActive = true;
    public $committed = false;
    public $rolledBack = false;

    public function __construct(DbStub $db)
    {
        $this->db = $db;
    }

    public function commit()
    {
        $this->committed = true;
        $this->isActive = false;
    }

    public function rollBack()
    {
        $this->rolledBack = true;
        $this->isActive = false;
    }
}

class ElementsStub
{
    public function saveElement($element)
    {
        \craft\elements\Asset::$store[$element->id] = $element;
        return true;
    }

    public function invalidateAllCaches()
    {
        // No-op for tests
    }
}

class VolumesStub
{
    private $volumes = [];

    public function addVolume(VolumeStub $volume)
    {
        $this->volumes[$volume->id] = $volume;
    }

    public function getVolumeByHandle($handle)
    {
        foreach ($this->volumes as $volume) {
            if ($volume->handle === $handle) {
                return $volume;
            }
        }
        return null;
    }

    public function getVolumeById($id)
    {
        return $this->volumes[$id] ?? null;
    }
}

class VolumeStub
{
    public $id;
    public $handle;
    private $fs;

    public function __construct($id, $handle)
    {
        $this->id = $id;
        $this->handle = $handle;
        $this->fs = new FsStub();
    }

    public function getFs()
    {
        return $this->fs;
    }
}

class FsStub
{
    private $files = [];

    public function write($path, $contents)
    {
        $this->files[$path] = $contents;
    }

    public function read($path)
    {
        return $this->files[$path] ?? null;
    }

    public function fileExists($path)
    {
        return array_key_exists($path, $this->files);
    }

    public function deleteFile($path)
    {
        unset($this->files[$path]);
    }
}

class AssetsStub
{
    private $folders = [];

    public function addRootFolder($volumeId, $folderId)
    {
        $this->folders[$volumeId] = (object)['id' => $folderId];
    }

    public function getRootFolderByVolumeId($volumeId)
    {
        return $this->folders[$volumeId] ?? null;
    }
}

class TemplateCachesStub
{
    public function deleteAllCaches()
    {
        // No-op for tests
    }
}

// Initialize default Craft app for tests
Craft::$app = new CraftAppStub();
}

namespace yii\web {
    class ForbiddenHttpException extends \Exception
    {
    }
}

namespace craft\helpers {

class FileHelper
{
    public static function createDirectory($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}

class Db
{
    public static function prepareDateForDb($dateTime)
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
}

namespace craft\db {

class Query
{
    private $table;
    private $where = [];
    private $orderBy;

    public static $dataSource;

    public function select($columns)
    {
        return $this;
    }

    public function from($table)
    {
        $this->table = trim($table, '{}%');
        return $this;
    }

    public function where($condition)
    {
        $this->where = $condition;
        return $this;
    }

    public function orderBy($order)
    {
        $this->orderBy = $order;
        return $this;
    }

    public function one()
    {
        $data = $this->getData();
        return $data[0] ?? null;
    }

    public function all()
    {
        return $this->getData();
    }

    private function getData()
    {
        $db = \Craft::$app->getDb();
        $tableData = $db->tables[$this->table] ?? [];
        $results = array_values($tableData);

        if (!empty($this->where) && isset($this->where['migrationId'])) {
            $results = array_values(array_filter($results, function ($row) {
                return isset($row['migrationId']) && $row['migrationId'] === $this->where['migrationId'];
            }));
        }

        if (!empty($this->where) && isset($this->where['status'])) {
            $statuses = (array)$this->where['status'];
            $results = array_values(array_filter($results, function ($row) use ($statuses) {
                return in_array($row['status'] ?? null, $statuses, true);
            }));
        }

        if ($this->orderBy) {
            foreach ($this->orderBy as $column => $direction) {
                usort($results, function ($a, $b) use ($column, $direction) {
                    $aVal = $a[$column] ?? null;
                    $bVal = $b[$column] ?? null;
                    if ($aVal === $bVal) {
                        return 0;
                    }
                    return ($direction === SORT_DESC ? -1 : 1) * (($aVal <=> $bVal));
                });
            }
        }

        return $results;
    }
}
}

namespace craft\elements {

class Asset
{
    public static $store = [];
    public $id;
    public $volumeId;
    public $folderId;

    public function __construct($id, $volumeId = null, $folderId = null)
    {
        $this->id = $id;
        $this->volumeId = $volumeId;
        $this->folderId = $folderId;
    }

    public static function findOne($id)
    {
        return self::$store[$id] ?? null;
    }
}
}
