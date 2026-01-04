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
 * Handles: headers, bold, italic, links, code blocks, indented code, lists.
 */
function aviflosu_markdown_to_html( string $markdown ): string {
	$html = $markdown;

	// Store code blocks to protect them from HTML escaping
	$code_blocks = array();
	$code_index  = 0;

	// Handle indented code blocks (4 spaces) - convert to placeholders
	$html = preg_replace_callback(
		'/(?:^    .+$\n?)+/m',
		function ( $m ) use ( &$code_blocks, &$code_index ) {
			// Remove the 4-space indent from each line
			$code                         = preg_replace( '/^    /m', '', $m[0] );
			$placeholder                  = '<!--CODEBLOCK' . $code_index . '-->';
			$code_blocks[ $code_index++ ] = '<pre class="avif-code-block"><code>' . esc_html( trim( $code ) ) . '</code></pre>';
			return $placeholder;
		},
		$html
	);

	// Handle fenced code blocks (```) - convert to placeholders
	$html = preg_replace_callback(
		'/```(?:bash|php|html|css|js)?\n(.*?)```/s',
		function ( $m ) use ( &$code_blocks, &$code_index ) {
			$placeholder                  = '<!--CODEBLOCK' . $code_index . '-->';
			$code_blocks[ $code_index++ ] = '<pre class="avif-code-block"><code>' . esc_html( trim( $m[1] ) ) . '</code></pre>';
			return $placeholder;
		},
		$html
	);

	// Now escape HTML entities (code blocks are protected as placeholders)
	$html = esc_html( $html );

	// Restore code blocks
	foreach ( $code_blocks as $idx => $block ) {
		$html = str_replace( esc_html( '<!--CODEBLOCK' . $idx . '-->' ), $block, $html );
	}

	// Headers (## and ###)
	$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
	$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );

	// Bold and italic
	$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
	$html = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $html );

	// Inline code (but not inside code blocks)
	$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

	// Links [text](url) - need to handle escaped brackets
	$html = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)]+)\)/',
		function ( $m ) {
			return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
		},
		$html
	);

	// Numbered lists (1. item, 2. item, etc.)
	$html = preg_replace( '/^\d+\. (.+)$/m', '<li>$1</li>', $html );

	// Unordered lists (- item)
	$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );

	// Wrap consecutive list items in ul
	$html = preg_replace( '/(<li>.*?<\/li>\s*)+/s', '<ul>$0</ul>', $html );

	// Clean up extra newlines in lists
	$html = preg_replace( '/<\/li>\s*<li>/s', '</li><li>', $html );

	// Paragraphs (double newlines)
	$html = preg_replace( '/\n\n+/', '</p><p>', $html );
	$html = '<p>' . $html . '</p>';

	// Clean up empty paragraphs and fix structure
	$html = preg_replace( '/<p>\s*<(h[34]|ul|pre|ol)/s', '<$1', $html );
	$html = preg_replace( '/<\/(h[34]|ul|pre|ol)>\s*<\/p>/s', '</$1>', $html );
	$html = preg_replace( '/<p>\s*<\/p>/', '', $html );
	// Remove empty paragraphs with just whitespace or single newlines
	$html = preg_replace( '/<p>[\s\n]*<\/p>/', '', $html );

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