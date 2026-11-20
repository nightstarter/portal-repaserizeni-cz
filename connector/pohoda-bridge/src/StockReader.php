<?php

namespace PortalRepase\Bridge;

use PDO;
use PDOException;

class StockReader
{
    private $config;
    private $pdoFactory;
    private $pdo;

    public function __construct(array $config, $pdoFactory = null)
    {
        $this->config = $config;
        $this->pdoFactory = $pdoFactory;
    }

    public function connect()
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        try {
            $this->pdo = is_callable($this->pdoFactory)
                ? call_user_func($this->pdoFactory)
                : new PDO($this->config['sql_dsn'], $this->config['sql_username'], $this->config['sql_password']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new BridgeException('SQL_CONNECTION_FAILED');
        }

        return $this->pdo;
    }

    public static function stockSql()
    {
        return 'SELECT SKz.ID, SKz.IDS, SKz.StavZ, SKz.Rezer, SKz.DatSave, SKz.RefSklad, SKz.RelSkTyp '
            .'FROM SKz WHERE SKz.RefSklad = :warehouse_ref AND SKz.RelSkTyp = :stock_type '
            ."AND LTRIM(RTRIM(SKz.IDS)) <> '' ORDER BY SKz.ID";
    }

    public function readAll()
    {
        $pdo = $this->connect();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $statement = $pdo->prepare(self::stockSql());
            $statement->execute(array('warehouse_ref' => (int) $this->config['warehouse_ref'], 'stock_type' => 1));
            $rows = $statement->fetchAll();
            $pdo->commit();
            return $rows;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new BridgeException('SQL_READ_FAILED');
        }
    }

    public function inspect($limit)
    {
        return array_slice($this->readAll(), 0, max(1, min(100, (int) $limit)));
    }

    public function schemaColumns()
    {
        $statement = $this->connect()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table_name AND COLUMN_NAME IN ('ID','IDS','StavZ','Rezer','DatSave','RefSklad','RelSkTyp')");
        $statement->execute(array('table_name' => 'SKz'));
        return array_values(array_map(function ($row) {
            return $row['COLUMN_NAME'];
        }, $statement->fetchAll()));
    }

    public function dangerousPermissions()
    {
        $statement = $this->connect()->query("SELECT permission_name FROM fn_my_permissions('dbo.SKz', 'OBJECT') WHERE permission_name IN ('INSERT','UPDATE','DELETE','ALTER','CONTROL')");
        return array_values(array_map(function ($row) {
            return $row['permission_name'];
        }, $statement->fetchAll()));
    }

    public function warehouseCount()
    {
        $statement = $this->connect()->prepare('SELECT COUNT(*) AS total FROM SKz WHERE RefSklad = :warehouse_ref AND RelSkTyp = :stock_type');
        $statement->execute(array('warehouse_ref' => (int) $this->config['warehouse_ref'], 'stock_type' => 1));
        $row = $statement->fetch();
        return (int) $row['total'];
    }
}
