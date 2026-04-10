<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$defaults = array(
	'id' => null,
	'performance' => array(),
	'icontag' => 'dt',
	'captiontag' => 'dd',
	'caption' => null,
	'size' => 'thumbnail',
	'link_posts' => get_option( 'sportspress_link_players', 'yes' ) == 'yes' ? true : false,
);

extract( $defaults, EXTR_SKIP );

// Add player number to caption if available
$player_number = get_post_meta( $id, 'sp_number', true );
if ( has_post_thumbnail( $id ) )
    $thumbnail = get_the_post_thumbnail( $id, 'player_photo' );
else
    $thumbnail = '<img width="740" height="740" src="http://www.gravatar.com/avatar/?s=740&d=mm&f=y" class="attachment-thumbnail wp-post-image">';
?>

<li>
    <div class="player_image">
        <a href="<?php echo esc_url( get_the_permalink($id) ); ?>">
            <?php echo champion_filtered_output( $thumbnail ); ?>
        </a>
        <div class="player_like">
            <?php $like = ( get_post_meta($id, 'champion_stm_like', true) == '' ) ? 0 : get_post_meta($id, 'champion_stm_like', true); ?>
            <a href="#" class="like_button" data-id="<?php echo esc_attr( $id ); ?>" onclick="champion_stm_like(jQuery(this)); return false;"><i class="fa fa-heart"></i> <span><?php echo esc_html( $like ); ?></span></a>
        </div>
    </div>
    <h4><a href="<?php echo esc_url( get_the_permalink($id) ); ?>"><?php echo get_the_title($id); ?></a></h4>
    <div class="player_info clearfix">
        <div class="number"><i class="t-shirt"></i> <?php echo esc_html( $player_number ); ?></div>
        <div class="position"><?php echo esc_html( $position );?></div>
    </div>
</li>
