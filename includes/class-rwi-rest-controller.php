<?php
/**
 * REST controller for remote WXR imports.
 *
 * @package Remote_WXR_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the rwi/v1/import endpoint.
 */
class RWI_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'rwi/v1';

	/**
	 * REST route.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/import';

	/**
	 * Registers routes exposed by this controller.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Ensures that only Application Password-authenticated importers may proceed.
	 *
	 * Cookie and nonce authentication is deliberately not accepted by this route.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function permissions_check( $request ) {
		unset( $request );

		$app_password_uuid = function_exists( 'rest_get_authenticated_app_password' )
			? rest_get_authenticated_app_password()
			: null;

		if ( ! is_user_logged_in() || empty( $app_password_uuid ) ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'This endpoint requires Application Password authentication.', 'remote-wxr-importer' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'import' ) || ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'rwi_forbidden',
				__( 'You do not have permission to import content and create posts owned by other users.', 'remote-wxr-importer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Imports one uploaded WXR file.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( $request ) {
		$started_at     = microtime( true );
		$staged_file    = '';
		$output_level   = null;
		$authenticated = get_current_user_id();
		$content_length = (int) $request->get_header( 'content-length' );
		$post_max_size  = (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );

		if ( $post_max_size > 0 && $content_length > $post_max_size ) {
			return $this->error(
				'rwi_file_too_large',
				__( 'The request body exceeds the server post_max_size limit.', 'remote-wxr-importer' ),
				413
			);
		}

		$author_parameter = $request->get_param( 'author_id' );
		if ( null === $author_parameter || '' === $author_parameter ) {
			return $this->error(
				'rwi_missing_author',
				__( 'The author_id field is required.', 'remote-wxr-importer' ),
				400
			);
		}

		$author_is_integer = ! is_array( $author_parameter )
			&& ! is_object( $author_parameter )
			&& ! is_bool( $author_parameter )
			&& preg_match( '/^[1-9][0-9]*$/', trim( (string) $author_parameter ) );

		if ( ! $author_is_integer ) {
			return $this->error(
				'rwi_invalid_author',
				__( 'The author_id field must be the integer ID of an existing user.', 'remote-wxr-importer' ),
				400
			);
		}

		$author_id = absint( $author_parameter );
		if ( ! $author_id || ! get_userdata( $author_id ) ) {
			return $this->error(
				'rwi_invalid_author',
				__( 'No user was found for the specified author_id.', 'remote-wxr-importer' ),
				400
			);
		}

		$file_parameters = $request->get_file_params();
		if ( empty( $file_parameters['file'] ) || ! is_array( $file_parameters['file'] ) ) {
			return $this->error(
				'rwi_missing_file',
				__( 'A WXR XML file must be attached in the file field.', 'remote-wxr-importer' ),
				400
			);
		}

		$upload = $file_parameters['file'];
		if ( isset( $upload['name'] ) && is_array( $upload['name'] ) ) {
			return $this->error(
				'rwi_invalid_file_type',
				__( 'Only one XML file may be uploaded per request.', 'remote-wxr-importer' ),
				400
			);
		}

		$upload_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
			return $this->error(
				'rwi_missing_file',
				__( 'A WXR XML file must be attached in the file field.', 'remote-wxr-importer' ),
				400
			);
		}

		if ( in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return $this->error(
				'rwi_file_too_large',
				__( 'The uploaded file exceeds the size allowed by the server.', 'remote-wxr-importer' ),
				413
			);
		}

		if ( UPLOAD_ERR_OK !== $upload_error ) {
			return $this->error(
				'rwi_import_failed',
				__( 'The uploaded file could not be received.', 'remote-wxr-importer' ),
				500
			);
		}

		$original_name = isset( $upload['name'] ) ? wp_basename( (string) $upload['name'] ) : '';
		$response_name = sanitize_file_name( $original_name );
		if ( '' === $response_name ) {
			$response_name = 'import.xml';
		}

		if ( 'xml' !== strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return $this->error(
				'rwi_invalid_file_type',
				__( 'Only files with the .xml extension may be uploaded.', 'remote-wxr-importer' ),
				400
			);
		}

		$max_upload_size = $this->get_max_upload_size();
		$reported_size   = isset( $upload['size'] ) ? (int) $upload['size'] : 0;
		if ( $reported_size > $max_upload_size ) {
			return $this->file_too_large_error( $max_upload_size );
		}

		try {
			$dependency_error = $this->load_wordpress_importer();
			if ( is_wp_error( $dependency_error ) ) {
				return $dependency_error;
			}

			$staged_file = $this->stage_upload( isset( $upload['tmp_name'] ) ? $upload['tmp_name'] : '' );
			if ( is_wp_error( $staged_file ) ) {
				return $staged_file;
			}

			$actual_size = filesize( $staged_file );
			if ( false === $actual_size ) {
				return $this->error(
					'rwi_import_failed',
					__( 'The size of the uploaded file could not be determined.', 'remote-wxr-importer' ),
					500
				);
			}

			if ( $actual_size > $max_upload_size ) {
				return $this->file_too_large_error( $max_upload_size );
			}

			$client_mime = isset( $upload['type'] ) ? $upload['type'] : '';
			$mime_error  = $this->validate_mime_type( $staged_file, $client_mime );
			if ( is_wp_error( $mime_error ) ) {
				return $mime_error;
			}

			$wxr_validation = $this->validate_wxr( $staged_file );
			if ( is_wp_error( $wxr_validation ) ) {
				return $wxr_validation;
			}

			$fetch_attachments = true;
			if ( null !== $request->get_param( 'fetch_attachments' ) ) {
				$fetch_attachments = rest_sanitize_boolean( $request->get_param( 'fetch_attachments' ) );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$output_level = ob_get_level();
			ob_start();

			$importer = new RWI_Importer( $author_id, $fetch_attachments );
			$result   = $importer->import_file( $staged_file );

			if ( is_wp_error( $result ) ) {
				$this->log_result(
					$authenticated,
					$response_name,
					$author_id,
					$fetch_attachments,
					null,
					microtime( true ) - $started_at,
					$result->get_error_message()
				);

				return $this->error(
					'rwi_invalid_wxr',
					__( 'The WXR file could not be parsed.', 'remote-wxr-importer' ),
					422
				);
			}

			$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
			$response   = array(
				'success'           => true,
				'file'              => $response_name,
				'author_id'         => $author_id,
				'fetch_attachments' => $fetch_attachments,
				'imported'          => $result['imported'],
				'skipped'           => $result['skipped'],
				'failed'            => $result['failed'],
				'elapsed_ms'         => $elapsed_ms,
			);

			$this->log_result(
				$authenticated,
				$response_name,
				$author_id,
				$fetch_attachments,
				$result,
				$elapsed_ms / 1000
			);

			return new WP_REST_Response( $response, 200 );
		} catch ( Throwable $throwable ) {
			$this->log_result(
				$authenticated,
				$response_name,
				$author_id,
				isset( $fetch_attachments ) ? $fetch_attachments : true,
				null,
				microtime( true ) - $started_at,
				$throwable->getMessage()
			);

			return $this->error(
				'rwi_import_failed',
				__( 'An unrecoverable error occurred while running the import.', 'remote-wxr-importer' ),
				500
			);
		} finally {
			if ( null !== $output_level ) {
				while ( ob_get_level() > $output_level ) {
					ob_end_clean();
				}
			}

			if ( is_string( $staged_file ) && '' !== $staged_file && is_file( $staged_file ) ) {
				@unlink( $staged_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Loads the active official WordPress Importer without invoking its admin UI.
	 *
	 * The plugin's main file returns early outside WP_LOAD_IMPORTERS, so the
	 * dependency files need to be loaded directly for REST requests.
	 *
	 * @return true|WP_Error
	 */
	private function load_wordpress_importer() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_basename = 'wordpress-importer/wordpress-importer.php';
		$is_active       = is_plugin_active( $plugin_basename );
		if ( is_multisite() ) {
			$is_active = $is_active || is_plugin_active_for_network( $plugin_basename );
		}

		$importer_directory = WP_PLUGIN_DIR . '/wordpress-importer';
		if ( ! $is_active || ! is_file( $importer_directory . '/wordpress-importer.php' ) ) {
			return $this->error(
				'rwi_importer_missing',
				__( 'The WordPress Importer plugin must be installed and activated.', 'remote-wxr-importer' ),
				424
			);
		}

		require_once ABSPATH . 'wp-admin/includes/import.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/taxonomy.php';

		$parser_files = array(
			'WXR_Parser'               => '/parsers/class-wxr-parser.php',
			'WXR_Parser_SimpleXML'     => '/parsers/class-wxr-parser-simplexml.php',
			'WXR_Parser_XML'           => '/parsers/class-wxr-parser-xml.php',
			'WXR_Parser_Regex'         => '/parsers/class-wxr-parser-regex.php',
			'WXR_Parser_XML_Processor' => '/parsers/class-wxr-parser-xml-processor.php',
		);
		$required_files = array_merge(
			array( '/compat.php', '/class-wp-import.php' ),
			array_values( $parser_files )
		);

		if ( ! class_exists( 'WordPress\\XML\\XMLProcessor' ) ) {
			$required_files[] = '/php-toolkit/load.php';
		}

		foreach ( $required_files as $dependency_file ) {
			if ( ! is_file( $importer_directory . $dependency_file ) ) {
				return $this->error(
					'rwi_importer_missing',
					__( 'The WordPress Importer plugin files are incomplete. Please reinstall the plugin.', 'remote-wxr-importer' ),
					424
				);
			}
		}

		require_once $importer_directory . '/compat.php';

		if ( ! class_exists( 'WordPress\\XML\\XMLProcessor' ) ) {
			require_once $importer_directory . '/php-toolkit/load.php';
		}

		foreach ( $parser_files as $parser_class => $parser_file ) {
			if ( ! class_exists( $parser_class ) ) {
				require_once $importer_directory . $parser_file;
			}
		}

		if ( ! class_exists( 'WP_Import' ) ) {
			require_once $importer_directory . '/class-wp-import.php';
		}

		if ( ! class_exists( 'WP_Import' ) ) {
			return $this->error(
				'rwi_importer_missing',
				__( 'The WordPress Importer could not be loaded.', 'remote-wxr-importer' ),
				424
			);
		}

		if ( ! class_exists( 'RWI_Importer' ) ) {
			require_once RWI_PLUGIN_DIR . 'includes/class-rwi-importer.php';
		}

		return true;
	}

	/**
	 * Copies or moves the PHP upload to a private system temporary file.
	 *
	 * @param string $source PHP upload temporary path.
	 * @return string|WP_Error
	 */
	private function stage_upload( $source ) {
		$source_is_readable = is_string( $source )
			&& '' !== $source
			&& is_file( $source )
			&& is_readable( $source );

		if ( ! $source_is_readable ) {
			return $this->error(
				'rwi_import_failed',
				__( 'The temporary upload file is not readable.', 'remote-wxr-importer' ),
				500
			);
		}

		$temporary_prefix = 'rwi-' . substr( md5( home_url( '/' ) ), 0, 10 ) . '-';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$temporary_file = @tempnam( sys_get_temp_dir(), $temporary_prefix );
		if ( false === $temporary_file ) {
			return $this->error(
				'rwi_import_failed',
				__( 'A temporary file for the import could not be created.', 'remote-wxr-importer' ),
				500
			);
		}

		$moved = false;
		if ( is_uploaded_file( $source ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$moved = @move_uploaded_file( $source, $temporary_file );
		} else {
			// This branch supports REST integration tests and internal requests.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$moved = @copy( $source, $temporary_file );
		}

		if ( ! $moved ) {
			@unlink( $temporary_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $this->error(
				'rwi_import_failed',
				__( 'The uploaded file could not be moved to a secure temporary location.', 'remote-wxr-importer' ),
				500
			);
		}

		return $temporary_file;
	}

	/**
	 * Returns the effective maximum upload size.
	 *
	 * @return int Maximum bytes.
	 */
	private function get_max_upload_size() {
		$default_size      = 50 * MB_IN_BYTES;
		$filtered_size     = (int) apply_filters( 'rwi_max_upload_size', $default_size );
		$configured        = $filtered_size > 0 ? $filtered_size : $default_size;
		$wp_upload_limit   = (int) wp_max_upload_size();
		$php_upload_limit  = (int) wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		$php_post_limit    = (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$effective_limits  = array( $configured );

		foreach ( array( $wp_upload_limit, $php_upload_limit, $php_post_limit ) as $server_limit ) {
			if ( $server_limit > 0 ) {
				$effective_limits[] = $server_limit;
			}
		}

		return max( 1, min( $effective_limits ) );
	}

	/**
	 * Validates both the supplied and server-detected media types.
	 *
	 * @param string $file        Temporary file path.
	 * @param string $client_mime Client supplied MIME type.
	 * @return true|WP_Error
	 */
	private function validate_mime_type( $file, $client_mime ) {
		$allowed_mimes = array( 'text/xml', 'application/xml' );
		$client_mime   = strtolower( trim( strtok( (string) $client_mime, ';' ) ) );

		if ( '' !== $client_mime && ! in_array( $client_mime, $allowed_mimes, true ) ) {
			return $this->error(
				'rwi_invalid_file_type',
				__( 'Only files with a text/xml or application/xml MIME type may be uploaded.', 'remote-wxr-importer' ),
				400
			);
		}

		$detected_mime = '';
		if ( class_exists( 'finfo' ) ) {
			$file_info = new finfo( FILEINFO_MIME_TYPE );
			$detected  = $file_info->file( $file );
			if ( is_string( $detected ) ) {
				$detected_mime = strtolower( trim( strtok( $detected, ';' ) ) );
			}
		} elseif ( function_exists( 'mime_content_type' ) ) {
			$detected = mime_content_type( $file );
			if ( is_string( $detected ) ) {
				$detected_mime = strtolower( trim( strtok( $detected, ';' ) ) );
			}
		}

		if ( '' === $detected_mime || ! in_array( $detected_mime, $allowed_mimes, true ) ) {
			return $this->error(
				'rwi_invalid_file_type',
				__( 'The detected MIME type of the file content is not XML.', 'remote-wxr-importer' ),
				400
			);
		}

		return true;
	}

	/**
	 * Parses the complete XML stream and validates the WXR version marker.
	 *
	 * External entity loading and DTD processing are disabled. DTD-bearing input
	 * is rejected to keep parser behavior consistent across PHP XML backends.
	 *
	 * @param string $file Temporary file path.
	 * @return string|WP_Error Valid WXR version or an error.
	 */
	private function validate_wxr( $file ) {
		if ( ! class_exists( 'XMLReader' ) ) {
			return $this->validate_wxr_with_dom( $file );
		}

		$previous_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$reader  = new XMLReader();
		$opened  = $reader->open( $file, null, LIBXML_NONET | LIBXML_COMPACT );
		$version = '';
		$has_dtd = false;

		if ( $opened ) {
			$reader->setParserProperty( XMLReader::LOADDTD, false );
			$reader->setParserProperty( XMLReader::SUBST_ENTITIES, false );

			while ( $reader->read() ) {
				if ( XMLReader::DOC_TYPE === $reader->nodeType ) {
					$has_dtd = true;
				}

				if ( XMLReader::ELEMENT === $reader->nodeType && 'wxr_version' === $reader->localName ) {
					$namespace = (string) $reader->namespaceURI;
					if ( preg_match( '#^https?://wordpress\\.org/export/1\\.[0-2]/$#', $namespace ) ) {
						$version = trim( $reader->readString() );
					}
				}
			}

			$reader->close();
		}

		$xml_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		$has_parse_error = ! $opened;
		foreach ( $xml_errors as $xml_error ) {
			if ( LIBXML_ERR_ERROR <= $xml_error->level ) {
				$has_parse_error = true;
				break;
			}
		}

		if ( $has_parse_error || $has_dtd ) {
			return $this->error(
				'rwi_invalid_wxr',
				__( 'The XML document is invalid or contains a disallowed DTD.', 'remote-wxr-importer' ),
				422
			);
		}

		if ( ! in_array( $version, array( '1.0', '1.1', '1.2' ), true ) ) {
			return $this->error(
				'rwi_invalid_wxr',
				__( 'The WXR version marker is missing or outside the supported range of 1.0 through 1.2.', 'remote-wxr-importer' ),
				422
			);
		}

		return $version;
	}

	/**
	 * DOM fallback for hosts without XMLReader.
	 *
	 * @param string $file Temporary file path.
	 * @return string|WP_Error Valid WXR version or an error.
	 */
	private function validate_wxr_with_dom( $file ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->error(
				'rwi_invalid_wxr',
				__( 'The server cannot validate the XML document.', 'remote-wxr-importer' ),
				422
			);
		}

		$previous_errors         = libxml_use_internal_errors( true );
		$document                = new DOMDocument();
		$document->resolveExternals = false;
		$document->substituteEntities = false;
		$loaded                  = $document->load( $file, LIBXML_NONET | LIBXML_COMPACT );
		$xml_errors              = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $loaded || $document->doctype ) {
			return $this->error(
				'rwi_invalid_wxr',
				__( 'The XML document is invalid or contains a disallowed DTD.', 'remote-wxr-importer' ),
				422
			);
		}

		foreach ( $xml_errors as $xml_error ) {
			if ( LIBXML_ERR_ERROR <= $xml_error->level ) {
				return $this->error(
					'rwi_invalid_wxr',
					__( 'The XML document could not be parsed.', 'remote-wxr-importer' ),
					422
				);
			}
		}

		$version_nodes = $document->getElementsByTagNameNS( '*', 'wxr_version' );
		foreach ( $version_nodes as $version_node ) {
			$namespace = (string) $version_node->namespaceURI;
			if ( preg_match( '#^https?://wordpress\\.org/export/1\\.[0-2]/$#', $namespace ) ) {
				$version = trim( $version_node->textContent );
				if ( in_array( $version, array( '1.0', '1.1', '1.2' ), true ) ) {
					return $version;
				}
			}
		}

		return $this->error(
			'rwi_invalid_wxr',
			__( 'The WXR version marker is missing or outside the supported range of 1.0 through 1.2.', 'remote-wxr-importer' ),
			422
		);
	}

	/**
	 * Builds a file-size error.
	 *
	 * @param int $max_upload_size Effective limit in bytes.
	 * @return WP_Error
	 */
	private function file_too_large_error( $max_upload_size ) {
		return $this->error(
			'rwi_file_too_large',
			sprintf(
				/* translators: %s: Maximum upload size. */
				__( 'The uploaded file exceeds the allowed size of %s.', 'remote-wxr-importer' ),
				size_format( $max_upload_size )
			),
			413
		);
	}

	/**
	 * Creates a REST-aware error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Safe user-facing message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Writes a single import summary and each failed item to the PHP error log.
	 *
	 * @param int        $authenticated_user Authenticated user ID.
	 * @param string     $file               Original file name.
	 * @param int        $author_id          Target author ID.
	 * @param bool       $fetch_attachments  Whether downloads were enabled.
	 * @param array|null $result             Import summary, when available.
	 * @param float      $elapsed_seconds    Duration in seconds.
	 * @param string     $fatal_reason       Fatal reason, when applicable.
	 * @return void
	 */
	private function log_result(
		$authenticated_user,
		$file,
		$author_id,
		$fetch_attachments,
		$result,
		$elapsed_seconds,
		$fatal_reason = ''
	) {
		$log = array(
			'requested_at'      => gmdate( 'c', (int) ( microtime( true ) - $elapsed_seconds ) ),
			'authenticated_user' => (int) $authenticated_user,
			'file'               => sanitize_file_name( $file ),
			'author_id'          => (int) $author_id,
			'fetch_attachments'  => (bool) $fetch_attachments,
			'elapsed_ms'         => (int) round( $elapsed_seconds * 1000 ),
		);

		if ( is_array( $result ) ) {
			$log['status']   = 'completed';
			$log['imported'] = $result['imported'];
			$log['skipped']  = $result['skipped'];
			$log['failures'] = count( $result['failed'] );
		} else {
			$log['status'] = 'failed';
			$log['reason'] = $this->sanitize_log_value( $fatal_reason );
		}

		$encoded_log = wp_json_encode(
			$log,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[Remote WXR Importer] ' . $encoded_log );

		if ( ! is_array( $result ) ) {
			return;
		}

		foreach ( $result['failed'] as $failure ) {
			$failure['reason'] = isset( $failure['reason'] )
				? $this->sanitize_log_value( $failure['reason'] )
				: '';
			$encoded_failure  = wp_json_encode(
				$failure,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Remote WXR Importer] failure ' . $encoded_failure );
		}
	}

	/**
	 * Removes line breaks and tags from a log value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_log_value( $value ) {
		return trim( preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
	}
}
