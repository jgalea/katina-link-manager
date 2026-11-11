<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KATINA_Migrator_LinkCentral extends KATINA_Migrator {

	public static function is_available() {
		return post_type_exists( 'linkcentral_link' );
	}

	public static function has_orphaned_data() {
		return self::cpt_has_posts( 'linkcentral_link' ) || self::table_exists( 'linkcentral_stats' );
	}

	public static function cleanup_orphaned_data() {
		return self::cleanup_generic(
			'linkcentral_link',
			array( 'linkcentral_category' ),
			array( 'linkcentral_stats' ),
			// Prefix-anchored only.
			array( 'linkcentral\_%', '\_linkcentral\_%' )
		);
	}

	public static function get_source_name() {
		return 'LinkCentral';
	}

	public static function get_source_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'linkcentral_link'
			 AND post_status IN ('publish', 'draft', 'private')"
		);
	}

	const SOURCE = 'linkcentral';

	public function run() {
		global $wpdb;
		self::register_source_taxonomy( 'linkcentral_category', 'linkcentral_link' );
		if ( 0 === $this->cursor ) {
			$this->migrate_categories();
		}
		$category_map = $this->build_category_map();
		$has_clicks   = self::table_exists( 'linkcentral_stats' );
		$stats_table  = $wpdb->prefix . 'linkcentral_stats';

		$fetch_clicks = function ( $source_id, $after_id, $cutoff, $limit ) use ( $wpdb, $stats_table ) {
			$sql  = "SELECT * FROM `{$stats_table}` WHERE link_id = %d AND id > %d";
			$args = array( $source_id, $after_id );
			if ( $cutoff ) {
				$sql   .= ' AND click_date < %s';
				$args[] = $cutoff;
			}
			$sql   .= ' ORDER BY id ASC LIMIT %d';
			$args[] = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			return array_map( function ( $row ) {
				return array(
					'id'           => (int) $row['id'],
					'clicked_at'   => $row['click_date'],
					'referrer'     => isset( $row['referring_url'] ) ? $row['referring_url'] : '',
					'country_code' => isset( $row['country'] ) ? $row['country'] : '',
					'user_agent'   => isset( $row['user_agent'] ) ? $row['user_agent'] : '',
				);
			}, $rows );
		};

		while ( true ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'linkcentral_link' AND post_status NOT IN ('trash', 'auto-draft') AND ID > %d ORDER BY ID ASC LIMIT 100",
				$this->cursor
			) );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $post_id ) {
				$post_id = (int) $post_id;
				$post    = get_post( $post_id );

				if ( $post ) {
					$new_link_id = $this->create_link( array(
						'source'          => self::SOURCE,
						'old_id'          => $post_id,
						'title'           => $post->post_title,
						'slug'            => $post->post_name,
						'destination_url' => get_post_meta( $post_id, '_linkcentral_destination_url', true ),
						'redirect_type'   => get_post_meta( $post_id, '_linkcentral_redirection_type', true ) ?: 'default',
						'nofollow'        => $this->normalize_bool( get_post_meta( $post_id, '_linkcentral_nofollow', true ) ),
						'sponsored'       => $this->normalize_bool( get_post_meta( $post_id, '_linkcentral_sponsored', true ) ),
						'new_window'      => $this->normalize_bool( get_post_meta( $post_id, '_linkcentral_new_tab', true ) ),
						'css_classes'     => get_post_meta( $post_id, '_linkcentral_custom_css_classes', true ) ?: '',
						'pass_query_str'  => $this->normalize_bool( get_post_meta( $post_id, '_linkcentral_parameter_forwarding', true ) ),
					) );

					if ( $new_link_id ) {
						if ( $new_link_id > 0 ) {
							$terms = wp_get_object_terms( $post_id, 'linkcentral_category', array( 'fields' => 'ids' ) );
							if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
								$katina_term_ids = array();
								foreach ( $terms as $term_id ) {
									if ( isset( $category_map[ $term_id ] ) ) {
										$katina_term_ids[] = $category_map[ $term_id ];
									}
								}
								if ( $katina_term_ids ) {
									wp_set_object_terms( $new_link_id, $katina_term_ids, 'katina_category' );
								}
							}
						}

						if ( $has_clicks && ! $this->import_link_clicks( $new_link_id, $post_id, $fetch_clicks ) ) {
							$this->complete = false;
							return $this->results;
						}
					}
				}

				$this->cursor = $post_id;
				if ( $this->out_of_time() ) {
					$this->complete = false;
					return $this->results;
				}
			}
		}

		return $this->results;
	}

	private function migrate_categories() {
		$terms = get_terms( array(
			'taxonomy'   => 'linkcentral_category',
			'hide_empty' => false,
			'orderby'    => 'parent',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$term_id_map = array();

		foreach ( $terms as $term ) {
			$parent_id = 0;
			if ( $term->parent && isset( $term_id_map[ $term->parent ] ) ) {
				$parent_id = $term_id_map[ $term->parent ];
			}

			$new_term_id = $this->map_category( $term->name, $parent_id );
			if ( $new_term_id ) {
				$term_id_map[ $term->term_id ] = $new_term_id;
			}
		}

		return $term_id_map;
	}

	private function build_category_map() {
		$terms = get_terms( array(
			'taxonomy'   => 'linkcentral_category',
			'hide_empty' => false,
			'orderby'    => 'parent',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$old_to_new  = array();
		$term_id_map = array();

		foreach ( $terms as $term ) {
			$parent_id = 0;
			if ( $term->parent && isset( $old_to_new[ $term->parent ] ) ) {
				$parent_id = $old_to_new[ $term->parent ];
			}

			$katina_term = get_term_by( 'name', $term->name, 'katina_category' );
			if ( $katina_term && (int) $katina_term->parent === (int) $parent_id ) {
				$old_to_new[ $term->term_id ]  = $katina_term->term_id;
				$term_id_map[ $term->term_id ] = $katina_term->term_id;
			}
		}

		return $term_id_map;
	}
}
