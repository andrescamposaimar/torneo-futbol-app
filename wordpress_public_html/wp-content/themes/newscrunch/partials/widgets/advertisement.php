<?php
/**
* Widget API: Advertisement Widget Class
* @package Newscrunch
*/
class Newscrunch_Advertisement_Widget_Controller extends WP_Widget {
    //construct part
    function __construct()
    {
        parent::__construct(
        //Base ID of widget
        'newscrunch_adv',

        //widget name will appear in UI
        esc_html__('Newscrunch : Advertisement  Sidebar','newscrunch'),

        // Widget description
        array( 'description' => esc_html__('Display the advertisement','newscrunch'))  
        );

    }

    //Widget Front End
    public function widget( $args, $instance ) {
        if ( ! isset( $args['widget_id'] ) ) { $args['widget_id'] = $this->id; }
        $title = isset( $instance['title'] ) ? $instance['title'] : '';
        $name  = isset( $instance['name'] ) ? $instance['name'] : '';
        $img   = isset( $instance['img'] ) ? $instance['img'] : '';
        $txt   = isset( $instance['txt'] ) ? $instance['txt'] : '';
        $url   = isset( $instance['url'] ) ? $instance['url'] : '#';
        $desc  = isset( $instance['desc'] ) ? $instance['desc'] : '';

        echo wp_kses_post($args['before_widget']);
        if ( $title ) { echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']); }


        if (str_contains($img, 'https://demo-newscrunch.spicethemes.com')) 
        { 
            $exp = explode('wp-content',$img);
            $img = home_url('/').'wp-content'.$exp[1];
        }
        ?>

            <figure class="spnc-wp-block-image" <?php if($img):?> style="background-image:url(<?php echo esc_url($img);?>)" <?php endif;?> >
                <div class="spnc_widget_img_overlay"></div>
                   <div class="adv-wrapper">
                    <div class="adv-img-content">
                     <h4><?php echo esc_html($name); ?></h4>
                <p><?php if($desc) echo esc_html($desc); ?></p>
                <div> <?php if($txt) echo '<a href="'.esc_url($url).'">'.esc_html($txt).'</a>'; ?> </div>
            </div>
        </div>
            </figure>  
        <?php
        echo wp_kses_post($args['after_widget']);
    }

    //Widget Back End
    public function form( $instance ) 
    {
        if ( isset( $instance[ 'title' ])){ $title = $instance[ 'title' ]; } else { $title = esc_html__('Widget title','newscrunch' ); }
        if ( isset( $instance[ 'name' ])){ $name = $instance[ 'name' ]; } else { $name = 'Lorem ipsum dolor sit ame'; }
        if ( isset( $instance[ 'desc' ])){ $desc = $instance[ 'desc' ]; } else { $desc = 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor.'; }
        if ( isset( $instance[ 'img' ])){ $img = $instance[ 'img' ]; }
        if ( isset( $instance[ 'txt' ])) { $txt= $instance[ 'txt' ]; }
        if ( isset( $instance[ 'url' ])){ $url = $instance[ 'url' ]; }
        ?>
            <!-- Heading -->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'title' )); ?>"><?php echo esc_html__( 'Widget Title','newscrunch' ); echo ':'; ?></label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'title' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'title' )); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
            </p>
            <!-- Advertisement Heading-->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'name' )); ?>"><?php echo esc_html__( 'Heading','newscrunch' ); echo ':'; ?></label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'name' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'name' )); ?>" type="text" value="<?php echo esc_attr( $name ); ?>" />
            </p>
            <!--Advertisement Description-->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'desc' )); ?>"><?php echo esc_html__( 'Description','newscrunch' ); echo ':'; ?></label>
                <textarea class="widefat" id="<?php echo esc_attr($this->get_field_id( 'desc' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'desc' )); ?>" type="text"/><?php echo esc_html( $desc ); ?></textarea>
            </p>
            <!--Button Text-->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'txt' )); ?>"><?php echo esc_html__( 'Button Text','newscrunch' ); echo ':'; ?></label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'txt' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'txt' )); ?>" type="text" value="<?php echo esc_attr( $txt ); ?>" />
            </p>
            <!--Button URL-->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'url' )); ?>"><?php echo esc_html__( 'Button URL','newscrunch' ); echo ':'; ?></label>
                <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'url' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'url' )); ?>" type="text" value="<?php if($url) echo esc_url( $url ); ?>" />

            </p>
            <!-- Img -->
            <p class="newscrunch-widet-area">
                <label for="<?php echo esc_attr($this->get_field_id( 'img' )); ?>"><?php echo esc_html__( 'Image','newscrunch' ); echo ':';?></label>
                <input class="widefat newscrunch_auth_img" id="<?php echo esc_attr($this->get_field_id( 'img' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'img' )); ?>" type="hidden" value="<?php echo esc_url( $img ); ?>" />   
                <img src="<?php echo esc_url($img) ;?>" class="newscrunch-widget-img"/>
                <button type="button" class="button button-primary newscrunch-media-upload"><?php echo esc_html__( 'Select Image','newscrunch' ); ?> </button>
            </p>        
        <?php
    }

    //save or uption option
    public function update( $new_instance, $old_instance)
    {
      $instance = $old_instance;
      $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
      $instance['name'] = ( ! empty( $new_instance['name'] ) ) ? sanitize_text_field( $new_instance['name'] ) : '';
      $instance['img'] = ( ! empty( $new_instance['img'] ) ) ? esc_url_raw( $new_instance['img'] ) : '';
      $instance['txt'] = ( ! empty( $new_instance['txt'] ) ) ? sanitize_text_field( $new_instance['txt'] ) : '';
      $instance['url'] = ( ! empty( $new_instance['url'] ) ) ? esc_url_raw( $new_instance['url'] ) : '';
      $instance['desc'] = ( ! empty( $new_instance['desc'] ) ) ? sanitize_text_field( $new_instance['desc'] ) : '';
      return $instance;
    }

}