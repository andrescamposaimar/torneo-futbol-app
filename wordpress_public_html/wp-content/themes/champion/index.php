<?php get_header(); ?>
<?php if (!(is_front_page())) { ?>
    <?php get_template_part('partials/breadcrumbs'); ?>
<?php } ?>

<?php
$layout = get_theme_mod('blog_layout');
if (!empty($_GET['layout'])) {
    $layout = $_GET['layout'];
}
?>

<?php if ($layout == 'fullwidth' || $layout == '') { ?>

    <?php if (is_home()) { ?>
        <h1 class="page-title"><?php esc_html_e('Blog', 'champion'); ?></h1>
    <?php } ?>
    <?php get_template_part('partials/posts'); ?>

<?php } elseif ($layout == 'sidebar_right') { ?>

    <div class="row">
        <div class="col-x-12 col-sm-9 col-md-9 col-lg-9 three-col">
            <?php if (is_home()) { ?>
                <h1 class="page-title"><?php esc_html_e('Blog', 'champion'); ?></h1>
            <?php } ?>
            <?php get_template_part('partials/posts'); ?>
        </div>
        <div class="hidden-xs col-sm-3 col-md-3 col-lg-3 ">
            <?php get_sidebar(); ?>
        </div>
    </div>

<?php } elseif ($layout == 'sidebar_left') { ?>

    <div class="row">
        <div class="hidden-xs col-sm-3 col-md-3 col-lg-3">
            <?php get_sidebar(); ?>
        </div>
        <div class="col-x-12 col-sm-9 col-md-9 col-lg-9 three-col">
            <?php if (is_home()) { ?>
                <h1 class="page-title"><?php esc_html_e('Blog', 'champion'); ?></h1>
            <?php } ?>
            <?php get_template_part('partials/posts'); ?>
        </div>
    </div>

<?php } ?>
<?php get_footer(); ?>