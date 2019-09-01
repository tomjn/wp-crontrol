<?php
/**
 * Functions related to logging cron events.
 *
 * @package WP Crontrol
 */

namespace Crontrol;

use ParseError;
use Error;
use Throwable;
use Exception;

class Log {

	protected $data = array();
	protected $old_exception_handler = null;

	public function init() {
		foreach ( Event\count_by_hook() as $hook => $count ) {
			add_action( $hook, array( $this, 'log_start' ), -9999, 50 );
			add_action( $hook, array( $this, 'log_end' ), 9999, 50 );
		}

		add_filter( 'manage_crontrol_log_posts_columns',       array( $this, 'columns' ) );
		add_action( 'manage_crontrol_log_posts_custom_column', array( $this, 'column' ), 10, 2 );

		register_post_type( 'crontrol_log', array(
			'public'  => false,
			'show_ui' => true,
			'show_in_admin_bar' => false,
			'labels'  => array(
				'name'                     => 'Cron Logs',
				'singular_name'            => 'Cron Log',
				'menu_name'                => 'Cron Logs',
				'name_admin_bar'           => 'Cron Log',
				'add_new'                  => 'Add New',
				'add_new_item'             => 'Add New Cron Log',
				'edit_item'                => 'Edit Cron Log',
				'new_item'                 => 'New Cron Log',
				'view_item'                => 'View Cron Log',
				'view_items'               => 'View Cron Logs',
				'search_items'             => 'Search Cron Logs',
				'not_found'                => 'No cron logs found.',
				'not_found_in_trash'       => 'No cron logs found in trash.',
				'parent_item_colon'        => 'Parent Cron Log:',
				'all_items'                => 'Cron Logs',
				'archives'                 => 'Cron Log Archives',
				'attributes'               => 'Cron Log Attributes',
				'insert_into_item'         => 'Insert into cron log',
				'uploaded_to_this_item'    => 'Uploaded to this cron log',
				'filter_items_list'        => 'Filter cron logs list',
				'items_list_navigation'    => 'Cron logs list navigation',
				'items_list'               => 'Cron logs list',
				'item_published'           => 'Cron log published.',
				'item_published_privately' => 'Cron log published privately.',
				'item_reverted_to_draft'   => 'Cron log reverted to draft.',
				'item_scheduled'           => 'Cron log scheduled.',
				'item_updated'             => 'Cron log updated.',
			),
			'show_in_menu' => 'tools.php',
			'map_meta_cap' => true,
			'capabilities' => array(
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'do_not_allow',
				'publish_posts'          => 'do_not_allow',
				'read_private_posts'     => 'manage_options',
				'read'                   => 'manage_options',
				'delete_posts'           => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'delete_others_posts'    => 'manage_options',
				'edit_private_posts'     => 'do_not_allow',
				'edit_published_posts'   => 'do_not_allow',
				'create_posts'           => 'do_not_allow',
			),
		) );

		register_taxonomy( 'crontrol_log_hook', 'crontrol_log', array(
			'public' => false,
			'show_admin_column' => true,
			'capabilities' => array(
				'manage_terms' => 'do_not_allow',
				'edit_terms'   => 'do_not_allow',
				'delete_terms' => 'do_not_allow',
				'assign_terms' => 'do_not_allow',
			),
			'labels' => array(
				'menu_name'                  => 'Hooks',
				'name'                       => 'Hooks',
				'singular_name'              => 'Hook',
				'search_items'               => 'Search Hooks',
				'popular_items'              => 'Popular Hooks',
				'all_items'                  => 'All Hooks',
				'parent_item'                => 'Parent Hook',
				'parent_item_colon'          => 'Parent Hook:',
				'edit_item'                  => 'Edit Hook',
				'view_item'                  => 'View Hook',
				'update_item'                => 'Update Hook',
				'add_new_item'               => 'Add New Hook',
				'new_item_name'              => 'New Hook Name',
				'separate_items_with_commas' => 'Separate hooks with commas',
				'add_or_remove_items'        => 'Add or remove hooks',
				'choose_from_most_used'      => 'Choose from most used hooks',
				'not_found'                  => 'No hooks found',
				'no_terms'                   => 'No hooks',
				'items_list_navigation'      => 'Hooks list navigation',
				'items_list'                 => 'Hooks list',
				'most_used'                  => 'Most Used',
				'back_to_items'              => '&larr; Back to Hooks',
			),
		) );
	}

	public function columns( array $columns ) {
		$columns['error'] = 'Error';

		return $columns;
	}

	public function column( $name, $post_id ) {
		switch ( $name ) {

			case 'error':
				$error = get_post_meta( $post_id, 'crontrol_log_exception', true );
				if ( ! empty( $error ) ) {
					printf(
						'<span style="color:#c00"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %1$s</span><br>%2$s:%3$s',
						esc_html( $error['message'] ),
						esc_html( str_replace( [ WP_CONTENT_DIR . '/', ABSPATH . '/' ], '', $error['file'] ) ),
						esc_html( $error['line'] )
					);
				}
				break;

		}
	}

	/**
	 * Exception handler.
	 *
	 * In PHP >= 7 this will catch Throwable objects.
	 * In PHP < 7 it will catch Exception objects.
	 *
	 * @param Throwable|Exception $e The error or exception.
	 * @throws Exception Re-thrown when necessary.
	 */
	public function exception_handler( $e ) {
		$this->data['exception'] = $e;

		$this->log_end();

		if ( $this->old_exception_handler ) {
			call_user_func( $this->old_exception_handler, $e );
		} else {
			throw new Exception( $e->getMessage(), $e->getCode(), $e );
		}
	}

	public function log_start() {
		global $wpdb;

		$this->data['start_memory']  = memory_get_usage();
		$this->data['start_time']    = microtime( true );
		$this->data['start_queries'] = $wpdb->num_queries;
		$this->data['args']          = func_get_args();
		$this->data['hook']          = current_filter();

		$this->old_exception_handler = set_exception_handler( array( $this, 'exception_handler' ) );
	}

	public function log_end() {
		global $wpdb;

		set_exception_handler( $this->old_exception_handler );

		$this->data['end_memory']  = memory_get_usage();
		$this->data['end_time']    = microtime( true );
		$this->data['end_queries'] = $wpdb->num_queries;

		$post_id = wp_insert_post( array(
			'post_type'    => 'crontrol_log',
			'post_title'   => $this->data['hook'],
			'post_date'    => get_date_from_gmt( date( 'Y-m-d H:i:s', $this->data['start_time'] ), 'Y-m-d H:i:s' ),
			'post_status'  => 'publish',
			'post_content' => wp_json_encode( $this->data['args'] ),
			'post_name'    => uniqid(),
		), true );

		if ( is_wp_error( $post_id ) ) {
			return; // ¯\_(ツ)_/¯
		}

		$metas = array(
			'crontrol_log_memory'  => ( $this->data['end_memory'] - $this->data['start_memory'] ),
			'crontrol_log_time'    => ( $this->data['end_time'] - $this->data['start_time'] ),
			'crontrol_log_queries' => ( $this->data['end_queries'] - $this->data['start_queries'] ),
		);

		if ( ! empty( $this->data['exception'] ) ) {
			$metas['crontrol_log_exception'] = array(
				'message' => $this->data['exception']->getMessage(),
				'file'    => $this->data['exception']->getFile(),
				'line'    => $this->data['exception']->getLine(),
			);
		}

		foreach ( $metas as $meta_key => $meta_value ) {
			add_post_meta( $post_id, $meta_key, $meta_value, true );
		}

		wp_set_post_terms( $post_id, $this->data['hook'], 'crontrol_log_hook' );
	}

	/**
	 * Singleton instantiator.
	 *
	 * @return Log Logger instance.
	 */
	public static function get_instance() {
		static $instance;

		if ( ! isset( $instance ) ) {
			$instance = new Log();
		}

		return $instance;
	}

	/**
	 * Private class constructor. Use `get_instance()` to get the instance.
	 */
	final private function __construct() {}

}