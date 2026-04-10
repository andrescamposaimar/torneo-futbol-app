<?php
/**
 * Event List
 *
 * @author 		ThemeBoy
 * @package 	SportsPress/Templates
 * @version   2.6.12
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


$layout = get_theme_mod('event_list_layout', 'theme');

$defaults = array(
	'id' => null,
	'title' => false,
	'status' => 'default',
	'format' => 'all',
	'date' => 'default',
	'date_from' => 'default',
	'date_to' => 'default',
	'date_past' => 'default',
	'date_future' => 'default',
	'date_relative' => 'default',
	'day' => 'default',
	'league' => null,
	'season' => null,
	'venue' => null,
	'team' => null,
	'teams_past' => null,
	'date_before' => null,
	'player' => null,
	'number' => -1,
	'show_team_logo' => get_option( 'sportspress_event_list_show_logos', 'no' ) == 'yes' ? true : false,
	'link_events' => get_option( 'sportspress_link_events', 'yes' ) == 'yes' ? true : false,
	'link_teams' => get_option( 'sportspress_link_teams', 'no' ) == 'yes' ? true : false,
	'link_venues' => get_option( 'sportspress_link_venues', 'yes' ) == 'yes' ? true : false,
	'responsive' => get_option( 'sportspress_enable_responsive_tables', 'no' ) == 'yes' ? true : false,
	'sortable' => get_option( 'sportspress_enable_sortable_tables', 'yes' ) == 'yes' ? true : false,
	'scrollable' => get_option( 'sportspress_enable_scrollable_tables', 'yes' ) == 'yes' ? true : false,
	'paginated' => get_option( 'sportspress_event_list_paginated', 'yes' ) == 'yes' ? true : false,
	'rows' => get_option( 'sportspress_event_list_rows', 10 ),
	'order' => 'default',
	'columns' => null,
	'show_all_events_link' => false,
	'show_title' => get_option( 'sportspress_event_list_show_title', 'yes' ) == 'yes' ? true : false,
	'title_format' => get_option( 'sportspress_event_list_title_format', 'title' ),
	'time_format' => get_option( 'sportspress_event_list_time_format', 'combined' ),
);

extract( $defaults, EXTR_SKIP );

$calendar = new SP_Calendar( $id );
if ( $status != 'default' )
	$calendar->status = $status;
if ( $format != 'all' )
	$calendar->event_format = $format;
if ( $date != 'default' )
	$calendar->date = $date;
if ( $date_from != 'default' )
	$calendar->from = $date_from;
if ( $date_to != 'default' )
	$calendar->to = $date_to;
if ( $date_past != 'default' )
	$calendar->past = $date_past;
if ( $date_future != 'default' )
	$calendar->future = $date_future;
if ( $date_relative != 'default' )
	$calendar->relative = $date_relative;
if ( $league )
	$calendar->league = $league;
if ( $season )
	$calendar->season = $season;
if ( $venue )
	$calendar->venue = $venue;
if ( $team )
	$calendar->team = $team;
if ( $teams_past )
	$calendar->teams_past = $teams_past;
if ( $date_before )
	$calendar->date_before = $date_before;
if ( $player )
	$calendar->player = $player;
if ( $order != 'default' )
	$calendar->order = $order;
if ( $day != 'default' )
	$calendar->day = $day;
$data = $calendar->data();
$usecolumns = $calendar->columns;
$title_format = get_option( 'sportspress_event_list_title_format', 'title' );
$time_format = get_option( 'sportspress_event_list_time_format', 'combined' );

if ( isset( $columns ) ):
	if ( is_array( $columns ) )
		$usecolumns = $columns;
	else
		$usecolumns = explode( ',', $columns );
endif;

if ( $show_title && false === $title && $id ):
	$caption = $calendar->caption;
	if ( $caption )
		$title = $caption;
	else
		$title = get_the_title( $id );
endif;

if($layout == 'theme') {
	?>

<div class="upcoming_events">
    <ul>
        <?php $i = 0;

				if ( is_numeric( $number ) && $number > 0 )
					$limit = $number;
				
				foreach ( $data as $event ){ 
				
				if ( isset( $limit ) && $i >= $limit ) continue;
				
                $teams = get_post_meta( $event->ID, 'sp_team' );
                $results = get_post_meta( $event->ID, 'sp_results', true );
                $result = '';
                $i = 0;
                if($results){
                    foreach($results as $val){ $i++;
                        if($i == 1){
                            if($val['goals'] == ''){
                                $result .= '-  ';
                            }else{
                                $result .= $val['goals'].' : ';
                            }
                        }else{
                            if($val['goals'] == '') {
                                $result .= '';
                            }else{
                                $result .= $val['goals'];
                            }
                        }
                    }
                }
                $teams_output = '';
                $teams_array = '';
            ?>
            <li>
                <div class="event_date">
                    <div class="date">
                        <?php echo get_post_time( 'j', false, $event, true ); ?>
                        <span><?php echo get_post_time( 'F', false, $event, true ); ?></span>
                    </div>
                </div>
                <div class="commands">
                    <?php if($teams){ ?>
                        <h3><?php echo get_the_title($teams[0]); ?> <span><?php echo esc_html( $result ); ?></span> <?php echo esc_html( get_the_title($teams[1]) ); ?></h3>
                    <?php } ?>
                    <div class="time"><i class="fa fa-clock-o"></i> <?php echo get_post_time( get_option( 'time_format' ), false, $event, true ); ?></div>
                    <?php
                        $venues = get_the_terms( $event->ID, 'sp_venue' );
                        if ( $venues ){
                            foreach ( $venues as $venue ) {
                                echo '<div class="stadium"><i class="fa fa-map-marker"></i> ' . $venue->name . '</div>';
                            }
                        }
                    ?>
                </div>
                <div class="read_more">
                    <a class="btn btn-info btn-lg white" href="<?php echo esc_url( get_post_permalink( $event->ID, false, true ) ); ?>"><span><?php esc_html_e('read more', 'champion'); ?></span></a>
                </div>
            </li>
        <?php } ?>
    </ul>
</div>
<?php
} else {
?>
<div class="sp-template sp-template-event-list">
	<div class="sp-table-wrapper">
		<table class="sp-event-list sp-data-table<?php if ( $paginated ) { ?> sp-paginated-table<?php } if ( $sortable ) { ?> sp-sortable-table<?php } if ( $scrollable ) { ?> sp-scrollable-table<?php } ?>" data-sp-rows="<?php echo esc_attr($rows); ?>">
			<thead>
				<tr>
					<?php
					echo '<th class="data-date">' . esc_html__( 'Date', 'champion' ) . '</th>';

					switch ( $title_format ) {
						case 'homeaway':
							if ( sp_column_active( $usecolumns, 'event' ) ) {
								echo '<th class="data-home">' . esc_html__( 'Home', 'champion' ) . '</th>';
							}

							if ( 'combined' == $time_format && sp_column_active( $usecolumns, 'time' ) ) {
								echo '<th class="data-time">' . esc_html__( 'Time/Results', 'champion' ) . '</th>';
								$labels[] = esc_html__( 'Time/Results', 'champion' );
							} elseif ( in_array( $time_format, array( 'separate', 'results' ) ) && sp_column_active( $usecolumns, 'results' ) ) {
								echo '<th class="data-results">' . esc_html__( 'Results', 'champion' ) . '</th>';
							}

							if ( sp_column_active( $usecolumns, 'event' ) ) {
								echo '<th class="data-away">' . esc_html__( 'Away', 'champion' ) . '</th>';
							}

							if ( in_array( $time_format, array( 'separate', 'time' ) ) && sp_column_active( $usecolumns, 'time' ) ) {
								echo '<th class="data-time">' . esc_html__( 'Time', 'champion' ) . '</th>';
							}
							break;
						default:
							if ( sp_column_active( $usecolumns, 'event' ) ) {
								if ( $title_format == 'teams' ){
									echo '<th class="data-teams">' . esc_html__( 'Teams', 'champion' ) . '</th>';
								} else {
									echo '<th class="data-event">' . esc_html__( 'Event', 'champion' ) . '</th>';
								}
							}

							switch ( $time_format ) {
								case 'separate':
									if ( sp_column_active( $usecolumns, 'time' ) )
										echo '<th class="data-time">' . esc_html__( 'Time', 'champion' ) . '</th>';
									if ( sp_column_active( $usecolumns, 'results' ) )
										echo '<th class="data-results">' . esc_html__( 'Results', 'champion' ) . '</th>';
									break;
								case 'time':
									if ( sp_column_active( $usecolumns, 'time' ) )
										echo '<th class="data-time">' . esc_html__( 'Time', 'champion' ) . '</th>';
									break;
								case 'results':
									if ( sp_column_active( $usecolumns, 'results' ) )
										echo '<th class="data-results">' . esc_html__( 'Results', 'champion' ) . '</th>';
									break;
								default:
									if ( sp_column_active( $usecolumns, 'time' ) )
										echo '<th class="data-time">' . esc_html__( 'Time/Results', 'champion' ) . '</th>';
							}
					}

					if ( sp_column_active( $usecolumns, 'league' ) )
						echo '<th class="data-league">' . esc_html__( 'Competition', 'champion' ) . '</th>';

					if ( sp_column_active( $usecolumns, 'season' ) )
						echo '<th class="data-season">' . esc_html__( 'Season', 'champion' ) . '</th>';

					if ( sp_column_active( $usecolumns, 'venue' ) )
						echo '<th class="data-venue">' . esc_html__( 'Venue', 'champion' ) . '</th>';

					if ( sp_column_active( $usecolumns, 'article' ) )
						echo '<th class="data-article">' . esc_html__( 'Article', 'champion' ) . '</th>';

					if ( sp_column_active( $usecolumns, 'day' ) )
						echo '<th class="data-day">' . esc_html__( 'Match Day', 'champion' ) . '</th>';

					do_action( 'sportspress_event_list_head_row', $usecolumns );
					?>
				</tr>
			</thead>
			<tbody>
				<?php
				$i = 0;

				if ( is_numeric( $number ) && $number > 0 )
					$limit = $number;

				foreach ( $data as $event ):
					if ( isset( $limit ) && $i >= $limit ) continue;

					$teams = get_post_meta( $event->ID, 'sp_team' );
					$video = get_post_meta( $event->ID, 'sp_video', true );

					$main_results = apply_filters( 'sportspress_event_list_main_results', sp_get_main_results( $event ), $event->ID );

					$teams_output = '';
					$team_class = '';
					$teams_array = array();
					$team_logos = array();

					if ( $teams ):
						foreach ( $teams as $t => $team ):
							$name = sp_team_short_name( $team );
							if ( $name ):

								$name = '<meta itemprop="name" content="' . $name . '">' . $name;

								if ( $show_team_logo ):
									if ( has_post_thumbnail( $team ) ):
										$logo = '<span class="team-logo">' . sp_get_logo( $team, 'mini', array( 'itemprop' => 'url' ) ) . '</span>';
										$team_logos[] = $logo;
										$team_class .= ' has-logo';

										if ( $t ):
											$name = $logo . ' ' . $name;
										else:
											$name .= ' ' . $logo;
										endif;
									endif;
								endif;

								if ( $link_teams ):
									$team_output = '<a href="' . get_post_permalink( $team ) . '" itemprop="url">' . $name . '</a>';
								else:
									$team_output = $name;
								endif;

								$team_result = sp_array_value( $main_results, $team, null );

								if ( $team_result != null ):
									if ( $usecolumns != null && ! in_array( 'time', $usecolumns ) ):
										$team_output .= ' (' . $team_result . ')';
									endif;
								endif;

								$teams_array[] = $team_output;

								$teams_output .= $team_output . '<br>';
							endif;
						endforeach;
					else:
						$teams_output .= '&mdash;';
					endif;

					echo '<tr class="sp-row sp-post' . ( $i % 2 == 0 ? ' alternate' : '' ) . ' sp-row-no-' . $i . '" itemscope itemtype="http://schema.org/SportsEvent">';

						$date_html = '<date>' . get_post_time( 'Y-m-d H:i:s', false, $event ) . '</date>' . apply_filters( 'sportspress_event_date', get_post_time( get_option( 'date_format' ), false, $event, true ), $event->ID );

						if ( $link_events ) $date_html = '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">' . $date_html . '</a>';

						echo '<td class="data-date" itemprop="startDate" content="' . mysql2date( 'Y-m-d\TH:iP', $event->post_date ) . '" data-label="'.esc_html__( 'Date', 'champion' ).'">' . $date_html . '</td>';

						switch ( $title_format ) {
							case 'homeaway':
								if ( sp_column_active( $usecolumns, 'event' ) ) {
									$team = array_shift( $teams_array );
									echo '<td class="data-home' . $team_class . '" itemprop="competitor" itemscope itemtype="http://schema.org/SportsTeam" data-label="'.esc_attr__( 'Home', 'champion' ).'">' . $team . '</td>';
								}

								if ( 'combined' == $time_format && sp_column_active( $usecolumns, 'time' ) ) {
									echo '<td class="data-time" data-label="'.esc_attr__( 'Time/Results', 'champion' ).'">';
									if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
									if ( ! empty( $main_results ) ):
										echo implode( ' - ', $main_results );
									else:
										echo '<date>&nbsp;' . get_post_time( 'H:i:s', false, $event ) . '</date>' . apply_filters( 'sportspress_event_time', sp_get_time( $event ), $event->ID );
									endif;
									if ( $link_events ) echo '</a>';
									echo '</td>';
								} elseif ( in_array( $time_format, array( 'separate', 'results' ) ) && sp_column_active( $usecolumns, 'results' ) ) {
									echo '<td class="data-results" data-label="'.esc_attr__( 'Results', 'champion' ).'">';
									if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
									if ( ! empty( $main_results ) ):
										echo implode( ' - ', $main_results );
									else:
										echo '-';
									endif;
									if ( $link_events ) echo '</a>';
									echo '</td>';
								}

								if ( sp_column_active( $usecolumns, 'event' ) ) {
									$team = array_shift( $teams_array );
									echo '<td class="data-away' . $team_class . '" itemprop="competitor" itemscope itemtype="http://schema.org/SportsTeam" data-label="'.esc_attr__( 'Away', 'champion' ).'">' . $team . '</td>';
								}

								if ( in_array( $time_format, array( 'separate', 'time' ) ) && sp_column_active( $usecolumns, 'time' ) ) {
									echo '<td class="data-time" data-label="'.esc_attr__( 'Time', 'champion' ).'">';
									if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
									echo '<date>&nbsp;' . get_post_time( 'H:i:s', false, $event ) . '</date>' . apply_filters( 'sportspress_event_time', sp_get_time( $event ), $event->ID );
									if ( $link_events ) echo '</a>';
									echo '</td>';
								}
								break;
							default:
								if ( sp_column_active( $usecolumns, 'event' ) ) {
									if ( $title_format == 'teams' ) {
										echo '<td class="data-event data-teams" data-label="'.esc_attr__( 'Teams', 'champion' ).'">' . $teams_output . '</td>';
									} else {
										$title_html = implode( ' ', $team_logos ) . ' ' . $event->post_title;
										if ( $link_events ) $title_html = '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">' . $title_html . '</a>';
										echo '<td class="data-event" data-label="'.esc_attr__( 'Event', 'champion' ).'">' . $title_html . '</td>';
									}
								}

								switch ( $time_format ) {
									case 'separate':
										if ( sp_column_active( $usecolumns, 'time' ) ) {
											echo '<td class="data-time" data-label="'.esc_attr__( 'Time', 'champion' ).'">';
											if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
											echo '<date>&nbsp;' . get_post_time( 'H:i:s', false, $event ) . '</date>' . apply_filters( 'sportspress_event_time', sp_get_time( $event ), $event->ID );
											if ( $link_events ) echo '</a>';
											echo '</td>';
										}
										if ( sp_column_active( $usecolumns, 'results' ) ) {
											echo '<td class="data-results" data-label="'.esc_attr__( 'Results', 'champion' ).'">';
											if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
											if ( ! empty( $main_results ) ):
												echo implode( ' - ', $main_results );
											else:
												echo '-';
											endif;
											if ( $link_events ) echo '</a>';
											echo '</td>';
										}
										break;
									case 'time':
										if ( sp_column_active( $usecolumns, 'time' ) ) {
											echo '<td class="data-time" data-label="'.esc_attr__( 'Time', 'champion' ).'">';
											if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
											echo '<date>&nbsp;' . get_post_time( 'H:i:s', false, $event ) . '</date>' . apply_filters( 'sportspress_event_time', sp_get_time( $event ), $event->ID );
											if ( $link_events ) echo '</a>';
											echo '</td>';
										}
										break;
									case 'results':
										if ( sp_column_active( $usecolumns, 'results' ) ) {
											echo '<td class="data-results" data-label="'.esc_attr__( 'Results', 'champion' ).'">';
											if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
											if ( ! empty( $main_results ) ):
												echo implode( ' - ', $main_results );
											else:
												echo '-';
											endif;
											if ( $link_events ) echo '</a>';
											echo '</td>';
										}
										break;
									default:
										if ( sp_column_active( $usecolumns, 'time' ) ) {
											echo '<td class="data-time" data-label="'.esc_html__( 'Time/Results', 'champion' ).'">';
											if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';
											if ( ! empty( $main_results ) ):
												echo implode( ' - ', $main_results );
											else:
												echo '<date>&nbsp;' . get_post_time( 'H:i:s', false, $event ) . '</date>' . apply_filters( 'sportspress_event_time', sp_get_time( $event ), $event->ID );
											endif;
											if ( $link_events ) echo '</a>';
											echo '</td>';
										}
								}
						}

						if ( sp_column_active( $usecolumns, 'league' ) ):
							echo '<td class="data-league" data-label="'.esc_html__( 'League', 'champion' ).'">';
							$leagues = get_the_terms( $event->ID, 'sp_league' );
							if ( $leagues ): foreach ( $leagues as $league ):
								echo champion_filtered_output($league->name);
							endforeach; endif;
							echo '</td>';
						endif;

						if ( sp_column_active( $usecolumns, 'season' ) ):
							echo '<td class="data-season" data-label="'.esc_html__( 'Season', 'champion' ).'">';
							$seasons = get_the_terms( $event->ID, 'sp_season' );
							if ( $seasons ): foreach ( $seasons as $season ):
								echo champion_filtered_output($season->name);
							endforeach; endif;
							echo '</td>';
						endif;

						if ( sp_column_active( $usecolumns, 'venue' ) ):
							echo '<td class="data-venue" data-label="'.esc_html__( 'Venue', 'champion' ).'">';
							if ( $link_venues ):
								the_terms( $event->ID, 'sp_venue' );
							else:
								$venues = get_the_terms( $event->ID, 'sp_venue' );
								if ( $venues ): foreach ( $venues as $venue ):
									echo champion_filtered_output($venue->name);
								endforeach; endif;
							endif;
							echo '</td>';
						endif;

						if ( sp_column_active( $usecolumns, 'article' ) ):
							echo '<td class="data-article" data-label="'.esc_html__( 'Article', 'champion' ).'">';
								if ( $link_events ) echo '<a href="' . get_post_permalink( $event->ID, false, true ) . '" itemprop="url">';

								if ( $video ):
									echo '<div class="dashicons dashicons-video-alt"></div>';
								elseif ( has_post_thumbnail( $event->ID ) ):
									echo '<div class="dashicons dashicons-camera"></div>';
								endif;
								if ( $event->post_content !== null ):
									if ( $event->post_status == 'publish' ):
										esc_html_e( 'Recap', 'champion' );
									else:
										esc_html_e( 'Preview', 'champion' );
									endif;
								endif;

								if ( $link_events ) echo '</a>';
							echo '</td>';
						endif;

						if ( sp_column_active( $usecolumns, 'day' ) ):
							echo '<td class="data-day" data-label="'.esc_html__( 'Match Day', 'champion' ).'">';
							$day = get_post_meta( $event->ID, 'sp_day', true );
							if ( '' == $day ) {
								echo '-';
							} else {
								echo champion_filtered_output($day);
							}
							echo '</td>';
						endif;

						do_action( 'sportspress_event_list_row', $event, $usecolumns );

					echo '</tr>';

					$i++;
				endforeach;
				?>
			</tbody>
		</table>
	</div>
	<?php
	if ( $id && $show_all_events_link )
		echo '<div class="sp-calendar-link sp-view-all-link"><a href="' . get_permalink( $id ) . '">' . esc_html__( 'View all events', 'champion' ) . '</a></div>';
	?>
</div>
<?php }