<?php
/**
* Widget API: List Grid View Widget Class
* @package Newscrunch
*/
class Newscrunch_List_Grid_View_Widget_Controller extends WP_Widget {

    //construct part
    function __construct()
    {
        parent::__construct(
        //Base ID of widget
        'newscrunch_list_grid_view',

        //widget name will appear in UI
        esc_html__('Newscrunch : List Grid View','newscrunch'),

        // Widget description
        array( 'description' => esc_html__('Display your blog in a grid view with a list layout','newscrunch'))  
        );

    }

    //Widget Front End
    public function widget( $args, $instance ) {
        if ( ! isset( $args['widget_id'] ) ) { $args['widget_id'] = $this->id; }
        $title    = isset( $instance['title'] ) ? $instance['title'] : '';
        $category = isset( $instance['category'] ) ? $instance['category'] : '';
        if ( ! is_numeric($category) && !empty($category) ) {  $term = get_term_by('slug', $category, 'category'); $category = $term->term_id; }
        $number   = isset( $instance['number'] ) ? $instance['number'] : 4;
        $date     = isset( $instance['date'] ) ? $instance['date'] : '';
        if ( class_exists('Newscrunch_Plus') ): $show_read_time     = isset( $instance['show_read_time'] ) ? $instance['show_read_time'] : ''; endif;
        echo wp_kses_post($args['before_widget']);
        if ( $title ) { echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']);}
        ?>
            <div class="spnc-blog-wrapper-1">
                <div class="spnc-blog-1-post">
                    <?php
                        $query_args = new WP_Query( apply_filters( 'widget_posts_args', array(
                            'no_found_rows'       => true,
                            'post_status'         => 'publish',
                            'cat'                 => $category,
                            'posts_per_page'      => $number,
                            'order'               => 'DESC',
                            'ignore_sticky_posts' => true
                        ) ) );
                        if ( $query_args->have_posts() ) { 
                            while ( $query_args->have_posts() ) {
                            $query_args->the_post();?>
                            <div class="spnc-post-wrapper">
                                <article class="spnc-post">
                                    <div class="spnc-post-overlay">
                                        <figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
                                            <?php 
                                            if ( has_post_thumbnail() ){ 
                                                the_post_thumbnail( 'medium', ['class' => 'img-fluid sp-thumb-img'] ); 
                                            }   
                                            else { ?>
                                                <img class="img-fluid sp-thumb-img" src="<?php echo esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ); ?>/assets/images/no-preview.jpg" alt="<?php the_title_attribute(); ?>">
                                                <?php 
                                            } ?>
                                        </figure>
                                    </div>
                                    <div class="spnc-post-content">
                                        <header class="entry-header">
                                            <h4 class="spnc-entry-title">
                                                <a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
                                            </h4>
                                        </header>
                                        <div class="spnc-footer-meta spnc-entry-meta">
                                            <?php if($date=='on') { ?>
                                            <span class="plgv spnc-date"><i class="fa fa-clock"></i><?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
                                            <?php } 
                                            if ( class_exists('Newscrunch_Plus') ):
                                                if(get_theme_mod('reading_time_enable',false) === true):
                                                    if($show_read_time=='on'){
                                                ?>
                                                <span class="spnc-read-time"><i class="fa fa-eye"></i> <?php spncp_reading_time();?></span>
                                            <?php } endif; endif;
                                             ?>
                                        </div>
                                        
                                    </div>
                                </article>
                            </div>
                            <?php }
                            wp_reset_postdata();
                        }
                    ?>
                </div>
            </div>               
        <?php
        echo wp_kses_post($args['after_widget']);
    }

    //Widget Back End
    public function form( $instance ) {
        if ( isset( $instance[ 'title' ])){ $title = $instance[ 'title' ]; } else { $title = esc_html__("Widget title","newscrunch" ); }
        if ( isset( $instance[ 'category' ])){ $category = $instance[ 'category' ]; }
        if ( isset( $instance[ 'number' ])){ $number = $instance[ 'number' ]; } else { $number = 4; }
        if ( isset( $instance[ 'date' ])){ $date = $instance[ 'date' ]; }
        if ( class_exists('Newscrunch_Plus') ): if ( isset( $instance[ 'show_read_time' ])){ $show_read_time = $instance[ 'show_read_time' ]; } endif;
        if ( ! is_numeric($category) ) { $category = get_term_by('slug', $category, 'category'); $category =$category->term_id; }
        ?>
            <!-- Heading -->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'title' )); ?>"><?php echo esc_html__( 'Widget Title','newscrunch' ); echo ':'; ?></label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'title' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'title' )); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
            </p>
            <!-- Category -->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'category' )); ?>"><?php echo esc_html__( 'Categories','newscrunch' ); echo ':'; ?></label>
                <select class="widefat" id="<?php echo esc_attr($this->get_field_id( 'category' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'category' )); ?>">
                    <option value=""><?php echo esc_html__( 'All Category', 'newscrunch' );?></option>
                    <?php  
                    $categories = get_categories(); 
                    foreach( $categories as $cat ): ?>
                    <option  value="<?php echo esc_attr($cat->term_id);?>" <?php if($cat->term_id == $category) { echo 'selected'; } ?>><?php echo esc_html($cat->name). ' (' .esc_html($cat->count). ') ';?></option>
                    <?php endforeach;?>
                </select>
            </p>
             <!-- No of post -->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'number' )); ?>"> <?php echo esc_html__( 'Number of posts to show','newscrunch' ); echo ':'; ?> </label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'number' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'number' )); ?>" type="number" value="<?php echo esc_attr( $number ); ?>" min="1" />
            </p>
            <!-- date -->
            <p class="newscrunch-widet-area" style="float: left; width: 100%;">
                <input <?php if($date=='on') { echo 'checked'; }?> class="widefat" id="<?php echo esc_attr($this->get_field_id( 'date' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'date' )); ?>" type="checkbox" />
                <label for="<?php echo esc_attr($this->get_field_id( 'date' )); ?>"> <?php echo esc_html__( 'Show post date','newscrunch' ); ?> </label>
            </p>
            <!-- read time -->
            <?php if ( class_exists('Newscrunch_Plus') ):?>
            <p class="newscrunch-widet-area" style="float: left; width: 100%;">
                <input <?php if($show_read_time=='on') { echo 'checked'; }?> class="widefat" id="<?php echo esc_attr($this->get_field_id( 'show_read_time' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'show_read_time' )); ?>" type="checkbox" />
                <label for="<?php echo esc_attr($this->get_field_id( 'show_read_time' )); ?>"> <?php echo esc_html__( 'Show read time','newscrunch' ); ?> </label>
                <label style="color: #11b524;" > <?php echo esc_html__( 'If you want display post read time, then you should need to enable this setting from customizer ( Global >> Read Time )','newscrunch' ); ?> </label>
            </p>
        <?php
            endif;
    }

    //save or uption option
    public function update( $new_instance, $old_instance)
    {
      $instance = $old_instance;
      $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
      $instance['category'] = ( ! empty( $new_instance['category'] ) ) ? sanitize_text_field( $new_instance['category'] ) : '';
      $instance['number'] = ( ! empty( $new_instance['number'] ) ) ? absint( $new_instance['number'] ) : '';
      $instance['date'] = ( ! empty( $new_instance['date'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['date'] ) : '';
      if ( class_exists('Newscrunch_Plus') ): $instance['show_read_time'] = ( ! empty( $new_instance['show_read_time'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['show_read_time'] ) : ''; endif;
      return $instance;
    }

}