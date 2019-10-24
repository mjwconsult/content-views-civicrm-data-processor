<?php

/**
 * Content_Views_CiviCRM_Query class.
 */
class Content_Views_CiviCRM_Query {

	/**
	 * Plugin instance reference.
	 * @since 0.1
	 * @var Content_Views_CiviCRM Reference to plugin instance
	 */
	protected $cvc;

	/**
	 * Constructor.
	 *
	 * @param object $cvc Reference to plugin instance
	 */
	public function __construct( $cvc ) {
		$this->cvc = $cvc;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 * @since 0.1
	 */
	public function register_hooks() {
		// alter query params
		add_filter( PT_CV_PREFIX_ . 'query_parameters', [ $this, 'alter_query_parameters' ] );
		// filter query params
		add_filter( PT_CV_PREFIX_ . 'query_params', [ $this, 'filter_query_params' ] );
	}

	/**
	 * Alter query parameters before filtering them.
	 *
	 * @param array $args WP_Query parameters
	 *
	 * @return array $args WP_Query parameters
	 * @since 0.1
	 */
	public function alter_query_parameters( $args ) {
		$id                = current( PT_CV_Functions::settings_values_by_prefix( PT_CV_PREFIX . 'data_processor_id', true ) );
		$sort              = current( PT_CV_Functions::settings_values_by_prefix( PT_CV_PREFIX . 'civicrm_sort', true ) );
		$limit             = current( PT_CV_Functions::settings_values_by_prefix( PT_CV_PREFIX . 'civicrm_limit', true ) );
		$pagination_enable = current( PT_CV_Functions::settings_values_by_prefix( PT_CV_PREFIX . 'enable-pagination', true ) );
		$pagination_limit  = current( PT_CV_Functions::settings_values_by_prefix( PT_CV_PREFIX . 'pagination-items-per-page', true ) );
		$params            = [
			'options' => [],
		];
		if ( $sort ) {
			$params['options']['sort'] = $sort;
		}
		if ( $limit || $limit === '0' ) {
			$params['options']['limit'] = $limit;
		}
		// pagination
		if ( $pagination_enable == 'yes' && ! empty( $pagination_limit ) ) {
			$params['options']['limit'] = $pagination_limit;
		}
		if ( $args['offset'] ) {
			$params['options']['offset'] = $args['offset'];
		}
		// live filters
		if ( $_POST['query'] ) {
			parse_str( $_POST['query'], $query );
			unset( $query['page'] );
			foreach ( $query as $key => $value ) {
				if ( $key == 'contact_name_search' ) {
					$params[ $key ] = [ "IN" => $this->search_contact_name( $value ) ];
				} else {
					$params[ $key ] = $value;
				}
			}
		}
		$args['civicrm_api_params'] = $params;
		$args['data_processor_id']  = $id;

		return $args;
	}

	protected function search_contact_name( string $search ) {
		$result = $this->cvc->api->call_values( 'Contact', 'get', [
			'sequential' => 1,
			'return'     => [ "id" ],
			'sort_name'  => $search,
		] );
		$ids    = [];
		foreach ( $result as $item ) {
			$ids[] = $item['id'];
		}

		return $ids;
	}

	/**
	 * Filters query parameters before they WP_Query is instantiated.
	 *
	 * @param array $args WP_Query parameters
	 *
	 * @return array $args WP_Query parameters
	 * @since 0.1
	 */
	public function filter_query_params( $args ) {
		if ( $args['post_type'] == 'civicrm' ) // bypass query
		{
			$this->bypass_query( $args );
		}

		return $args;
	}


	/**
	 * Bypasses the WP_Query.
	 *
	 * When quering a Contact post type bypasses the WP_Query
	 * and use Civi's API to retrieve Contacts.
	 *
	 * @param array $args Query args to instantiate WP_Query
	 *
	 * @uses 'posts_pre_query'
	 * @since 0.1
	 */
	public function bypass_query( $args ) {
		// bypass query
		add_filter( 'posts_pre_query', function ( $posts, $class ) use ( $args ) {
			if ( empty( $args['data_processor_id'] ) ) {
				return [];
			}
			$dp     = $this->cvc->api->call_values( 'DataProcessorOutput', 'get', [
				'sequential'        => 1,
				'type'              => "api",
				'data_processor_id' => $args['data_processor_id']
			] );
			$dp     = array_shift( $dp );
			$result = $this->cvc->api->call_values( $dp['api_entity'], $dp['api_action'], $args['civicrm_api_params'] );

			// mock WP_Posts contacts
			foreach ( $result as $item ) {
				$post                    = new WP_Post( (object) [] );
				$post->ID                = $item['id'];
				$post->post_title        = $item['title'];
				$post->post_type         = 'civicrm';
				$post->filter            = 'raw'; // set to raw to bypass sanitization
				$post->data_processor_id = $args['data_processor_id'];

				// clean object
				foreach ( $post as $prop => $value ) {
					if ( ! in_array( $prop, [ 'ID', 'post_title', 'post_type', 'filter', 'data_processor_id' ] ) ) {
						unset( $post->$prop );
					}
				}
				// add rest of contact properties
				foreach ( $item as $field => $value ) {
					if ( ! in_array( $field, [ 'hash' ] ) ) {
						$post->$field = $value;
					}
				}

				// build array
				$posts[] = $post;

			}

			return $posts;

		}, 10, 2 );
	}
}