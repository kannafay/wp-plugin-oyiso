<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @phpstan-import-type Upload from Oyiso_Agent_Attachments */
final class Oyiso_Agent_Private_Files
{
    public const VERSION = 'site-encrypted-v1';

    /** @return string|WP_Error */
    public static function directory(): string|WP_Error
    {
        $privateRoot = trailingslashit(WP_CONTENT_DIR) . 'oyiso-private';
        $configured = defined('OYISO_AGENT_PRIVATE_DIR') ? constant('OYISO_AGENT_PRIVATE_DIR') : $privateRoot . '/agent-files/site-' . get_current_blog_id();
        $directory = apply_filters('oyiso_agent_private_directory', $configured);
        if (!is_string($directory) || !self::absolute($directory)) return self::error();
        if (self::publicPath($directory) && !self::inside($directory, $privateRoot)) return self::error();
        if (!wp_mkdir_p($directory)) return self::error();
        $resolved = realpath($directory);
        $resolvedRoot = realpath($privateRoot) ?: $privateRoot;
        if ($resolved === false || !is_writable($resolved)) return self::error();
        if ((self::publicPath($resolved) || self::inside($directory, $privateRoot)) && !self::inside($resolved, $resolvedRoot)) return self::error();
        @chmod($resolved, 0700);
        $guards = [
            'index.php' => "<?php\nhttp_response_code(403);\nexit;\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];
        foreach ($guards as $name => $content) {
            $guard = $resolved . '/' . $name;
            if (is_link($guard) || ((!is_file($guard) || file_get_contents($guard) !== $content) && file_put_contents($guard, $content, LOCK_EX) !== strlen($content))) return self::error();
        }
        return wp_normalize_path($resolved);
    }

    private static function absolute(string $path): bool
    {
        return (path_is_absolute($path) || preg_match('~^[a-zA-Z]:/~', $path) === 1) && !wp_is_stream($path) && !preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $path);
    }

    private static function publicPath(string $path): bool
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        return self::inside($path, realpath(ABSPATH) ?: ABSPATH) || (is_string($documentRoot) && $documentRoot !== '' && self::inside($path, realpath($documentRoot) ?: $documentRoot));
    }

    private static function inside(string $path, string $root): bool
    {
        $path = strtolower(untrailingslashit(wp_normalize_path($path)));
        $root = strtolower(untrailingslashit(wp_normalize_path($root)));
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function error(): WP_Error
    {
        return new WP_Error('agent_private_directory', '无法使用私有附件目录。请检查 wp-content/oyiso-private 的写入权限和目录保护文件。', ['status' => 500]);
    }

    /** @return string|WP_Error */
    public static function path(string $relative): string|WP_Error
    {
        $directory = self::directory();
        if (is_wp_error($directory)) return $directory;
        return self::at($directory, $relative);
    }

    private static function at(string $directory, string $relative): string|WP_Error
    {
        if (!preg_match('/^[a-f0-9-]{36}\.bin$/D', $relative)) return new WP_Error('agent_file_path', '附件路径无效。', ['status' => 500]);
        $path = $directory . '/' . $relative;
        if (is_link($path)) return new WP_Error('agent_file_path', '附件路径无效。', ['status' => 500]);
        $resolved = realpath($path);
        if ($resolved !== false && wp_normalize_path(dirname($resolved)) !== $directory) return new WP_Error('agent_file_path', '附件路径无效。', ['status' => 500]);
        return $path;
    }

    /** Resolve the previous outside-site location without creating it. */
    private static function legacyPath(string $relative): string|WP_Error
    {
        $webRoot = realpath(ABSPATH) ?: ABSPATH;
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $documentRoot = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($documentRoot !== false && self::inside($webRoot, $documentRoot)) $webRoot = $documentRoot;
        $site = substr(hash('sha256', wp_normalize_path(ABSPATH) . '|' . get_current_blog_id()), 0, 16);
        $configured = defined('OYISO_AGENT_PRIVATE_DIR') ? constant('OYISO_AGENT_PRIVATE_DIR') : null;
        if (!is_string($configured) || self::publicPath($configured)) $configured = dirname($webRoot) . '/.oyiso-private/' . $site;
        $directory = apply_filters('oyiso_agent_legacy_private_directory', $configured);
        if (!is_string($directory) || !self::absolute($directory)) return self::error();
        $directory = realpath($directory) ?: $directory;
        if (self::publicPath($directory)) return self::error();
        return self::at(wp_normalize_path($directory), $relative);
    }

    /** @return string|WP_Error Relative file name, never a public URL. */
    public static function write(string $content): string|WP_Error
    {
        $relative = wp_generate_uuid4() . '.bin';
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        $result = self::store($path, $content);
        return is_wp_error($result) ? $result : $relative;
    }

    private static function store(string $path, string $content): bool|WP_Error
    {
        try {
            Oyiso_Private_Files::write($path, $content);
            @chmod($path, 0600);
            $verified = self::readFile($path, hash('sha256', $content));
            return is_wp_error($verified) ? $verified : true;
        } catch (Throwable $error) {
            return new WP_Error('agent_file_write', '无法写入加密附件：' . $error->getMessage(), ['status' => 500]);
        }
    }

    /** @return string|WP_Error */
    public static function read(string $relative, string $checksum): string|WP_Error
    {
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        if (!file_exists($path)) {
            $path = self::legacyPath($relative);
            if (is_wp_error($path)) return $path;
            return self::readFile($path, $checksum, false);
        }
        return self::readFile($path, $checksum);
    }

    private static function readFile(string $path, string $checksum, bool $encrypted = true): string|WP_Error
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size > Oyiso_Agent_Attachments::MAX_IMAGE_UPLOAD + 65536) return new WP_Error('agent_file_missing', '附件文件不存在或大小异常。', ['status' => 404]);
        try {
            if ($encrypted && file_get_contents($path, false, null, 0, 12) !== "OYISO-ENC-1\n") return new WP_Error('agent_file_checksum', '附件文件校验失败。', ['status' => 500]);
            $content = Oyiso_Private_Files::read($path);
        } catch (Throwable $error) {
            return new WP_Error('agent_file_checksum', '附件文件校验失败：' . $error->getMessage(), ['status' => 500]);
        }
        if (!hash_equals($checksum, hash('sha256', $content))) return new WP_Error('agent_file_checksum', '附件文件校验失败。', ['status' => 500]);
        return $content;
    }

    /** Copy and verify before the caller updates metadata or removes the source. */
    public static function relocate(string $relative, string $checksum): bool|WP_Error
    {
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        if (is_file($path)) {
            $verified = self::readFile($path, $checksum);
            if (!is_wp_error($verified)) return true;
            $legacy = self::legacyPath($relative);
            if (is_wp_error($legacy) || !is_file($legacy)) return $verified;
            $content = self::readFile($legacy, $checksum, false);
        } else {
            $legacy = self::legacyPath($relative);
            $content = is_wp_error($legacy) ? $legacy : self::readFile($legacy, $checksum, false);
        }
        return is_wp_error($content) ? $content : self::store($path, $content);
    }

    public static function removeLegacy(string $relative): bool|WP_Error
    {
        $path = self::path($relative); $legacy = self::legacyPath($relative);
        if (is_wp_error($path)) return $path;
        if (is_wp_error($legacy)) return $legacy;
        if ($legacy === $path || !file_exists($legacy)) return true;
        return @unlink($legacy) ? true : new WP_Error('agent_file_delete', '无法清理旧附件文件，原文件已保留。', ['status' => 500]);
    }

    public static function remove(string $relative): bool|WP_Error
    {
        $path = self::path($relative);
        if (is_wp_error($path)) return $path;
        if (file_exists($path) && !@unlink($path)) return new WP_Error('agent_file_delete', '无法删除附件文件，请检查目录权限。', ['status' => 500]);
        return self::removeLegacy($relative);
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
        $temporary = tmpfile();
        if ($temporary === false) return new WP_Error('agent_image_temporary', '无法创建图片处理临时文件。', ['status' => 500]);
        $target = '';
        try {
            $metadata = stream_get_meta_data($temporary);
            $source = $metadata['uri'] ?? '';
            Oyiso_Private_Files::validateTemporaryPath($source);
            if (fwrite($temporary, $upload['content']) !== $upload['size'] || !fflush($temporary)) return new WP_Error('agent_image_temporary', '无法写入图片处理临时文件。', ['status' => 500]);
            $target = $source . '.webp';
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
        } catch (Throwable $error) {
            return new WP_Error('agent_image_temporary', '图片处理失败：' . $error->getMessage(), ['status' => 500]);
        } finally {
            if (is_file($target)) @unlink($target);
            fclose($temporary);
        }
    }
}
