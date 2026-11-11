<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KATINA_Migrator_PrettyLinks extends KATINA_Migrator {

	public static function is_available() {
		// Pretty Links doesn't use a CPT — check for its plugin classes instead
		// so leftover tables from a deactivated install don't trigger the option.
		return class_exists( 'PrliAppController' ) || defined( 'PRLI_VERSION' );
	}

	public static function has_orphaned_data() {
		return self::table_exists( 'prli_links' );
	}

	public static function cleanup_orphaned_data() {
		return self::cleanup_generic(
			'',
			array(),
			array( 'prli_links', 'prli_clicks', 'prli_link_metas', 'prli_groups' ),
			array( 'prli\_%', 'pretty-link\_%' )
		);
	}

	public static function get_source_name() {
		return 'Pretty Links';
	}

	public static function get_source_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'prli_links';
return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE link_status = 'enabled'" );
	}

	const SOURCE = 'prettylinks';

	public function run() {
		global $wpdb;
		$links_table  = $wpdb->prefix . 'prli_links';
		$clicks_table = $wpdb->prefix . 'prli_clicks';

		$has_categories = taxonomy_exists( 'pretty-link-category' );
		$has_clicks     = self::table_exists( 'prli_clicks' );

		$fetch_clicks = function ( $source_id, $after_id, $cutoff, $limit ) use ( $wpdb, $clicks_table ) {
			$sql  = "SELECT id, created_at, referer, robot FROM `{$clicks_table}` WHERE link_id = %d AND id > %d";
			$args = array( $source_id, $after_id );
			if ( $cutoff ) {
				$sql   .= ' AND created_at < %s';
				$args[] = $cutoff;
			}
			$sql   .= ' ORDER BY id ASC LIMIT %d';
			$args[] = $limit;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			return array_map( function ( $row ) {
				return array(
					'id'         => (int) $row['id'],
					'clicked_at' => $row['created_at'],
					'referrer'   => $row['referer'],
					'is_bot'     => (int) $row['robot'],
				);
			}, $rows );
		};

		while ( true ) {
			$links = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$links_table}` WHERE link_status = 'enabled' AND id > %d ORDER BY id ASC LIMIT 100",
				$this->cursor
			), ARRAY_A );

			if ( empty( $links ) ) {
				break;
			}

			foreach ( $links as $link ) {
				$redirect_type = in_array( (string) $link['redirect_type'], KATINA_Link::REDIRECT_TYPES, true ) ? (string) $link['redirect_type'] : 'default';

				$new_id = $this->create_link( array(
					'source'          => self::SOURCE,
					'old_id'          => (int) $link['id'],
					'title'           => $link['name'],
					'slug'            => $link['slug'],
					'destination_url' => $link['url'],
					'redirect_type'   => $redirect_type,
					'nofollow'        => $this->normalize_bool( (int) $link['nofollow'] ),
					'sponsored'       => $this->normalize_bool( (int) $link['sponsored'] ),
					'new_window'      => 'default',
					'css_classes'     => '',
					'pass_query_str'  => ! empty( $link['param_forwarding'] ) ? 'yes' : 'default',
					'rel_tags'        => '',
				) );

				if ( $new_id ) {
					if ( $has_categories && ! empty( $link['link_cpt_id'] ) ) {
						$terms = wp_get_object_terms( (int) $link['link_cpt_id'], 'pretty-link-category', array( 'fields' => 'names' ) );
						if ( ! is_wp_error( $terms ) ) {
							$term_ids = array();
							foreach ( $terms as $term_name ) {
								$term_id = $this->map_category( $term_name );
								if ( $term_id ) {
									$term_ids[] = $term_id;
								}
							}
							if ( ! empty( $term_ids ) ) {
								wp_set_object_terms( $new_id, $term_ids, 'katina_category' );
							}
						}
					}

					if ( $has_clicks && ! $this->import_link_clicks( $new_id, (int) $link['id'], $fetch_clicks ) ) {
						$this->complete = false;
						return $this->results;
					}
				}

				$this->cursor = (int) $link['id'];
				if ( $this->out_of_time() ) {
					$this->complete = false;
					return $this->results;
				}
			}
		}

		return $this->results;
	}
}
