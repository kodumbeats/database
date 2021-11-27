<?php

namespace Utopia\Database\Adapter;

use PDO;
use Exception;
use PDOException;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\QueryBuilder;
use Utopia\Database\Validator\Authorization;

class MariaDB extends Adapter
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param PDO $pdo
     */
    public function __construct($pdo)
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
        $builder = new QueryBuilder($this->getPDO());

        return $builder->createDatabase($name)->execute();
    }

    /**
     * Check if database exists
     *
     * @return bool
     */
    public function exists(): bool
    {
        $name = $this->getNamespace();

        $builder = new QueryBuilder($this->getPDO());
        $builder
            ->from('INFORMATION_SCHEMA.SCHEMATA', ['SCHEMA_NAME'])
            ->where('SCHEMA_NAME', Query::TYPE_EQUAL, $name)
            ->execute();

        /** @var array $document */
        $document = $builder->fetch();

        return (($document['SCHEMA_NAME'] ?? '') == $name);
    }

    /**
     * List Databases
     * 
     * @return array
     */
    public function list(): array
    {
        $list = [];
        return $list;
    }

    /**
     * Delete Database
     * 
     * @return bool
     */
    public function delete(): bool
    {
        $name = $this->getNamespace();
        $builder = new QueryBuilder($this->getPDO());

        return $builder->drop(QueryBuilder::TYPE_DATABASE, $name)->execute();
    }

    /**
     * Create Collection
     * 
     * @param string $name
     * @param Document[] $attributes (optional)
     * @param Document[] $indexes (optional)
     * @return bool
     */
    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $id = $this->filter($name);

        if (!empty($attributes) || !empty($indexes)) {
            foreach ($attributes as &$attribute) {
                $attrId = $attribute->getId();
                $attrType = $this->getSQLType($attribute->getAttribute('type'), $attribute->getAttribute('size', 0), $attribute->getAttribute('signed', true));

                if($attribute->getAttribute('array')) {
                    $attrType = 'LONGTEXT';
                }

                $attribute = "`{$attrId}` {$attrType}, ";
            }

            foreach ($indexes as &$index) {
                $indexId = $this->filter($index->getId()); 
                $indexType = $this->getSQLIndexType($index->getAttribute('type'));

                $indexAttributes = $index->getAttribute('attributes');
                foreach ($indexAttributes as $key => &$attribute) {
                    $indexLength = $index->getAttribute('lengths')[$key] ?? '';
                    $indexLength = (empty($indexLength)) ? '' : '('.(int)$indexLength.')';
                    $indexOrder = $index->getAttribute('orders')[$key] ?? '';
                    $indexAttribute = $this->filter($attribute);

                    if ($indexType === Database::INDEX_FULLTEXT) {
                        $indexOrder = '';
                    }

                    $attribute = "`{$indexAttribute}`{$indexLength} {$indexOrder}";
                }

                $index = "{$indexType} `{$indexId}` (" . \implode(", ", $indexAttributes) . " ),";
            }

            $this->getPDO()
                ->prepare("CREATE TABLE IF NOT EXISTS {$this->getNamespace()}.{$id} (
                    `_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                    `_uid` CHAR(255) NOT NULL,
                    `_read` " . $this->getTypeForReadPermission() . " NOT NULL,
                    `_write` TEXT NOT NULL,
                    " . \implode(' ', $attributes) . "
                    PRIMARY KEY (`_id`),
                    " . \implode(' ', $indexes) . "
                    UNIQUE KEY `_index1` (`_uid`)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
                ->execute();

        } else {
            $builder = new QueryBuilder($this->getPDO());
            $builder
                ->createTable("{$this->getNamespace()}.{$id}")
                ->group([
                    '`_id` int(11) unsigned NOT NULL AUTO_INCREMENT',
                    '`_uid` CHAR(255) NOT NULL',
                    '`_read` ' . $this->getTypeForReadPermission() . ' NOT NULL',
                    '`_write` TEXT NOT NULL',
                    'PRIMARY KEY (`_id`)',
                    'UNIQUE KEY `_index1` (`_uid`)'
                ])
                ->append("ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
                ->execute();
        }

        // Update $this->getIndexCount when adding another default index
        return $this->createIndex($id, '_index2', $this->getIndexTypeForReadPermission(), ['_read'], [], []);
    }

    /**
     * Delete Collection
     * 
     * @param string $id
     * @return bool
     */
    public function deleteCollection(string $id): bool
    {
        $id = $this->filter($id);
        $name = $this->getNamespace();
        $builder = new QueryBuilder($this->getPDO());

        return $builder->drop(QueryBuilder::TYPE_TABLE, "{$name}.{$id}")->execute();
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
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);
        $type = $this->getSQLType($type, $size, $signed);

        if($array) {
            $type = 'LONGTEXT';
        }

        $builder = new QueryBuilder($this->getPDO());

        return $builder
            ->alterTable("{$this->getNamespace()}.{$name}")
            ->addColumn($id, $type)
            ->execute();
    }

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
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        $builder = new QueryBuilder($this->getPDO());

        return $builder
            ->alterTable("{$this->getNamespace()}.{$name}")
            ->dropColumn($id)
            ->execute();
    }

    /**
     * Create Index
     * 
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param string[] $attributes
     * @param int[] $lengths
     * @param string[] $orders
     * 
     * @return bool
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        foreach($attributes as $i => &$attribute) {
            $length = $lengths[$i] ?? '';
            $length = (empty($length)) ? '' : '('.(int)$length.')';
            $order = $orders[$i] ?? '';
            $attribute = $this->filter($attribute);

            if($type === Database::INDEX_FULLTEXT ) {
                $order = '';
                $length = '';
            }

            $attribute = "`{$attribute}` {$length} {$order}";
        }

        $builder = new QueryBuilder($this->getPDO());
        return $builder
            ->createIndex($id, $type)
            ->on("{$this->getNamespace()}.{$name}")
            ->group($attributes)
            ->execute();
    }

    /**
     * Delete Index
     * 
     * @param string $collection
     * @param string $id
     * 
     * @return bool
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        $builder = new QueryBuilder($this->getPDO());

        return $builder
            ->alterTable("{$this->getNamespace()}.{$name}")
            ->dropIndex($id)
            ->execute();
    }

    /**
     * Get Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return Document
     */
    public function getDocument(string $collection, string $id): Document
    {
        $name = $this->filter($collection);

        $builder = new QueryBuilder($this->getPDO());
        $builder
            ->from("{$this->getNamespace()}.{$name}")
            ->where('_uid', Query::TYPE_EQUAL, $id)
            ->one()
            ->execute();

        /** @var array $document */
        $document = $builder->fetch();

        if(empty($document)) {
            return new Document([]);
        }

        $document['$id'] = $document['_uid'];
        $document['$internalId'] = $document['_id'];
        $document['$read'] = (isset($document['_read'])) ? json_decode($document['_read'], true) : [];
        $document['$write'] = (isset($document['_write'])) ? json_decode($document['_write'], true) : [];

        unset($document['_id']);
        unset($document['_uid']);
        unset($document['_read']);
        unset($document['_write']);

        return new Document($document);
    }

    /**
     * Create Document
     *
     * @param string $collection
     * @param Document $document
     *
     * @return Document
     */
    public function createDocument(string $collection, Document $document): Document
    {
        $attributes = $document->getAttributes();
        $name = $this->filter($collection);
        $columns = [];

        foreach ($attributes as $attribute => $value) {
            if(is_array($value)) { // arrays & objects should be saved as strings
                $value = json_encode($value);
            }

            $attribute = $this->filter($attribute);
            $value = (is_bool($value)) ? (int)$value : $value;
            $columns[$attribute] = $value;
        }

        $columns['_uid'] = $document->getId();
        $columns['_read'] = json_encode($document->getRead());
        $columns['_write'] = json_encode($document->getWrite());

        $builder = new QueryBuilder($this->getPDO());

        $builder
            ->insertInto("{$this->getNamespace()}.{$name}")
            ->set($columns)
            ->execute();

        return $document;
    }

    /**
     * Update Document
     *
     * @param string $collection
     * @param Document $document
     *
     * @return Document
     */
    public function updateDocument(string $collection, Document $document): Document
    {
        $attributes = $document->getAttributes();
        $name = $this->filter($collection);
        $columns = [];

        foreach ($attributes as $attribute => $value) {
            if(is_array($value)) { // arrays & objects should be saved as strings
                $value = json_encode($value);
            }

            $attribute = $this->filter($attribute);
            $value = (is_bool($value)) ? (int)$value : $value;
            $columns[$attribute] = $value;
        }

        $columns['_uid'] = $document->getId();
        $columns['_read'] = json_encode($document->getRead());
        $columns['_write'] = json_encode($document->getWrite());

        $builder = new QueryBuilder($this->getPDO());

        $builder
            ->update("{$this->getNamespace()}.{$name}")
            ->set($columns)
            ->where('_uid', Query::TYPE_EQUAL, $document->getId())
            ->execute();

        return $document;
    }

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        $name = $this->filter($collection);

        $builder = new QueryBuilder($this->getPDO());

        return $builder
            ->deleteFrom("{$this->getNamespace()}.{$name}")
            ->where('_uid', Query::TYPE_EQUAL, $id)
            ->one()
            ->execute();
    }

    /**
     * Find Documents
     *
     * Find data sets using chosen queries
     *
     * @param string $collection
     * @param array $queries
     * @param int $limit
     * @param int $offset
     * @param array $orderAttributes
     * @param array $orderTypes
     * @param array $cursor
     * @param string $cursorDirection
     *
     * @return array 
     * @throws Exception 
     * @throws PDOException 
     */
    public function find(string $collection, array $queries = [], int $limit = 25, int $offset = 0, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], string $cursorDirection = Database::CURSOR_AFTER): array
    {
        $name = $this->filter($collection);
        $roles = Authorization::getRoles();
        $where = ['1=1'];
        $orders = [];

        foreach($orderAttributes as $i => $attribute) {
            $attribute = $this->filter($attribute);
            $orderType = $this->filter($orderTypes[$i] ?? Database::ORDER_ASC);

            // Get most dominant/first order attribute
            if ($i === 0 && !empty($cursor)) {
                $orderOperatorInternalId = Query::TYPE_GREATER; // To preserve natural order
                $orderOperator = $orderType === Database::ORDER_DESC ? Query::TYPE_LESSER : Query::TYPE_GREATER;

                if ($cursorDirection === Database::CURSOR_BEFORE) {
                    $orderType = $orderType === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
                    $orderOperatorInternalId = $orderType === Database::ORDER_ASC ? Query::TYPE_LESSER : Query::TYPE_GREATER;
                    $orderOperator = $orderType === Database::ORDER_DESC ? Query::TYPE_LESSER : Query::TYPE_GREATER;
                }

                $where[] = "(
                        {$attribute} {$this->getSQLOperator($orderOperator)} :cursor 
                        OR (
                            {$attribute} = :cursor 
                            AND
                            _id {$this->getSQLOperator($orderOperatorInternalId)} {$cursor['$internalId']}
                        )
                    )";
            } else if ($cursorDirection === Database::CURSOR_BEFORE) {
                $orderType = $orderType === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
            }

            $orders[] = $attribute.' '.$orderType;
        }

        // Allow after pagination without any order
        if (empty($orderAttributes) && !empty($cursor)) {
            $orderType = $orderTypes[0] ?? Database::ORDER_ASC;
            $orderOperator = $cursorDirection === Database::CURSOR_AFTER ? (
                $orderType === Database::ORDER_DESC ? Query::TYPE_LESSER : Query::TYPE_GREATER
            ) : (
                $orderType === Database::ORDER_DESC ? Query::TYPE_GREATER : Query::TYPE_LESSER
            );
            $where[] = "( _id {$this->getSQLOperator($orderOperator)} {$cursor['$internalId']} )";
        }

        // Allow order type without any order attribute, fallback to the natural order (_id)
        if(empty($orderAttributes) && !empty($orderTypes)) {
            $order = $orderTypes[0] ?? Database::ORDER_ASC;
            if ($cursorDirection === Database::CURSOR_BEFORE) {
                $order = $order === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
            }

            $orders[] = '_id '.$this->filter($order);
        } else {
            $orders[] = '_id '.($cursorDirection === Database::CURSOR_AFTER ? Database::ORDER_ASC : Database::ORDER_DESC); // Enforce last ORDER by '_id'
        }

        $permissions = (Authorization::$status) ? $this->getSQLPermissions($roles) : '1=1'; // Disable join when no authorization required

        foreach($queries as $i => $query) {
            $conditions = [];
            foreach ($query->getValues() as $key => $value) {
                $conditions[] = $this->getSQLCondition('table_main.'.$query->getAttribute(), $query->getOperator(), ':attribute_'.$i.'_'.$key.'_'.$query->getAttribute(), $value);
            }
            $condition = implode(' OR ', $conditions);
            $where[] = empty($condition) ? '' : '('.$condition.')';
        }

        $order = 'ORDER BY '.implode(', ', $orders);

        $stmt = $this->getPDO()->prepare("SELECT table_main.* FROM {$this->getNamespace()}.{$name} table_main
            WHERE {$permissions} AND ".implode(' AND ', $where)."
            {$order}
            LIMIT :offset, :limit;
        ");

        foreach($queries as $i => $query) {
            if($query->getOperator() === Query::TYPE_SEARCH) continue;
            foreach($query->getValues() as $key => $value) {
                $stmt->bindValue(':attribute_'.$i.'_'.$key.'_'.$query->getAttribute(), $value, $this->getPDOType($value));
            }
        }

        if (!empty($cursor) && !empty($orderAttributes) && array_key_exists(0, $orderAttributes)) {
            $attribute = $orderAttributes[0];
            if (is_null($cursor[$attribute] ?? null)) {
                throw new Exception("Order attribute '{$attribute}' is empty.");
            }
            $stmt->bindValue(':cursor', $cursor[$attribute], $this->getPDOType($cursor[$attribute]));
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$value) {
            $value['$id'] = $value['_uid'];
            $value['$internalId'] = $value['_id'];
            $value['$read'] = (isset($value['_read'])) ? json_decode($value['_read'], true) : [];
            $value['$write'] = (isset($value['_write'])) ? json_decode($value['_write'], true) : [];
            unset($value['_uid']);
            unset($value['_id']);
            unset($value['_read']);
            unset($value['_write']);

            $value = new Document($value);
        }

        if ($cursorDirection === Database::CURSOR_BEFORE) {
            $results = array_reverse($results); //TODO: check impact on array_reverse
        }

        return $results;
    }

    /**
     * Count Documents
     *
     * Count data set size using chosen queries
     *
     * @param string $collection
     * @param Query[] $queries
     * @param int $max
     *
     * @return int
     */
    public function count(string $collection, array $queries = [], int $max = 0): int
    {
        $name = $this->filter($collection);
        $roles = Authorization::getRoles();

        $builder = new QueryBuilder($this->getPDO());
        $builder->from("{$this->getNamespace()}.{$name} table_main", [1]);

        if (Authorization::$status) { // Skip permission check when no authorization required
            $builder->whereMatch('table_main._read', $this->getPermissions($roles));
        } else {
            $builder->where(1, Query::TYPE_EQUAL, 1);
        }

        foreach ($queries as $query) {
            $count = \count($query->getValues());
            $builder->or(
                \array_fill(0, $count, 'table_main.' . $query->getAttribute()),
                \array_fill(0, $count, $query->getOperator()),
                $query->getValues(),
            );
        }

        if($max !== 0) {
            $builder->limit($max);
        }

        $builder
            ->count()
            ->execute()
        ;

        $result = $builder->fetch();

        return $result['sum'] ?? 0;
    }

    /**
     * Sum an Attribute
     *
     * Sum an attribute using chosen queries
     *
     * @param string $collection
     * @param string $attribute
     * @param Query[] $queries
     * @param int $max
     *
     * @return int|float
     */
    public function sum(string $collection, string $attribute, array $queries = [], int $max = 0)
    {
        $name = $this->filter($collection);
        $roles = Authorization::getRoles();

        $builder = new QueryBuilder($this->getPDO());
        $builder->from("{$this->getNamespace()}.{$name} table_main", [$attribute]);

        if (Authorization::$status) { // Skip permission check when no authorization required
            $builder->whereMatch('table_main._read', $this->getPermissions($roles));
        } else {
            $builder->where(1, Query::TYPE_EQUAL, 1);
        }

        foreach ($queries as $query) {
            $count = \count($query->getValues());
            $builder->or(
                \array_fill(0, $count, 'table_main.' . $query->getAttribute()),
                \array_fill(0, $count, $query->getOperator()),
                $query->getValues(),
            );
        }

        if($max !== 0) {
            $builder->limit($max);
        }

        $builder
            ->sum($attribute)
            ->execute()
        ;

        $result = $builder->fetch();

        return $result['sum'] ?? 0;
    }

    /**
     * Get max STRING limit
     * 
     * @return int
     */
    public function getStringLimit(): int
    {
        return 4294967295;
    }

    /**
     * Get max INT limit
     * 
     * @return int
     */
    public function getIntLimit(): int
    {
        return 4294967295;
    }

    /**
     * Is index supported?
     * 
     * @return bool
     */
    public function getSupportForIndex(): bool
    {
        return true;
    }

    /**
     * Is unique index supported?
     * 
     * @return bool
     */
    public function getSupportForUniqueIndex(): bool
    {
        return true;
    }

    /**
     * Is fulltext index supported?
     * 
     * @return bool
     */
    public function getSupportForFulltextIndex(): bool
    {
        return true;
    }

    /**
     * Get current index count from collection document
     * 
     * @param Document $collection
     * @return int
     */
    public function getIndexCount(Document $collection): int
    {
        $indexes = \count($collection->getAttribute('indexes') ?? []);
        return $indexes + static::getNumberOfDefaultIndexes();
    }

    /**
     * Get maximum index limit.
     * https://mariadb.com/kb/en/innodb-limitations/#limitations-on-schema
     * 
     * @return int
     */
    public function getIndexLimit(): int
    {
        return 64;
    }

    /**
     * Get current attribute count from collection document
     * 
     * @param Document $collection
     * @return int
     */
    public function getAttributeCount(Document $collection): int
    {
        $attributes = \count($collection->getAttribute('attributes') ?? []);

        // +1 ==> virtual columns count as total, so add as buffer
        return $attributes + static::getNumberOfDefaultAttributes() + 1;
    }

    /**
     * Get maximum column limit.
     * https://mariadb.com/kb/en/innodb-limitations/#limitations-on-schema
     * Can be inherited by MySQL since we utilize the InnoDB engine
     * 
     * @return int
     */
    public function getAttributeLimit(): int
    {
        return 1017;
    }

    /**
     * Get maximum width, in bytes, allowed for a SQL row
     * Return 0 when no restrictions apply
     *
     * @return int
     */
    public static function getRowLimit(): int
    {
        return 65535;
    }

    public static function getNumberOfDefaultAttributes(): int
    {
        return 4;
    }

    public static function getNumberOfDefaultIndexes(): int
    {
        return 3;
    }

    /**
     * Estimate maximum number of bytes required to store a document in $collection.
     * Byte requirement varies based on column type and size.
     * Needed to satisfy MariaDB/MySQL row width limit.
     * 
     * @param Document $collection
     * @return int
     */
    public function getAttributeWidth(Document $collection): int
    {
        // Default collection has:
        // `_id` int(11) => 4 bytes
        // `_uid` char(255) => 1020 (255 bytes * 4 for utf8mb4)
        // `_read` text => 98 bytes? (estimate)
        // `_write` text => 98 bytes? (estimate)
        // but this number seems to vary, so we give a +300 byte buffer
        $total = 1500;

        /** @var array $attributes */
        $attributes = $collection->getAttributes()['attributes'];
        foreach ($attributes as $attribute) {
            switch ($attribute['type']) {
                case Database::VAR_STRING:
                    switch (true) {
                        case ($attribute['size'] > 16777215):
                            // 8 bytes length + 4 bytes for LONGTEXT
                            $total += 12;
                        break;

                        case ($attribute['size'] > 65535):
                            // 8 bytes length + 3 bytes for MEDIUMTEXT
                            $total += 11;
                        break;

                        case ($attribute['size'] > 16383):
                            // 8 bytes length + 2 bytes for TEXT
                            $total += 10;
                        break;

                        case ($attribute['size'] > 255):
                            // $size = $size * 4; // utf8mb4 up to 4 bytes per char
                            // 8 bytes length + 2 bytes for VARCHAR(>255)
                            $total += ($attribute['size'] * 4) + 2;
                        break;

                        default:
                            // $size = $size * 4; // utf8mb4 up to 4 bytes per char
                            // 8 bytes length + 1 bytes for VARCHAR(<=255)
                            $total += ($attribute['size'] * 4) + 1;
                        break;
                    }
                break;

                case Database::VAR_INTEGER:
                case Database::VAR_FLOAT:
                    // INT takes 4 bytes
                    // FLOAT(p) takes 4 bytes when p <= 24, 8 otherwise
                    $total += 4;
                break;

                case Database::VAR_BOOLEAN:
                    // TINYINT(1) takes one byte
                    $total +=1;
                break;

                case Database::VAR_DOCUMENT:
                    // CHAR(255)
                    $total += 255;
                break;

                default:
                    throw new Exception('Unknown Type');
                break;
            }
        }

        return $total;
    }

    /**
     * Does the adapter handle casting?
     * 
     * @return bool
     */
    public function getSupportForCasting(): bool
    {
        return false;
    }

    /**
     * Returns the attribute type for read permissions
     *
     * @return string
     */
    protected function getTypeForReadPermission(): string
    {
        return "TEXT";
    }

    /**
     * Returns the index type for read permissions
     *
     * @return string
     */
    protected function getIndexTypeForReadPermission(): string
    {
        return Database::INDEX_FULLTEXT;
    }

    /**
     * Get SQL Type
     * 
     * @param string $type
     * @param int $size in chars
     * 
     * @return string
     */
    protected function getSQLType(string $type, int $size, bool $signed = true): string
    {
        switch ($type) {
            case Database::VAR_STRING:
                // $size = $size * 4; // Convert utf8mb4 size to bytes
                if($size > 16777215) {
                    return 'LONGTEXT';
                }

                if($size > 65535) {
                    return 'MEDIUMTEXT';
                }

                if($size > 16383) {
                    return 'TEXT';
                }

                return "VARCHAR({$size})";
            break;

            case Database::VAR_INTEGER:  // We don't support zerofill: https://stackoverflow.com/a/5634147/2299554
                $signed = ($signed) ? '' : ' UNSIGNED';
                return 'INT'.$signed;
            break;

            case Database::VAR_FLOAT:
                $signed = ($signed) ? '' : ' UNSIGNED';
                return 'FLOAT'.$signed;
            break;

            case Database::VAR_BOOLEAN:
                return 'TINYINT(1)';
            break;

            case Database::VAR_DOCUMENT:
                return 'CHAR(255)';
            break;

            default:
                throw new Exception('Unknown Type');
            break;
        }
    }

    /**
     * Get SQL Condtions
     * 
     * @param string $attribute
     * @param string $operator
     * @param string $placeholder
     * @param mixed $value
     * 
     * @return string
     */
    protected function getSQLCondition(string $attribute, string $operator, string $placeholder, $value): string
    {
        switch ($operator) {
            case Query::TYPE_SEARCH:
                return 'MATCH('.$attribute.') AGAINST('.$this->getPDO()->quote($value).')';
            break;

            default:
                return $attribute.' '.$this->getSQLOperator($operator).' '.$placeholder; // Using `attrubute_` to avoid conflicts with custom names;
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

    /**
     * Get SQL Index Type
     * 
     * @param string $type
     * 
     * @return string
     */
    protected function getSQLIndexType(string $type): string
    {
        switch ($type) {
            case Database::INDEX_KEY:
            case Database::INDEX_ARRAY:
                return 'INDEX';
            break;

            case Database::INDEX_UNIQUE:
                return 'UNIQUE INDEX';
            break;

            case Database::INDEX_FULLTEXT:
                return 'FULLTEXT INDEX';
            break;

            default:
                throw new Exception('Unknown Index Type:' . $type);
            break;
        }
    }

    /**
     * Get SQL Permissions
     * 
     * @param array $roles
     * @param string $operator
     * @param string $placeholder
     * @param mixed $value
     * 
     * @return string
     */
    protected function getSQLPermissions(array $roles): string
    {
        foreach($roles as &$role) { // Add surrounding quotes after escaping, use + as placeholder after getPDO()->quote()
            $role = "+".str_replace('+', ' ', $role)."+";
        }

        return "MATCH (table_main._read) AGAINST (".str_replace('+', '"', $this->getPDO()->quote(implode(' ', $roles)))." IN BOOLEAN MODE)";
    }

    protected function getPermissions(array $roles): string
    {
        foreach($roles as &$role) { // Add surrounding quotes after escaping, use + as placeholder after getPDO()->quote()
            $role = "+".str_replace('+', ' ', $role)."+";
        }

        return str_replace('+', '"', $this->getPDO()->quote(implode(' ', $roles)));
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
     * @return PDO
     *
     * @throws Exception
     */
    protected function getPDO()
    {
        return $this->pdo;
    }
}
