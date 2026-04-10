<?php if (have_posts()) { ?>

    <div class="posts_list">

        <?php
        while (have_posts()) {
            the_post();

            get_template_part('partials/loop', get_post_format());

        }
        ?>

    </div>

    <?php local_pagination(); ?>

<?php } else { ?>

    <?php get_template_part('partials/content', 'none'); ?>

<?php } ?>