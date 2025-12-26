<?php
/**
 * About tab template with markdown README parsing.
 *
 * @package Ddegner\AvifLocalSupport
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template local variables.
defined( 'ABSPATH' ) || exit;

/**
 * Simple Markdown to HTML converter for README display.
 * Handles basic markdown: headers, bold, italic, links, code, lists.
 */
function aviflosu_markdown_to_html( string $markdown ): string {
	$html = $markdown;

	// Escape HTML entities first
	$html = esc_html( $html );

	// Headers (## and ###)
	$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
	$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );

	// Bold and italic
	$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
	$html = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $html );

	// Inline code
	$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

	// Links [text](url)
	$html = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)]+)\)/',
		function ( $m ) {
			return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
		},
		$html
	);

	// Code blocks ```...```
	$html = preg_replace_callback(
		'/```(?:bash|php|html)?\n(.*?)```/s',
		function ( $m ) {
			return '<pre style="background:#f6f7f7;padding:10px;border-radius:4px;overflow-x:auto;"><code>' . $m[1] . '</code></pre>';
		},
		$html
	);

	// Unordered lists (- item)
	$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
	$html = preg_replace( '/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html );
	// Clean up extra newlines in lists
	$html = preg_replace( '/<\/li>\n<li>/s', '</li><li>', $html );

	// Paragraphs (double newlines)
	$html = preg_replace( '/\n\n+/', '</p><p>', $html );
	$html = '<p>' . $html . '</p>';

	// Clean up empty paragraphs and fix structure
	$html = preg_replace( '/<p>\s*<(h[34]|ul|pre|ol)/s', '<$1', $html );
	$html = preg_replace( '/<\/(h[34]|ul|pre|ol)>\s*<\/p>/s', '</$1>', $html );
	$html = preg_replace( '/<p>\s*<\/p>/', '', $html );

	return $html;
}

$readme_path    = \AVIFLOSU_PLUGIN_DIR . 'README.md';
$readme_content = '';

// Fallback to readme.txt if README.md doesn't exist
if ( ! file_exists( $readme_path ) || ! is_readable( $readme_path ) ) {
	$readme_path = \AVIFLOSU_PLUGIN_DIR . 'readme.txt';
}

if ( file_exists( $readme_path ) && is_readable( $readme_path ) ) {
	$readme_content = @file_get_contents( $readme_path );
}

// Get version from main plugin file
$version     = '';
$plugin_data = get_file_data( \AVIFLOSU_PLUGIN_DIR . 'avif-local-support.php', array( 'Version' => 'Version' ) );
if ( ! empty( $plugin_data['Version'] ) ) {
	$version = $plugin_data['Version'];
}
?>
<div id="avif-local-support-tab-about" class="avif-local-support-tab">
	<div class="metabox-holder">
		<div class="postbox">
			<div class="inside">
				<?php if ( empty( $readme_content ) ) : ?>
					<p class="description"><?php esc_html_e( 'Unable to read README file.', 'avif-local-support' ); ?></p>
				<?php else : ?>

					<!-- Plugin Header -->
					<div style="margin-bottom:20px;">
						<h2 style="margin:0 0 10px;">
							AVIF Local Support
							<?php if ( $version !== '' ) : ?>
								<span class="avif-badge avif-badge-neutral"
									style="font-size:12px;font-weight:normal;margin-left:8px;">v<?php echo esc_html( $version ); ?></span>
							<?php endif; ?>
						</h2>
						<p>
							<a href="https://wordpress.org/plugins/avif-local-support/" target="_blank" rel="noopener">
								<?php esc_html_e( 'View on WordPress.org', 'avif-local-support' ); ?>
							</a>
							&nbsp;|&nbsp;
							<a href="https://github.com/ddegner/avif-local-support" target="_blank" rel="noopener">
								<?php esc_html_e( 'GitHub Repository', 'avif-local-support' ); ?>
							</a>
						</p>
					</div>

					<!-- Readme Content -->
					<div class="avif-readme-section">
						<?php
						// For markdown files, convert to HTML
						if ( str_ends_with( $readme_path, '.md' ) ) {
							// Remove badges and first header (we show our own)
							$content = preg_replace( '/^# .+$/m', '', $readme_content, 1 );
							$content = preg_replace( '/\[!\[.+?\]\(.+?\)\]\(.+?\)/', '', $content );
							$content = preg_replace( '/\[!\[.+?\]\(.+?\)\]/', '', $content );
							echo wp_kses_post( aviflosu_markdown_to_html( trim( $content ) ) );
						} else {
							// For readme.txt, use wpautop
							echo wp_kses_post( wpautop( $readme_content ) );
						}
						?>
					</div>

				<?php endif; ?>
			</div>
		</div>
	</div>
</div>