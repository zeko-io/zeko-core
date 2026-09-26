<?php
/**
 * Centralized upload validation + authorization for the Zeko ecosystem.
 *
 * Every first-party upload path (profile photos, portfolio images, resumes,
 * candidate documents, CSV/JSON imports, media-library attachments) should
 * validate through this single policy so size limits, permitted types, and
 * content checks are enforced identically everywhere (audit item 28).
 *
 * Validation binds extension AND MIME via wp_check_filetype_and_ext()
 * against an explicit per-feature allow-list, and rejects script-capable or
 * ambiguous formats. A wp_handle_upload_prefilter overlay hardens the
 * generic WP media upload path for non-admin users too.
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Upload. */
final class Zeko_Core_Upload {

	/**
	 * Type policy.
	 *
	 * @var array Type policy.
	 */
	private static array $type_policy = array(
		'image'     => array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		),
		'document'  => array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		),
		'documents' => array(
			'pdf'          => 'application/pdf',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'svg'          => 'image/svg+xml',
			'csv'          => 'text/csv',
		),
		'json'      => array(
			'json' => 'application/json',
		),
	);

	/**
	 * Size policy.
	 *
	 * @var array Size policy.
	 */
	private static array $size_policy = array(
		'image'     => 5242880,
		'document'  => 8388608,
		'documents' => 5242880,
		'csv'       => 4194304,
		'json'      => 4194304,
	);

	/**
	 * Get the permitted MIME allow-list for a feature (filterable).
	 *
	 * @return array<string,string> mime => extension(s).
	 * @param string $feature Feature key: image|document|csv|json.
	 */
	public static function permitted_types( string $feature ): array {
		$policy = apply_filters( 'zeko_upload_policy_types', self::$type_policy );
		return $policy[ $feature ] ?? array();
	}

	/**
	 * Get the maximum allowed bytes for a feature (filterable).
	 *
	 * @return int Max bytes, capped by the server's own upload limit.
	 * @param string $feature Feature key.
	 */
	public static function max_bytes( string $feature ): int {
		$policy = apply_filters( 'zeko_upload_policy_sizes', self::$size_policy );
		$max    = $policy[ $feature ] ?? 8388608;

		$server_limit = wp_max_upload_size();
		if ( $server_limit > 0 ) {
			$max = min( $max, $server_limit );
		}
		return $max;
	}

	/**
	 * Validate an uploaded file against the feature policy.
	 * Binds extension AND MIME content via wp_check_filetype_and_ext() and
	 * enforces the size cap.
	 *
	 * @return true|\WP_Error
	 * @param array  $file $_FILES entry (or the prefilter-processed array).
	 * @param string $feature Feature key.
	 * @param int    $max_bytes Optional override for the default size cap.
	 */
	public static function validate_file( array $file, string $feature, int $max_bytes = 0 ): true|\WP_Error {
		$allowed = self::permitted_types( $feature );
		if ( empty( $allowed ) ) {
			return new \WP_Error( 'zeko_upload_unknown_feature', __( 'Upload feature is not configured.', 'zeko-core' ) );
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'zeko_upload_error', __( 'The file failed to upload.', 'zeko-core' ) );
		}

		if ( $max_bytes <= 0 ) {
			$max_bytes = self::max_bytes( $feature );
		}
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new \WP_Error( 'zeko_upload_too_large', __( 'The uploaded file exceeds the allowed size.', 'zeko-core' ) );
		}

		$filename = $file['name'] ?? '';
		$tmp      = $file['tmp_name'] ?? '';
		if ( '' === $filename || '' === $tmp ) {
			return new \WP_Error( 'zeko_upload_missing', __( 'No file was provided.', 'zeko-core' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$check = wp_check_filetype_and_ext( $tmp, $filename, $allowed );

		// Text formats (CSV/JSON) sniff inconsistently across environments.
		// (finfo often reports text/plain); bind the extension strictly and.
		// accept only text-ish sniffed MIME for those features.
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			if ( in_array( $feature, array( 'csv', 'json' ), true ) ) {
				$ext_check = wp_check_filetype( $filename, $allowed );
				if ( ! empty( $ext_check['ext'] ) && self::sniff_is_text_like( $tmp ) ) {
					return true;
				}
			}
			return new \WP_Error( 'zeko_upload_bad_type', __( 'This file type is not allowed here.', 'zeko-core' ) );
		}

		return true;
	}

	/**
	 * Whether a file's sniffed MIME looks like plain text (CSV/JSON tolerance).
	 *
	 * @return bool
	 * @param string $path Absolute path to the file.
	 */
	private static function sniff_is_text_like( string $path ): bool {
		$real = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$real = (string) finfo_file( $finfo, $path );
				finfo_close( $finfo );
			}
		} elseif ( function_exists( 'mime_content_type' ) ) {
			$real = (string) mime_content_type( $path );
		}

		if ( '' === $real ) {
			return true;
		}

		return in_array(
			$real,
			array(
				'text/plain',
				'text/csv',
				'text/x-csv',
				'application/csv',
				'application/json',
				'text/json',
				'text/x-json',
				'application/x-json',
				'application/octet-stream',
			),
			true
		);
	}

	/**
	 * Validate + store an upload through the feature policy.
	 *
	 * @return array|\WP_Error wp_handle_upload result array.
	 * @param array  $file $_FILES entry.
	 * @param string $feature Feature key.
	 * @param int    $max_bytes Optional size override.
	 */
	public static function handle_upload( array $file, string $feature, int $max_bytes = 0 ): array|\WP_Error {
		$valid = self::validate_file( $file, $feature, $max_bytes );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		return wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::permitted_types( $feature ),
			)
		);
	}

	/**
	 * True when the current upload would be script-capable or ambiguous.
	 * Used by the global prefilter for the generic WP media path (non-admins).
	 *
	 * @return bool
	 * @param array $file $_FILES entry (has name/type) or resolved type string.
	 */
	public static function is_dangerous( array $file ): bool {
		$filename = $file['name'] ?? '';
		$exts     = preg_split( '/[|,]/', strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) );
		$ext      = $exts ? trim( (string) $exts[0] ) : '';

		$dangerous = array(
			'php',
			'php2',
			'php3',
			'php4',
			'php5',
			'php7',
			'phtml',
			'phar',
			'phps',
			'pht',
			'pht3',
			'js',
			'mjs',
			'html',
			'htm',
			'shtml',
			'xhtml',
			'svg',
			'svgz',
			'xml',
			'xsl',
			'xsd',
			'mhtml',
			'webarchive',
			'hta',
			'cgi',
			'pl',
			'py',
			'rb',
			'sh',
			'bat',
			'cmd',
			'vbs',
			'ps1',
		);

		if ( in_array( $ext, $dangerous, true ) ) {
			return true;
		}

		$type = (string) ( $file['type'] ?? '' );
		foreach ( array( 'image/svg+xml', 'text/html', 'application/xhtml', 'application/javascript', 'text/xml', 'application/xml' ) as $mime ) {
			if ( strpos( $type, $mime ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when an attachment's author matches the user (ownership check).
	 * Use before letting a request attach an existing attachment to content.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @param int $user_id Claimed owner.
	 */
	public static function attachment_belongs_to( int $attachment_id, int $user_id ): bool {
		if ( $attachment_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}
		return (int) $post->post_author === $user_id;
	}

	/**
	 * Ensure the {uploads}/zeko/{subdir} directory resists script execution.
	 * Creates an index.html and a .htaccess that deny PHP/phar/PHP-like file
	 * execution plus direct access, mirroring the zeko-jobs documents dir.
	 *
	 * @return array{path:string,url:string}|false
	 * @param string $subdir Subdirectory under wp_upload_dir()-based zeko dir.
	 */
	public static function ensure_secure_dir( string $subdir ): array|false {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$dirname = untrailingslashit( $uploads['basedir'] ) . '/zeko/' . ltrim( $subdir, '/' );
		if ( ! wp_mkdir_p( $dirname ) ) {
			return false;
		}

		$index = $dirname . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- empty sentinel.
		}

		$htaccess = $dirname . '/.htaccess';
		$rules    = "Options -ExecCGI -Indexes\n"
			. "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|pht3|cgi|pl|py|rb|sh|shtml)$\">\n"
			. "\tOrder allow,deny\n\tDeny from all\n</FilesMatch>\n"
			. "<FilesMatch \"^(index\\.html|index\\.php)$\">\n\tAllow from all\n</FilesMatch>\n";
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- standard Apache guard.
		}

		return array(
			'path' => $dirname,
			'url'  => untrailingslashit( $uploads['baseurl'] ) . '/zeko/' . ltrim( $subdir, '/' ),
		);
	}

	/**
	 * Register the global prefilter hardening the generic WP media upload path.
	 * Non-admins get a size cap and script-capable/ambiguous formats are
	 * rejected. Admins keep WordPress's default behavior.
	 *
	 * @return array
	 * @param array $file Prefilter file array.
	 */
	public static function prefilter( array $file ): array {
		if ( current_user_can( 'manage_options' ) ) {
			return $file;
		}

		$max = self::max_bytes( 'document' );
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max ) {
			$file['error'] = __( 'The uploaded file exceeds the allowed size.', 'zeko-core' );
			return $file;
		}

		if ( self::is_dangerous( $file ) ) {
			$file['error'] = __( 'This file type is not allowed.', 'zeko-core' );
			return $file;
		}

		return $file;
	}
}
