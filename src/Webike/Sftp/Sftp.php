<?php

namespace Webike\Sftp;

use Exception;
use Webike\Exceptions\DeleteFileException;
use Webike\Exceptions\DownloadAllException;
use Webike\Exceptions\DownloadDirException;
use Webike\Exceptions\FileException;
use Webike\Exceptions\LoginException;
use Webike\Exceptions\RemoveDirException;
use Webike\Exceptions\UploadAllException;
use Webike\Exceptions\UploadDirException;
use Webike\WString\WString as WString;
use phpseclib\Net\SFTP as SecFtp;

class Sftp
{
    /** @var SecFtp */
    protected $sftp = false;

    /**
     * Login to SFTP server
     *
     * @param string $server
     * @param string $user
     * @param string $password
     * @param int $port
     * @return self
     * @throws LoginException
     */
    public function login($server, $user, $password, $port = 22)
    {
        try {
            $this->sftp = new SecFtp($server, $port);
            if (!$this->sftp->login($user, $password)) {
                $this->sftp = false;
            }
        } catch (Exception $e) {
            throw new LoginException($e);
        }

        return $this;
    }

    /**
     * Test SFTP connection
     *
     * @param string $server
     * @param string $user
     * @param string $password
     *
     * @param int $port
     * @return bool
     * @throws LoginException
     */
    public function test($server, $user, $password, $port = 22)
    {
        $this->login($server, $user, $password, $port);
        return $this->sftp;
    }

    /**
     * Check if a file exists on SFTP Server
     *
     * @param string $remoteFile
     * @return bool $is_file
     * @throws FileException
     */
    public function isFile($remoteFile)
    {
        try {
            return $this->sftp->is_file($remoteFile);
        } catch (Exception $e) {
            throw new FileException($e);
        }
    }

    /**
     * Delete a file on remote SFTP server
     *
     * @param $remoteFile
     * @return bool $deleted
     * @throws DeleteFileException
     */
    public function delete($remoteFile)
    {
        $deleted = false;

        try {
            if ($this->sftp->isFile($remoteFile)) {
                $deleted = $this->sftp->delete($remoteFile);
            }
        } catch (Exception $e) {
            throw new DeleteFileException($e);
        }

        return $deleted;
    }

    /**
     * Recursively deletes files and folder in given directory
     *
     * If remote_path ends with a slash delete folder content
     * otherwise delete folder itself
     *
     * @param $remotePath
     * @return bool $deleted
     * @throws RemoveDirException
     */
    public function rmdir($remotePath)
    {
        $deleted = false;

        try {
            # Delete directory content
            if (Sftp::cleanDir($remotePath, $this->sftp)) {
                # If remote_path do not ends with /
                if (!WString::endsWith($remotePath, '/')) {
                    # Delete directory itself
                    if ($this->sftp->rmdir($remotePath)) {
                        $deleted = true;
                    }
                } else {
                    $deleted = true;
                }
            }
        } catch (Exception $e) {
            throw new RemoveDirException($e);
        }

        return $deleted;
    }

    /**
     * Recursively deletes files and folder
     *
     * @param $remotePath
     * @param resource $sftp
     *
     * @return bool $clean
     */
    private static function cleanDir($remotePath, $sftp)
    {
        $clean = false;

        $toDelete = 0;
        $deleted = 0;

        $list = $sftp->nlist($remotePath);
        foreach ($list as $element) {
            if ($element !== '.' && $element !== '..') {
                $toDelete++;

                if ($sftp->is_dir($remotePath . DIRECTORY_SEPARATOR . $element)) {
                    # Empty directory
                    Sftp::cleanDir($remotePath . DIRECTORY_SEPARATOR . $element, $sftp);

                    # Delete empty directory
                    if ($sftp->rmdir($remotePath . DIRECTORY_SEPARATOR . $element)) {
                        $deleted++;
                    }
                } else {
                    # Delete file
                    if ($sftp->delete($remotePath . DIRECTORY_SEPARATOR . $element)) {
                        $deleted++;
                    }
                }
            }
        }

        if ($deleted === $toDelete) {
            $clean = true;
        }

        return $clean;
    }

    /**
     * @param $remotePath
     * @return array
     */
    public function getAllFiles($remotePath)
    {
        $files = [];
        $list = $this->sftp->nlist($remotePath);
        if (!$list) {
            return $files;
        }
        foreach ($list as $element) {
            if ($element !== '.' && $element !== '..') {
                if ($this->sftp->is_dir($remotePath . DIRECTORY_SEPARATOR . $element)) {
                    # Empty directory
                    foreach ($this->getAllFiles($remotePath . DIRECTORY_SEPARATOR . $element) as $fileSubFolder) {
                        $files[] = $fileSubFolder;
                    }
                } else {
                    $files[] = $element;
                }
            }
        }
        return $files;
    }

    /**
     * Recursively copy files and folders on remote SFTP server
     *
     * If local_path ends with a slash upload folder content
     * otherwise upload folder itself
     *
     * @param $localPath
     * @param $remotePath
     * @return bool $uploaded
     * @throws UploadDirException
     */
    public function uploadDir($localPath, $remotePath)
    {
        $uploaded = false;
        try {
            # Remove trailing slash
            $remotePath = rtrim($remotePath, DIRECTORY_SEPARATOR);

            # If local_path do not ends with /
            if (!WString::endsWith($localPath, '/')) {
                # Create fisrt level directory on remote filesystem
                $remotePath = $remotePath . DIRECTORY_SEPARATOR . basename($localPath);
                $this->sftp->mkdir($remotePath);
            }

            if ($this->sftp->is_dir($remotePath)) {
                $uploaded = Sftp::uploadAll($this->sftp, $localPath, $remotePath);
            }
        } catch (Exception $e) {
            throw new UploadDirException($e);
        }

        return $uploaded;
    }

    /**
     * Recursively copy files and folders on remote SFTP server
     *
     * @param SFTP $sftp
     * @param $localDir
     * @param $remoteDir
     * @return bool $uploaded_all
     * @throws UploadAllException
     */
    private static function uploadAll($sftp, $localDir, $remoteDir)
    {
        $uploadedAll = false;
        try {
            # Create remote directory
            if (!$sftp->is_dir($remoteDir)) {
                if (!$sftp->mkdir($remoteDir)) {
                    throw new Exception("Cannot create remote directory.", 1);
                }
            }

            $toUpload = 0;
            $uploaded = 0;

            $d = dir($localDir);
            while ($file = $d->read()) {
                if ($file != "." && $file != "..") {
                    $toUpload++;

                    if (is_dir($localDir . DIRECTORY_SEPARATOR . $file)) {
                        # Upload directory
                        # Recursive part
                        if (Sftp::uploadAll(
                            $sftp,
                            $localDir . DIRECTORY_SEPARATOR . $file,
                            $localDir . DIRECTORY_SEPARATOR . $file)) {
                            $uploaded++;
                        }
                    } else {
                        # Upload file
                        if ($sftp->put(
                            $remoteDir . DIRECTORY_SEPARATOR . $file,
                            $localDir . DIRECTORY_SEPARATOR . $file,
                            SecFtp::SOURCE_LOCAL_FILE)) {
                            $uploaded++;
                        }
                    }
                }
            }
            $d->close();

            if ($toUpload === $uploaded) {
                $uploadedAll = true;
            }
        } catch (Exception $e) {
            throw new UploadAllException($e);
        }

        return $uploadedAll;
    }

    /**
     * Download a file from remote SFTP server
     *
     * @param $remoteFile
     * @return bool $downloaded
     * @throws FileException
     */
    public function download($remoteFile)
    {
        $downloaded = false;
        try {
            # Download File
            return $this->sftp->get($remoteFile);
        } catch (Exception $e) {
            throw new FileException($e);
        }

        return $downloaded;
    }

    /**
     * Download a directory from remote SFTP server
     *
     * If remote_dir ends with a slash download folder content
     * otherwise download folder itself
     *
     * @param $remoteDir
     * @param $localDir
     * @return bool $downloaded
     * @throws DownloadDirException
     */
    public function downloadDir($remoteDir, $localDir)
    {
        $downloaded = false;
        try {
            if (is_dir($localDir) && is_writable($localDir)) {
                # If remote_dir do not ends with /
                if (!WString::endsWith($remoteDir, '/')) {
                    # Create fisrt level directory on local filesystem
                    $local_dir = rtrim($localDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($remoteDir);
                    mkdir($localDir);
                }

                # Remove trailing slash
                $localDir = rtrim($localDir, DIRECTORY_SEPARATOR);

                # Recursive part
                $downloaded = Sftp::downloadAll($this->sftp, $remoteDir, $localDir);
            } else {
                throw new Exception("Local directory does not exist or is not writable", 1);
            }
        } catch (Exception $e) {
            throw new DownloadDirException($e);
        }

        return $downloaded;
    }

    /**
     * Recursive function to download remote files
     *
     * @param ressource $sftp
     * @param $remoteDir
     * @param $localDir
     * @return bool $downloaded
     *
     * @throws DownloadAllException
     */
    private static function downloadAll($sftp, $remoteDir, $localDir)
    {
        $downloadAll = false;

        try {
            if ($sftp->is_dir($remoteDir)) {
                $files = $sftp->nlist($remoteDir);
                if ($files !== false) {
                    $toDownload = 0;
                    $downloaded = 0;
                    # do this for each file in the remote directory 
                    foreach ($files as $file) {
                        // error_log('file : ' . $file);
                        # To prevent an infinite loop 
                        if ($file != "." && $file != "..") {
                            $toDownload++;
                            # do the following if it is a directory 
                            if ($sftp->is_dir($remoteDir . DIRECTORY_SEPARATOR . $file)) {
                                # Create directory on local filesystem
                                mkdir($localDir . DIRECTORY_SEPARATOR . basename($file));

                                # Recursive part 
                                if (Sftp::downloadAll($sftp, $remoteDir . DIRECTORY_SEPARATOR . $file, $localDir . DIRECTORY_SEPARATOR . basename($file))) {
                                    $downloaded++;
                                }
                            } else {
                                # Download files 
                                if ($sftp->get($remoteDir . DIRECTORY_SEPARATOR . $file, $localDir . DIRECTORY_SEPARATOR . basename($file))) {
                                    $downloaded++;
                                }
                            }
                        }
                    }

                    # Check all files and folders have been downloaded
                    if ($toDownload === $downloaded) {
                        $downloadAll = true;
                    }
                } else {
                    # Nothing to download
                    $downloadAll = true;
                }
            }
        } catch (Exception $e) {
            throw new DownloadAllException($e);
        }

        return $downloadAll;
    }

    /**
     * Rename a file on remote SFTP server
     *
     * @param $currentFilename
     * @param $newFilename
     * @return bool $renamed
     * @throws FileException
     */
    public function rename($currentFilename, $newFilename)
    {
        $renamed = false;

        try {
            if ($this->sftp->rename($currentFilename, $newFilename)) {
                $renamed = true;
            }
        } catch (Exception $e) {
            throw new FileException($e);
        }

        return $renamed;
    }

    /**
     * Create a directory on remote SFTP server
     *
     * @param string $directory
     * @return bool $created
     * @throws FileException
     */
    public function mkdir($directory)
    {
        $created = false;

        try {
            if ($this->sftp->mkdir($directory, true)) {
                $created = true;
            }
        } catch (Exception $e) {
            throw new FileException($e);
        }

        return $created;
    }

    /**
     * Create and fill in a file on remote SFTP server
     *
     * @param $remoteFile
     * @param string $content
     * @return bool $content
     * @throws FileException
     */
    public function touch($remoteFile, $content = '')
    {
        $created = false;

        try {
            # Create temp file
            $local_file = tmpfile();
            fwrite($local_file, $content);
            fseek($local_file, 0);
            if ($this->sftp->put($remoteFile, $local_file, SecFtp::SOURCE_LOCAL_FILE)) {
                $created = true;
            }
            fclose($local_file);
        } catch (Exception $e) {
            throw new FileException($e);
        }

        return $created;
    }

    /**
     * Upload a file on SFTP server
     *
     * @param $localFile
     * @param $remoteFile
     * @return bool $uploaded
     *
     * @throws FileException
     */
    public function upload($localFile, $remoteFile)
    {
        $uploaded = false;

        try {
            if ($this->sftp->put($remoteFile, $localFile, SecFtp::SOURCE_LOCAL_FILE)) {
                $uploaded = true;
            }
        } catch (Exception $e) {
            throw new FileException($e);
        }

        return $uploaded;
    }

    /**
     * List files in given directory on SFTP server
     *
     * @param string $path
     * @return array $files Files listed in directory or false
     */
    public function scanDir($path)
    {
        $files = $this->sftp->nlist($path);
        if (is_array($files)) {
            # Removes . and ..
            $files = array_diff($files, ['.', '..']);
        }

        return $files;
    }

    /**
     * Get default login SFTP directory aka pwd
     *
     * @return string $dir Print Working Directory or false
     */
    public function pwd()
    {
        return $this->sftp->pwd();
    }

}
