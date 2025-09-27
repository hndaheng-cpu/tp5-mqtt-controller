<?php
namespace think\mqtt;

use think\Db;

class DbHandler
{
    public function save(array $messages, array $config)
    {
        if (empty($messages)) return;

        $tableData = [];
        foreach ($messages as $msg) {
            if (!isset($msg['table'])) continue;
            $table = $msg['table'];
            unset($msg['table']);
            $tableData[$table][] = $msg;
        }

        foreach ($tableData as $table => $rows) {
            try {
                $this->ensureTableStructure($table, $rows);

                Db::name($table)->insertAll($rows);
                echo "[" . date('Y-m-d H:i:s') . "] 已写入 $table 表 " . count($rows) . " 条数据\n";

                if (!empty($config['index_suggestion']['enabled'])) {
                    $this->suggestIndexes($table, $rows, $config['index_suggestion']);
                }
            } catch (\Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] 写入 $table 失败: " . $e->getMessage() . "\n";
            }
        }
    }

    protected function ensureTableStructure(string $table, array $dataRows)
    {
        $prefix = \think\Config::get('database.prefix', '');
        $fullTable = $prefix . $table;
        $dbName = Db::query('SELECT database() AS db')[0]['db'];

        $tableExists = Db::query(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
            [$dbName, $fullTable]
        );

        $fieldTypes = [];
        foreach ($dataRows as $row) {
            foreach ($row as $field => $value) {
                if ($field == 'id' || $field == 'topic' || $field == 'created_at') continue;
                $mysqlType = $this->getMysqlType($value);
                if (!isset($fieldTypes[$field]) || $mysqlType == 'VARCHAR(255)' || $mysqlType == 'JSON') {
                    $fieldTypes[$field] = $mysqlType;
                }
            }
        }

        if (!$tableExists) {
            $columns = [
                'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                'topic VARCHAR(255) NOT NULL',
                'created_at DATETIME NOT NULL',
            ];
            foreach ($fieldTypes as $field => $type) {
                $columns[] = "`{$field}` {$type} NULL";
            }
            $columns[] = 'PRIMARY KEY (id)';
            $columns[] = "KEY `idx_topic` (topic)";
            $columns[] = "KEY `idx_created_at` (created_at)";

            $sql = "CREATE TABLE `{$fullTable}` (" . implode(', ', $columns) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            Db::execute($sql);
            echo "[" . date('Y-m-d H:i:s') . "] 自动创建表: {$fullTable}\n";
        } else {
            $existingColumns = Db::query(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?",
                [$dbName, $fullTable]
            );
            $existingFields = array_column($existingColumns, 'column_name');

            foreach ($fieldTypes as $field => $type) {
                if (!in_array($field, $existingFields)) {
                    Db::execute("ALTER TABLE `{$fullTable}` ADD `{$field}` {$type} NULL");
                    echo "[" . date('Y-m-d H:i:s') . "] 自动添加字段: {$fullTable}.{$field} ({$type})\n";
                }
            }
        }
    }

    protected function suggestIndexes(string $table, array $sampleData, array $indexConfig)
    {
        $prefix = \think\Config::get('database.prefix', '');
        $fullTable = $prefix . $table;
        $dbName = Db::query('SELECT database() AS db')[0]['db'];

        $existingIndexes = Db::query(
            "SELECT column_name 
             FROM information_schema.statistics 
             WHERE table_schema = ? AND table_name = ? AND index_name != 'PRIMARY'",
            [$dbName, $fullTable]
        );
        $indexedColumns = array_column($existingIndexes, 'column_name');

        $fieldStats = [];
        foreach ($sampleData as $row) {
            foreach ($row as $field => $value) {
                if ($field == 'id' || $field == 'topic' || $field == 'created_at') continue;
                if (!isset($fieldStats[$field])) {
                    $fieldStats[$field] = ['values' => [], 'non_null_count' => 0];
                }
                if ($value !== null) {
                    $fieldStats[$field]['non_null_count']++;
                    $fieldStats[$field]['values'][serialize($value)] = true;
                }
            }
        }

        foreach ($fieldStats as $field => $stats) {
            if (in_array($field, $indexedColumns)) continue;

            $selectivity = count($stats['values']) / max(1, $stats['non_null_count']);
            if ($selectivity >= $indexConfig['min_selectivity'] && $stats['non_null_count'] > 0) {
                Db::execute("ALTER TABLE `{$fullTable}` ADD INDEX `idx_{$field}` (`{$field}`)");
                echo "[" . date('Y-m-d H:i:s') . "] 自动创建索引: {$fullTable}.idx_{$field} (选择性: " . number_format($selectivity, 2) . ")\n";
            }
        }
    }

    protected function getMysqlType($value): string
    {
        if (is_bool($value)) return 'TINYINT(1)';
        if (is_int($value)) return 'INT';
        if (is_float($value)) return 'DECIMAL(10,2)';
        if (is_array($value) || is_object($value)) return 'JSON';
        return 'VARCHAR(255)';
    }
}