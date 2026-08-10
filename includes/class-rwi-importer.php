<?php
/**
 * REST-oriented WordPress Importer extension.
 *
 * @package Remote_WXR_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports WXR content for one forced local author and collects a JSON summary.
 */
class RWI_Importer extends WP_Import {

	/**
	 * Target local user ID.
	 *
	 * @var int
	 */
	private $target_author_id;

	/**
	 * Items already represented in the summary.
	 *
	 * @var array<string,bool>
	 */
	private $accounted_posts = array();

	/**
	 * Newly created term IDs already counted.
	 *
	 * @var array<int,bool>
	 */
	private $counted_terms = array();

	/**
	 * Number of attempts made for deferred menu items.
	 *
	 * @var array<string,int>
	 */
	private $menu_item_attempts = array();

	/**
	 * Import summary.
	 *
	 * @var array
	 */
	private $result = array(
		'imported' => array(
			'posts'       => 0,
			'attachments' => 0,
			'terms'       => 0,
		),
		'skipped'  => array(
			'posts'       => 0,
			'attachments' => 0,
		),
		'failed'   => array(),
	);

	/**
	 * Creates an importer for a target author.
	 *
	 * @param int  $target_author_id Target local user ID.
	 * @param bool $fetch_attachments Whether remote attachment files are downloaded.
	 */
	public function __construct( $target_author_id, $fetch_attachments = true ) {
		$this->target_author_id = (int) $target_author_id;
		$this->fetch_attachments = (bool) $fetch_attachments;
	}

	/**
	 * Parses WXR with the WordPress Importer's streaming XML processor.
	 *
	 * Selecting it directly avoids the upstream parser selector preferring
	 * SimpleXML, which would load the full XML document into memory.
	 *
	 * @param string $file WXR file path.
	 * @return array|WP_Error Parsed WXR data.
	 */
	public function parse( $file ) {
		$parser = new WXR_Parser_XML_Processor();
		return $parser->parse( $file );
	}

	/**
	 * Runs the importer without its admin-screen output or uploaded-media cleanup.
	 *
	 * @param string $file Private temporary WXR path.
	 * @return array|WP_Error Import summary or a parsing error.
	 */
	public function import_file( $file ) {
		$import_data = $this->parse( $file );
		if ( is_wp_error( $import_data ) ) {
			return $import_data;
		}

		$allowed_versions = array( '1.0', '1.1', '1.2' );
		$version_is_allowed = ! empty( $import_data['version'] )
			&& in_array( (string) $import_data['version'], $allowed_versions, true );

		if ( ! $version_is_allowed ) {
			return new WP_Error(
				'rwi_invalid_wxr',
				__( 'The file is not a supported WXR 1.0 through 1.2 document.', 'remote-wxr-importer' )
			);
		}

		$this->prepare_import_data( $import_data );
		$this->register_import_hooks();

		$previous_term_defer    = wp_defer_term_counting();
		$previous_comment_defer = wp_defer_comment_counting();
		$previous_cache_suspend = wp_suspend_cache_invalidation( true );
		$completed              = false;

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		try {
			do_action( 'import_start' );

			$this->get_author_mapping();
			$this->process_categories();
			$this->process_tags();
			$this->process_terms();
			$this->process_posts();

			$this->backfill_parents();
			$this->backfill_attachment_urls();
			$this->remap_featured_images();

			wp_cache_flush();
			foreach ( get_taxonomies() as $taxonomy ) {
				delete_option( "{$taxonomy}_children" );
				_get_term_hierarchy( $taxonomy );
			}

			$completed = true;
		} finally {
			wp_suspend_cache_invalidation( $previous_cache_suspend );
			wp_defer_term_counting( $previous_term_defer );
			wp_defer_comment_counting( $previous_comment_defer );
			$this->unregister_import_hooks();
		}

		if ( $completed ) {
			do_action( 'import_end' );
		}

		return $this->result;
	}

	/**
	 * Populates the state expected by WP_Import from parsed WXR data.
	 *
	 * @param array $import_data Parsed WXR data.
	 * @return void
	 */
	private function prepare_import_data( $import_data ) {
		$this->version = (string) $import_data['version'];
		$this->get_authors_from_import( $import_data );
		$this->posts      = isset( $import_data['posts'] ) ? $import_data['posts'] : array();
		$this->terms      = isset( $import_data['terms'] ) ? $import_data['terms'] : array();
		$this->categories = isset( $import_data['categories'] ) ? $import_data['categories'] : array();
		$this->tags       = isset( $import_data['tags'] ) ? $import_data['tags'] : array();
		$this->base_url   = isset( $import_data['base_url'] ) ? esc_url( $import_data['base_url'] ) : '';

		$can_rewrite_urls = version_compare( get_bloginfo( 'version' ), '6.7', '>=' )
			&& class_exists( 'WordPress\\DataLiberation\\URL\\WPURL' )
			&& function_exists( 'WordPress\\DataLiberation\\URL\\wp_rewrite_urls' );

		$this->options = apply_filters(
			'wp_import_options',
			array( 'rewrite_urls' => $can_rewrite_urls )
		);

		if ( empty( $this->options['rewrite_urls'] ) || '' === $this->base_url ) {
			$this->options['rewrite_urls'] = false;
			return;
		}

		$old_base_url = rtrim( $this->base_url, '/' ) . '/';
		$new_base_url = rtrim( get_site_url(), '/' ) . '/';

		$this->base_url_parsed = WordPress\DataLiberation\URL\WPURL::parse( $old_base_url );
		$this->site_url_parsed = WordPress\DataLiberation\URL\WPURL::parse( $new_base_url );

		if ( ! $this->base_url_parsed || ! $this->site_url_parsed ) {
			$this->options['rewrite_urls'] = false;
		}
	}

	/**
	 * Registers request-scoped hooks used by the upstream importer.
	 *
	 * @return void
	 */
	private function register_import_hooks() {
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( $this, 'bump_request_timeout' ) );
		add_filter( 'import_attachment_size_limit', array( $this, 'attachment_size_limit' ), 1 );
		add_filter( 'wp_import_post_data_raw', array( $this, 'record_raw_post' ), 1 );
		add_filter(
			'wp_import_post_data_processed',
			array( $this, 'force_post_author' ),
			PHP_INT_MAX,
			2
		);
		add_filter( 'wp_import_existing_post', array( $this, 'record_existing_post' ), PHP_INT_MAX, 2 );
		add_action( 'wp_import_insert_post', array( $this, 'record_inserted_post' ), 10, 4 );
		add_action( 'wp_import_post_exists', array( $this, 'record_invalid_post' ), 10, 1 );
	}

	/**
	 * Removes request-scoped importer hooks.
	 *
	 * @return void
	 */
	private function unregister_import_hooks() {
		remove_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		remove_filter( 'http_request_timeout', array( $this, 'bump_request_timeout' ) );
		remove_filter( 'import_attachment_size_limit', array( $this, 'attachment_size_limit' ), 1 );
		remove_filter( 'wp_import_post_data_raw', array( $this, 'record_raw_post' ), 1 );
		remove_filter(
			'wp_import_post_data_processed',
			array( $this, 'force_post_author' ),
			PHP_INT_MAX
		);
		remove_filter( 'wp_import_existing_post', array( $this, 'record_existing_post' ), PHP_INT_MAX );
		remove_action( 'wp_import_insert_post', array( $this, 'record_inserted_post' ), 10 );
		remove_action( 'wp_import_post_exists', array( $this, 'record_invalid_post' ), 10 );
	}

	/**
	 * Maps every WXR author and creator value to the requested local user.
	 *
	 * This override intentionally never invokes the upstream user-creation path.
	 *
	 * @return void
	 */
	public function get_author_mapping() {
		foreach ( $this->authors as $author_key => $author ) {
			$old_login = isset( $author['author_login'] ) ? $author['author_login'] : $author_key;
			$this->author_mapping[ sanitize_user( $old_login, true ) ] = $this->target_author_id;

			if ( ! empty( $author['author_id'] ) ) {
				$this->processed_authors[ (int) $author['author_id'] ] = $this->target_author_id;
			}
		}

		foreach ( $this->posts as $post ) {
			if ( isset( $post['post_author'] ) ) {
				$this->author_mapping[ sanitize_user( $post['post_author'], true ) ] = $this->target_author_id;
			}
		}
	}

	/**
	 * Enforces the target author after all ordinary importer processing.
	 *
	 * @param array $post_data Processed local post data.
	 * @param array $raw_post  Original WXR post data.
	 * @return array
	 */
	public function force_post_author( $post_data, $raw_post ) {
		unset( $raw_post );

		$post_data['post_author'] = $this->target_author_id;
		return $post_data;
	}

	/**
	 * Supplies the required 30 MB default attachment limit.
	 *
	 * Existing filters can set a non-zero value before this callback, and later
	 * callbacks on import_attachment_size_limit can adjust the resulting value.
	 *
	 * @param int $limit Existing byte limit, or zero for unlimited.
	 * @return int
	 */
	public function attachment_size_limit( $limit ) {
		return (int) $limit > 0 ? (int) $limit : 30 * MB_IN_BYTES;
	}

	/**
	 * Records post types the upstream importer silently skips.
	 *
	 * @param array $post Raw WXR post.
	 * @return array
	 */
	public function record_raw_post( $post ) {
		if ( isset( $post['status'] ) && 'auto-draft' === $post['status'] ) {
			$this->record_skipped( $post );
		}

		return $post;
	}

	/**
	 * Records an existing post before the upstream duplicate branch runs.
	 *
	 * @param int   $post_exists Existing local ID.
	 * @param array $post        Raw WXR post.
	 * @return int
	 */
	public function record_existing_post( $post_exists, $post ) {
		$is_same_type = $post_exists
			&& isset( $post['post_type'] )
			&& get_post_type( $post_exists ) === $post['post_type'];

		if ( $is_same_type ) {
			$this->record_skipped( $post );
		}

		return $post_exists;
	}

	/**
	 * Records a newly inserted non-attachment post.
	 *
	 * @param int|WP_Error $post_id          Inserted local ID or error.
	 * @param int          $original_post_id Original WXR ID.
	 * @param array        $post_data        Processed local data.
	 * @param array        $raw_post         Raw WXR post.
	 * @return void
	 */
	public function record_inserted_post( $post_id, $original_post_id, $post_data, $raw_post ) {
		unset( $original_post_id, $post_data );

		if ( $this->is_accounted( $raw_post ) ) {
			return;
		}

		if ( is_wp_error( $post_id ) ) {
			$this->record_failure( 'post', $this->post_title( $raw_post ), $post_id->get_error_message() );
			$this->mark_accounted( $raw_post );
			return;
		}

		if ( (int) $post_id > 0 ) {
			++$this->result['imported']['posts'];
			$this->mark_accounted( $raw_post );
			return;
		}

		$this->record_failure(
			'post',
			$this->post_title( $raw_post ),
			__( 'The post could not be created.', 'remote-wxr-importer' )
		);
		$this->mark_accounted( $raw_post );
	}

	/**
	 * Records invalid post types reported through the upstream action.
	 *
	 * @param array $post Raw WXR post.
	 * @return void
	 */
	public function record_invalid_post( $post ) {
		if ( $this->is_accounted( $post ) ) {
			return;
		}

		$post_type = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
		$this->record_failure(
			'post',
			$this->post_title( $post ),
			sprintf(
				/* translators: %s: Invalid post type. */
				__( 'The post type is not registered: %s', 'remote-wxr-importer' ),
				$post_type
			)
		);
		$this->mark_accounted( $post );
	}

	/**
	 * Downloads and records one attachment, or treats downloads as skipped.
	 *
	 * @param array  $post Attachment post details.
	 * @param string $url  Remote attachment URL.
	 * @return int|WP_Error
	 */
	public function process_attachment( $post, $url ) {
		$raw_post = array(
			'post_id'    => isset( $post['import_id'] ) ? $post['import_id'] : 0,
			'post_type'  => 'attachment',
			'post_title' => isset( $post['post_title'] ) ? wp_unslash( $post['post_title'] ) : '',
			'post_date'  => isset( $post['post_date'] ) ? $post['post_date'] : '',
			'guid'       => isset( $post['guid'] ) ? $post['guid'] : $url,
		);

		if ( ! $this->fetch_attachments ) {
			$this->record_skipped( $raw_post );
			return new WP_Error(
				'rwi_attachment_skipped',
				__( 'The attachment was skipped because attachment downloads are disabled.', 'remote-wxr-importer' )
			);
		}

		$attachment_id = parent::process_attachment( $post, $url );
		if ( is_wp_error( $attachment_id ) ) {
			$this->record_failure(
				'attachment',
				$this->post_title( $raw_post ),
				sprintf(
					/* translators: %s: WordPress Importer download error. */
					__( 'Download failed: %s', 'remote-wxr-importer' ),
					$attachment_id->get_error_message()
				)
			);
			$this->mark_accounted( $raw_post );
			return $attachment_id;
		}

		if ( (int) $attachment_id <= 0 ) {
			$error = new WP_Error(
				'rwi_attachment_insert_failed',
				__( 'The downloaded file could not be added to the Media Library.', 'remote-wxr-importer' )
			);
			$this->record_failure(
				'attachment',
				$this->post_title( $raw_post ),
				$error->get_error_message()
			);
			$this->mark_accounted( $raw_post );
			return $error;
		}

		++$this->result['imported']['attachments'];
		$this->mark_accounted( $raw_post );

		return $attachment_id;
	}

	/**
	 * Processes a menu item and forces its local post author as well.
	 *
	 * Menu items bypass wp_import_post_data_processed in the upstream importer,
	 * so their author needs to be corrected after insertion.
	 *
	 * @param array $item Raw menu item data.
	 * @return void
	 */
	public function process_menu_item( $item ) {
		$item_key = $this->post_key( $item );
		if ( ! isset( $this->menu_item_attempts[ $item_key ] ) ) {
			$this->menu_item_attempts[ $item_key ] = 0;
		}
		++$this->menu_item_attempts[ $item_key ];

		$deferred_before = count( $this->missing_menu_items );
		parent::process_menu_item( $item );

		$old_post_id = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
		if ( $old_post_id && isset( $this->processed_menu_items[ $old_post_id ] ) ) {
			$menu_item_id = (int) $this->processed_menu_items[ $old_post_id ];
			$updated_id   = wp_update_post(
				array(
					'ID'          => $menu_item_id,
					'post_author' => $this->target_author_id,
				),
				true
			);

			if ( is_wp_error( $updated_id ) ) {
				$this->record_failure( 'post', $this->post_title( $item ), $updated_id->get_error_message() );
			} else {
				++$this->result['imported']['posts'];
			}

			$this->mark_accounted( $item );
			return;
		}

		$was_deferred = count( $this->missing_menu_items ) > $deferred_before;
		if ( $was_deferred && $this->menu_item_attempts[ $item_key ] < 2 ) {
			return;
		}

		if ( ! $this->is_accounted( $item ) ) {
			$this->record_failure(
				'post',
				$this->post_title( $item ),
				__( 'The menu item could not be imported.', 'remote-wxr-importer' )
			);
			$this->mark_accounted( $item );
		}
	}

	/**
	 * Counts newly created categories and reports failures.
	 *
	 * @param array $category Category data.
	 * @return array|false
	 */
	protected function process_category( $category ) {
		$result = parent::process_category( $category );
		if ( false === $result ) {
			$title = isset( $category['cat_name'] ) ? $category['cat_name'] : '';
			$this->record_failure( 'term', $title, __( 'The category could not be imported.', 'remote-wxr-importer' ) );
		} elseif ( ! empty( $result['created'] ) ) {
			$this->count_term( $result['term_id'] );
		}

		return $result;
	}

	/**
	 * Counts newly created tags and reports failures.
	 *
	 * @param array $tag Tag data.
	 * @return array|false
	 */
	protected function process_tag( $tag ) {
		$result = parent::process_tag( $tag );
		if ( false === $result ) {
			$title = isset( $tag['tag_name'] ) ? $tag['tag_name'] : '';
			$this->record_failure( 'term', $title, __( 'The tag could not be imported.', 'remote-wxr-importer' ) );
		} elseif ( ! empty( $result['created'] ) ) {
			$this->count_term( $result['term_id'] );
		}

		return $result;
	}

	/**
	 * Counts newly created custom terms and reports failures.
	 *
	 * @param array $term Term data.
	 * @return array|false
	 */
	protected function process_term( $term ) {
		$result = parent::process_term( $term );
		if ( false === $result ) {
			$title = isset( $term['term_name'] ) ? $term['term_name'] : '';
			$this->record_failure( 'term', $title, __( 'The taxonomy term could not be imported.', 'remote-wxr-importer' ) );
		} elseif ( ! empty( $result['created'] ) ) {
			$this->count_term( $result['term_id'] );
		}

		return $result;
	}

	/**
	 * Counts terms created from item-level term assignments.
	 *
	 * @param array $term    Term data.
	 * @param int   $post_id Local post ID.
	 * @param array $post    Raw WXR post.
	 * @return array|false
	 */
	protected function process_post_term( $term, $post_id, $post ) {
		$taxonomy = isset( $term['domain'] ) && 'tag' === $term['domain']
			? 'post_tag'
			: $term['domain'];

		$existing       = term_exists( $term['slug'], $taxonomy );
		$processed_term = parent::process_post_term( $term, $post_id, $post );

		if ( false === $processed_term ) {
			$title = isset( $term['name'] ) ? $term['name'] : '';
			$this->record_failure( 'term', $title, __( 'The post taxonomy term could not be imported.', 'remote-wxr-importer' ) );
		} elseif ( ! $existing ) {
			$this->count_term( $processed_term['term_id'] );
		}

		return $processed_term;
	}

	/**
	 * Adds one newly created term to the unique term count.
	 *
	 * @param int $term_id Local term ID.
	 * @return void
	 */
	private function count_term( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || isset( $this->counted_terms[ $term_id ] ) ) {
			return;
		}

		$this->counted_terms[ $term_id ] = true;
		++$this->result['imported']['terms'];
	}

	/**
	 * Records a skipped post or attachment once.
	 *
	 * @param array $post Raw WXR post.
	 * @return void
	 */
	private function record_skipped( $post ) {
		if ( $this->is_accounted( $post ) ) {
			return;
		}

		$bucket = isset( $post['post_type'] ) && 'attachment' === $post['post_type']
			? 'attachments'
			: 'posts';
		++$this->result['skipped'][ $bucket ];
		$this->mark_accounted( $post );
	}

	/**
	 * Adds a safe item failure to the response.
	 *
	 * @param string $type   Item type.
	 * @param string $title  Item title.
	 * @param string $reason Failure reason.
	 * @return void
	 */
	private function record_failure( $type, $title, $reason ) {
		$this->result['failed'][] = array(
			'type'   => sanitize_key( $type ),
			'title'  => sanitize_text_field( wp_unslash( (string) $title ) ),
			'reason' => sanitize_text_field( wp_strip_all_tags( (string) $reason ) ),
		);
	}

	/**
	 * Returns a stable request-local identity for a WXR item.
	 *
	 * @param array $post Raw or normalized WXR post data.
	 * @return string
	 */
	private function post_key( $post ) {
		if ( ! empty( $post['post_id'] ) || ! empty( $post['import_id'] ) ) {
			$old_id = ! empty( $post['post_id'] ) ? $post['post_id'] : $post['import_id'];
			return ( isset( $post['post_type'] ) ? $post['post_type'] : 'post' ) . ':' . (int) $old_id;
		}

		return md5(
			implode(
				'|',
				array(
					isset( $post['post_type'] ) ? $post['post_type'] : '',
					isset( $post['post_title'] ) ? $post['post_title'] : '',
					isset( $post['post_date'] ) ? $post['post_date'] : '',
					isset( $post['guid'] ) ? $post['guid'] : '',
				)
			)
		);
	}

	/**
	 * Checks whether an item has already been represented in the summary.
	 *
	 * @param array $post Raw or normalized WXR post data.
	 * @return bool
	 */
	private function is_accounted( $post ) {
		return isset( $this->accounted_posts[ $this->post_key( $post ) ] );
	}

	/**
	 * Marks an item as represented in the summary.
	 *
	 * @param array $post Raw or normalized WXR post data.
	 * @return void
	 */
	private function mark_accounted( $post ) {
		$this->accounted_posts[ $this->post_key( $post ) ] = true;
	}

	/**
	 * Gets a clean item title for the response.
	 *
	 * @param array $post WXR post data.
	 * @return string
	 */
	private function post_title( $post ) {
		return isset( $post['post_title'] )
			? sanitize_text_field( wp_unslash( $post['post_title'] ) )
			: '';
	}
}
