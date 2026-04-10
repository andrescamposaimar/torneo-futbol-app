<?php

class Stm_breadcrumbs {

	static function breadcrumbs( $args ) {

		$r = wp_parse_args(
			$args, array(
				'list_tpl'     => '<div class="breadcrumbs"><ul>%s</ul></div>',
				'item_tpl'     => '<li class="%1$s">%2$s</li>',
				'link_tpl'     => '<a href="%1$s">%2$s</a>',
				'show_current' => true,
				'text'         => array(),
				'echo'         => true
			)
		);

		$text = $r['text'];

		$output = '';

		$paged = '';
		global $post;
		if ( get_query_var( 'paged' ) ) {
			$paged = ( ! is_home() ? ' (' : '' ) . sprintf( $text['paged'], get_query_var( 'paged' ) ) . ( ! is_home() ? ')' : '' );
		}

		$output .= sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], home_url( '/' ), $text['home'] ) );

		/** @var WP_Query $wp_the_query */
		global $wp_the_query;

		if ( $wp_the_query->is_page() ) {
			if ( $parent_id = $wp_the_query->post->post_parent ) {
				$breadcrumbs = array();
				while ( $parent_id ) {
					$page          = get_post( $parent_id );
					$breadcrumbs[] = sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], get_permalink( $page->ID ), get_the_title( $page->ID ) ) );
					$parent_id     = $page->post_parent;
				}
				$output .= join( '', array_reverse( $breadcrumbs ) );
			}
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', get_the_title() );
			}
		} elseif ( is_home() ) {
			if ( ! is_front_page() ) {
				$output .= sprintf( $r['item_tpl'], '', $text['blog'] . $paged );
			} elseif ( $paged ) {
				$output .= sprintf( $r['item_tpl'], '', $paged );
			}
		} elseif ( is_category() ) {
			$thisCat = get_category( get_query_var( 'cat' ), false );
			if ( $thisCat->parent != 0 ) {
				$chain = self::get_category_parents_array( $thisCat->parent );
				foreach ( $chain as $cat ) {
					$output .= sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], esc_url( get_category_link( $cat->term_id ) ), $cat->name ) );
				}
			}
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', sprintf( $text['category'], single_cat_title( '', false ) ) . $paged );
			}
		} elseif ( is_search() ) {
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], '', sprintf( $text['search'], get_search_query() ) );
			}
		} elseif ( is_day() ) {
			$output .= sprintf( $r['link_tpl'], get_year_link( get_the_time( 'Y' ) ), get_the_time( 'Y' ) );
			$output .= sprintf( $r['link_tpl'], get_month_link( get_the_time( 'Y' ), get_the_time( 'm' ) ), get_the_time( 'F' ) );
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', get_the_time( 'd' ) );
			}
		} elseif ( is_month() ) {
			$output .= sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], get_year_link( get_the_time( 'Y' ) ), get_the_time( 'Y' ) ) );
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', get_the_time( 'F' ) );
			}
		} elseif ( is_year() ) {
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', get_the_time( 'Y' ) );
			}
		} elseif ( is_single() ) {
			if ( get_post_type() == 'sp_player' || get_post_type() == 'ivan_vc_projects' || get_post_type() == 'sp_team' || get_post_type() == 'sp_table' || get_post_type() == 'sp_event' || get_post_type() == 'sp_staff' ) {
				if ( $r['show_current'] ) {
					$output .= sprintf( $r['item_tpl'], 'active', get_the_title() );
				}
			} elseif ( get_post_type() != 'post' ) {
				$_link = self::custom_taxonomies_terms_links( $post );
				if ( $_link ) {
					$output .= sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], $_link['url'], $_link['title'] ) );
				}
				if ( $r['show_current'] ) {
					$output .= sprintf( $r['item_tpl'], 'active', get_the_title() );
				}
			} else {
				$cat = get_the_category();
				$cat = $cat[0];

				$chain = self::get_category_parents_array( $cat );
				foreach ( $chain as $cat ) {
					$output .= sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], esc_url( get_category_link( $cat->term_id ) ), $cat->name ) );
				}
				if ( $r['show_current'] ) {
					$output .= sprintf( $r['item_tpl'], 'active', get_the_title() );
				}
			}
		} elseif ( is_attachment() ) {
			$parent = get_post( $post->post_parent );
			$cat    = get_the_category( $parent->ID );
			$cat    = $cat[0];
			$chain  = self::get_category_parents_array( $cat );
			foreach ( $chain as $cat ) {
				$output .= sprintf( $r['link_tpl'], esc_url( get_category_link( $cat->term_id ) ), $cat->name );
			}
			$output .= sprintf( $r['item_tpl'], '', sprintf( $r['link_tpl'], get_permalink( $parent ), $parent->post_title ) );
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', get_the_title() );
			}
		} elseif ( is_tag() ) {
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', sprintf( $text['tag'], single_tag_title( '', false ) ) );
			}
		} elseif ( is_author() ) {
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', sprintf( $text['author'], get_user_meta( get_queried_object_id(), 'display_name' ) ) );
			}
		} elseif ( is_404() ) {
			if ( $r['show_current'] ) {
				$output .= sprintf( $r['item_tpl'], 'active', $text['not_found'] );
			}
		}

		$output = sprintf( $r['list_tpl'], $output );

		if ( $r['echo'] ) {
			echo champion_filtered_output( $output );
		}

		return $output;
	}

	public static function get_category_parents_array( $id, $chain = array() ) {
		$parent = get_category( $id );
		if ( is_wp_error( $parent ) ) {
			return $chain;
		}

		$chain[ $parent->term_id ] = $parent;
		if ( $parent->parent && ( $parent->parent != $parent->term_id ) && ! isset( $chain[ $parent->parent ] ) ) {
			$chain = self::get_category_parents_array( $parent->parent, $chain );
		}

		return $chain;
	}

	public static function custom_taxonomies_terms_links( $post ) {
		$post        = get_post( $post->ID );
		$post_type   = $post->post_type;
		$taxonomies  = get_object_taxonomies( $post_type );
		$taxonomy    = $taxonomies[0];
		$terms_array = get_the_terms( $post->ID, $taxonomy );
		$term        = false;
		if ( $terms_array ) {
			foreach ( $terms_array as $term_val ) {
				$term = $term_val;
			}
		}
		$link = array();
		if ( $term ) {
			$link['url']   = get_term_link( $term->slug, $taxonomy );
			$link['title'] = $term->name;
		}

		return $link;
	}

}