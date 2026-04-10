<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( get_option( 'sportspress_staff_show_details', 'yes' ) === 'no' ) return;

if ( ! isset( $id ) )
	$id = get_the_ID();

$defaults = array(
	'show_nationality_flags' => get_option( 'sportspress_staff_show_flags', 'yes' ) == 'yes' ? true : false,
);

extract( $defaults, EXTR_SKIP );

$countries = SP()->countries->countries;

$staff = new SP_Staff( $id );

$nationality = $staff->nationality;
$current_team = $staff->current_team;
$past_teams = $staff->past_teams();

$data = array();
if ( $nationality ):
	if ( 2 == strlen( $nationality ) ):
		$legacy = SP()->countries->legacy;
		$nationality = strtolower( $nationality );
		$nationality = sp_array_value( $legacy, $nationality, null );
	endif;
	$country_name = sp_array_value( $countries, $nationality, null );
	$data[ esc_html__( 'Nationality', 'champion' ) ] = $country_name;
endif;

if ( $current_team )
	$data[ esc_html__( 'Current Team', 'champion' ) ] = '<a href="' . esc_url(get_post_permalink( $current_team )) . '">' . get_the_title( $current_team ) . '</a>';

if ( $past_teams ):
	$teams = array();
	foreach ( $past_teams as $team ):
		$teams[] = '<a href="' . esc_url(get_post_permalink( $team )) . '">' . get_the_title( $team ) . '</a>';
	endforeach;
	$data[ esc_html__( 'Past Teams', 'champion' ) ] = implode( ', ', $teams );
endif;

$data = apply_filters( 'sportspress_staff_details', $data, $id );

$output = '
    <div class="player_photo">' . get_the_post_thumbnail( $id, 'player_photo' ) . '</div>' .
    '<div class="player_info">
        <table>
            <tbody>';

foreach( $data as $label => $value ):

	$output .= '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';

endforeach;

$output .= '</tbody>
        </table>
        </div>';
?>
<div class="player_detail clearfix">
	<?php echo champion_filtered_output($output); ?>
</div>