<?php
if (!isset($id))
    $id = get_the_ID();

$event = new SP_Event($id);

$data = $event->results();
$performance = $event->performance();
$date = get_the_time(get_option('date_format'), $id);
$time = get_the_time(get_option('time_format'), $id);
$venues = get_the_terms($id, 'sp_venue');
unset($data[0]);
unset($performance[0]);

$now = time();
$time_date = strtotime(get_the_date('c'));

if ($performance) {
    foreach ($performance as $key => $val) {
        unset($performance[$key][0]);
    }
}

$sportspress_primary_result = get_option('sportspress_primary_result', null);

if (!empty($sportspress_primary_result))
    $goals = $sportspress_primary_result;
else
    $goals = "goals";

?>
<?php the_title('<h1 class="page-title">', '</h1>'); ?>
<?php if (!empty($data)) { ?>
    <?php
    $event_banner = has_post_thumbnail() ? wp_get_attachment_url(get_post_thumbnail_id(get_the_ID())) : get_template_directory_uri() . '/assets/images/temp/fixture_bg.jpg';
    ?>
    <div class="event_banner" style="background-image: url('<?php echo esc_url($event_banner); ?>'); ">
        <div class="fixture_detail<?php if ($now <= $time_date) {
            echo ' future';
        } ?> clearfix">
            <h3><?php echo get_the_excerpt(); ?></h3>
            <?php $i = 0;
            foreach ($data as $team_id => $result) {
                $i++; ?>
                <?php
                $wr_class = ($i == 2) ? 'command_right' : 'command_left';
                ?>
                <div class="<?php echo esc_attr($wr_class); ?>">
                    <div class="command_info">
                        <div class="logo"><?php echo get_the_post_thumbnail($team_id, 'team_logo'); ?></div>
                        <?php if ($now >= $time_date) { ?>
                            <div class="score"><?php if (!empty($result[$goals])) echo esc_html($result[$goals]); else echo "0"; ?></div>
                        <?php } else { ?>
                            <div class="score"></div>
                        <?php } ?>
                    </div>
                    <div class="goals">
                        <h2><?php echo get_the_title($team_id); ?></h2>
                        <?php
                        if (isset($result['outcome'][0])) {
                            if ($now >= $time_date) {
                                $outcome = get_page_by_path($result['outcome'][0], OBJECT, 'sp_outcome');
                                echo '<h4>' . get_the_title($outcome->ID) . '</h4>';
                            }
                        }
                        ?>
                        <?php if (!empty($performance[$team_id]) && $now >= $time_date) { ?>
                            <ul class="players">
                                <?php foreach ($performance[$team_id] as $player_id => $player) { ?>
                                    <?php if (!empty($player[$goals])) { ?>
                                        <?php if (!empty($player[$goals]) && $player[$goals] >= 1) { ?>
                                            <li><?php echo get_the_title($player_id); ?> -
                                                <span><?php echo esc_html($player[$goals]); ?><?php esc_html_e('goal(s)', 'champion'); ?></span>
                                            </li>
                                        <?php } ?>
                                    <?php } ?>
                                <?php } ?>
                            </ul>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
            <div class="fixture_info">
                <?php if ($now >= $time_date) { ?>
                    <div class="date_time"><?php echo esc_html($date); ?> | <?php echo esc_html($time); ?></div>
                    <?php if (!empty($venues)) : foreach ($venues as $venue) { ?>
                        <div class="venue"><?php echo esc_html($venue->name); ?></div>
                    <?php } endif; ?>
                <?php } else { ?>
                    <ul>
                        <li><i class="fa fa-calendar"></i> <?php echo esc_html($date); ?></li>
                        <li><i class="fa fa-clock-o"></i> <?php echo esc_html($time); ?></li>
                        <?php foreach ($venues as $venue) { ?>
                            <li><i class="fa fa-map-marker"></i> <?php echo esc_html($venue->name); ?></li>
                        <?php } ?>
                    </ul>
                    <?php
                    $now = new DateTime(current_time('mysql', 0));
                    $date = new DateTime($post->post_date);
                    $interval = date_diff($now, $date);

                    $days = $interval->invert ? 0 : $interval->days;
                    $h = $interval->invert ? 0 : $interval->h;
                    $i = $interval->invert ? 0 : $interval->i;
                    $s = $interval->invert ? 0 : $interval->s;
                    ?>
                    <p class="countdown sp-countdown<?php if ($days >= 10): ?> long-countdown<?php endif; ?>">
                        <time datetime="<?php echo esc_attr($post->post_date); ?>"
                              data-countdown="<?php echo str_replace( '-', '/', get_gmt_from_date( $post->post_date ) ); ?>">
                            <span><?php echo sprintf('%02s', $days); ?> <small><?php esc_html_e('days', 'champion'); ?></small></span>
                            <span><?php echo sprintf('%02s', $h); ?> <small><?php esc_html_e('hrs', 'champion'); ?></small></span>
                            <span><?php echo sprintf('%02s', $i); ?> <small><?php esc_html_e('mins', 'champion'); ?></small></span>
                            <span><?php echo sprintf('%02s', $s); ?> <small><?php esc_html_e('secs', 'champion'); ?></small></span>
                        </time>
                    </p>
                <?php } ?>
            </div>
        </div>

    </div>
<?php } ?>
<div class="row">
    <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

            <div class="entry-content">
                <?php the_content(); ?>
            </div>
            <?php the_tags('<div class="tags_block"><h3>' . esc_html__('Tags', 'champion') . '</h3><span class="tag-links">', '', '</span></div>'); ?>

        </article>
        <!-- #post-## -->
    </div>
    <?php get_sidebar('sport'); ?>
</div>