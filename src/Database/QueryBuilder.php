<?php

namespace Utopia\Database;

use Exception;
use PDO;
use PDOStatement;
use PDOException;
use Throwable;
use Utopia\Database\Exception\Duplicate;

class QueryBuilder
{
    const TYPE_DATABASE = 'DATABASE';
    const TYPE_TABLE = 'TABLE';

    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var PDOStatement
     */
    protected $statement;

    /**
     * @var string
     */
    protected $queryTemplate;

    /**
     * @var array
     */
    protected $params;

    /**
     * TODO@kodumbeats make PDO required
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo = null)
    {
        $this->reset();
        $this->pdo = $pdo;
    }

    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return PDOStatement
     */
    public function getStatement(): PDOStatement
    {
        return $this->statement;
    }

    /**
     * @return string
     */
    public function getTemplate(): string
    {
        return $this->queryTemplate;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param string $name
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function createDatabase(string $name): self
    {
        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }
        $this->queryTemplate = "CREATE DATABASE `{$name}` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;";

        return $this;
    }

    /**
     * @param string $name
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function createTable(string $name): self
    {
        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }
        $this->queryTemplate = "CREATE TABLE IF NOT EXISTS `{$name}`;";

        return $this;
    }


    /**
     * @param string $type one of DATABASE, TABLE
     * @param string $name
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function drop(string $type, string $name): self
    {
        //TODO@kodumbeats with PHP8.1, use enums
        if ($type !== self::TYPE_DATABASE && $type !== self::TYPE_TABLE) {
            throw new Exception('Invalid type');
        }

        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }

        $this->queryTemplate = "DROP {$type} {$name};";

        return $this;
    }

    /**
     * @param string $table
     * @param string[] $keys
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function from(string $table, array $keys = ['*']): self
    {
        foreach ($keys as &$key) {
            if ($key !== '*') {
                $key = '`'.$this->filter($key).'`';
            }
        }

        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }

        $keys = \implode(", ", $keys);
        $this->queryTemplate = "SELECT {$keys} FROM {$table};";

        return $this;
    }

    /**
     * @param string $table
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function deleteFrom(string $table): self
    {
        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }

        $this->queryTemplate = "DELETE FROM {$table};";

        return $this;
    }

    /**
     * @param string $key
     * @param string $type
     *
     * @return QueryBuilder
     */
    public function addColumn($key, $type): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $this->queryTemplate .= " ADD COLUMN `{$key}` {$type};";

        return $this;
    }

    /**
     * @param string $key
     *
     * @return QueryBuilder
     */
    public function dropColumn($key): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $this->queryTemplate .= " DROP COLUMN `{$key}`;";

        return $this;
    }

    /**
     * @param string $key
     *
     * @return QueryBuilder
     */
    public function dropIndex($key): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $this->queryTemplate .= " DROP INDEX `{$key}`;";

        return $this;
    }

    /**
     * @param string $table
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function insertInto(string $table): self
    {
        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }

        $this->queryTemplate = "INSERT INTO {$table};";

        return $this;
    }

    /**
     * @param string $table
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function alterTable(string $table): self
    {
        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }

        $this->queryTemplate = "ALTER TABLE {$table};";

        return $this;
    }

    /**
     * @param string $table
     *
     * @throws Exception
     * @return QueryBuilder
     */
    public function update(string $table): self
    {
        if ($this->statement) {
            throw new Exception('Multiple statements detected - not supported yet');
        }

        $this->queryTemplate = "UPDATE {$table};";

        return $this;
    }

    /**
     * @param array $values assoc array of columns to update with their values
     *
     * @return QueryBuilder
     */
    public function set(array $values): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $this->queryTemplate .= " SET ";

        foreach ($values as $key => $value) {
            $key = $this->filter($key);
            $this->queryTemplate .= "`{$key}` = :{$key},";
            $this->params[":{$key}"] = $value;
        }

        // replace trailing comma with semicolon
        if (\mb_substr($this->getTemplate(), -1) === ',') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1) . ';';
        }

        return $this;
    }

    /**
     * @param string $key
     * @param string $condition
     * @param string $value
     *
     * @return QueryBuilder
     */
    public function where($key, $condition, $value): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $count = \count($this->getParams());

        $key = $this->filter($key);
        $condition = $this->getSQLOperator($condition);
        $this->queryTemplate .= " WHERE {$key} {$condition} :value{$count};";
        $this->params[":value{$count}"] = $value;

        return $this;
    }

    /**
     * @param int $limit
     *
     * @return QueryBuilder
     */
    public function limit(int $limit): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $this->queryTemplate .= " LIMIT {$limit};";

        return $this;
    }
    /**
     * @return QueryBuilder
     */
    public function one(): self
    {
        // strip trailing semicolon if present
        if (\mb_substr($this->getTemplate(), -1) === ';') {
            $this->queryTemplate = \mb_substr($this->getTemplate(), 0, -1);
        }

        $this->queryTemplate .= " LIMIT 1;";

        return $this;
    }

    /**
     * @throws Exception
     * @return PDOStatement
     */
    public function execute(): bool
    {
        $this->getPDO()->beginTransaction();

        $this->statement = $this->getPDO()->prepare($this->getTemplate());

        foreach ($this->getParams() as $key => $value) {
            $this->getStatement()->bindValue($key, $value, $this->getPDOType($value));
        }

        try {
            $this->getStatement()->execute();
            if(!$this->getPDO()->commit()) {
                throw new Exception('Failed to commit transaction');
            }
            return true;
        } catch (PDOException $e) {
            switch ($e->getCode()) {
                case 1062:
                case 23000:
                    $this->getPDO()->rollBack();
                    throw new Duplicate('Duplicated document: '.$e->getMessage());
                    break;
                default:
                    throw new Exception ($e->getMessage());;
                    break;
            }
        } catch (Throwable $th) {
            $this->getPDO()->rollBack();
            throw new Exception($th->getMessage());
        }
    }

    public function reset()
    {
        $this->queryTemplate = '';
        $this->params = [];
        $this->limit = null;
    }

    /**
     * Filter Keys
     * 
     * @throws Exception
     * @return string
     */
    public function filter(string $value): string
    {
        $value = preg_replace("/[^A-Za-z0-9]_/", '', $value);

        if(\is_null($value)) {
            throw new Exception('Failed to filter key');
        }

        return $value;
    }

    /**
     * Get PDO Type
     *
     * @param mixed $value
     *
     * @return int
     */
    protected function getPDOType($value): int
    {
        switch (gettype($value)) {
            case 'string':
                return PDO::PARAM_STR;
            break;

            case 'boolean':
                return PDO::PARAM_INT;
            break;

            //case 'float': // (for historical reasons "double" is returned in case of a float, and not simply "float")
            case 'double':
                return PDO::PARAM_STR;
            break;

            case 'integer':
                return PDO::PARAM_INT;
            break;

            case 'NULL':
                return PDO::PARAM_NULL;
            break;

            default:
                throw new Exception('Unknown PDO Type for ' . gettype($value));
            break;
        }
    }

    /**
     * Get SQL Operator
     *
     * @param string $operator
     *
     * @return string
     */
    protected function getSQLOperator(string $operator): string
    {
        switch ($operator) {
            case Query::TYPE_EQUAL:
                return '=';
            break;

            case Query::TYPE_NOTEQUAL:
                return '!=';
            break;

            case Query::TYPE_LESSER:
                return '<';
            break;

            case Query::TYPE_LESSEREQUAL:
                return '<=';
            break;

            case Query::TYPE_GREATER:
                return '>';
            break;

            case Query::TYPE_GREATEREQUAL:
                return '>=';
            break;

            default:
                throw new Exception('Unknown Operator:' . $operator);
            break;
        }
    }
}
