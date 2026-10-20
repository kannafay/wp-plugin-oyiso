<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-import-type Upload from Oyiso_Agent_Attachments */
final class Oyiso_Agent_Private_Files
{
    /** @return string|WP_Error */
    public static function directory(): string|WP_Error
    {
        $webRoot = realpath(ABSPATH) ?: ABSPATH;
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $documentRoot = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($documentRoot !== false && self::inside($webRoot, $documentRoot)) $webRoot = $documentRoot;
        $site = substr(hash('sha256', wp_normalize_path(ABSPATH) . '|' . get_current_blog_id()), 0, 16);
        $configured = defined('OYISO_AGENT_PRIVATE_DIR') ? constant('OYISO_AGENT_PRIVATE_DIR') : dirname($webRoot) . '/.oyiso-private/' . $site;
        $directory = apply_filters('oyiso_agent_private_directory', $configured);
        if (!is_string($directory) || (!path_is_absolute($directory) && !preg_match('~^[a-zA-Z]:/~', $directory)) || wp_is_stream($directory) || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $directory)) return self::error();
        if (self::inside($directory, $webRoot) || self::inside($directory, ABSPATH)) return self::error();
        if (!wp_mkdir_p($directory)) return self::error();
        $resolved = realpath($directory);
        if ($resolved === false || self::inside($resolved, $webRoot) || self::inside($resolved, ABSPATH) || !is_writable($resolved)) return self::error();
        @chmod($resolved, 0700);
        return wp_normalize_path($resolved);
    }

    private static function inside(string $path, string $root): bool
    {
        $path = strtolower(untrailingslashit(wp_normalize_path($path)));
        $root = strtolower(untrailingslashit(wp_normalize_path($root)));
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function error(): WP_Error
    {
        return new WP_Error('agent_private_directory', '无法使用私有附件目录。请检查站点上级目录的写入权限，或通过 OYISO_AGENT_PRIVATE_DIR 指定网站目录以外的位置。', ['status' => 500]);
    }

    /** @return string|WP_Error */
    public static function path(string $relative): string|WP_Error
    {
        if (!preg_match('/^[a-f0-9-]{36}\.bin$/D', $relative)) return new WP_Error('agent_file_path', '附件路径无效。', ['status' => 500]);
        $directory = self::directory();
        if (is_wp_error($directory)) return $directory;
        $path = $directory . '/' . $relative;
        if (is_link($path)) return new WP_Error('agent_file_path', '附件路径无效。', ['status' => 500]);
        $resolved = realpath($path);
        if ($resolved !== false && wp_normalize_path(dirname($resolved)) !== $directory) return new WP_Error('agent_file_path', '附件路径无效。', ['status' => 500]);
        return $path;
    }

    /** @return string|WP_Error Relative file name, never a public URL. */
    public static function write(string $content): string|WP_Error
    {
        $relative = wp_generate_uuid4() . '.bin';
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        $handle = @fopen($path, 'xb');
        if ($handle === false) return new WP_Error('agent_file_write', '无法写入私有附件。', ['status' => 500]);
        @chmod($path, 0600);
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content) || !hash_equals(hash('sha256', $content), (string) hash_file('sha256', $path))) {
            @unlink($path);
            return new WP_Error('agent_file_write', '私有附件写入校验失败，原数据已保留。', ['status' => 500]);
        }
        return $relative;
    }

    /** @return string|WP_Error */
    public static function read(string $relative, string $checksum): string|WP_Error
    {
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size > Oyiso_Agent_Attachments::MAX_IMAGE_UPLOAD) return new WP_Error('agent_file_missing', '附件文件不存在或大小异常。', ['status' => 404]);
        $content = file_get_contents($path);
        if ($content === false || !hash_equals($checksum, hash('sha256', $content))) return new WP_Error('agent_file_checksum', '附件文件校验失败。', ['status' => 500]);
        return $content;
    }

    public static function remove(string $relative): bool|WP_Error
    {
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        if (!file_exists($path)) return true;
        return @unlink($path) ? true : new WP_Error('agent_file_delete', '无法删除附件文件，请检查目录权限。', ['status' => 500]);
    }

    /** @param Upload $upload
     * @return Upload|WP_Error
     */
    public static function optimize(array $upload): array|WP_Error
    {
        if (!in_array($upload['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) return $upload;
        // Preserve animated containers instead of flattening them to one frame.
        if ($upload['mime'] === 'image/webp' && strlen($upload['content']) > 20 && substr($upload['content'], 12, 4) === 'VP8X' && (ord($upload['content'][20]) & 2) !== 0) return $upload;
        if ($upload['mime'] === 'image/png' && str_contains($upload['content'], 'acTL')) return $upload;
        $relative = self::write($upload['content']);
        if (is_wp_error($relative)) return $relative;
        $source = self::path($relative);
        if (is_wp_error($source)) return $source;
        $target = $source . '.webp';
        try {
            $editor = wp_get_image_editor($source);
            if (is_wp_error($editor)) return $upload['size'] <= Oyiso_Agent_Attachments::MAX_FILE ? $upload : new WP_Error('agent_image_compress', '服务器无法压缩这张图片，请先缩小图片再上传。', ['status' => 400]);
            $rotated = $editor->maybe_exif_rotate();
            if (is_wp_error($rotated)) return $rotated;
            $size = $editor->get_size();
            if ($size['width'] > 2048 || $size['height'] > 2048) {
                $resized = $editor->resize(2048, 2048, false);
                if (is_wp_error($resized)) return $resized;
            }
            $editor->set_quality(82);
            $mime = wp_image_editor_supports(['mime_type' => 'image/webp']) ? 'image/webp' : $upload['mime'];
            $target = $source . ($mime === 'image/webp' ? '.webp' : ($mime === 'image/png' ? '.png' : '.jpg'));
            $saved = $editor->save($target, $mime);
            if (is_wp_error($saved)) return $upload['size'] <= Oyiso_Agent_Attachments::MAX_FILE ? $upload : $saved;
            $savedPath = wp_normalize_path($saved['path']);
            if (!str_starts_with($savedPath, wp_normalize_path($source) . '.') || dirname($savedPath) !== wp_normalize_path(dirname($source))) return new WP_Error('agent_image_path', '图片压缩输出路径无效。', ['status' => 500]);
            $target = $saved['path']; $mime = $saved['mime-type'];
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $upload['size'] <= Oyiso_Agent_Attachments::MAX_FILE ? $upload : new WP_Error('agent_image_format', '图片压缩格式不受支持，请先缩小图片后重试。', ['status' => 400]);
            $content = file_get_contents($target);
            if ($content !== false && strlen($content) < $upload['size']) {
                $extension = $mime === 'image/webp' ? 'webp' : ($mime === 'image/png' ? 'png' : 'jpg');
                $upload = ['name' => pathinfo($upload['name'], PATHINFO_FILENAME) . '.' . $extension, 'mime' => $mime, 'size' => strlen($content), 'content' => $content];
            }
            return $upload['size'] <= Oyiso_Agent_Attachments::MAX_FILE ? $upload : new WP_Error('agent_image_compress', '图片压缩后仍超过 4 MB，请缩小图片后重试。', ['status' => 400]);
        } finally {
            if (is_file($target)) @unlink($target);
            self::remove($relative);
        }
    }
}
