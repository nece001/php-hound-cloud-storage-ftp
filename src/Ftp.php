<?php

namespace Nece\Hound\Cloud\Storage;

use Exception;

class Ftp extends Storage implements IStorage
{
    /**
     * FTP 主机地址
     *
     * @var string
     */
    private $host;

    /**
     * FTP 端口 默认21
     *
     * @var int
     */
    private $port;

    /**
     * FTP 用户名
     *
     * @var string
     */
    private $username;

    /**
     * FTP 密码
     *
     * @var string
     */
    private $password;

    /**
     * 文件访问URL基础路径
     *
     * @var string
     */
    private $base_uri;

    /**
     * FTP 超时时间 默认10秒
     *
     * @var int
     */
    private $timeout;

    /**
     * 是否使用被动模式 默认true
     *
     * @var bool
     */
    private $passive;

    /**
     * 是否使用SSL 默认false
     *
     * @var bool
     */
    private $ssl;

    /**
     * FTP 连接资源
     *
     * @var \FTP\Connection
     */
    private $connection;

    /**
     * 构造函数
     *
     * @author nece001@163.com
     * @create 2026-03-31 14:21:00
     *
     * @param string $host FTP 主机地址
     * @param string $username FTP 用户名
     * @param string $password FTP 密码
     * @param int $port FTP 端口 默认21
     * @param string $base_uri 文件访问URL基础路径
     * @param int $timeout FTP 超时时间 默认10秒
     * @param boolean $passive
     * @param boolean $ssl
     */
    public function __construct($host, $username, $password, $port = 21, $base_uri = '', $timeout = 10, $passive = true, $ssl = false)
    {
        $this->base_uri = rtrim(str_replace('\\', '/', $base_uri), '/');
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
        $this->passive = $passive;
        $this->ssl = $ssl;

        $this->support();
        $this->open();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function support()
    {
        if (!function_exists('ftp_connect')) {
            throw new StorageException('FTP extension is not loaded', Consts::ERROR_CODE_NOT_SUPPORTED);
        }
    }

    private function open()
    {
        if ($this->ssl) {
            $this->connection = ftp_ssl_connect($this->host, $this->port, $this->timeout);
        } else {
            $this->connection = ftp_connect($this->host, $this->port, $this->timeout);
        }

        if ($this->connection) {
            if (ftp_login($this->connection, $this->username, $this->password)) {
                if ($this->passive) {
                    ftp_pasv($this->connection, true);
                }
            } else {
                $this->close();
                throw new StorageException('FTP login failed', Consts::ERROR_CODE_TOKEN_INVALIDATED);
            }
        } else {
            throw new StorageException('Unable to connect to FTP server', Consts::ERROR_CODE_TOKEN_INVALIDATED);
        }
    }

    private function close()
    {
        if ($this->connection) {
            ftp_close($this->connection);
            $this->connection = null;
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @inheritDoc
     */
    public function exists(string $path): bool
    {
        if ($this->isFile($path)) {
            return true;
        } else {
            return $this->isDir($path);
        }
    }

    /**
     * @inheritDoc
     */
    public function isDir(string $path): bool
    {
        $path = $this->fullPath($path);
        $pwd = ftp_pwd($this->connection);
        if (@ftp_chdir($this->connection, $path)) {
            ftp_chdir($this->connection, $pwd);
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function isFile(string $path): bool
    {
        $path = $this->fullPath($path);
        // ftp_size不存在或是目录时返回-1
        return ftp_size($this->connection, $path) >= 0;
    }

    /**
     * @inheritDoc
     */
    public function copy(string $from, string $to): bool
    {
        if (!$this->exists($from)) {
            throw new StorageException('File Or Path not found', Consts::ERROR_CODE_NOT_FOUND);
        }

        $from = $this->fullPath($from);
        $to = $this->fullPath($to);

        // 下载到临时目录，然后再上传到FTP指定的目录
        if ($this->isFile($from)) {
            $tmp_file = tempnam(sys_get_temp_dir(), 'ftp_copy_tmp_file_' . rand());
            $this->download($from, $tmp_file);
            $this->upload($tmp_file, $to);
            $this->tmpDelete($tmp_file);
            return true;
        } else {

            $tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ftp_copy_tmp_dir_' . rand();
            if (!file_exists($tmp_dir)) {
                mkdir($tmp_dir);
            }
            $this->download($from, $tmp_dir);

            $this->mkdir($to);
            $this->upload($tmp_dir, $to);

            $this->tmpDelete($tmp_dir);
            return true;
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): bool
    {
        return ftp_rename($this->connection, $from, $to);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): bool
    {
        $full_path = $this->fullPath($path);
        if ($this->exists($full_path)) {
            if ($this->isFile($full_path)) {
                return ftp_delete($this->connection, $full_path);
            } else {
                return $this->rmdir($path);
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        $path = $this->fullPath($path);
        if (!$this->exists($path)) {
            try {
                $parts = explode('/', trim($path, '/'));
                $dir = '';
                foreach ($parts as $part) {
                    $dir .= '/' . $part;
                    if (!$this->exists($dir)) {
                        ftp_mkdir($this->connection, $dir);
                    }
                }

                return true;
            } catch (Exception $e) {
                echo $e->getMessage();
                exit;
            }
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function rmdir(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($this->isDir($path)) {
            $full_path = $this->fullPath($path);
            $list = ftp_nlist($this->connection, $full_path);

            foreach ($list as $name) {
                if ($this->isFile($name)) {
                    ftp_delete($this->connection, $name);
                } else {
                    $this->rmdir($name);
                }
            }
            ftp_rmdir($this->connection, $this->fullPath($path));
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function list(string $path, int $order = Consts::SCANDIR_SORT_ASCENDING): array
    {
        $path = $this->fullPath($path);
        $list = ftp_nlist($this->connection, $path);

        $files = array();
        if ($list) {
            foreach ($list as $name) {
                $basename = basename($name);

                $size = ftp_size($this->connection, $name);
                $is_dir = $size <= 0;
                $ctime = $is_dir ? 0 : ftp_mdtm($this->connection, $name);
                $mtime = $ctime;
                $atime = $ctime;

                $files[] = $this->buildObjectListItem($basename, $size, $is_dir, $ctime, $mtime, $atime);
            }
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local_src, string $to): bool
    {
        if (!file_exists($local_src)) {
            throw new StorageException('File not found', Consts::ERROR_CODE_NOT_FOUND);
        }

        $to = $this->fullPath($to);
        if (is_file($local_src)) {
            return ftp_put($this->connection, $to, $local_src, FTP_BINARY);
        } else {
            $files = scandir($local_src);
            foreach ($files as $file) {
                if (!in_array($file, array('.', '..'))) {
                    $local_path = $local_src . DIRECTORY_SEPARATOR . $file;
                    $ftp_path = $to . '/' . $file;

                    if (is_file($local_path)) {
                        $this->mkdir(dirname($ftp_path));
                        ftp_put($this->connection, $ftp_path, $local_path, FTP_BINARY);
                    } else {
                        $this->mkdir($ftp_path);
                        $this->upload($local_path, $ftp_path);
                    }
                }
            }
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function download(string $src, string $local_dst): bool
    {
        if (!$this->exists($src)) {
            throw new StorageException('File Or Path not found', Consts::ERROR_CODE_NOT_FOUND);
        }

        $src = $this->fullPath($src);
        if ($this->isFile($src)) {
            return ftp_get($this->connection, $local_dst, $src, FTP_BINARY);
        } else {
            if (!file_exists($local_dst)) {
                mkdir($local_dst, 0755, true);
            }

            $list = ftp_nlist($this->connection, $src);
            foreach ($list as $path) {
                $local_path = $local_dst . DIRECTORY_SEPARATOR . basename($path);

                if ($this->isFile($path)) {
                    ftp_get($this->connection, $local_path, $path, FTP_BINARY);
                } else {
                    $this->download($path, $local_path);
                }
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function file(string $path): IObject
    {
        return new FtpObject($this, $this->fullPath($path));
    }

    /**
     * @inheritDoc
     */
    public function uri(string $path): string
    {
        return trim($this->fullPath($path), '/');
    }

    /**
     * @inheritDoc
     */
    public function url(string $path): string
    {
        return $this->base_uri . $this->uri($path);
    }

    private function fullPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return '/' . trim($path, '/');
    }

    private function tmpDelete(string $path)
    {
        if (file_exists($path)) {
            if (is_dir($path)) {
                $files = scandir($path);
                foreach ($files as $file) {
                    if (!in_array($file, array('.', '..'))) {
                        $this->tmpDelete($path . '/' . $file);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }
}
