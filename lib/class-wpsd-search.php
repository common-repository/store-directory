<?php

/**
 *
 */

if ( !class_exists( 'WPSD_Search' ) ) :

class WPSD_Search {

	private static $instance;

	public $lat;
	public $long;
	public $radius;

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public function __clone() { wp_die( "Please don't __clone WPSD_Search" ); }

	public function __wakeup() { wp_die( "Please don't __wakeup WPSD_Search" ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new WPSD_Search;
			self::$instance->setup();
		}
		return self::$instance;
	}

	public function setup() {
		add_action( 'init', array( $this, 'rewrite_tags' ) );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		if ( apply_filters( 'wpsd_automap', true ) ) {
			add_action( 'loop_start', array( $this, 'add_map' ) );
		}
	}

	public function scripts() {
		wp_enqueue_script( 'wpsd_map', WPSD_URL . 'js/map.js', array( 'jquery', 'wpsd_gmaps' ), '1.1', true );
		wp_enqueue_script( 'wpsd_gmaps', 'http://maps.google.com/maps/api/js?sensor=false', array(), '1.0', true );
	}

	public function rewrite_tags() {
		add_rewrite_tag( '%sd_addr%',   '([^/]+)' );
		add_rewrite_tag( '%sd_radius%', '(\d+)' );
		add_rewrite_tag( '%sd_lat%',    '([-\.\d]+)' );
		add_rewrite_tag( '%sd_lng%',    '([-\.\d]+)' );
	}

	public function pre_get_posts( $query ) {
		if ( $query->is_main_query() && $query->is_post_type_archive( WPSD_Post_Type()->post_type ) ) {
			$this->lat    = floatval( get_query_var( 'sd_lat' ) );
			$this->long   = floatval( get_query_var( 'sd_lng' ) );
			$this->radius = intval( get_query_var( 'sd_radius' ) );
			if ( ! in_array( $this->radius, wpsd_radius_options() ) ) {
				$this->radius = 5;
			}

			if ( $this->lat && $this->long && $this->radius ) {
				$query->query_vars['wpsd_search'] = true;

				add_filter( 'posts_fields', array( $this, 'fields' ), 10, 2 );
				add_filter( 'posts_where', array( $this, 'where' ), 10, 2 );
				add_filter( 'posts_join', array( $this, 'join' ), 10, 2 );
				add_filter( 'posts_orderby', array( $this, 'orderby' ), 10, 2 );
			}
		}
	}

	public function fields( $fields, $query ) {
		if ( $this->doing_wpsd_search( $query ) ) {
			global $wpdb;
			$fields .= $wpdb->prepare( ",
				lat.meta_value AS `latitude`,
				long.meta_value AS `longitude`,
				( %3\$d * acos( cos( radians('%1\$s') ) * cos( radians( lat.meta_value ) ) * cos( radians( long.meta_value ) - radians('%2\$s') ) + sin( radians('%1\$s') ) * sin( radians( lat.meta_value ) ) ) ) AS `distance`
				",
				$this->lat,
				$this->long,
				( 'miles' == WPSD_Post_Type()->units ? 3959 : 6371 ) # radius of the earth
			);
		}
		return $fields;
	}

	public function where( $where, $query ) {
		if ( $this->doing_wpsd_search( $query ) ) {
			global $wpdb;
			$where .= $wpdb->prepare( ' HAVING `distance` < %d ', $this->radius );
		}
		return $where;
	}

	public function join( $join, $query ) {
		if ( $this->doing_wpsd_search( $query ) ) {
			global $wpdb;
			$join .= "
			INNER JOIN {$wpdb->postmeta} AS `lat` ON `lat`.post_id={$wpdb->posts}.ID AND `lat`.meta_key='latitude'
			INNER JOIN {$wpdb->postmeta} AS `long` ON `long`.post_id={$wpdb->posts}.ID AND `long`.meta_key='longitude'
			";
		}
		return $join;
	}

	public function orderby( $orderby, $query ) {
		if ( $this->doing_wpsd_search( $query ) ) {
			$orderby = '`distance`';
		}
		return $orderby;
	}

	public function doing_wpsd_search( $query ) {
		return ! empty( $query->query_vars['wpsd_search'] );
	}

	public function get_mappable_data( $post ) {
		return apply_filters( 'wpsd_mappable_data', array(
			'name'      => $post->post_title,
			'address'   => get_post_meta( $post->ID, 'address', true ),
			'latitude'  => $post->latitude,
			'longitude' => $post->longitude,
			'distance'  => $post->distance
		), $post );
	}

	public function add_map( $query ) {
		if ( $query->is_main_query() && ( $query->is_post_type_archive( WPSD_Post_Type()->post_type ) || $query->is_singular( WPSD_Post_Type()->post_type ) ) ) {
			$posts = array_map( array( $this, 'get_mappable_data' ), $query->posts );
			if ( $this->lat && $this->long ) {
				# If we have a search point, center the map around it
				wpsd_the_map( $posts, $this->lat, $this->long );
			} else {
				# Otherwise, center the map around the first post
				wpsd_the_map( $posts, $query->posts[0]->latitude, $query->posts[0]->longitude );
			}
		}
	}
}

function WPSD_Search() {
	return WPSD_Search::instance();
}
add_action( 'after_setup_theme', 'WPSD_Search' );

endif;