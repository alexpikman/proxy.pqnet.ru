<?php
declare(strict_types=1);

final class JsonStore
{
    private static ?string $directory = null;

    public static function init(?string $directory = null): void
    {
        if ($directory !== null) {
            self::$directory = rtrim($directory, DIRECTORY_SEPARATOR);
            return;
        }

        $configured = getenv('PQSERVICE_DATA_DIR');
        self::$directory = is_string($configured) && $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data';
    }

    public static function directory(): string
    {
        if (self::$directory === null) {
            self::init();
        }
        return self::$directory;
    }

    public static function ensureDirectory(): void
    {
        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create data directory.');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Data directory is not writable.');
        }
    }

    private static function path(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.json$/D', $name)) {
            throw new InvalidArgumentException('Invalid storage filename.');
        }
        return self::directory() . DIRECTORY_SEPARATOR . $name;
    }

    private static function lock(string $name, int $mode)
    {
        $handle = fopen(self::path($name) . '.lock', 'c');
        if ($handle === false || !flock($handle, $mode)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Cannot lock storage file.');
        }
        return $handle;
    }

    private static function readUnlocked(string $path, mixed $default): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read storage file.');
        }
        if (trim($raw) === '') {
            return $default;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Corrupt JSON storage: ' . basename($path), 0, $e);
        }
    }

    private static function writeUnlocked(string $path, mixed $value): void
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($tmp, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create temporary storage file.');
        }

        try {
            $length = strlen($json);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Incomplete storage write.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Cannot flush storage file.');
            }
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        chmod($tmp, 0600);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic storage replacement failed.');
        }
        chmod($path, 0600);
    }

    public static function read(string $name, mixed $default = []): mixed
    {
        self::ensureDirectory();
        $path = self::path($name);
        $lock = self::lock($name, LOCK_SH);
        try {
            return self::readUnlocked($path, $default);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function write(string $name, mixed $value): void
    {
        self::ensureDirectory();
        $path = self::path($name);
        $lock = self::lock($name, LOCK_EX);
        try {
            self::writeUnlocked($path, $value);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function update(string $name, callable $callback, mixed $default = []): mixed
    {
        self::ensureDirectory();
        $path = self::path($name);
        $lock = self::lock($name, LOCK_EX);
        try {
            $data = self::readUnlocked($path, $default);
            $result = $callback($data);
            self::writeUnlocked($path, $data);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
