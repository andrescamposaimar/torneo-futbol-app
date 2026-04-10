<?php
/**
 * Team Player Lists
 *
 * @author 		ThemeBoy
 * @package 	SportsPress/Templates
 * @version     1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! isset( $id ) )
	$id = get_the_ID();

$team = new SP_Team( $id );
$lists = $team->lists();

$i = 0; ?>
<ul class="nav nav-tabs" role="tablist">
<?php foreach ( $lists as $list ) { $i++;
    $id = $list->ID;
    $grouping = get_post_meta($id, 'sp_grouping', true);
    if($i==1){
        $tab_class = ' class="active"';
    }else{
        $tab_class = '';
    }
    ?>

        <li<?php echo champion_filtered_output($tab_class); ?>>
            <a href="#<?php echo 'tab_'.esc_attr( $id );?>" role="tab" data-toggle="tab">
                <?php echo esc_html( $list->post_title ); ?>
            </a>
        </li>

    <?php } ?>
</ul>
<div class="tab-content">
<?php $i = 0; foreach ( $lists as $list ) { $i++;
    $id = $list->ID;
    $grouping = get_post_meta($id, 'sp_grouping', true);
    $format = get_post_meta($id, 'sp_format', true);
    if($i==1){
        $tab_class = ' active';
    }else{
        $tab_class = '';
    }
    ?>
    <div class="tab-pane fade in<?php echo esc_attr( $tab_class ); ?>" id="<?php echo 'tab_'.esc_attr( $id );?>">
    <?php
    if (array_key_exists($format, SP()->formats->list)) {
        sp_get_template('player-' . $format . '.php', array('id' => $id));
    }else {
        sp_get_template('player-list.php', array('id' => $id));
    }
    ?>
    </div>
<?php } ?>
</div>
