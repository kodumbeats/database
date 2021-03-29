<?php

namespace Utopia\Tests\Adapter;

use PDO;
use Utopia\Database\Database;
use Utopia\Database\Adapter\SQLite;
use Utopia\Tests\Base;

class SQLiteTest extends Base
{
    /**
     * @var Database
     */
    static $database = null;

    /**
     * @reture Adapter
     */
    static function getDatabase(): Database
    {
        if(!is_null(self::$database)) {
            return self::$database;
        }

        $dbPath= './test.db';

        $pdo = new PDO('sqlite:'. $dbPath);

        // Connection settings
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // Return arrays
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

        $database = new Database(new SQLite($pdo));
        $database->setNamespace('myapp_'.uniqid());

        return self::$database = $database;
    }
}
