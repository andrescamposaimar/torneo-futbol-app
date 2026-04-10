<?php get_header();

$pick_league = get_the_terms( $post->ID, 'sp_league' );
if(!empty($pick_league[0])){
	$latest_results = get_posts(array('post_status' => 'publish', 'posts_per_page' => -1, 'post_type' => 'sp_event', 'orderby' => 'date', 'order' => 'ASC',
	'tax_query' => array(
        array(
           'taxonomy' => 'sp_league',
			'field' => 'term_id',
			'terms' => $pick_league[0]->term_id
        )
    )
	));	
}else{
	$latest_results = get_posts(array('post_status' => 'publish', 'posts_per_page' => -1, 'post_type' => 'sp_event', 'orderby' => 'date', 'order' => 'ASC',));
}

if($latest_results){
	$pick_team = get_post_meta($post->ID, 'sp_team' );
	foreach($latest_results as $post){
		if( ! empty( $pick_team[0] ) ){
			if( in_array( $pick_team[0], get_post_meta($post->ID, 'sp_team' ) ) ){
				$id = $post->ID;
				break;
			}
		}else{
			$id = $post->ID;
		}
	}
}

$event = new SP_Event( $id );

$data = $event->results();
$performance = $event->performance();
$date = get_the_time( get_option('date_format'), $id );
$time = get_the_time( get_option('time_format'), $id );
$venues = get_the_terms( $id, 'sp_venue' );
unset( $data[0] );
unset( $performance[0] );

if($performance){
    foreach($performance as $key => $val){
        unset( $performance[$key][0] );
    }
}

?>
<?php if(!empty($data)){ 

$sportspress_primary_result = get_option( 'sportspress_primary_result', null );

if( !empty( $sportspress_primary_result ) )
	$goals = $sportspress_primary_result;
else
	$goals = "goals" ;
?>
    <?php
    $event_banner = has_post_thumbnail($id) ? wp_get_attachment_url( get_post_thumbnail_id($id) ) : get_template_directory_uri().'/assets/images/temp/fixture_bg.jpg';
    ?>
    <div class="event_banner latest_result" style="background-image: url('<?php echo esc_url( $event_banner ); ?>'); ">
        <div class="fixture_detail clearfix">
            <h2><?php esc_html_e('latest results', 'champion'); ?></h2>
            <h3><?php echo get_the_excerpt(); ?></h3>
            <?php $i=0; foreach($data as $team_id => $result){ $i++; ?>
                <?php
                $wr_class = ($i == 2) ? 'command_right' : 'command_left';
                ?>
                <div class="<?php echo esc_attr( $wr_class ); ?>">
                    <div class="command_info">
                        <div class="logo"><?php echo get_the_post_thumbnail($team_id, 'team_logo'); ?></div>
                        <div class="score"><?php if( !empty($result[$goals]) ) echo esc_html( $result[$goals] ); else echo "0"; ?></div>
                    </div>
                    <div class="goals">
                        <h2><?php echo get_the_title( $team_id ); ?></h2>
                        <?php 
	                        if( isset( $result['outcome'][0] ) ){
		                    	$outcome = get_page_by_path( $result['outcome'][0], OBJECT, 'sp_outcome' );
								echo '<h4>'. get_the_title( $outcome->ID ) .'</h4>';    
							}
	                    ?>
                        <?php if($performance){ ?>
                            <ul class="players">
                                <?php foreach($performance[$team_id] as $player_id => $player){ ?>
                                    <?php if(!empty($player[$goals]) && $player[$goals] >= 1){ ?>
                                        <li><?php echo get_the_title($player_id); ?> - <span><?php echo esc_html( $player[$goals] ); ?> <?php esc_html_e('goal(s)', 'champion'); ?></span></li>
                                    <?php } ?>
                                <?php } ?>
                            </ul>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
            <div class="fixture_info">
                <div class="date_time"><?php echo esc_html( $date ); ?>  | <?php echo esc_html( $time ); ?></div>
                <?php foreach( $venues as $venue ){ ?>
                    <div class="venue"><?php echo esc_html( $venue->name ); ?></div>
                <?php } ?>
                <a class="btn btn-danger btn-lg" href="<?php echo esc_url( get_the_permalink($id) ); ?>"><span><?php esc_html_e('read more', 'champion'); ?></span></a>
            </div>
        </div>

    </div>
<?php } ?>

<?php
while ( have_posts() ) {
    the_post();
    get_template_part( 'sportspress/content', 'calendar' );
}
?>

<?php get_footer(); ?>
