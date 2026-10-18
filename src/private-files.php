<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** Private data stays encrypted even when the web server serves the storage directory. */
final class Oyiso_Private_Files {
    private const MAGIC = "OYISO-ENC-1\n";
    private const KEY_OPTION = 'oyiso_private_files_key';
    private const MIGRATED_OPTION = 'oyiso_private_files_encrypted_v1';
    private const ERROR_OPTION = 'oyiso_private_files_migration_error';
    private const CHUNK_BYTES = 65536;

    private static function loadCrypto(): void {
        if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('私有文件保护需要 PHP OpenSSL 的 AES-256-GCM 支持。');
        }
    }

    public static function validateTemporaryPath(string $path): void {
        $realPath = realpath($path);
        if (false === $realPath) {
            throw new RuntimeException('临时文件不存在。');
        }
        foreach ([ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
            $publicRoot = is_string($root) && '' !== $root ? realpath($root) : false;
            if (false !== $publicRoot && str_starts_with(strtolower(wp_normalize_path($realPath)), strtolower(trailingslashit(wp_normalize_path($publicRoot))))) {
                throw new RuntimeException('PHP 临时目录位于公开站点目录中，请配置站点外的临时目录。');
            }
        }
    }

    /** @return resource */
    private static function temporaryStream() {
        $stream = tmpfile();
        if (false === $stream) {
            throw new RuntimeException('无法创建私有临时文件。');
        }
        try {
            $metadata = stream_get_meta_data($stream);
            if (!isset($metadata['uri'])) {
                throw new RuntimeException('无法确定临时文件位置。');
            }
            self::validateTemporaryPath($metadata['uri']);
            return $stream;
        } catch (Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    private static function key(bool $create): string {
        $stored = get_option(self::KEY_OPTION, false);
        if (false === $stored && $create) {
            if ('1' === get_option(self::MIGRATED_OPTION, '') || self::hasEncryptedFiles()) {
                throw new RuntimeException('私有文件原密钥缺失，请从数据库备份恢复原密钥。');
            }
            $encoded = base64_encode(random_bytes(32));
            if (!add_option(self::KEY_OPTION, $encoded, '', false)) {
                wp_cache_delete(self::KEY_OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
            }
            $stored = get_option(self::KEY_OPTION, false);
        }
        $key = is_string($stored) ? base64_decode($stored, true) : false;
        if (false === $key || 32 !== strlen($key)) {
            throw new RuntimeException('私有文件密钥缺失或无效，已停止读写，请保留数据库中的原密钥。');
        }
        return $key;
    }

    private static function hasEncryptedFiles(): bool {
        foreach (['backups', 'order-email-html'] as $group) {
            $directory = trailingslashit(WP_CONTENT_DIR) . 'oyiso-private/' . $group . '/site-' . get_current_blog_id();
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new DirectoryIterator($directory) as $file) {
                if ($file->isLink() || !$file->isFile()) {
                    continue;
                }
                $handle = fopen($file->getPathname(), 'rb');
                if (false === $handle) {
                    throw new RuntimeException('无法检查已有私有文件，请保留数据库中的原密钥。');
                }
                try {
                    if (self::MAGIC === fread($handle, strlen(self::MAGIC))) {
                        return true;
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
        return false;
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $bytes): void {
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('无法完整写入私有文件。');
            }
        }
    }

    /** @param resource $stream */
    private static function readExactly($stream, int $length): string {
        $bytes = '';
        while (($remaining = $length - strlen($bytes)) > 0) {
            $part = fread($stream, $remaining);
            if (false === $part || '' === $part) {
                throw new RuntimeException('私有文件不完整或无法读取。');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    /** @param resource $source */
    private static function writeStream(string $path, $source, bool $closeSource = false): void {
        self::loadCrypto();
        $salt = random_bytes(32);
        $key = hash_hkdf('sha256', self::key(true), 32, 'oyiso-private-files-v1', $salt);
        $temporary = $path . '.encrypted-' . bin2hex(random_bytes(8));
        $output = fopen($temporary, 'xb');
        if (false === $output) {
            throw new RuntimeException('无法创建私有文件。');
        }
        try {
            self::writeAll($output, self::MAGIC . $salt);
            $index = 0;
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if (false === $chunk) {
                    throw new RuntimeException('无法读取私有文件内容。');
                }
                if ('' === $chunk) {
                    break;
                }
                self::writeRecord($output, $chunk, $key, $salt, $index++, false);
            }
            self::writeRecord($output, '', $key, $salt, $index, true);
            fclose($output);
            $output = false;
            if ($closeSource) {
                fclose($source);
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('无法保存加密的私有文件。');
            }
        } finally {
            if ($closeSource && is_resource($source)) {
                fclose($source);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary)) {
                wp_delete_file($temporary);
            }
        }
    }

    /** @param resource $output */
    private static function writeRecord($output, string $plain, string $key, string $salt, int $index, bool $final): void {
        if ($index >= 0xffffffff) {
            throw new RuntimeException('私有文件超过支持的大小。');
        }
        $sequence = pack('N', $index);
        $record = pack('N', strlen($plain)) . ($final ? "\x01" : "\x00");
        // A fresh per-file key and increasing nonce prevent nonce reuse. AAD binds order, size and finality.
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            str_repeat("\0", 8) . $sequence, $tag, self::MAGIC . $salt . $sequence . $record, 16);
        if (false === $encrypted || 16 !== strlen($tag)) {
            throw new RuntimeException('私有文件加密失败。');
        }
        self::writeAll($output, $record . $encrypted . $tag);
    }

    public static function write(string $path, string $contents): int {
        $source = self::temporaryStream();
        try {
            self::writeAll($source, $contents);
            rewind($source);
            self::writeStream($path, $source);
            return strlen($contents);
        } finally {
            fclose($source);
        }
    }

    public static function protect(string $sourcePath, ?string $destinationPath = null): void {
        $source = fopen($sourcePath, 'rb');
        if (false === $source) {
            throw new RuntimeException('无法打开待保护的私有文件。');
        }
        $modified = filemtime($sourcePath);
        try {
            if (null === $destinationPath && self::MAGIC === fread($source, strlen(self::MAGIC))) {
                return;
            }
            rewind($source);
            self::writeStream($destinationPath ?? $sourcePath, $source, true);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
        if (null === $destinationPath && false !== $modified) {
            touch($sourcePath, $modified);
        }
    }

    /**
     * @param resource $source
     * @return resource
     */
    private static function decodeStream($source) {
        $prefix = fread($source, strlen(self::MAGIC));
        if (self::MAGIC !== $prefix) {
            rewind($source);
            return $source;
        }
        self::loadCrypto();
        $salt = self::readExactly($source, 32);
        $key = hash_hkdf('sha256', self::key(false), 32, 'oyiso-private-files-v1', $salt);
        $output = self::temporaryStream();
        try {
            $index = 0;
            do {
                $record = self::readExactly($source, 5);
                $size = unpack('Nsize', substr($record, 0, 4));
                $length = is_array($size) ? ($size['size'] ?? 0) : 0;
                $final = "\x01" === $record[4];
                if (!is_int($length) || $length < 0 || $length > self::CHUNK_BYTES
                    || ($final && 0 !== $length) || (!$final && ("\x00" !== $record[4] || 0 === $length))
                    || $index >= 0xffffffff) {
                    throw new RuntimeException('私有文件格式无效。');
                }
                $encrypted = self::readExactly($source, $length);
                $tag = self::readExactly($source, 16);
                $sequence = pack('N', $index++);
                $plain = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                    str_repeat("\0", 8) . $sequence, $tag, self::MAGIC . $salt . $sequence . $record);
                if (false === $plain) {
                    throw new RuntimeException('私有文件校验失败，已拒绝读取。');
                }
                self::writeAll($output, $plain);
            } while (!$final);
            if ('' !== fread($source, 1)) {
                throw new RuntimeException('私有文件包含无效的附加内容。');
            }
            rewind($output);
            return $output;
        } catch (Throwable $error) {
            fclose($output);
            throw $error;
        }
    }

    /** @param resource $source */
    public static function readStream($source): string {
        $decoded = self::decodeStream($source);
        try {
            $contents = stream_get_contents($decoded);
            if (false === $contents) {
                throw new RuntimeException('无法读取私有文件。');
            }
            return $contents;
        } finally {
            if ($decoded !== $source) {
                fclose($decoded);
            }
        }
    }

    /** @return resource */
    public static function open(string $path) {
        $source = fopen($path, 'rb');
        if (false === $source) {
            throw new RuntimeException('无法打开私有文件。');
        }
        try {
            $decoded = self::decodeStream($source);
            if ($decoded !== $source) {
                fclose($source);
            }
            return $decoded;
        } catch (Throwable $error) {
            fclose($source);
            throw $error;
        }
    }

    public static function read(string $path): string {
        $source = self::open($path);
        try {
            $contents = stream_get_contents($source);
            if (false === $contents) {
                throw new RuntimeException('无法读取私有文件。');
            }
            return $contents;
        } finally {
            fclose($source);
        }
    }

    public static function hash(string $path): string {
        $source = self::open($path);
        try {
            $hash = hash_init('md5');
            hash_update_stream($hash, $source);
            return hash_final($hash);
        } finally {
            fclose($source);
        }
    }

    public static function migrate(): void {
        if ('1' === get_option(self::MIGRATED_OPTION, '')) {
            return;
        }
        try {
            self::key(true);
            $site = (string) get_current_blog_id();
            foreach (['backups', 'order-email-html'] as $group) {
                $directory = trailingslashit(WP_CONTENT_DIR) . 'oyiso-private/' . $group . '/site-' . $site;
                if (!is_dir($directory)) {
                    continue;
                }
                foreach (new DirectoryIterator($directory) as $file) {
                    if (!$file->isLink() && $file->isFile()
                        && preg_match('/\.(?:json|html|png|jpe?g)(?:\.previous|\.tmp-[a-zA-Z0-9]+)?$/i', $file->getFilename())) {
                        self::protect($file->getPathname());
                    }
                }
            }
            update_option(self::MIGRATED_OPTION, '1', false);
            delete_option(self::ERROR_OPTION);
        } catch (Throwable $error) {
            update_option(self::ERROR_OPTION, '私有文件保护未完成：' . $error->getMessage(), false);
        }
    }

    public static function notice(): void {
        $message = get_option(self::ERROR_OPTION, '');
        if (is_string($message) && '' !== $message && current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }
}

add_action('plugins_loaded', [Oyiso_Private_Files::class, 'migrate'], 5);
add_action('admin_notices', [Oyiso_Private_Files::class, 'notice']);
