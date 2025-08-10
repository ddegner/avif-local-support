<?php

declare(strict_types=1);

namespace AVIFSuite;

// Prevent direct access
\defined('ABSPATH') || exit;

final class Support
{
    private array $fileCache = [];
    private array $uploadsInfo = [];

    public function init(): void
    {
        $this->uploadsInfo = \wp_upload_dir();
        $this->fileCache = \get_transient('avif_local_support_file_cache') ?: [];
        add_filter('wp_get_attachment_image', [$this, 'wrapAttachment'], 10, 5);
        add_filter('the_content', [$this, 'wrapContentImages']);
        add_filter('post_thumbnail_html', [$this, 'wrapContentImages']);
        add_action('shutdown', [$this, 'saveCache']);
    }

    public function wrapAttachment(string $html, int $attachmentId, $size, bool $icon, array $attr): string
    {
        if (str_contains($html, '<picture') || str_contains($html, 'type="image/avif"')) {
            return $html;
        }

        $mime = \get_post_mime_type($attachmentId);
        if (!\is_string($mime) || !\in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return $html;
        }

        $imageSrc = \wp_get_attachment_image_src($attachmentId, $size);
        if (!$imageSrc || !\is_array($imageSrc) || empty($imageSrc[0])) {
            return $html;
        }

        $avifSrc = $this->avifUrlFor($imageSrc[0]);
        if (!$avifSrc) {
            return $html;
        }

        $srcset = \wp_get_attachment_image_srcset($attachmentId, $size);
        $avifSrcset = $srcset ? $this->convertSrcsetToAvif($srcset) : '';
        $sizes = \wp_get_attachment_image_sizes($attachmentId, $size) ?: '';

        return $this->pictureMarkup($html, $avifSrc, $avifSrcset, $sizes);
    }

    public function wrapContentImages(string $content): string
    {
        if (\is_admin() || \wp_doing_ajax() || (\defined('REST_REQUEST') && REST_REQUEST)) {
            return $content;
        }
        if (!str_contains($content, '<img')) {
            return $content;
        }
        if (str_contains($content, '<picture') || str_contains($content, 'type="image/avif"')) {
            return $content;
        }

        $uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
        if (!$uploadsUrl) {
            return $content;
        }

        $pattern = sprintf(
            '/<img\s+([^>]*?)src=["\'](%s[^"\']*\.(?:jpe?g|JPE?G))["\']([^>]*?)>/i',
            preg_quote($uploadsUrl, '/')
        );

        $result = preg_replace_callback($pattern, function (array $matches): string {
            $fullTag = $matches[0];
            $src = $matches[2];

            $avifSrc = $this->avifUrlFor($src);
            if (!$avifSrc) {
                return $fullTag;
            }

            $avifSrcset = $avifSrc;
            if (preg_match('/srcset=["\']([^"\']*)["\']/', $fullTag, $srcsetMatches)) {
                $converted = $this->convertSrcsetToAvif($srcsetMatches[1]);
                if ($converted) {
                    $avifSrcset = $converted;
                }
            }

            $sizes = '';
            if (preg_match('/sizes=["\']([^"\']*)["\']/', $fullTag, $sizesMatches)) {
                $sizes = $sizesMatches[1];
            }

            return $this->pictureMarkup($fullTag, $avifSrc, $avifSrcset, $sizes);
        }, $content);

        return $result !== null ? $result : $content;
    }

    private function avifUrlFor(string $jpegUrl): ?string
    {
        if (!$this->isUploadsImage($jpegUrl) || !preg_match('/\.(jpe?g|JPE?G)$/', $jpegUrl)) {
            return null;
        }
        $avifUrl = preg_replace('/\.(jpe?g|JPE?G)$/i', '.avif', $jpegUrl);
        $relative = str_replace($this->uploadsInfo['baseurl'] ?? '', '', (string) $avifUrl);
        $avifLocal = ($this->uploadsInfo['basedir'] ?? '') . $relative;
        return $this->avifExists($avifLocal) ? $avifUrl : null;
    }

    private function isUploadsImage(string $src): bool
    {
        $uploadsUrl = $this->uploadsInfo['baseurl'] ?? '';
        return $uploadsUrl !== '' && str_starts_with($src, $uploadsUrl);
    }

    private function avifExists(string $filePath): bool
    {
        if (isset($this->fileCache[$filePath])) {
            return $this->fileCache[$filePath];
        }
        $exists = file_exists($filePath);
        $this->fileCache[$filePath] = $exists;
        return $exists;
    }

    private function convertSrcsetToAvif(string $srcset): string
    {
        $parts = array_map('trim', explode(',', $srcset));
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $pieces = preg_split('/\s+/', trim($part), 2);
            $url = $pieces[0];
            $descriptor = $pieces[1] ?? '';
            $avifUrl = $this->avifUrlFor($url);
            if ($avifUrl) {
                $out[] = trim($avifUrl . ' ' . $descriptor);
            }
        }
        return implode(', ', $out);
    }

    private function pictureMarkup(string $originalHtml, string $avifSrc, string $avifSrcset = '', string $sizes = ''): string
    {
        if ($avifSrc === '' || $originalHtml === '') {
            return $originalHtml;
        }
        $srcset = $avifSrcset !== '' ? $avifSrcset : $avifSrc;
        $sizesAttr = $sizes !== '' ? sprintf(' sizes="%s"', \esc_attr($sizes)) : '';
        return sprintf('<picture><source type="image/avif" srcset="%s"%s>%s</picture>', \esc_attr($srcset), $sizesAttr, $originalHtml);
    }

    public function saveCache(): void
    {
        set_transient('avif_local_support_file_cache', $this->fileCache, (int) get_option('avif_local_support_cache_duration', 3600));
    }
}
