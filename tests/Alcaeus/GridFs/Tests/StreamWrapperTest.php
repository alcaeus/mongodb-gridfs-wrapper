<?php

namespace Alcaeus\GridFs\Tests;

use Alcaeus\GridFs\StreamWrapper;

/**
 * @author Voycer Development <dev@voycer.com>
 */
class StreamWrapperTest extends BaseTest
{
    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        StreamWrapper::register();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        StreamWrapper::unregister();

        parent::tearDown();
    }

    /**
     * Tests whether a simple file can be created
     */
    public function testCreateFile()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        $this->assertFalse(file_exists($filename));

        file_put_contents($filename, 'It works!');

        $this->assertTrue(file_exists($filename));
        $this->assertSame('It works!', file_get_contents($filename));
    }

    /**
     * Tests whether existing files can be overwritten
     */
    public function testOverwriteFile()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        file_put_contents($filename, 'It works!');
        file_put_contents($filename, 'It works wonderful!');

        $this->assertSame('It works wonderful!', file_get_contents($filename));
    }

    /**
     * Tests if an existing file can be unlinked
     */
    public function testUnlink()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        file_put_contents($filename, 'It works!');
        $this->assertTrue(file_exists($filename));

        $this->assertTrue(unlink($filename));

        clearstatcache();
        $this->assertFalse(file_exists($filename));
    }

    /**
     * Tests if trying to remove a non-existing file results in an error
     */
    public function testUnlinkNonExistingFile()
    {
        $this->assertFalse(unlink(static::$baseFile . '/fs/tmp.txt'));
    }

    /**
     * Tests touching a non-existing file to make sure it exists
     */
    public function testTouchNonExistingFile()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        $this->assertFalse(file_exists($filename));

        $this->assertTrue(touch($filename, 1, 2));

        clearstatcache();
        $this->assertTrue(file_exists($filename));

        $stat = stat($filename);
        $this->assertSame(1, $stat['mtime']);
        $this->assertSame(2, $stat['atime']);
    }

    /**
     * Tests touching an existing file to make sure times are updated
     */
    public function testTouchExistingFile()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';
        file_put_contents($filename, 'foo');

        $this->assertTrue(touch($filename, 1, 2));

        $stat = stat($filename);
        $this->assertSame(1, $stat['mtime']);
        $this->assertSame(2, $stat['atime']);
    }

    /**
     * Tests renaming a file to a different path
     */
    public function testRename()
    {
        $oldFilename = static::$baseFile . '/fs/tmp.txt';
        $newFilename = static::$baseFile . '/fs/test/tmp.txt';

        file_put_contents($oldFilename, 'It works!');

        $this->assertTrue(rename($oldFilename, $newFilename));

        $this->assertFalse(file_exists($oldFilename));
        $this->assertTrue(file_exists($newFilename));
    }

    /**
     * Tests renaming a file to a different host, database or bucket as this is
     * not supported at this time.
     *
     * @param string $newFilename The target filename
     *
     * @dataProvider dataRenameFailure
     */
    public function testRenameFailure($newFilename)
    {
        $oldFilename = static::$baseFile . '/fs/tmp.txt';

        file_put_contents($oldFilename, 'It works!');
        $this->assertFalse(rename($oldFilename, $newFilename));
    }

    /**
     * @return array
     */
    public static function dataRenameFailure()
    {
        $host = 'localhost';
        $db = 'alcaeus_gridfs';
        $bucket = 'fs';
        $filename = 'gridfs://%s/%s/%s/test.txt';

        return [
            'differentHost' => [sprintf($filename, '127.0.0.1', $db, $bucket)],
            'differentDb' => [sprintf($filename, $host, 'otherDb', $bucket)],
            'differentBucket' => [sprintf($filename, $host, $db, 'otherBucket')],
        ];
    }

    /**
     * Tests creating a file and reading from it using fopen, fwrite and fread
     */
    public function testFileMethods()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        $fp = fopen($filename, 'x+');
        fwrite($fp, 'foobar');
        fseek($fp, 0);
        $this->assertSame('foo', fread($fp, 3));
        $this->assertSame(3, ftell($fp));
        fwrite($fp, 'foo');
        fclose($fp);

        $this->assertSame('foofoo', file_get_contents($filename));
    }

    /**
     * Tests the behavior of fwrite when opening a file in read-only mode
     */
    public function testFwriteWhenReadOnly()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        touch($filename);
        $fp = fopen($filename, 'r');
        $this->assertSame(0, fwrite($fp, 'foo'));
        fclose($fp);

        $this->assertSame('', file_get_contents($filename));
    }

    /**
     * Tests the behavior of fread when opening a file in write-only mode
     */
    public function testFreadWhenWriteOnly()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';

        $fp = fopen($filename, 'w');
        $this->assertSame(3, fwrite($fp, 'foo'));
        fseek($fp, 0);
        $this->assertSame('', fread($fp, 3));
        fclose($fp);

        $this->assertSame('foo', file_get_contents($filename));
    }

    /**
     * Tests if opening a file in append mode always writes data at the end of
     * the file, regardless of stream position
     */
    public function testAppend()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';
        file_put_contents($filename, 'foo');

        $fp = fopen($filename, 'a+');
        fseek($fp, 0);
        fwrite($fp, 'bar');
        fclose($fp);

        $this->assertSame('foobar', file_get_contents($filename));
    }

    /**
     * Tests if opening a non-existant file in read mode results in an error
     */
    public function testReadNonExistingFile()
    {
        $filename = static::$baseFile . '/fs/tmp.txt';
        try {
            file_get_contents($filename);

            $this->fail('Expected read of non-existing file to fail');
        } catch (\PHPUnit_Framework_Error_Warning $e) {
            $this->assertContains('failed to open stream', $e->getMessage());
        }
    }
}
