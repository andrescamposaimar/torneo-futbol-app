<?php
/**
 * Player Details
 *
 * @author 		ThemeBoy
 * @package 	SportsPress/Templates
 * @version   2.6.8
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( get_option( 'sportspress_player_show_details', 'yes' ) === 'no' ) return;

if ( ! isset( $id ) )
	$id = get_the_ID();

$defaults = array(
	'show_number' => get_option( 'sportspress_player_show_number', 'no' ) == 'yes' ? true : false,
	'show_name' => get_option( 'sportspress_player_show_name', 'no' ) == 'yes' ? true : false,
	'show_nationality' => get_option( 'sportspress_player_show_nationality', 'yes' ) == 'yes' ? true : false,
	'show_positions' => get_option( 'sportspress_player_show_positions', 'yes' ) == 'yes' ? true : false,
	'show_current_teams' => get_option( 'sportspress_player_show_current_teams', 'yes' ) == 'yes' ? true : false,
	'show_past_teams' => get_option( 'sportspress_player_show_past_teams', 'yes' ) == 'yes' ? true : false,
	'show_leagues' => get_option( 'sportspress_player_show_leagues', 'no' ) == 'yes' ? true : false,
	'show_seasons' => get_option( 'sportspress_player_show_seasons', 'no' ) == 'yes' ? true : false,
	'show_nationality_flags' => get_option( 'sportspress_player_show_flags', 'yes' ) == 'yes' ? true : false,
	'link_teams' => get_option( 'sportspress_link_teams', 'no' ) == 'yes' ? true : false,
);

extract( $defaults, EXTR_SKIP );

$countries = SP()->countries->countries;

$player = new SP_Player( $id );

$nationalities = $player->nationalities();
$positions = $player->positions();
$current_teams = $player->current_teams();
$past_teams = $player->past_teams();
$leagues = $player->leagues();
$seasons = $player->seasons();
$metrics_before = $player->metrics( true );
$metrics_after = $player->metrics( false );

$common = array();

if ( $show_nationality && $nationalities && is_array( $nationalities ) ):
	$values = array();
	foreach ( $nationalities as $nationality ):
		$country_name = sp_array_value( $countries, $nationality, null );
		$values[] = $country_name ? ( $show_nationality_flags ? '<img src="' . plugin_dir_url( SP_PLUGIN_FILE ) . 'assets/images/flags/' . strtolower( $nationality ) . '.png" alt="' . esc_attr($nationality) . '"> ' : '' ) . $country_name : '&mdash;';
	endforeach;
	$common[ esc_html__( 'Nationality', 'champion' ) ] = implode( '<br>', $values );
endif;

if ( $show_positions && $positions && is_array( $positions ) ):
	$position_names = array();
	foreach ( $positions as $position ):
		$position_names[] = $position->name;
	endforeach;
	$common[ esc_html__( 'Position', 'champion' ) ] = implode( ', ', $position_names );
endif;

$data = array_merge( $metrics_before, $common, $metrics_after );

if ( $show_current_teams && $current_teams ):
	$teams = array();
	foreach ( $current_teams as $team ):
		$team_name = sp_team_short_name( $team );
		if ( $link_teams ) $team_name = '<a href="' . esc_url(get_post_permalink( $team )) . '">' . $team_name . '</a>';
		$teams[] = $team_name;
	endforeach;
	$data[ esc_html__( 'Current Team', 'champion' ) ] = implode( ', ', $teams );
endif;

if ( $show_past_teams && $past_teams ):
	$teams = array();
	foreach ( $past_teams as $team ):
		$team_name = sp_team_short_name( $team );
		if ( $link_teams ) $team_name = '<a href="' . esc_url(get_post_permalink( $team )) . '">' . $team_name . '</a>';
		$teams[] = $team_name;
	endforeach;
	$data[ esc_html__( 'Past Teams', 'champion' ) ] = implode( ', ', $teams );
endif;

if ( $show_leagues && $leagues && ! is_wp_error( $leagues ) ):
	$terms = array();
	foreach ( $leagues as $league ) {
		$terms[] = $league->name;
	}
	$data[ esc_html__( 'Competitions', 'champion' ) ] = implode( ', ', $terms );
endif;

if ( $show_seasons && $seasons && ! is_wp_error( $seasons ) ):
	$terms = array();
	foreach ( $seasons as $season ) {
		$terms[] = $season->name;
	}
	$data[ esc_html__( 'Seasons', 'champion' ) ] = implode( ', ', $terms );
endif;

$data = apply_filters( 'sportspress_player_details', $data, $id );

if ( empty( $data ) )
	return;


$like = ( get_post_meta($id, 'champion_stm_like', true) == '' ) ? 0 : get_post_meta($id, 'champion_stm_like', true);

$output = '<div class="sp-template sp-template-player-details sp-template-details">';
$output .= '<div class="sp-list-wrapper">';
$output .= '<div class="player_detail clearfix">';
if ( get_option( 'sportspress_player_show_photo', 'yes' ) !== 'no' ) {
	$output .= '<div class="player_photo">' . get_the_post_thumbnail( $id, 'player_photo' ) . '</div>';
}
$output .= '<div class="player_info"><table>';
foreach( $data as $label => $value ):

	$output .= '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';

endforeach;

$output .= '<tr><th><script type="text/javascript" src="//s7.addthis.com/js/300/addthis_widget.js"></script>';
$output .= '<div class="addthis_toolbox">';
$output .= '<a class="addthis_button_compact btn btn-danger btn-lg">' . esc_html__("Share Now", "champion");
$output .= '<i class="fa fa-share-alt"></i></a>';
$output .= '</div>';
$output .= '</th>';
$output .= '<td>';
$output .= '<a href="#" class="like_button" data-id="'.$id.'" onclick="champion_stm_like(jQuery(this)); return false;"><i class="fa fa-heart"></i> <span>' . sanitize_text_field( $like ) . '</span></a>';
$output .= '</td>';
$output .= '</tr>';

$output .= '</table></div></div></div></div>';

echo champion_filtered_output($output);