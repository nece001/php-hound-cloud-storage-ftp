<?php

namespace Nece\Hound\Cloud\Storage;

use FTP\Connection;

class FtpObject implements IObject
{
    /**
     * FTP对象
     *
     * @var Ftp
     */
    private $ftp;

    /**
     * FTP连接
     *
     * @var Connection
     */
    private $connection;

    /**
     * 文件路径
     *
     * @var string
     */
    private $path;

    /**
     * 构造函数
     *
     * @author nece001@163.com
     * @create 2026-03-31 16:17:09
     *
     * @param Connection $connection FTP连接对象
     * @param string $path 文件路径，包含开头的斜杠
     */
    public function __construct(Ftp $ftp, string $path)
    {
        $this->ftp = $ftp;
        $this->connection = $ftp->getConnection();
        $this->path = $path;
    }

    /**
     * @inheritDoc
     */
    public function getAccessTime(): int
    {
        return $this->getModifyTime();
    }

    /**
     * @inheritDoc
     */
    public function getCreateTime(): int
    {
        return $this->getModifyTime();
    }

    /**
     * @inheritDoc
     */
    public function getModifyTime(): int
    {
        return ftp_mdtm($this->connection, $this->path);
    }

    /**
     * @inheritDoc
     */
    public function getBasename(string $suffix = ""): string
    {
        return basename($this->path, $suffix);
    }

    /**
     * @inheritDoc
     */
    public function getExtension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * @inheritDoc
     */
    public function getFilename(): string
    {
        return basename($this->path);
    }

    /**
     * @inheritDoc
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @inheritDoc
     */
    public function getRealname(): string
    {
        return $this->path;
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        $path = str_replace('\\', '/', $this->path);
        return trim($path, '/');
    }

    /**
     * @inheritDoc
     */
    public function getSize(): int
    {
        return ftp_size($this->connection, $this->path);
    }

    /**
     * @inheritDoc
     */
    public function getMimeType(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function isDir(): bool
    {
        return !$this->isFile();
    }

    /**
     * @inheritDoc
     */
    public function isFile(): bool
    {
        return ftp_size($this->connection, $this->path) >= 0;
    }

    /**
     * @inheritDoc
     */
    public function getContent(): string
    {
        $tmp_file = tempnam(sys_get_temp_dir(), 'ftp_get_content_tmp_file_' . rand());
        ftp_get($this->connection, $tmp_file, $this->path, FTP_BINARY);
        $content = file_get_contents($tmp_file);
        unlink($tmp_file);
        return $content;
    }

    /**
     * @inheritDoc
     */
    public function putContent(string $content, bool $append = false): bool
    {
        $dir = dirname($this->path);
        $this->ftp->mkdir($dir);

        $tmp_file = tempnam(sys_get_temp_dir(), 'ftp_put_content_tmp_file_' . rand());
        if ($append) {
            ftp_get($this->connection, $tmp_file,$this->path,  FTP_BINARY);
            file_put_contents($tmp_file, $content, FILE_APPEND);
        } else {
            file_put_contents($tmp_file, $content);
        }
        ftp_put($this->connection, $this->path, $tmp_file, FTP_BINARY);
        unlink($tmp_file);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(): bool
    {
        return ftp_delete($this->connection, $this->path);
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        return $this->getKey();
    }
}
