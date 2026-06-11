<?php
/**
 * Shared media-library attachment ID lookups.
 *
 * @package Ddegner\AvifLocalSupport
 */

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Shared media-library attachment ID lookups.
 *
 * Centralizes the attachment queries that bulk operations (conversion scans,
 * LQIP generation, deletions, diagnostics) previously each built themselves.
 */
final class AttachmentQuery {

	/**
	 * JPEG MIME types as stored by WordPress.
	 *
	 * @var string[]
	 */
	public const JPEG_MIMES = array( 'image/jpeg', 'image/jpg' );

	/**
	 * Image MIME types supported by LQIP generation.
	 *
	 * @var string[]
	 */
	public const IMAGE_MIMES = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * All matching attachment IDs, for synchronous (CLI/admin) full scans.
	 *
	 * @param string[] $mimeTypes MIME types to match.
	 * @return int[]
	 */
	public static function allIds( array $mimeTypes = self::JPEG_MIMES ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => $mimeTypes,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'cache_results'          => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Next batch of attachment IDs with ID greater than the cursor.
	 *
	 * ID-ordered cursor pagination keeps batched scans resumable without
	 * offset drift: completed IDs are never revisited, and attachments
	 * uploaded mid-scan (always higher IDs) are still picked up.
	 *
	 * @param int      $cursor    Last processed attachment ID (0 starts fresh).
	 * @param int      $limit     Maximum number of IDs to return.
	 * @param string[] $mimeTypes MIME types to match.
	 * @return int[]
	 */
	public static function idsAfter( int $cursor, int $limit, array $mimeTypes = self::JPEG_MIMES ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $mimeTypes ), '%s' ) );

		// WP_Query cannot paginate by ID cursor; the placeholder list is built
		// from a counted fill, so the interpolated SQL is safe. The sniff also
		// cannot count dynamic IN-clause placeholders.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment'
				   AND post_status = 'inherit'
				   AND post_mime_type IN ({$placeholders})
				   AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				array_merge( $mimeTypes, array( $cursor, $limit ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
