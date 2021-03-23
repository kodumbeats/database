<?php

namespace Utopia\Database\Adapter;

use PDO;
use Exception;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use Utopia\Database\Adapter;

class SQLite extends Adapter
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var list 
     */
    protected $list = [];

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Create Database
     * 
     * @return bool
     */
    public function create(): bool
    {
        $name = $this->getNamespace();
        //TODO
    }

    /**
     * List Databases
     * 
     * @return array
     */
    public function list(): array
    {
        $name = $this->getNamespace();
        return $this->list;
    }

    /**
     * Delete Database
     * 
     * @return bool
     */
    public function delete(): bool
    {
        $name = $this->getNamespace();
        //TODO
    }

    /**
     * Create Collection
     * 
     * @param string $name
     * @return bool
     */
    public function createCollection(string $name): bool
    {
        $name = $this->filter($name).'_documents';

        return $this->getPDO()
            ->prepare("CREATE TABLE {$this->getNamespace()}.{$name}(
                _id     INT         PRIMARY KEY     NOT NULL,
                _uid    CHAR(13)                    NOT NULL
             );")
            ->execute();
    }

    /**
     * List Collections
     * 
     * @return array
     */
    public function listCollections(): array
    {
    }

    /**
     * Delete Collection
     * 
     * @param string $name
     * @return bool
     */
    public function deleteCollection(string $name): bool
    {
        $name = $this->filter($name).'_documents';

        return $this->getPDO()
            ->prepare("DROP TABLE {$this->getNamespace()}.{$name};")
            ->execute();
    }

    /**
     * @return PDO
     *
     * @throws Exception
     */
    protected function getPDO()
    {
        return $this->pdo;
    }
}
