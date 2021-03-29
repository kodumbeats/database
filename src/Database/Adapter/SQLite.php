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
     * Create Attribute
     * 
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $array
     * 
     * @return bool
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false): bool
    {}

    /**
     * Delete Attribute
     * 
     * @param string $collection
     * @param string $id
     * @param bool $array
     * 
     * @return bool
     */
    public function deleteAttribute(string $collection, string $id, bool $array = false): bool
    {}

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array $attributes
     * @param array $lengths
     * @param array $orders
     *
     * @return bool
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders): bool
    {}

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteIndex(string $collection, string $id): bool
    {}

    /**
     * Get Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return array
     */
    public function getDocument(string $collection, string $id): Document
    {}

    /**
     * Create Document
     *
     * @param string $collection
     * @param Document $document
     *
     * @return Document
     */
    public function createDocument(string $collection, Document $document): Document
    {}

    /**
     * Get max STRING limit
     * 
     * @return int
     */
    public function getStringLimit(): int
    {}

    /**
     * Get max INT limit
     * 
     * @return int
     */
    public function getIntLimit(): int
    {}

    /**
     * Is index supported?
     * 
     * @return bool
     */
    public function getIndexSupport(): bool
    {}

    /**
     * Is unique index supported?
     * 
     * @return bool
     */
    public function getUniqueIndexSupport(): bool
    {}

    /**
     * Is fulltext index supported?
     * 
     * @return bool
     */
    public function getFulltextIndexSupport(): bool
    {}

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
