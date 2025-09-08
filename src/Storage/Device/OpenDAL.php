<?php

namespace Utopia\Storage\Device;

use Exception;
use OpenDAL\Operator;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;
class OpenDAL extends Device
{
    protected Operator $operator;
    protected string $root = '';
    protected array $config;

    public function __construct(string $scheme_str, array $config = [], string $root = '')
    {
        $this->config = $config;
        $this->root = $root;
        $this->operator = new Operator($scheme_str, $config);
    }

    public function getName(): string
    {
        return 'OpenDAL Storage';
    }

    public function getType(): string
    {
        return Storage::DEVICE_OPENDAL;
    }

    public function getDescription(): string
    {
        return 'Adapter for OpenDAL storage that provides unified interface to various storage backends.';
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function getPath(string $filename, string $prefix = null): string
    {
        $path = $this->root;
        if ($prefix) {
            $path .= '/' . trim($prefix, '/');
        }
        $path .= '/' . $filename;
        return $this->getAbsolutePath($path);
    }

    public function upload(string $source, string $path, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
    {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new Exception('Failed to read source file: ' . $source);
        }
        $binaryContent = unpack('C*', $content);
        
        if ($chunks > 1) {
            return $this->uploadChunk($binaryContent, $path, $chunk, $chunks, $metadata);
        }
        
        try {
            $this->operator->write_binary($path, $binaryContent);
        } catch (Exception $writeException) {
            throw new Exception('OpenDAL write failed: ' . $writeException->getMes sage());
        }
        
        return 1;
    }

    public function uploadData(string $data, string $path, string $contentType, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
    {
        if ($chunks > 1) {
            return $this->uploadChunk($data, $path, $chunk, $chunks, $metadata);
        }
        
        try {
            $binaryContent = unpack('C*', $data);
            $this->operator->write_binary($path, $binaryContent);
        } catch (Exception $writeException) {
            throw new Exception('OpenDAL write failed: ' . $writeException->getMessage());
        }
        
        return 1;
    }

    protected function uploadChunk(string $chunkData, string $path, int $chunk, int $chunks, array &$metadata): int
    {
        $chunkPath = $this->getChunkPath($path, $chunk);        
        try {
            $binaryContent = unpack('C*', $chunkData);
            $this->operator->write_binary($chunkPath, $binaryContent);
        } catch (Exception $writeException) {
            throw new Exception('Chunk write failed: ' . $writeException->getMessage());
        }
        
        if ($chunk === $chunks) {
            $this->mergeChunks($path, $chunks, $metadata);
        }
        
        return $chunk;
    }

    protected function getChunkPath(string $path, int $chunk): string
    {
        return $path . '.chunk.' . $chunk;
    }

    protected function mergeChunks(string $path, int $chunks, array &$metadata): void
    {
        $mergedContent = '';
        
        // 按顺序读取并合并所有分块
        for ($i = 1; $i <= $chunks; $i++) {
            $chunkPath = $this->getChunkPath($path, $i);

            if (!$this->exists($chunkPath)) {
                throw new Exception("Missing chunk {$i} for file: {$path}");
            }
            
            $chunkContent = $this->operator->read($chunkPath);
            $mergedContent .= $chunkContent;
            
            $this->operator->delete($chunkPath);
        }
        
        $binaryContent = unpack('C*', $mergedContent);
        $this->operator->write_binary($path, $binaryContent);
    }

    public function abort(string $path, string $extra = ''): bool
    {
        // 查找并删除所有相关的分块文件
        $chunkIndex = 1;
        $deletedChunks = 0;
        
        while (true) {
            $chunkPath = $this->getChunkPath($path, $chunkIndex);
            
            if ($this->exists($chunkPath)) {
                $this->operator->delete($chunkPath);
                $deletedChunks++;
                $chunkIndex++;
            } else {
                break;
            }
        }
        
        // 如果目标文件存在，也删除它
        if ($this->exists($path)) {
            $this->operator->delete($path);
        }
        
        return $deletedChunks > 0 || $this->exists($path);
    }

    public function read(string $path, int $offset = 0, int $length = null): string
    {
        $content = $this->operator->read($path);
            
        if ($offset > 0 || $length !== null) {
            $content = substr($content, $offset, $length);
        }
        
        return $content;
    }

    public function transfer(string $path, string $destination, Device $device): bool
    {
        $content = $this->read($path);
        return $device->write($destination, $content, $this->getFileMimeType($path));
    }

    public function write(string $path, string $data, string $contentType): bool
    {
        $binaryContent = unpack('C*', $data);
        $this->operator->write_binary($path, $binaryContent);
        return true;
    }

    public function delete(string $path, bool $recursive = false): bool
    {
        $this->operator->delete($path);
        return true;
    }

    public function deletePath(string $path): bool
    {
        $this->operator->delete($path);
        return true;
    }

    public function exists(string $path): bool
    {
        $exists = (bool) $this->operator->is_exist($path);
        return $exists;
    }

    public function getFileSize(string $path): int
    {
        $metadata = $this->operator->stat($path);
        $size = $metadata->content_length();
            
        return $size;
    }

    /**
     * 从文件路径推断 MIME 类型
     */
    protected function inferMimeTypeFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    public function getFileMimeType(string $path): string
    {
        // 获取文件的元数据
        $metadata = $this->operator->stat($path);
        $contentType = $metadata->content_type();
        
        // 如果文件没有 MIME 类型，尝试从路径推断
        if (!$contentType) {
            $contentType = $this->inferMimeTypeFromPath($path);
        }
        
        return $contentType ?: 'application/octet-stream';
    }

    public function getFileHash(string $path): string
    {
        $content = $this->read($path);
        return md5($content);
    }

    public function createDirectory(string $path): bool
    {
        if (!str_ends_with($path, '/')) {
            $path .= '/';
        }
        $this->operator->create_dir($path);
        return true;
    }

    public function getDirectorySize(string $path): int
    {
        $files = $this->getFiles($path);
        $totalSize = 0;
        foreach ($files as $file) {
            if (isset($file['type']) && $file['type'] === 'file') {
                $totalSize += $file['size'] ?? 0;
            }
        }
        return $totalSize;
    }

    public function getPartitionFreeSpace(): float
    {
        return -1.0;
    }

    public function getPartitionTotalSpace(): float
    {
        return -1.0;
    }

    public function getFiles(string $dir, int $max = self::MAX_PAGE_SIZE, string $continuationToken = ''): array
    {
        return [];
    }
}