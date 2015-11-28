<?php

namespace Alcaeus\GridFs\Writer;

class MongoDb
{
    /**
     * @var string
     */
    protected $host;

    /**
     * @var string
     */
    protected $db;

    /**
     * @var \MongoClient
     */
    protected $client;

    /**
     * @param string $host
     * @param string $db
     */
    public function __construct($host, $db)
    {
        $this->host = $host;
        $this->db = $db;

        $this->client = new \MongoClient($host);
        $this->client->selectDB($db);
    }

    /**
     * Writes data to a file. If the filename already exists, the data is overwritten
     *
     * @param string $bucket
     * @param string $filename
     * @param string $data
     *
     * @return mixed
     */
    public function write($bucket, $filename, $data)
    {
        $fields = [
            'filename' => $filename,
            'metadata' => [
                'ctime' => new \MongoDate(),
                'mtime' => new \MongoDate(),
                'atime' => new \MongoDate(),
            ]
        ];

        $newId = $this->getGridFs($bucket)->storeBytes($data, $fields);
        $this->remove($bucket, $filename, $newId);

        return $this->getGridFs($bucket)->findOne(['_id' => $newId]);
    }

    /**
     * Returns a file from GridFS.
     *
     * @param string $bucket
     * @param string $filename
     *
     * @return \MongoGridFSFile|null
     */
    public function read($bucket, $filename)
    {
        $cursor = $this
            ->getGridFs($bucket)
            ->find(['filename' => $filename])
            ->sort(['mtime' => -1]);

        $file = $cursor->getNext();
        $cursor->reset();

        // Set access time
        if ($file !== null) {

        }

        return $file;
    }

    /**
     * Removes a file from GridFS
     *
     * @param string $bucket
     * @param string $filename
     * @param \MongoId $excludedId
     *
     * @return bool
     */
    public function remove($bucket, $filename, \MongoId $excludedId = null)
    {
        $criteria = ['filename' => $filename];
        if ($excludedId !== null) {
            $criteria['_id'] = ['$ne' => $excludedId];
        }

        $remove = $this->getGridFs($bucket)->remove($criteria, ['w' => 1]);
        $filesRemoved = (isset($remove['n'])) ? $remove['n'] : 0;

        return (isset($remove['err'])) ? false : ($filesRemoved > 0);
    }

    /**
     * Renames a file in this bucket
     *
     * @param string $bucket
     * @param string $oldFilename
     * @param string $newFilename
     * @return bool
     */
    public function rename($bucket, $oldFilename, $newFilename)
    {
        $oldFile = $this->read($bucket, $oldFilename);
        if (! $oldFile) {
            return false;
        }

        $this
            ->getGridFs($bucket)
            ->update(
                ['_id' => $oldFile->file['_id']],
                ['$set' => ['filename' => $newFilename, 'metadata.mtime' => new \MongoDate(), 'metadata.atime' => new \MongoDate()]]
            );

        $this->remove($bucket, $newFilename, $oldFile->file['_id']);

        return true;
    }

    /**
     * Sets access and modification time of a file. If the file does not exist, it will be created.
     *
     * @param string $bucket
     * @param string $filename
     * @param int|null $time The touch time. If time is not supplied, the current system time is used.
     * @param int|null $atime If present, the access time of the given filename is set to the value of atime. Otherwise, it is set to the value passed to the time parameter. If neither are present, the current system time is used.
     *
     * @return bool
     */
    public function touch($bucket, $filename, $time = null, $atime = null)
    {
        if ($time === null) {
            $time = time();
        }

        if ($atime === null) {
            $atime = $time;
        }

        $file = $this->read($bucket, $filename);

        // File does not exists, attempt to create it
        if ($file === null && ! $file = $this->write($bucket, $filename, '')) {
            return false;
        }

        $this
            ->getGridFs($bucket)
            ->update(
                ['_id' => $file->file['_id']],
                ['$set' => ['metadata.mtime' => new \MongoDate($time), 'metadata.atime' => new \MongoDate($atime)]]
            );

        return true;
    }

    /**
     * @param $bucket
     *
     * @return \MongoGridFS
     */
    protected function getGridFs($bucket)
    {
        return $this->client->selectDB($this->db)->getGridFS($bucket);
    }
}
