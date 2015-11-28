<?php

namespace Alcaeus\GridFs\Tests;

use PHPUnit_Framework_TestCase;

abstract class BaseTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var string
     */
    protected static $dbName = 'alcaeus_gridfs';

    /**
     * @var string
     */
    protected static $baseFile = 'gridfs://localhost/alcaeus_gridfs';

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        $client = new \MongoClient();
        $client->selectDB(static::$dbName)->drop();
    }
}
