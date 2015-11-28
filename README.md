# GridFS Stream Wrapper

[![Build Status](https://secure.travis-ci.org/alcaeus/mongodb-gridfs-wrapper.png?branch=master)](https://travis-ci.org/alcaeus/mongodb-gridfs-wrapper)

This library provides a PHP stream wrapper for GridFS files in MongoDB. This allows you to access files in GridFS with
accessible URLs:
```file_put_contents('gridfs://localhost/database/fs/foo.txt', 'Hello world!');```

This follows a simple structure:
```gridfs://<host>/<database>/<bucket>/<path>```

The wrapper will accept directory separators in the path, but since there is no directory support in GridFS there won't
be any real directories.

To use the stream wrapper, include this library in your composer dependencies:
```composer require alcaeus/mongodb-gridfs-wrapper:^1.0@dev```

Then, in your bootstrap process, register the stream wrapper:
```Alcaeus\GridFs\StreamWrapper::register()```

## Caveats

1. Stream options (blocking, timeouts, etc.) are not supported yet
2. The wrapper currently only works for servers that don't require authentication.
3. Directory iterators are not supported (yet)
4. File locking is not supported (yet)
5. When renaming files, database and bucket must remain the same (for now)
