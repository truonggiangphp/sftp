<?php

namespace Webike\Sftp;

use Exception;
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
     */
    public function login($server, $user, $password, $port = 22)
    {
        try {
            $this->sftp = new SecFtp($server, $port);
            if (!$this->sftp->login($user, $password)) {
                $this->sftp = false;
            }
        } catch (Exception $e) {
            error_log("SFtp::login : " . $e->getMessage());
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
     */
    public function isFile($remoteFile)
    {
        $isFile = false;
        try {
            $isFile = $this->sftp->isFile($remoteFile);
        } catch (Exception $e) {
            error_log("Sftp::is_file : " . $e->getMessage());
        }

        return $isFile;
    }

    /**
     * Delete a file on remote SFTP server
     *
     * @param $remoteFile
     * @return bool $deleted
     */
    public function delete($remoteFile)
    {
        $deleted = false;

        try {
            if ($this->sftp->isFile($remoteFile)) {
                $deleted = $this->sftp->delete($remoteFile);
            }
        } catch (Exception $e) {
            error_log("Sft::delete : " . $e->getMessage());
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
            error_log("Sftp::rmdir : " . $e->getMessage());
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
     * Recursively copy files and folders on remote SFTP server
     *
     * If local_path ends with a slash upload folder content
     * otherwise upload folder itself
     *
     * @param $localPath
     * @param $remotePath
     * @return bool $uploaded
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
            error_log("Sftp::upload_dir : " . $e->getMessage());
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
            error_log("Sftp::upload_all : " . $e->getMessage());
        }

        return $uploadedAll;
    }

    /**
     * Download a file from remote SFTP server
     *
     * @param $remoteFile
     * @param $localFile
     * @return bool $downloaded
     */
    public function download($remoteFile, $localFile)
    {
        $downloaded = false;

        try {
            # Download File
            if ($this->sftp->get($remoteFile, $localFile)) {
                $downloaded = true;
            }
        } catch (Exception $e) {
            error_log("Sftp::download : " . $e->getMessage());
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
            error_log("Sftp::download_dir : " . $e->getMessage());
        }

        return $downloaded;
    }

    /**
     * Recursive function to download remote files
     *
     * @param ressource $sftp
     * @param string $remote_dir
     * @param string $local_dir
     *
     * @return bool $downloaded
     *
     */
    private static function downloadAll($sftp, $remote_dir, $local_dir)
    {
        $download_all = false;

        try {
            if ($sftp->is_dir($remote_dir)) {
                $files = $sftp->nlist($remote_dir);
                if ($files !== false) {
                    $to_download = 0;
                    $downloaded = 0;
                    # do this for each file in the remote directory 
                    foreach ($files as $file) {
                        // error_log('file : ' . $file);
                        # To prevent an infinite loop 
                        if ($file != "." && $file != "..") {
                            $to_download++;
                            # do the following if it is a directory 
                            if ($sftp->is_dir($remote_dir . DIRECTORY_SEPARATOR . $file)) {
                                # Create directory on local filesystem
                                mkdir($local_dir . DIRECTORY_SEPARATOR . basename($file));

                                # Recursive part 
                                if (Sftp::downloadAll($sftp, $remote_dir . DIRECTORY_SEPARATOR . $file, $local_dir . DIRECTORY_SEPARATOR . basename($file))) {
                                    $downloaded++;
                                }
                            } else {
                                # Download files 
                                if ($sftp->get($remote_dir . DIRECTORY_SEPARATOR . $file, $local_dir . DIRECTORY_SEPARATOR . basename($file))) {
                                    $downloaded++;
                                }
                            }
                        }
                    }

                    # Check all files and folders have been downloaded
                    if ($to_download === $downloaded) {
                        $download_all = true;
                    }
                } else {
                    # Nothing to download
                    $download_all = true;
                }
            }
        } catch (Exception $e) {
            error_log("Sftp::download_all : " . $e->getMessage());
        }

        return $download_all;
    }

    /**
     * Rename a file on remote SFTP server
     *
     * @param $currentFilename
     * @param $newFilename
     * @return bool $renamed
     */
    public function rename($currentFilename, $newFilename)
    {
        $renamed = false;

        try {
            if ($this->sftp->rename($currentFilename, $newFilename)) {
                $renamed = true;
            }
        } catch (Exception $e) {
            error_log("Sftp::rename : " . $e->getMessage());
        }

        return $renamed;
    }

    /**
     * Create a directory on remote SFTP server
     *
     * @param string $directory
     * @return bool $created
     */
    public function mkdir($directory)
    {
        $created = false;

        try {
            if ($this->sftp->mkdir($directory, true)) {
                $created = true;
            }
        } catch (Exception $e) {
            error_log("Sftp::mkdir : " . $e->getMessage());
        }

        return $created;
    }

    /**
     * Create and fill in a file on remote SFTP server
     *
     * @param $remoteFile
     * @param string $content
     * @return bool $content
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
            error_log("Sftp::touch : " . $e->getMessage());
        }

        return $created;
    }

    /**
     * Upload a file on SFTP server
     *
     * @param string $server
     * @param string $user
     * @param string $password
     * @param string $local_file
     * @param string $remote_file
     * @param int $port
     *
     * @return bool $uploaded
     *
     */
    public function upload($server, $user, $password, $local_file, $remote_file, $port = 22)
    {
        $uploaded = false;

        try {
            if ($this->sftp->put($remote_file, $local_file, SecFtp::SOURCE_LOCAL_FILE)) {
                $uploaded = true;
            }
        } catch (Exception $e) {
            error_log("Sftp::upload : " . $e->getMessage());
        }

        return $uploaded;
    }

    /**
     * List files in given directory on SFTP server
     *
     * @param string $server
     * @param string $user
     * @param string $password
     * @param string $path
     * @param int $port
     *
     * @return array $files Files listed in directory or false
     *
     */
    public function scandir($server, $user, $password, $path, $port = 22)
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
