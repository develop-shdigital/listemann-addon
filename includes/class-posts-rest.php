<?php
/**
 * REST route of the "Listemann Posts" widget (filter and "load more").
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit;

use Elementor\Plugin as Elementor;
use Listemann_Toolkit\Widgets\Posts;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /wp-json/listemann-toolkit/v1/posts?document=12&element=a1b2c3d&page=2&cats[]=5`
 *
 * Renders one page of cards of a Listemann Posts widget. The client only
 * names the widget (the Elementor document that contains it and the element
 * id) plus the page and the filter terms; the query itself is always built
 * from the settings saved in that document, and the filter terms are reduced
 * to the categories the widget's filter offers. Only published posts are
 * returned.
 *
 * The document must be readable by the current user: published (and not
 * password protected) for visitors; drafts and private documents need
 * `read_post` (logged-in users send the REST nonce, e.g. in the preview).
 *
 * Response: `{ html, pagination, page, maxPages, found, count, cats, message }`.
 */
final class Posts_Rest {

	/**
	 * Route namespace.
	 */
	const ROUTE_NAMESPACE = 'listemann-toolkit/v1';

	/**
	 * Route.
	 */
	const ROUTE = '/posts';

	/**
	 * Highest page number accepted.
	 */
	const MAX_PAGE = 100000;

	/**
	 * Hooks the route.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * URL of the route.
	 *
	 * @return string
	 */
	public static function get_url() {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_items' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'document' => array(
						'description' => 'ID of the Elementor document that contains the widget.',
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					),
					'element'  => array(
						'description' => 'Element ID of the widget.',
						'type'        => 'string',
						'pattern'     => '^[A-Za-z0-9]{1,32}$',
						'required'    => true,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_PAGE,
						'default' => 1,
					),
					'cats'     => array(
						'description' => 'Filter: category IDs.',
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'maxItems'    => 100,
						'default'     => array(),
					),
					'exclude'  => array(
						'description' => 'Current post, left out when the widget excludes the current post.',
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
					),
					'seed'     => array(
						'description' => 'Seed of the random order.',
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 2147483647,
						'default'     => 0,
					),
					'base'     => array(
						'description' => 'URL of the page, for the pagination links.',
						'type'        => 'string',
						'default'     => '',
					),
				),
			)
		);
	}

	/**
	 * Permission check: the document must be readable by the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function check_permission( $request ) {
		return self::can_read_document( (int) $request['document'] );
	}

	/**
	 * Whether the current user may read the widgets of a document.
	 *
	 * Visitors: published, not password protected (unless the password was
	 * entered), a public post type or an Elementor template. Everything else
	 * (drafts, private, pending, revisions, non-public types) needs the
	 * `read_post` capability (revisions: `edit_post`).
	 *
	 * @param int $post_id Document (post) ID.
	 * @return true|\WP_Error
	 */
	public static function can_read_document( $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return self::not_found();
		}

		if ( 'revision' === $post->post_type ) {
			return current_user_can( 'edit_post', $post->ID ) ? true : self::forbidden();
		}

		$public = 'publish' === $post->post_status
			&& ( is_post_type_viewable( $post->post_type ) || 'elementor_library' === $post->post_type );

		if ( $public && ! post_password_required( $post ) ) {
			return true;
		}

		if ( current_user_can( 'read_post', $post->ID ) && ( ! post_password_required( $post ) || current_user_can( 'edit_post', $post->ID ) ) ) {
			return true;
		}

		return self::forbidden();
	}

	/**
	 * Finds an element by id in Elementor element data (nested containers,
	 * sections, columns, inner sections).
	 *
	 * @param array  $elements   Element data (`_elementor_data`).
	 * @param string $element_id Element id.
	 * @return array|null
	 */
	public static function find_element( array $elements, $element_id ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) && (string) $element['id'] === (string) $element_id ) {
				return $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find_element( $element['elements'], $element_id );

				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the Posts widget instance of a document, or an error.
	 *
	 * @param int    $document_id Document ID.
	 * @param string $element_id  Element id.
	 * @return Posts|\WP_Error
	 */
	public static function get_widget( $document_id, $element_id ) {
		$document = Elementor::$instance->documents->get( $document_id );

		if ( ! $document || ! $document->is_built_with_elementor() ) {
			return self::not_found();
		}

		$data    = $document->get_elements_data();
		$element = is_array( $data ) ? self::find_element( $data, $element_id ) : null;

		if ( ! $element || 'widget' !== ( $element['elType'] ?? '' ) || Posts::NAME !== ( $element['widgetType'] ?? '' ) ) {
			return new \WP_Error( 'lst_posts_element_not_found', __( 'This widget was not found in the saved page.', 'listemann-toolkit-addon' ), array( 'status' => 404 ) );
		}

		$widget = Elementor::$instance->elements_manager->create_element_instance( $element );

		if ( ! $widget instanceof Posts ) {
			return self::not_found();
		}

		return $widget;
	}

	/**
	 * Route callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_items( $request ) {
		$document_id = (int) $request['document'];
		$widget      = self::get_widget( $document_id, (string) $request['element'] );

		if ( is_wp_error( $widget ) ) {
			return $widget;
		}

		if ( 'current' === ( $widget->get_settings( 'source' ) ?? '' ) ) {
			return new \WP_Error( 'lst_posts_unsupported', __( 'This widget shows the current query and loads further pages as regular pages.', 'listemann-toolkit-addon' ), array( 'status' => 400 ) );
		}

		// Dynamic tags in the settings resolve against the document.
		Elementor::$instance->db->switch_to_post( $document_id );

		$data = $widget->get_rest_data(
			(int) $request['page'],
			(array) $request['cats'],
			(int) $request['exclude'],
			(int) $request['seed'],
			self::get_base_url( (string) $request['base'], $document_id )
		);

		Elementor::$instance->db->restore_current_post();

		$response = rest_ensure_response( $data );

		if ( ! is_user_logged_in() ) {
			$response->header( 'Cache-Control', 'public, max-age=60' );
		}

		return $response;
	}

	/**
	 * Base URL of the pagination links: the page URL sent by the script when
	 * it belongs to this site, otherwise the document's permalink.
	 *
	 * @param string $url         URL sent by the client.
	 * @param int    $document_id Document ID.
	 * @return string
	 */
	public static function get_base_url( $url, $document_id ) {
		$url  = esc_url_raw( trim( $url ) );
		$home = wp_parse_url( home_url( '/' ) );
		$part = $url ? wp_parse_url( $url ) : false;

		if ( $part && isset( $part['scheme'], $part['host'] ) && in_array( $part['scheme'], array( 'http', 'https' ), true ) && strtolower( $part['host'] ) === strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return Posts::clean_url( $url );
		}

		$permalink = get_permalink( $document_id );

		return $permalink ? $permalink : home_url( '/' );
	}

	/**
	 * Error: not found.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return new \WP_Error( 'lst_posts_not_found', __( 'Not found.', 'listemann-toolkit-addon' ), array( 'status' => 404 ) );
	}

	/**
	 * Error: not allowed (401 for visitors, 403 for logged-in users).
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'lst_posts_forbidden', __( 'Sorry, you are not allowed to view these posts.', 'listemann-toolkit-addon' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
