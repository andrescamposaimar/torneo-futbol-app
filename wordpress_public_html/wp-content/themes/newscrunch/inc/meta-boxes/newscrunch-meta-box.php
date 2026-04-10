<?php
/**
 * @package Newscrunch
 */
if ( ! class_exists( 'Newscrunch_Layout_Meta_Box' ) ) {
    class Newscrunch_Layout_Meta_Box
    {
        public function __construct()
        {
            add_action( 'admin_enqueue_scripts', array( $this,'newscrunch_admin_script'));
            add_action( 'add_meta_boxes', array( $this, 'newscrunch_meta_fn'));
            add_action( 'save_post', array( $this, 'newscrunch_meta_save'));
        }

        /**
         * Load Admin Script
         *
         */
        public function newscrunch_admin_script()
        {   
            wp_enqueue_style('newscrunch-meta', NEWSCRUNCH_TEMPLATE_DIR_URI.'/inc/meta-boxes/assets/css/meta-box.css');
        }

        //Add Meta Box
        function newscrunch_meta_fn()
        {
            add_meta_box( 'newscrunch_meta_id', esc_html__('Layout Settings (Layout settings will not work with custom templates, except for the default template.)','newscrunch'), array($this,'newscrunch_meta_cb_fn'), '','normal','high' );
        }

        //Callback Meta Function
        function newscrunch_meta_cb_fn()
        {
            get_template_part('inc/meta-boxes/newscrunch-meta-box-page-settings');
        }

        //Save Meta Values
        function newscrunch_meta_save($post_id) 
        {  
            if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('DOING_AJAX') && DOING_AJAX) || isset($_REQUEST['bulk_edit']))
                  return;
              
            if ( ! current_user_can( 'edit_page', $post_id ) )
            {   return ;  } 
              
            if(isset( $_POST['post_ID']))
            {   
              $post_ID = absint($_POST['post_ID']);
                update_post_meta($post_ID, 'newscrunch_site_layout', sanitize_text_field($_POST['newscrunch_site_layout']));
                update_post_meta($post_ID, 'newscrunch_page_sidebar', sanitize_text_field($_POST['newscrunch_page_sidebar']));
                update_post_meta($post_ID, 'newscrunch_page_left_sidebar', sanitize_text_field($_POST['newscrunch_page_left_sidebar']));
            }       
        }
    }
}
new Newscrunch_Layout_Meta_Box();