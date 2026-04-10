<?php
/**
* Widget API: Grid Slider Three Column Widget Class
* @package Newscrunch
*/
class Newscrunch_Grid_Slider_Three_Column_Widget_Controller extends WP_Widget {
    //construct part
    function __construct()
    {
        parent::__construct(
        //Base ID of widget
        'newscrunch_grid_slider_three_column',

        //widget name will appear in UI
        esc_html__('Newscrunch : Post Grid 3 Column Slider','newscrunch'),

        // Widget description
        array( 'description' => esc_html__('Display your posts as a three-column grid slider','newscrunch'))  
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
        $show_cat = isset( $instance['show_cat'] ) ? $instance['show_cat'] : '';
        $show_auth= isset( $instance['show_auth'] ) ? $instance['show_auth'] : '';
        if ( class_exists('Newscrunch_Plus') ):
            $show_read_time  = isset( $instance['show_read_time'] ) ? $instance['show_read_time'] : '';
            $animation_speed = isset( $instance['animation_speed'] ) ? $instance['animation_speed'] : 4;
        endif;
        $btitle    = isset( $instance['btitle'] ) ? $instance['btitle'] : __('By','newscrunch');
        echo wp_kses_post($args['before_widget']);
        if ( $title ) { echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']); }
        ?>
            <div class="spnc-blog-wrapper grid-slider-three-column">
                <div id="grid-slider-col-3-<?php echo esc_attr($this->id);?>" class="owl-carousel spnc-blog1-carousel-1">
                    <?php
                        global $post;
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
                            <div class="spnc-filter">
                                <article class="spnc-post-grid-slider-three-column">
                                    <div  class="spnc-post-overlay">
                                        <figure class="spnc-post-thumbnail <?php echo esc_attr(get_theme_mod('img_animation','i_effect1'));?>">
                                            <?php 
                                            if ( has_post_thumbnail() ){ the_post_thumbnail( 'img-fluid', ['class' => 'sp-thumb-img'] ); } 
                                            else {
                                                 ?>
                                                    <img class="img-fluid sp-thumb-img" src="<?php echo esc_url( NEWSCRUNCH_TEMPLATE_DIR_URI ); ?>/assets/images/no-preview.jpg" alt="<?php the_title_attribute(); ?>">
                                                <?php 
                                            } ?>
                                        </figure>
                                        <?php 
                                        if($show_cat=='on') {
                                            echo '<div class="spnc-entry-meta"><span class="spnc-cat-links">';
                                            newscrunch_get_categories(get_the_ID()); 
                                            echo '</span></div>';
                                        }
                                        ?>
                                    </div>
                                    <div class="spnc-post-content"> 
                                        <header class="entry-header">
                                            <h4 class="spnc-entry-title">
                                                <a class="<?php echo esc_attr(get_theme_mod('link_animate','a_effect1'));?>" href="<?php the_permalink();?>" title="<?php the_title();?>"><?php the_title();?></a>
                                            </h4> 
                                        </header>
                                        <div class="spnc-entry-content">
                                            <div class="spnc-footer-meta spnc-entry-meta">  
                                                <?php 
                                                if($date=='on') { ?>
                                                    <span class="spnc-date"><?php echo newcrunch_post_date_time(get_the_ID()); ?></span>
                                                <?php }
                                                if($show_auth=='on') { ?>
                                                    <span class="spnc-author">
                                                        <a <?php if (is_rtl()) { echo 'dir="rtl"';} ?> href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>" title="<?php echo esc_attr__('Posts by','newscrunch') . ' ' . esc_attr(get_the_author()); ?>"><?php echo esc_html( $btitle);?> <?php echo esc_html(get_the_author());?></a>
                                                    </span>
                                                <?php
                                                } 
                                                if ( class_exists('Newscrunch_Plus') ):
                                                    if(get_theme_mod('reading_time_enable',false) === true):
                                                        if($show_read_time=='on'){ ?>
                                                            <span class="spnc-read-time"> <?php spncp_reading_time();?></span>
                                                <?php } endif; endif;?>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </div> 
                            <?php }
                            wp_reset_postdata();
                        }
                    ?>      
                </div>
                <div id="spnc-blog-hidden" class="hide"></div>
            </div> 
            
            <script type="text/javascript">
                <?php if ( class_exists('Newscrunch_Plus') ) { $smartspeed = $animation_speed*1000; $autplay = $smartspeed+700; } else { $smartspeed = 4300; $autplay = 5000; }  ?>
                jQuery(document).ready(function() 
                {
                   jQuery("#grid-slider-col-3-<?php echo esc_attr($this->id);?>").owlCarousel({
                   navigation : true, // Show next and prev buttons        
                    autoplay: true,
                    autoplayTimeout: <?php echo esc_attr($autplay);?>,
                    autoplayHoverPause: true,
                    smartSpeed: <?php echo esc_attr($smartspeed);?>,
                    loop:true, // loop is true up to 1199px screen.
                    nav:true, // is true across all sizes
                    margin:20, // margin 10px till 960 breakpoint
                    autoHeight: true,
                    responsiveClass:true, // Optional helper class. Add 'owl-reponsive-' + 'breakpoint' class to main element.
                    //items: 3,
                    dots: false,
                    navText: ["<i class='fa fa-angle-left'></i>","<i class='fa fa-angle-right'></i>"],
                    responsive:{    
                        200:{ items:1 },
                        480:{ items:3 },
                        768:{ items:3 },
                        1000:{ items:3 }            
                    }
                });
                });
            </script>
        <?php
        echo wp_kses_post($args['after_widget']);

    }

    //Widget Back End
    public function form( $instance ) {
        if ( isset( $instance[ 'title' ])){ $title = $instance[ 'title' ]; } else { $title = esc_html__('Widget title','newscrunch' ); }
        if ( isset( $instance[ 'category' ])){ $category = $instance[ 'category' ]; }
        if ( isset( $instance[ 'number' ])){ $number = $instance[ 'number' ]; } else { $number = 4; }
        if ( isset( $instance[ 'date' ])){ $date = $instance[ 'date' ]; }
        if ( isset( $instance[ 'show_cat' ])){ $show_cat = $instance[ 'show_cat' ]; }
        if ( isset( $instance[ 'show_auth' ])){ $show_auth = $instance[ 'show_auth' ]; }
        if ( class_exists('Newscrunch_Plus') ):
            if ( isset( $instance[ 'show_read_time' ])){ $show_read_time = $instance[ 'show_read_time' ]; }
            if ( isset( $instance[ 'animation_speed' ])){ $animation_speed = $instance[ 'animation_speed' ]; } else { $animation_speed = 4; }
            if ( isset( $instance[ 'btitle' ])){ $btitle = $instance[ 'btitle' ]; } else { $btitle = __('By','newscrunch' ); }
        endif;
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
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'number' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'number' )); ?>" type="number" value="<?php echo esc_attr( $number ); ?>" min="1" max="10" />
            </p>
            <!-- Animation Speed -->
            <?php if ( class_exists('Newscrunch_Plus') ): ?>
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'animation_speed' )); ?>"> <?php echo esc_html__( 'Animation Speed (In Second)','newscrunch' ); echo ':'; ?> </label>
                <input onKeypress="event.preventDefault();" class="widefat" id="<?php echo esc_attr($this->get_field_id( 'animation_speed' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'animation_speed' )); ?>" type="number" value="<?php echo esc_attr( $animation_speed ); ?>" min="1" max="30" />
            </p>
            <!-- By -->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'btitle' )); ?>"><?php echo esc_html__( 'By Author','newscrunch' ); echo ':'; ?></label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'btitle' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'btitle' )); ?>" type="text" value="<?php echo esc_attr( $btitle ); ?>" />
            </p>
            <?php endif; ?>
            <!-- Show Category -->
            <p class="newscrunch-widet-area" style="float: left; width: 100%;">
                <input <?php if($show_cat=='on') { echo 'checked'; }?> class="widefat" id="<?php echo esc_attr($this->get_field_id( 'show_cat' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'show_cat' )); ?>" type="checkbox"/>
                <label for="<?php echo esc_attr($this->get_field_id( 'show_cat' )); ?>"> <?php echo esc_html__( 'Show post category','newscrunch' ); ?> </label>
            </p>
             <!-- date -->
            <p class="newscrunch-widet-area" style="float: left; width: 100%;">
                <input <?php if($date=='on') { echo 'checked'; }?> class="widefat" id="<?php echo esc_attr($this->get_field_id( 'date' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'date' )); ?>" type="checkbox" />
                <label for="<?php echo esc_attr($this->get_field_id( 'date' )); ?>"> <?php echo esc_html__( 'Show post date','newscrunch' ); ?> </label>
            </p>
            <!-- Show Author -->
            <p class="newscrunch-widet-area" style="float: left; width: 100%;">
                <input <?php if($show_auth=='on') { echo 'checked'; }?> class="widefat" id="<?php echo esc_attr($this->get_field_id( 'show_auth' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'show_auth' )); ?>" type="checkbox"/>
                <label for="<?php echo esc_attr($this->get_field_id( 'show_auth' )); ?>"> <?php echo esc_html__( 'Show author details','newscrunch' ); ?> </label>
            </p>
            <!-- Show Read Time -->
            <?php if ( class_exists('Newscrunch_Plus') ): ?>
            <p class="newscrunch-widet-area" style="float: left; width: 100%;">
                <input <?php if($show_read_time=='on') { echo 'checked'; }?> class="widefat" id="<?php echo esc_attr($this->get_field_id( 'show_read_time' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'show_read_time' )); ?>" type="checkbox"/>
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
      $instance['show_cat'] = ( ! empty( $new_instance['show_cat'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['show_cat'] ) : '';
      $instance['show_auth'] = ( ! empty( $new_instance['show_auth'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['show_auth'] ) : '';
      if ( class_exists('Newscrunch_Plus') ):
        $instance['show_read_time'] = ( ! empty( $new_instance['show_read_time'] ) ) ? newscrunch_sanitize_checkbox( $new_instance['show_read_time'] ) : '';
        $instance['animation_speed'] = ( ! empty( $new_instance['animation_speed'] ) ) ? absint( $new_instance['animation_speed'] ) : '';
        $instance['btitle'] = ( ! empty( $new_instance['btitle'] ) ) ? sanitize_text_field( $new_instance['btitle'] ) : '';
      endif;
      return $instance;
    }

}