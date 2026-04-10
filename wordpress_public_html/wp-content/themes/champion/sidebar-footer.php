<?php if (is_active_sidebar('footer')) { ?>

    <?php
    $widget_areas = get_theme_mod('stm_widget_areas');
    if (!$widget_areas) {
        $widget_areas = 3;
    }
    ?>
    <?php if ($widget_areas != 'disabled') { ?>

        <div class="pre_footer">
            <div class="container">
                <div class="widgets <?php echo 'cols_' . esc_attr($widget_areas); ?> clearfix">
                    <?php dynamic_sidebar('footer'); ?>
                </div>
            </div>
        </div>

    <?php } ?>

<?php } ?>