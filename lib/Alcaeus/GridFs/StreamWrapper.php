<?php

namespace Alcaeus\GridFs;

/**
 * Handler class to access GridFS files using the gridfs:// protocol
 * Some of this code was shamelessly inspired by vfsStream: https://github.com/mikey179/vfsStream/
 *
 * @author alcaeus <alcaeus@alcaeus.org>
 */
class StreamWrapper
{
    /**
     * @const string
     */
    const SCHEME = 'gridfs';

    /**
     * Reading only
     */
    const READ = 'r';

    /**
     * Truncate file length to 0
     */
    const TRUNCATE = 'w';

    /**
     * File pointer at end of file, append new data
     */
    const APPEND = 'a';

    /**
     * File pointer to beginning of file, overwrite existing data
     */
    const WRITE = 'x';

    /**
     * Set file pointer to start of file, overwrite existing data; or create
     * file if does not exist
     */
    const WRITE_NEW = 'c';

    /**
     * Mode: read
     */
    const READONLY = 0;

    /**
     * Mode: write
     */
    const WRITEONLY = 1;

    /**
     * Mode: read and write
     */
    const ALL = 2;

    /**
     * @var resource
     */
    public $context;

    /**
     * @var bool
     */
    protected static $registered = false;

    /**
     * @var string
     */
    protected $currentBucket;

    /**
     * @var string
     */
    protected $openedPath;

    /**
     * @var resource
     */
    protected $fp;

    /**
     * @var \MongoGridFsFile|null
     */
    protected $openedFile;

    /**
     * @var int
     */
    protected $mode;

    /**
     * @var int
     */
    protected $writeMode;

    /**
     * @var Writer\MongoDb
     */
    protected $writer;

    /**
     * @var null|array
     */
    protected $dirContents = null;

    /**
     * Registers the class as handler for the gridfs URL wrapper.
     *
     * @throws Exception\StreamException if the handler could not be registered as stream wrapper
     */
    public static function register()
    {
        if (self::$registered === true) {
            return;
        }

        if (@stream_wrapper_register(static::SCHEME, __CLASS__) === false) {
            throw new Exception\StreamException('A handler has already been registered for the ' . static::SCHEME . ' protocol.');
        }

        self::$registered = true;
    }

    /**
     * Unregisters a previously registered wrapper.
     *
     * If this stream wrapper wasn't registered, the method returns silently.
     *
     * @throws Exception\StreamException
     */
    public static function unregister()
    {
        if (! self::$registered) {
            return;
        }

        if (! @stream_wrapper_unregister(static::SCHEME)) {
            throw new Exception\StreamException('The URL wrapper for the protocol ' . static::SCHEME . ' could not be unregistered.');
        }

        self::$registered = false;
    }

    /**
     * Close an open directory handle
     *
     * @see https://secure.php.net/manual/en/streamwrapper.dir-closedir.php
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function dir_closedir()
    {
        return false;
    }

    /**
     * Open directory handle
     *
     * @see https://secure.php.net/manual/en/streamwrapper.dir-opendir.php
     *
     * @param string $path Specifies the URL that was passed to opendir().
     * @param int $options Whether or not to enforce safe_mode (0x04).
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function dir_opendir($path, $options)
    {
        return false;
    }

    /**
     * Read entry from directory handle
     *
     * @see https://secure.php.net/manual/en/streamwrapper.dir-readdir.php
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function dir_readdir()
    {
        return false;
    }

    /**
     * Rewind directory handle
     *
     * @see https://secure.php.net/manual/en/streamwrapper.dir-rewinddir.php
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function dir_rewinddir()
    {
        return false;
    }

    /**
     * Renames a file or directory
     *
     * @see https://secure.php.net/manual/en/streamwrapper.rename.php
     *
     * @param string $oldPath The URL to the current file.
     * @param string $newPath The URL which the oldPath should be renamed to.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function rename($oldPath, $newPath)
    {
        $oldPathInfo = $this->parsePath($oldPath);
        $newPathInfo = $this->parsePath($newPath);
        if (! $oldPathInfo || ! $newPathInfo) {
            return false;
        }

        if (
            $oldPathInfo->host !== $newPathInfo->host ||
            $oldPathInfo->db !== $newPathInfo->db ||
            $oldPathInfo->bucket !== $newPathInfo->bucket
        ) {
            return false;
        }

        $writer = new Writer\MongoDb($oldPathInfo->host, $oldPathInfo->db);
        return $writer->rename($oldPathInfo->bucket, $oldPathInfo->path, $newPathInfo->path);
    }

    /**
     * Retrieve the underlaying resource
     *
     * Note that this method always returns false as there is no underlying resource to return
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-cast.php
     *
     * @param int $cast_as Can be STREAM_CAST_FOR_SELECT when stream_select() is calling stream_cast() or STREAM_CAST_AS_STREAM when stream_cast() is called for other uses.
     *
     * @return bool Always false
     */
    public function stream_cast($cast_as)
    {
        return false;
    }

    /**
     * Close a resource
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-close.php
     */
    public function stream_close()
    {
        fclose($this->fp);
    }

    /**
     * Tests for end-of-file on a file pointer
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-eof.php
     *
     * @return bool Returns TRUE if the read/write position is at the end of the stream and if no more data is available to be read, or FALSE otherwise.
     */
    public function stream_eof()
    {
        return feof($this->fp);
    }

    /**
     * Flushes the output
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-flush.php
     *
     * @return bool Returns TRUE if the cached data was successfully stored (or if there was no data to store), or FALSE if the data could not be stored.
     */
    public function stream_flush()
    {
        $read = function () {
            $stat = fstat($this->fp);
            return $stat['size'] > 0 ? fread($this->fp, $stat['size']) : '';
        };

        $data = $this->executeWithSeek($read, 0);

        $this->writer->write($this->currentBucket, $this->openedPath, $data);
    }

    /**
     * Advisory file locking
     *
     * @see https://php.net/manual/en/streamwrapper.stream-lock.php
     *
     * @param int $operation
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function stream_lock($operation)
    {
        trigger_error('Locking is not supported for gridfs:// stream wrapper.', E_WARNING);

        return false;
    }

    /**
     * Change stream options
     *
     * @see https://php.net/manual/en/streamwrapper.stream-metadata.php
     *
     * @param string $path The file path or URL to set metadata.
     * @param int $option The option to be set
     * @param mixed $value The option value to be set
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function stream_metadata($path, $option, $value)
    {
        if ($option !== STREAM_META_TOUCH) {
            return false;
        }

        if (! $pathInfo = $this->parsePath($path)) {
            return false;
        }

        $writer = new Writer\MongoDb($pathInfo->host, $pathInfo->db);

        return call_user_func_array([$writer, 'touch'], array_merge([$pathInfo->bucket, $pathInfo->path], $value));
    }

    /**
     * Opens file or URL
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-open.php
     *
     * @param string $path Specifies the URL that was passed to the original function.
     * @param string $mode The mode used to open the file.
     * @param int $options Holds additional flags set by the streams API.
     * @param string $openedPath If the path is opened successfully, and STREAM_USE_PATH is set in options, opened_path should be set to the full path of the file/resource that was actually opened.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function stream_open($path, $mode, $options, &$openedPath)
    {
        $pathInfo = $this->parsePath($path);
        if (! $pathInfo) {
            if ($this->isErrorReportingEnabled($options)) {
                trigger_error('Illegal file name ' . $path . ' given', E_USER_WARNING);
            }

            return false;
        }

        $extended = strstr($mode, '+') !== false;
        $mode = str_replace(['t', 'b', '+'], '', $mode);

        if (in_array($mode, ['r', 'w', 'a', 'x', 'c']) === false) {
            if ($this->isErrorReportingEnabled($options)) {
                trigger_error('Illegal mode ' . $mode . ', use r, w, a, x  or c, flavoured with t, b and/or +', E_USER_WARNING);
            }

            return false;
        }

        $this->currentBucket = $pathInfo->bucket;
        $this->openedPath = $pathInfo->path;
        $this->mode = $mode;
        $this->writeMode = $this->calculateMode($mode, $extended);
        $this->writer = new Writer\MongoDb($pathInfo->host, $pathInfo->db);
        $this->openedFile = $this->writer->read($this->currentBucket, $this->openedPath);

        if ($this->openedFile === null && $mode == static::READ) {
            if ($this->isErrorReportingEnabled($options)) {
                trigger_error('Failed to open stream: No such file', E_USER_WARNING);
            }

            return false;
        }

        $this->fp = fopen('php://temp', 'w+', false, $this->context);

        // Load existing data into file if we need it
        if ($mode !== static::TRUNCATE && $this->openedFile !== null) {
            try {
                stream_copy_to_stream($this->openedFile->getResource(), $this->fp);
            } catch (\MongoGridFSException $e) {
                if ($this->isErrorReportingEnabled($options)) {
                    trigger_error('Could not read file: ' . $path, E_USER_WARNING);
                }

                return false;
            }

            // Seek to beginning of file if we're not appending data
            if ($mode !== static::APPEND) {
                fseek($this->fp, 0);
            }
        }

        if (($options & STREAM_USE_PATH) == STREAM_USE_PATH) {
            $openedPath = static::SCHEME . '://' . implode('/', [$pathInfo->host, $pathInfo->db, $this->currentBucket, $this->openedPath]);
        }

        return true;
    }

    /**
     * Read from stream
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-read.php
     *
     * @param int $count How many bytes of data from the current position should be returned.
     *
     * @return string Returns the selected amount of bytes read from the file. If no more data is available it returns FALSE.
     */
    public function stream_read($count)
    {
        if ($this->writeMode === static::WRITEONLY) {
            return '';
        }

        return fread($this->fp, $count);
    }

    /**
     * Seeks to specific location in a stream
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-seek.php
     *
     * @param int $offset The stream offset to seek to.
     * @param int $whence One of SEEK_SET, SEEK_CUR or SEEK_END
     *
     * @return bool Return TRUE if the position was updated, FALSE otherwise.
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->fp, $offset, $whence) === 0;
    }

    /**
     * Change stream options
     *
     * Note that this method always returns false as options are not yet supported
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-set-option.php
     *
     * @param int $option The option to be set
     * @param mixed $arg1 The first option value to be set
     * @param mixed $arg2 The second option value to be set
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    /**
     * Retrieve information about a file resource
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-stat.php
     *
     * @return array Returns information about the file
     */
    public function stream_stat()
    {
        return $this->updateStatData(fstat($this->fp), $this->openedFile);
    }

    /**
     * Retrieve the current position of a stream
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-tell.php
     *
     * @return int Returns the current position of the stream.
     */
    public function stream_tell()
    {
        return ftell($this->fp);
    }

    /**
     * Truncate stream
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-truncate.php
     *
     * @param int $newSize The new size.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function stream_truncate($newSize)
    {
        return ftruncate($this->fp, $newSize);
    }

    /**
     * Write to stream
     *
     * @see https://secure.php.net/manual/en/streamwrapper.stream-write.php
     *
     * @param string $data The data to be stored into the underlying stream.
     *
     * @return int Returns the number of bytes that were successfully stored, or 0 if none could be stored.
     */
    public function stream_write($data)
    {
        if ($this->writeMode === static::READONLY) {
            return 0;
        }

        $write = function () use ($data) {
            fwrite($this->fp, $data);
        };

        if ($this->mode === static::APPEND) {
            $this->executeWithSeek($write, 0, SEEK_END);
        } else {
            $write();
        }

        return strlen($data);
    }

    /**
     * Delete a file
     *
     * @see https://secure.php.net/manual/en/streamwrapper.unlink.php
     *
     * @param string $path The file URL which should be deleted.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function unlink($path)
    {
        $pathInfo = $this->parsePath($path);
        if (! $pathInfo) {
            return false;
        }

        $writer = new Writer\MongoDb($pathInfo->host, $pathInfo->db);
        return $writer->remove($pathInfo->bucket, $pathInfo->path);
    }

    /**
     * Retrieve information about a file
     *
     * @param string $path The file path or URL to stat.
     * @param int $flags Holds additional flags set by the streams API.
     *
     * @return array|bool Returns information about the file or FALSE if the file does not exist.
     */
    public function url_stat($path, $flags)
    {
        $pathInfo = $this->parsePath($path);
        if (! $pathInfo) {
            return false;
        }

        $writer = new Writer\MongoDb($pathInfo->host, $pathInfo->db);
        $file = $writer->read($pathInfo->bucket, $pathInfo->path);

        if ($file === null) {
            if (($flags & STREAM_URL_STAT_QUIET) != STREAM_URL_STAT_QUIET) {
                trigger_error('No such file or directory: ' . $path, E_USER_WARNING);
            }

            return false;
        }

        $stat = fstat($file->getResource());

        return $this->updateStatData($stat, $file);
    }

    /**
     * Parses a path and stores the parts found
     *
     * @param string $path
     *
     * @return \stdClass|bool An object containing path info or false if the path could not be parsed.
     */
    protected function parsePath($path)
    {
        $pathInfo = new \stdClass();

        $parts = parse_url($path);
        if ($parts['scheme'] !== static::SCHEME) {
            return false;
        }

        $pathInfo->host = $parts['host'];
        if (isset($parts['port'])) {
            $pathInfo->host .= ':' . $parts['port'];
        }

        if (! isset($parts['path'])) {
            return false;
        }
        $pathParts = explode('/', ltrim($parts['path'], '/'));
        if (count($pathParts) < 3) {
            return false;
        }

        $pathInfo->db = array_shift($pathParts);
        $pathInfo->bucket = array_shift($pathParts);
        $pathInfo->path = $this->resolvePath($pathParts);

        if ($pathInfo->path !== '') {
            return $pathInfo;
        }

        return false;
    }

    /**
     * Resolves an array of path parts
     *
     * @param array $pathParts
     *
     * @return string
     */
    protected function resolvePath(array $pathParts)
    {
        $newPath  = array();
        foreach ($pathParts as $pathPart) {
            switch ($pathPart) {
                case '':
                case '.':
                    break;

                case '..':
                    if (count($newPath) > 1) {
                        array_pop($newPath);
                    }
                    break;

                default:
                    $newPath[] = $pathPart;
            }
        }

        return implode('/', $newPath);
    }

    /**
     * Calculates the file mode to see if the file can be written to or read from
     *
     * @param string $mode
     * @param bool $extended
     *
     * @return int
     */
    protected function calculateMode($mode, $extended)
    {
        if ($extended === true) {
            return static::ALL;
        }

        if ($mode === static::READ) {
            return static::READONLY;
        }

        return static::WRITEONLY;
    }

    /**
     * @param int $options
     *
     * @return bool
     */
    protected function isErrorReportingEnabled($options)
    {
        return ($options & STREAM_REPORT_ERRORS) === STREAM_REPORT_ERRORS;
    }

    /**
     * Executes a closure and restores the file pointer to its current position
     *
     * @param \Closure $closure
     * @param int $seek
     * @param int $whence
     *
     * @return mixed
     */
    protected function executeWithSeek(\Closure $closure, $seek, $whence = SEEK_SET)
    {
        $position = ftell($this->fp);
        fseek($this->fp, $seek, $whence);
        $result = $closure();
        fseek($this->fp, $position, SEEK_SET);

        return $result;
    }

    /**
     * Updates stat data with actual metadata about the file
     *
     * @param array $stat
     * @param \MongoGridFSFile $file
     *
     * @return array
     */
    protected function updateStatData(array $stat, \MongoGridFSFile $file)
    {
        if ($file === null) {
            return $stat;
        }

        if (isset($file->file['metadata']['atime']) && $file->file['metadata']['atime'] instanceof \MongoDate) {
            $stat['atime'] = $file->file['metadata']['atime']->sec;
            $stat[8] = $stat['atime'];
        }

        if (isset($file->file['metadata']['mtime']) && $file->file['metadata']['mtime'] instanceof \MongoDate) {
            $stat['mtime'] = $file->file['metadata']['mtime']->sec;
            $stat[9] = $stat['mtime'];
        }

        return $stat;
    }
}
