<?php
if ( ! function_exists( 'local_pagination' ) ) :

	function local_pagination() {
		if ( $GLOBALS['wp_query']->max_num_pages < 2 ) {
			return;
		}

		$paged        = get_query_var( 'paged' ) ? intval( get_query_var( 'paged' ) ) : 1;
		$pagenum_link = html_entity_decode( get_pagenum_link() );
		$query_args   = array();
		$url_parts    = explode( '?', $pagenum_link );

		$pagenum_link = remove_query_arg( array_keys( $query_args ), $pagenum_link );
		$pagenum_link = trailingslashit( $pagenum_link ) . '%_%';

		$format = $GLOBALS['wp_rewrite']->using_index_permalinks() && ! strpos( $pagenum_link, 'index.php' ) ? 'index.php/' : '';
		$format .= $GLOBALS['wp_rewrite']->using_permalinks() ? user_trailingslashit( 'page/%#%', 'paged' ) : '?paged=%#%';

		$ipp      = get_option( 'posts_per_page' );
		$pageNums = getPageNums( $GLOBALS['wp_query']->max_num_pages * $ipp, $paged, $ipp );

		if ( $pageNums ):

			?>
			<ul class="pagination clearfix">
				<?php foreach ( $pageNums['pages'] as $pageNum ): ?>
					<li <?php if ($pageNums['currentPage'] == $pageNum): ?>class="active"<?php endif; ?>>
						<?php if ( $pageNums['currentPage'] == $pageNum ): ?>
							<span class="current highlight_bg"><?php echo esc_html( $pageNum ); ?></span>
						<?php else: ?>
							<a href="<?php echo esc_url( get_pagenum_link( $pageNum ) ); ?>"><?php echo esc_html( $pageNum ); ?></a>
						<?php endif; ?>

					</li>
				<?php endforeach; ?>
			</ul>

		<?php

		endif;
	}
endif;


function getPageNums( $totalItems, $currentPage, $itemsPerPage = 10, $firstSet = 5, $beforeCurrent = 2, $afterCurrent = 2, $lastSet = 5, $path = '', $rewrite = false ) {
	global $config;


	$totalPages = ceil( $totalItems / $itemsPerPage );
	if ( $currentPage > $totalPages ) {
		return false;
	}

	$pageNums['totalPages']     = $totalPages;
	$pageNums['totalItems']     = $totalItems;
	$pageNums['currentPage']    = $currentPage;
	$pageNums['startIteration'] = ( $currentPage - 1 ) * $itemsPerPage + 1;
	$pageNums['endIteration']   = $currentPage * $itemsPerPage;
	if ( $pageNums['endIteration'] > $totalItems ) {
		$pageNums['endIteration'] = $totalItems;
	}


	if ( $firstSet > 0 ) {
		for ( $i = 1; $i <= ( $totalPages > $firstSet ? $firstSet : $totalPages ); $i ++ ) {
			$pages[$i] = $i;
		}
	}

	for ( $i = ( $currentPage - $beforeCurrent ); $i <= ( $currentPage + $afterCurrent ); $i ++ ) {
		if ( $i > 0 && $i <= $totalPages ) {
			$pages[$i] = $i;
		}
	}

	if ( $lastSet > 0 ) {
		for ( $i = ( $totalPages - $lastSet + 1 ); $i <= $totalPages; $i ++ ) {
			if ( $i > 0 ) {
				$pages[$i] = $i;
			}
		}
	}

	$i = 0;
	if ( ! empty( $pages ) && count( $pages ) > 1 ) {
		asort( $pages );
		$prevPage = 0;
		foreach ( $pages as $page ) {
			if ( ( $page - $prevPage ) > 1 && $i > 0 ) {
				array_splice( $pages, $i, 0, '...' );
				$i ++;
			}
			$prevPage = $page;
			$i ++;
		}
		$pageNums['pages'] = $pages;
	}

	if ( ( $currentPage + 1 ) <= $totalPages ) {
		if ( empty( $path ) ) {
			$pageNums['nextPage'] = $currentPage + 1;
		} else {
			$pageNums['nextPage']['num'] = $currentPage + 1;
			if ( $rewrite ) {
				$pageNums['nextPage']['url'] = $path . "/page" . ( $currentPage + 1 ) . ".$config[file_extension]";
			} else {
				$pageNums['nextPage']['url'] = $path . "page=" . ( $currentPage + 1 );
			}
		}
	}

	if ( ( $currentPage - 1 ) >= 1 ) {
		if ( empty( $path ) ) {
			$pageNums['previousPage'] = $currentPage - 1;
		} else {
			$pageNums['previousPage']['num'] = $currentPage - 1;
			if ( $rewrite ) {
				if ( ( $currentPage - 1 ) == 1 ) {
					$pageNums['previousPage']['url'] = $path . "/index.$config[file_extension]";
				} else {
					$pageNums['previousPage']['url'] = $path . "/page" . ( $currentPage - 1 ) . ".$config[file_extension]";
				}
			} else {
				$pageNums['previousPage']['url'] = $path . "page=" . ( $currentPage - 1 );
			}
		}
	}

	if ( $currentPage != 1 ) {
		if ( empty( $path ) ) {
			$pageNums['firstPage'] = $currentPage - 1;
		} else {
			$pageNums['firstPage']['num'] = $currentPage - 1;
			if ( $rewrite ) {
				$pageNums['firstPage']['url'] = $path . "/index.$config[file_extension]";
			} else {
				$pageNums['firstPage']['url'] = $path . "page=1";
			}
		}
	}

	if ( $currentPage != $totalPages ) {
		if ( empty( $path ) ) {
			$pageNums['lastPage'] = $currentPage - 1;
		} else {
			$pageNums['lastPage']['num'] = $currentPage - 1;
			if ( $rewrite ) {
				$pageNums['lastPage']['url'] = $path . "/page$totalPages.$config[file_extension]";
			} else {
				$pageNums['lastPage']['url'] = $path . "page=$totalPages";
			}
		}
	}

	if ( ! empty( $pageNums ) ) {
		if ( ! empty( $path ) && ! empty( $pageNums['pages'] ) ) {
			foreach ( $pageNums['pages'] as $i => $page ) {
				$pageNums['pages'][$i]        = array();
				$pageNums['pages'][$i]['num'] = $page;
				if ( $rewrite ) {
					if ( $page == 1 ) {
						$pageNums['pages'][$i]['url'] = $path . "/index.$config[file_extension]";
					} else {
						$pageNums['pages'][$i]['url'] = $path . "/page$page.$config[file_extension]";
					}
				} else {
					$pageNums['pages'][$i]['url'] = $path . "page=$page";
				}
			}
		}

		return $pageNums;
	} else {
		return array();
	}
}