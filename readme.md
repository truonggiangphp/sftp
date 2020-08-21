# php-sftp

PHP SFTP Utilities
## Dependencies :

phpseclib : [Github](https://github.com/phpseclib/phpseclib) - [Documentation](https://api.phpseclib.org/master/) - [Examples](http://phpseclib.sourceforge.net/sftp/examples.html)

## Install

Install package with composer
```
composer require webikevn/sftp
```

In your PHP code, load library
```php
require_once __DIR__ . '/vendor/autoload.php';
use Webike\Sftp\Sftp as Sftp;
```

## Usage
```php
$service = app()->make(Sftp::class);
$service->login($server, $user, $password, $port = 22);
```
Test SFTP connection
```php
$service->test();
```

Check if a file exists on SFTP Server
```php
$service->isFile($remote_file);
```

Delete a file on remote FTP server
```php
$service->delete($remote_file);
```

Recursively deletes files and folder in given directory (If remote_path ends with a slash delete folder content otherwise delete folder itself)
```php
$service->rmdir($remote_path);
```

Recursively copy files and folders on remote SFTP server (If local_path ends with a slash upload folder content otherwise upload folder itself)
```php
$service->uploadDir($local_path, $remote_path);
```

Download a file from remote SFTP server
```php
$service->download($remote_file, $local_file);
```

Download a directory from remote FTP server (If remote_dir ends with a slash download folder content otherwise download folder itself)
```php
$service->downloadDir($remote_dir, $local_dir);
```

Rename a file on remote SFTP server
```php
$service->rename($old_file, $new_file);
```

Create a directory on remote SFTP server
```php
$service->mkdir($directory);
```

Create a file on remote SFTP server
```php
$service->touch($remote_file, $content);
```

Upload a file on SFTP server
```php
$service->upload($local_file, $remote_file = '');
```

List files on SFTP server
```php
$service->scandir($path);
```

Get default login SFTP directory aka pwd
```php
$service->pwd();
```

## To Do

PHPUnit Tests

## Author

Nguyen Truong Giang [visit my website ;)](https://www.linkedin.com/in/nguyentruonggiang91/)