<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! isset( $id ) )
	$id = get_the_ID();

$defaults = array(
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

$nationality = $player->nationality;
if ( $nationality ) {
    $nationality = sp_array_value($countries, $nationality, null);
}
$current_teams = $player->current_teams();
$teams = array();
if ( $current_teams ) {
    foreach ($current_teams as $team){
        $teams[] = '<a href="' . get_post_permalink($team) . '">' . get_the_title($team) . '</a>';
    }
    $display_teams = implode(', ', $teams);
}
$past_teams = $player->past_teams();
if ( $show_past_teams && $past_teams ):
	$teams = array();
	foreach ( $past_teams as $team ):
		$teams[] = '<a href="' . get_post_permalink( $team ) . '">' . get_the_title( $team ) . '</a>';
	endforeach;
	$display_past_teams = implode( ', ', $teams );
endif;

$sq_number = get_post_meta($player->ID, 'sp_number', true);
$metrics_before = $player->metrics( true );
$metrics_after = $player->metrics( false );
$metrics =  array_merge( $metrics_before, $metrics_after );
$position_names = array();
$positions = get_the_terms( $id, 'sp_position' );
if ( $positions ): foreach ( $positions as $position ):
    if( !empty($position->name) ) $position_names[] = $position->name;
endforeach; endif;

$player_position = implode( ', ', $position_names );
?>

<div class="player_detail clearfix">
    <div class="player_photo">
        <?php echo get_the_post_thumbnail( $id, 'player_photo' ) ?>
    </div>
    <div class="player_info">
        <table>
            <tbody>
                <?php if($display_teams){ ?>
                    <tr>
                        <th><?php esc_html_e('Current Teams', 'champion'); ?>:</th>
                        <td><?php echo champion_filtered_output( $display_teams ); ?></td>
                    </tr>
                <?php } ?>
                <?php if($nationality){ ?>
                    <tr>
                        <th><?php esc_html_e('Nationality', 'champion'); ?>:</th>
                        <td><?php echo esc_html( $nationality ); ?></td>
                    </tr>
                <?php } ?>
                <?php if($sq_number){ ?>
                    <tr>
                        <th><?php esc_html_e('Squad Number', 'champion'); ?>:</th>
                        <td><i class="t-shirt"></i><?php echo esc_html( $sq_number ); ?></td>
                    </tr>
                <?php } ?>
		<?php if( !empty($display_past_teams) && $display_past_teams ){ ?>
                    <tr>
                        <th><?php esc_html_e('Past Teams', 'champion'); ?>:</th>
                        <td><?php echo champion_filtered_output( $display_past_teams ); ?></td>
                    </tr>
                <?php } ?>
                <?php if($metrics){ ?>
                    <?php foreach($metrics as $key => $val){ ?>
                        <tr>
                            <th><?php echo esc_html( $key ); ?>:</th>
                            <td><?php echo esc_html( $val ); ?></td>
                        </tr>
                    <?php } ?>
                <?php } ?>
                <?php if($player_position){ ?>
                    <tr>
                        <th><?php esc_html_e('Position', 'champion'); ?>:</th>
                        <td><?php echo esc_html( $player_position ); ?></td>
                    </tr>
                <?php } ?>
                <tr>
                    <th>
                        <script type="text/javascript" src="//s7.addthis.com/js/300/addthis_widget.js"></script>
                        <div class="addthis_toolbox">
                            <a class="addthis_button_compact btn btn-danger btn-lg"><?php esc_html_e("Share Now", 'champion'); ?> <i class="fa fa-share-alt"></i></a>
                        </div>
                    </th>
                    <td>
                        <?php $like = ( get_post_meta($id, 'champion_stm_like', true) == '' ) ? 0 : get_post_meta($id, 'champion_stm_like', true); ?>
                        <a href="#" class="like_button" data-id="<?php echo esc_attr( $id ); ?>" onclick="champion_stm_like(jQuery(this)); return false;"><i class="fa fa-heart"></i> <span><?php echo esc_html( $like ); ?></span></a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>