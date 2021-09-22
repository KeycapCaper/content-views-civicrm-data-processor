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
		if ( $args['post_type'] != 'civicrm' ) {
			return $args;
		}
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
		$offset = class_exists( 'CVP_LIVE_FILTER_QUERY' ) ? CVP_LIVE_FILTER_QUERY::_get_page(): 0;
		if ( $offset && $params['options']['limit'] ) {
			$offset = ( $offset - 1 ) * $params['options']['limit'];
			$params['options']['offset'] = $offset;
		}
		// live filters
		if ( !empty( $_POST['query'] ) ) {
			parse_str( $_POST['query'], $query );
			unset( $query['page'] );
			foreach ( $query as $key => $value ) {
				if ( $this->cvc->dp_options->has_option( $key, Content_Views_CiviCRM_Dp_Option::CONTACT_NAME_SEARCH ) ) {
					$params[ $key ] = [ "IN" => $this->search_contact_name( $value ) ];
				} else {
					$params[ $key ] = $value;
				}
			}
		}
		// hidden filters
		$fields = $this->cvc->api->call_values( 'DataProcessorFilter', 'get', [
			'is_exposed'        => 1,
			'data_processor_id' => $id
		] );
		foreach ( $fields as $field ) {
			if ( $field['is_required'] && $this->cvc->dp_options->has_option( $field['name'], Content_Views_CiviCRM_Dp_Option::USER_CONTACT_ID ) ) {
				$params[ $field['name'] ] = CRM_Core_Session::getLoggedInContactID();
			}
			// fixme all required filters should be an exception

			$requestValue = \CRM_Utils_Request::retrieveValue($field['name'], 'String');
			if (!empty($requestValue)) {
				$params[$field['name']] = $requestValue;
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
			'options'    => [ 'limit' => 0 ]
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
			if ( isset( $class->query['post_type'] ) && ( $class->query['post_type'] !== 'civicrm' ) ) {
				return $posts;
			}
			if ( empty( $args['data_processor_id'] ) ) {
				return [];
			}
			$dp     = $this->cvc->api->call_values( 'DataProcessorOutput', 'get', [
				'sequential'        => 1,
				'type'              => "api",
				'data_processor_id' => $args['data_processor_id']
			] );
			$dp     = array_shift( $dp );
			
			$apiParams = $this->create_api_params_from_arguments( $args );
			$apiParams['options'] = $args['civicrm_api_params']['options'] ?: [];

			$result = $this->cvc->api->call_values( $dp['api_entity'], $dp['api_action'], $apiParams );

			// clear posts from previous short codes
			$posts = [];

			// mock WP_Posts contacts
			foreach ( $result as $item ) {
				$post                    = new WP_Post( (object) [] );
				$post->ID                = $item['id'];
				$post->post_type         = 'civicrm';
				$post->filter            = 'raw'; // set to raw to bypass sanitization
				$post->data_processor_id = $args['data_processor_id'];

				// title - replace the placeholder with its value in current item
				$title = current( PT_CV_Functions::settings_values_by_prefix( PT_CV_PREFIX . 'civicrm_title', true ) );
				if ( ! empty( $title ) ) {
					$title = preg_replace_callback( '/\${(.*)}/U',
						function ( $matches ) use ( $item ) {
							return !empty( $item[ $matches[1] ] ) ? $item[ $matches[1] ] : '' ;
						},
						$title );
				}
				$post->post_title = $title;

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

	/**
	 * Convert the raw input into the expected format
	 * for CiviCRM API Parameters based on Data Processor Filter Settings
	 * 
	 * @param array $args Query args to instantiate WP_Query
	 * 
	 * @return array $apiParams API params in the format CiviCRM expects
	 * @since 0.2.2
	 */
	public function create_api_params_from_arguments( $args ) {

		// Retrieve a list of filters defined on Data Processor
		$filtersList = $this->cvc->api->call_values( 'DataProcessorFilter', 'get', [
			'sequential'        => 1,
			'is_required'       => 0,
			'is_exposed'        => 1,
			'data_processor_id' => $args['data_processor_id']
		] );

		$apiParams = [];
		if ( ! empty( $filtersList ) ) {
			foreach ($filtersList as $filter) {
				$apiParam = $this->filter_to_api_param( $args, $filter );
				if ( $apiParam !== null ) {
					$name = $filter['name'];
					$apiParams[ $name ] = $apiParam;
				}
			}
		}

		return $apiParams;
	}

	/**
	 * Convert a filter to CiviCRM API format
	 * See: Civi\DataProcessor\FilterHandler\ContactFilter::applyFilterFromSubmittedFilterParams
	 * 
	 * @param array $args Query args to instantiate WP_Query
	 * @param array $filter item returned DataProcessorFilter call
	 * 
	 * @return array $apiParams API params in the format CiviCRM expects
	 * @return null Filter is not currently set
	 * @since 0.2.2
	 */
	public function filter_to_api_param( $args, $filter ) {

		$civicrm_api_params = $args['civicrm_api_params'];
		$name = $filter['name'];
		$type = $filter['type'];

		// This filter is not currently used
		if (empty($civicrm_api_params[$name])) {
			return null;
		}
		
		// This filter does not tell us what to do, the default is the has operator
		$filter_value = empty($filter['filter_value']) ? [ 'op' => 'has' ] : $filter['filter_value'];

		if ( $type === 'date_filter' /* || $filterSpec->type == 'Timestamp' */ ) {
			// Handle dates separately
			return $this->date_filter_to_api_param( $args, $filter );
		} elseif ( isset( $filter_value['op'] ) ) {
			$op = $filter_value['op'];
			switch ( $op ) {
				case 'IN':
				case 'NOT IN':
				case '=':
				case '!=':
				case '>':
				case '<':
				case '>=':
				case '<=':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						return [
							$op => $civicrm_api_params[$name]
						];
					}
					break;
				case 'has':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						return [
							'LIKE' => '%' . $civicrm_api_params[$name] . '%'
						];
					}
					break;
				case 'nhas':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						return [
							'NOT LIKE' => '%' . $filter_value['value'] . '%'
						];
					}
					break;
				case 'sw':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						return [
							'LIKE' => $filter_value['value'] . '%'
						];
					}
					break;
				case 'ew':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						return [
							'LIKE' => '%' . $filter_value['value']
						];
					}
					break;
				case 'null':
					return [
						'IS NULL' => 1,
					];
				case 'not null':
					return [
						'IS NOT NULL' => 1,
					];
				case 'bw':
				case 'nbw':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						$min_max = explode( ';', $civicrm_api_params[$name] );
						if ( count( $min_max ) === 2 ) {
							return [
								($op === 'nbw' ? 'NOT ' : '') . 'BETWEEN' => [ $min_max[0], $min_max[1] ]
							];
						}
					}
					break;
			}
		}

		return null;
	}

	/**
	 * Translated date filters into the correct CiviCRM API Call
	 * See: Civi\DataProcessor\FilterHandler\ContactFilter::applyDateFilter
	 * 
	 * @param array $args Query args to instantiate WP_Query
	 * @param array $filter item returned DataProcessorFilter call
	 * 
	 * @return array $apiParams API params in the format CiviCRM expects
	 * @return null Filter is not currently set
	 * @since 0.2.2
	 */
	public function date_filter_to_api_param( $args, $filter ) {

		$civicrm_api_params = $args['civicrm_api_params'];
		$name = $filter['name'];

		// This filter is not currently used
		if ( empty( $civicrm_api_params[$name] ) ) {
			return null;
		}

		// This filter does not tell us what to do, the default is the has operator
		$filter_value = empty( $filter['filter_value'] ) ? [ 'op' => '=', 'min' => '', 'max' => '' ] : $filter['filter_value'];

		if ( isset( $filter_value['op'] ) ) {
			$op = $filter_value['op'];
			switch ( $op ) {
				case '=':
				case '!=':
				case '>':
				case '<':
				case '>=':
				case '<=':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						try {
							$dateTime = new DateTime( $civicrm_api_params[$name] );
						} catch (Exception $e) {
							// not a valid date
							return null;
						}
						return [
							// Not the best way to differentiate dates from datetimes, but will likely work in the vast majority of cases
							$op => $dateTime->format( 'His' ) === '000000' ? $dateTime->format( 'Y-m-d' ) : $dateTime->format( 'Y-m-d H:i:s' )
						];
					}
					break;
				case 'null':
					return [
						'IS NULL' => 1,
					];
				case 'not null':
					return [
						'IS NOT NULL' => 1,
					];
				case 'bw':
				case 'nbw':
					if ( isset( $civicrm_api_params[$name] ) && $civicrm_api_params[$name] ) {
						$min_max = explode( ';', $civicrm_api_params[$name] );
						if ( count( $min_max ) === 2 ) {
							try {
								$dateTimeStart = new DateTime( $min_max[0] );
								$dateTimeEnd = new DateTime( $min_max[1] );
							} catch (Exception $e) {
								// not valid dates
								return null;
							}
							$start = $dateTimeStart->format( 'His' ) === '000000' ? $dateTimeStart->format( 'Y-m-d' ) : $dateTimeStart->format( 'Y-m-d H:i:s' );
							$end = $dateTimeEnd->format( 'His' ) === '000000' ? $dateTimeEnd->format( 'Y-m-d' ) : $dateTimeEnd->format( 'Y-m-d H:i:s' );
							return [
								($op === 'nbw' ? 'NOT ' : '') . 'BETWEEN' => [ $start, $end ]
							];
						}
					}
					break;
			}
		}

		return null;
	}

}
