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

	// Store code blocks to protect them from HTML escaping.
	$code_blocks = array();
	$code_index  = 0;

	// Handle indented code blocks (4 spaces).
	$html = preg_replace_callback(
		'/(?:^    .+$\n?)+/m',
		function ( $m ) use ( &$code_blocks, &$code_index ) {
			$code                         = preg_replace( '/^    /m', '', $m[0] );
			$placeholder                  = '<!--CODEBLOCK' . $code_index . '-->';
			$code_blocks[ $code_index++ ] = '<pre class="avif-code-block"><code>' . esc_html( trim( $code ) ) . '</code></pre>';
			return $placeholder;
		},
		$html
	);

	// Handle fenced code blocks.
	$html = preg_replace_callback(
		'/```(?:bash|php|html|css|js)?\n(.*?)```/s',
		function ( $m ) use ( &$code_blocks, &$code_index ) {
			$placeholder                  = '<!--CODEBLOCK' . $code_index . '-->';
			$code_blocks[ $code_index++ ] = '<pre class="avif-code-block"><code>' . esc_html( trim( $m[1] ) ) . '</code></pre>';
			return $placeholder;
		},
		$html
	);

	// Escape everything else.
	$html = esc_html( $html );

	// Restore code blocks.
	foreach ( $code_blocks as $idx => $block ) {
		$html = str_replace( esc_html( '<!--CODEBLOCK' . $idx . '-->' ), $block, $html );
	}

	$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
	$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
	$html = preg_replace( '/\*\*([^\n*]+?)\*\*/', '<strong>$1</strong>', $html );
	$html = preg_replace( '/(?<!\*)\*([^\n*]+?)\*(?!\*)/', '<em>$1</em>', $html );
	$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

	$html = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)]+)\)/',
		function ( $m ) {
			return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
		},
		$html
	);

	$html = preg_replace( '/^\d+\. (.+)$/m', '<oli>$1</oli>', $html );
	$html = preg_replace( '/^\* (.+)$/m', '<uli>$1</uli>', $html );
	$html = preg_replace( '/^- (.+)$/m', '<uli>$1</uli>', $html );
	$html = preg_replace( '/(?:<oli>.*?<\/oli>\s*)+/s', '<ol>$0</ol>', $html );
	$html = preg_replace( '/(?:<uli>.*?<\/uli>\s*)+/s', '<ul>$0</ul>', $html );
	$html = str_replace(
		array( '<oli>', '</oli>', '<uli>', '</uli>' ),
		array( '<li>', '</li>', '<li>', '</li>' ),
		$html
	);
	$html = preg_replace( '/\n\n+/', '</p><p>', $html );
	$html = '<p>' . $html . '</p>';
	$html = preg_replace( '/<p>\s*<(h[34]|ul|pre|ol)/s', '<$1', $html );
	$html = preg_replace( '/<\/(h[34]|ul|pre|ol)>\s*<\/p>/s', '</$1>', $html );
	$html = preg_replace( '/<p>\s*<\/p>/', '', $html );
	$html = preg_replace( '/<p>[\s\n]*<\/p>/', '', $html );

	return $html;
}

/**
 * Convert WordPress.org readme.txt headings to markdown headings.
 * Strips metadata headers so About starts at the first readme section.
 *
 * @param string $readme Raw readme.txt content.
 */
function aviflosu_wporg_readme_to_markdown( string $readme ): string {
	$content       = str_replace( array( "\r\n", "\r" ), "\n", $readme );
	$lines         = explode( "\n", $content );
	$first_section = null;

	foreach ( $lines as $index => $line ) {
		if ( preg_match( '/^==\s*.+\s*==$/', trim( $line ) ) ) {
			$first_section = $index;
			break;
		}
	}

	if ( null !== $first_section ) {
		$content = implode( "\n", array_slice( $lines, $first_section ) );
	}

	$content = preg_replace( '/^==\s*(.+?)\s*==$/m', '## $1', $content );
	$content = preg_replace( '/^=\s*(.+?)\s*=$/m', '### $1', $content );

	return trim( $content );
}

/**
 * Strip markdown readme title and WordPress.org metadata header lines.
 *
 * @param string $readme Raw README.md content.
 */
function aviflosu_strip_markdown_readme_header( string $readme ): string {
	$content = str_replace( array( "\r\n", "\r" ), "\n", $readme );
	$lines   = explode( "\n", $content );

	if ( empty( $lines ) ) {
		return '';
	}

	if ( preg_match( '/^#\s+.+$/', trim( $lines[0] ) ) ) {
		array_shift( $lines );
	}

	$header_keys = '(Contributors|Donate link|Tags|Requires at least|Tested up to|Stable tag|Requires PHP|License|License URI)';

	while ( ! empty( $lines ) && preg_match( '/^' . $header_keys . '\s*:/i', trim( $lines[0] ) ) ) {
		array_shift( $lines );
	}

	while ( ! empty( $lines ) && '' === trim( $lines[0] ) ) {
		array_shift( $lines );
	}

	return trim( implode( "\n", $lines ) );
}

$readme_path    = \AVIFLOSU_PLUGIN_DIR . 'readme.txt';
$readme_content = '';

if ( ! file_exists( $readme_path ) || ! is_readable( $readme_path ) ) {
	$readme_path = \AVIFLOSU_PLUGIN_DIR . 'README.md';
}

if ( file_exists( $readme_path ) && is_readable( $readme_path ) ) {
	$readme_content = @file_get_contents( $readme_path );
}

$version     = '';
$plugin_data = get_file_data( \AVIFLOSU_PLUGIN_FILE, array( 'Version' => 'Version' ) );
if ( ! empty( $plugin_data['Version'] ) ) {
	$version = $plugin_data['Version'];
}
?>
<div id="avif-local-support-tab-about" class="avif-local-support-tab">
	<div class="avif-settings-form avif-readme-content">
		<h2 class="title"><?php esc_html_e( 'About', 'avif-local-support' ); ?></h2>
		<?php if ( empty( $readme_content ) ) : ?>
			<p class="description"><?php esc_html_e( 'Unable to read README file.', 'avif-local-support' ); ?></p>
		<?php else : ?>
			<?php if ( $version !== '' ) : ?>
				<p class="description">
					<?php
					/* translators: %s: Plugin version. */
					echo wp_kses_post( sprintf( __( 'Version: %s', 'avif-local-support' ), '<strong>' . esc_html( $version ) . '</strong>' ) );
					?>
				</p>
			<?php endif; ?>
			<p>
				<a href="https://wordpress.org/plugins/avif-local-support/" target="_blank" rel="noopener"><?php esc_html_e( 'View on WordPress.org', 'avif-local-support' ); ?></a>
				&nbsp;|&nbsp;
				<a href="https://github.com/ddegner/avif-local-support" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub Repository', 'avif-local-support' ); ?></a>
			</p>
			<hr />
			<?php
			if ( str_ends_with( $readme_path, '.md' ) ) {
				$content = aviflosu_strip_markdown_readme_header( $readme_content );
				$content = preg_replace( '/\[!\[.+?\]\(.+?\)\]\(.+?\)/', '', $content );
				$content = preg_replace( '/\[!\[.+?\]\(.+?\)\]/', '', $content );
				echo wp_kses_post( aviflosu_markdown_to_html( trim( $content ) ) );
			} else {
				echo wp_kses_post( aviflosu_markdown_to_html( aviflosu_wporg_readme_to_markdown( $readme_content ) ) );
			}
			?>
		<?php endif; ?>
	</div>
</div>
