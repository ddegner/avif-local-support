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
        add_filter('render_block', [$this, 'renderBlock'], 10, 2);
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

        $html = '<?xml encoding="utf-8" ?>' . $content;
        $dom = new \DOMDocument();
        \libxml_use_internal_errors(true);
        if (!$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            \libxml_clear_errors();
            return $content;
        }
        \libxml_clear_errors();

        $imgs = $dom->getElementsByTagName('img');
        // Because NodeList is live, collect first
        $toProcess = [];
        foreach ($imgs as $img) { $toProcess[] = $img; }
        foreach ($toProcess as $img) {
            if (!($img instanceof \DOMElement)) { continue; }
            // Skip if already inside a <picture>
            if ($this->isInsidePicture($img)) { continue; }
            $src = (string) $img->getAttribute('src');
            $avifUrl = $this->avifUrlFor($src);
            if (!$avifUrl) { continue; }
            // Build AVIF srcset
            $srcset = (string) $img->getAttribute('srcset');
            $sizes = (string) $img->getAttribute('sizes');
            $avifSrcset = $srcset !== '' ? $this->convertSrcsetToAvif($srcset) : $avifUrl;
            $this->wrapImgNodeToPicture($dom, $img, $avifSrcset, $sizes);
        }

        $out = $dom->saveHTML();
        return \is_string($out) && $out !== '' ? $out : $content;
    }

    public function renderBlock(string $block_content, array $block): string
    {
        $name = $block['blockName'] ?? '';
        if ($name !== 'core/image' && $name !== 'core/gallery') {
            return $block_content;
        }
        if ($block_content === '' || strpos($block_content, '<img') === false) {
            return $block_content;
        }

        $html = '<?xml encoding="utf-8" ?>' . $block_content;
        $dom = new \DOMDocument();
        \libxml_use_internal_errors(true);
        if (!$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            \libxml_clear_errors();
            return $block_content;
        }
        \libxml_clear_errors();

        $imgs = $dom->getElementsByTagName('img');
        $toProcess = [];
        foreach ($imgs as $img) { $toProcess[] = $img; }
        foreach ($toProcess as $img) {
            if (!($img instanceof \DOMElement)) { continue; }
            if ($this->isInsidePicture($img)) { continue; }
            $src = (string) $img->getAttribute('src');
            $avifUrl = $this->avifUrlFor($src);
            if (!$avifUrl) { continue; }
            $srcset = (string) $img->getAttribute('srcset');
            $sizes = (string) $img->getAttribute('sizes');
            $avifSrcset = $srcset !== '' ? $this->convertSrcsetToAvif($srcset) : $avifUrl;
            $this->wrapImgNodeToPicture($dom, $img, $avifSrcset, $sizes);
        }

        $out = $dom->saveHTML();
        return \is_string($out) && $out !== '' ? $out : $block_content;
    }

    private function avifUrlFor(string $jpegUrl): ?string
    {
        if (!$this->isUploadsImage($jpegUrl)) {
            return null;
        }
        $parts = \parse_url($jpegUrl);
        if ($parts === false || empty($parts['path'])) {
            return null;
        }
        $path = $parts['path'];
        if (!\preg_match('/\.(jpe?g)$/i', $path)) {
            return null;
        }
        $avifPath = (string) \preg_replace('/\.(jpe?g)$/i', '.avif', $path);
        $reconstructed = ($parts['scheme'] ?? '') !== ''
            ? ($parts['scheme'] . '://')
            : '';
        if (!empty($parts['host'])) { $reconstructed .= $parts['host']; }
        if (!empty($parts['port'])) { $reconstructed .= ':' . $parts['port']; }
        $reconstructed .= $avifPath;
        if (!empty($parts['query'])) { $reconstructed .= '?' . $parts['query']; }
        if (!empty($parts['fragment'])) { $reconstructed .= '#' . $parts['fragment']; }

        $relative = str_replace($this->uploadsInfo['baseurl'] ?? '', '', (string) $reconstructed);
        $avifLocal = ($this->uploadsInfo['basedir'] ?? '') . $relative;
        return $this->avifExists($avifLocal) ? $reconstructed : null;
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

    private function isInsidePicture(\DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent) {
            if ($parent instanceof \DOMElement && strtolower($parent->nodeName) === 'picture') {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    private function wrapImgNodeToPicture(\DOMDocument $dom, \DOMElement $img, string $avifSrcset, string $sizes): void
    {
        $picture = $dom->createElement('picture');
        $source = $dom->createElement('source');
        $source->setAttribute('type', 'image/avif');
        $source->setAttribute('srcset', $avifSrcset);
        if ($sizes !== '') {
            $source->setAttribute('sizes', $sizes);
        }
        $picture->appendChild($source);
        $img->parentNode?->replaceChild($picture, $img);
        $picture->appendChild($img);
    }

    public function saveCache(): void
    {
        set_transient('avif_local_support_file_cache', $this->fileCache, (int) get_option('avif_local_support_cache_duration', 3600));
    }
}
