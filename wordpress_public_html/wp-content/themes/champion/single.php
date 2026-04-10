<?php get_header(); ?>
<?php get_template_part('partials/breadcrumbs'); ?>

<?php
$content_layout = get_theme_mod('content_layout');
if (!empty($_GET['layout'])) {
    $content_layout = $_GET['layout'];
}
?>

<div id="primary" class="content-area">

    <?php if ($content_layout == 'sidebar_right' || $content_layout == '') { ?>

        <div class="row">
            <?php while (have_posts()) {
                the_post(); ?>
                <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
                    <?php get_template_part('partials/content', get_post_format()); ?>
                </div>
            <?php } ?>
            <div class="hidden-xs col-sm-3 col-md-3 col-lg-3 ">
                <?php get_sidebar(); ?>
            </div>
        </div>

    <?php } elseif ($content_layout == 'sidebar_left') { ?>

        <div class="row">
            <div class="hidden-xs col-sm-3 col-md-3 col-lg-3 ">
                <?php get_sidebar(); ?>
            </div>
            <?php while (have_posts()) {
                the_post(); ?>
                <div class="col-x-12 col-sm-9 col-md-9 col-lg-9">
                    <?php get_template_part('partials/content', get_post_format()); ?>
                </div>
            <?php } ?>
        </div>

    <?php } elseif ($content_layout == 'fullwidth') { ?>

        <?php while (have_posts()) {
            the_post();
            get_template_part('partials/content', get_post_format());
        }
        ?>

    <?php } ?>

</div>
<?php get_footer(); ?>
