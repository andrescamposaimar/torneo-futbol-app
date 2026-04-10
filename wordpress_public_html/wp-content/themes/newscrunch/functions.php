<?php
/**
 * Newscrunch functions and definitions
 *
 * @package Newscrunch
 */

// Global variables define
define('NEWSCRUNCH_TEMPLATE_DIR_URI', get_template_directory_uri());
define('NEWSCRUNCH_TEMPLATE_DIR', get_template_directory());

// wp_body_open function definition
if ( ! function_exists( 'wp_body_open' ) ) {

    function wp_body_open() {
        /**
         * Triggered after the opening <body> tag.
         */
        do_action( 'wp_body_open' );
    }
}

/**
 * Load all core theme function files
*/
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/scripts/script.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/helpers.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/breadcrumbs/breadcrumbs.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/breadcrumbs/breadcrumbs-2.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/menu/default_menu_walker.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/menu/newscrunch_nav_walker.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/partials/widgets/register-sidebars.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/customizer/customizer.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/theme-color/custom-color.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/theme-color/color-background.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/customizer/selective-refresh.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/meta-boxes/newscrunch-meta-box.php'; 
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/meta-boxes/newscrunch-post-format-meta-box.php';
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/pagination/newscrunch-pagination.php';
if( class_exists( 'Spice_Starter_Sites' )):
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/customizer/upsell/class-customize.php'; 
endif;
require NEWSCRUNCH_TEMPLATE_DIR . '/inc/customizer/customizer-recommended-plugin.php';

if ( ! function_exists( 'spncp_activate' ) ) {
	require NEWSCRUNCH_TEMPLATE_DIR . '/inc/font/font.php';
}

require_once ( NEWSCRUNCH_TEMPLATE_DIR . '/inc/customizer/sanitize-callback.php' );

if ( ! function_exists( 'newscrunch_setup' ) ) :
	/**
		* Sets up theme defaults and registers support for various WordPress features.
		*
		* Note that this function is hooked into the after_setup_theme hook, which
		* runs before the init hook. The init hook is too late for some features, such
		* as indicating support for post thumbnails.
	 */
	function newscrunch_setup() {
		/*
			* Make theme available for translation.
			* Translations can be filed in the /languages/ directory.
			* If you're building a theme based on News Crunch, use a find and replace
			* to change 'newscrunch' to the name of your theme in all the template files.
		*/
		load_theme_textdomain( 'newscrunch', NEWSCRUNCH_TEMPLATE_DIR . '/languages' );

		// Add default posts and comments RSS feed links to head.
		add_theme_support( 'automatic-feed-links' );

		/*
		* Add theme supports.
		*/
		add_theme_support( 'title-tag' );
		add_theme_support( "align-wide" );
		add_editor_style();
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'register_block_style' );
		add_theme_support( 'register_block_pattern' );
		/*
		* Enable support for Post Thumbnails on posts and pages.
		*/
		add_theme_support( 'post-thumbnails' );

		// This theme uses wp_nav_menu() in one location.
		register_nav_menus(
			array(
				'primary' => esc_html__( 'Primary Menu', 'newscrunch' ),
				'footer_menu' => esc_html__( 'Footer Menu', 'newscrunch' ),
			)
		);

		// Set up the WordPress core custom background feature.
		add_theme_support(
			'custom-background',
			apply_filters(
				'newscrunch_custom_background_args',
				array(
					'default-color' => 'ffffff',
					'default-image' => '',
				)
			)
		);

		// Add theme support for selective refresh for widgets.
		add_theme_support( 'customize-selective-refresh-widgets' );

		// Post Formats
		add_theme_support( 'post-formats', array( 'video', 'gallery', 'quote', 'audio', 'link' ) );

		//Add support for core custom logo.
		add_theme_support('custom-logo',
			array(
				'height'      => 60,
				'width'       => 250,
				'flex-width'  => true,
				'flex-height' => true,
				'header-text' => array('site-title', 'site-description')
			)
		);

		// Add theme support for HTML5.
    	add_theme_support( 'html5', array(
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
        ) );

        // woocommerce support
		add_theme_support( 'woocommerce' );

		if( !class_exists('Newscrunch_Plus') ) {
			//About Theme	         
	        $newscrunch_theme = wp_get_theme(); // gets the current theme
	        if ('Newscrunch' == $newscrunch_theme->name || 'Newscrunch Child' == $newscrunch_theme->name || 'Newscrunch child' == $newscrunch_theme->name ) {
	            if (is_admin()) {                       
	                require NEWSCRUNCH_TEMPLATE_DIR . '/admin/admin-init.php';
	            }
	        }
		}	
	}
endif;
add_action( 'after_setup_theme', 'newscrunch_setup' );

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width
 */
function newscrunch_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'newscrunch_content_width', 640 );
}
add_action( 'after_setup_theme', 'newscrunch_content_width', 0 );

/*
 * Add Body Class
 */
add_filter( 'body_class', 'newscrunch_body_class' );
function newscrunch_body_class( $classes ) {
        $classes[] = 'newscrunch';
	return $classes;
}

function newscrunch_hedder_full_layout(){
    if(get_theme_mod('header_layout','2')=='full'):?>
        <style type="text/css">
           @media (min-width: 1200px){
           	.spnc-topbar{padding:0 50px;}
           	.header-sidebar .spnc-container,.header-1 .spnc-custom .spnc-navbar{max-width: 100%;}
           	.header-sidebar.header-1 .spnc-navbar .spnc-container {padding: 12px 50px;}
           }
           .stickymenu {
           	max-width: 100%;
           }
        </style>
    <?php 
    endif;
}
add_action('wp_head','newscrunch_hedder_full_layout');

$newscrunch_theme = wp_get_theme(); // gets the current theme 

// Notice to add required plugin
if(!class_exists('Newscrunch_Plus')){
	if('Newscrunch' == $newscrunch_theme->name || 'Newscrunch Child' == $newscrunch_theme->name || 'Newscrunch child' == $newscrunch_theme->name ) :
	    function newscrunch_admin_plugin_notice_warn() {
	        $theme_name=wp_get_theme();
	        if ( get_option( 'dismissed-newscrunch_comanion_plugin', false ) ) {
	           return;
	        }

	        $dismissed = get_user_meta(get_current_user_id(), 'welcome_admin_notice_dismissed', true);
    
		    if ($dismissed) {
		        return;
		    } ?>

	        <div class="updated notice is-dismissible newscrunch-theme-notice">
	        	<div class="dashboard-hero-panel">
		            <div class="hero-panel-content">
		                <div class="hero-panel-subtitle">
		                    <?php esc_html_e('Hello', 'newscrunch'); 
		                    echo ', '; 
		                    $current_user = wp_get_current_user();
		                    echo esc_html($current_user->display_name);
		                    ?>
		                </div>
		                <div class="hero-panel-title">
		                    <?php 
		                    /* translators: %s: theme name */
		                    printf(esc_html__('Welcome to', 'newscrunch') . ' %s', $theme_name ); ?>
		                </div>
		                <div class="hero-panel-description">
		                    <?php 
		                    /* translators: %s: theme name */
		                    printf('%s ' . esc_html__("is now installed and ready to use. We've provide some links to get you started.", 'newscrunch'), $theme_name ); ?>
		                </div>
		                <div class="theme-admin-button-wrap theme-admin-button-group">
		                	<a href="<?php echo esc_url(admin_url('admin.php?page=newscrunch-welcome')); ?>" class="button theme-admin-button admin-button-secondary" target="_self" title="<?php esc_attr_e('Theme Dashboard', 'newscrunch'); ?>">
			                        <span class="dashicons dashicons-dashboard"></span>
			                        <span><?php esc_html_e('Theme Dashboard', 'newscrunch'); ?></span>
			                </a>
		                    <a href="<?php echo esc_url('https://spicethemes.com/newscrunch-wordpress-theme/#newscrunch_demo_lite'); ?>" class="button theme-admin-button admin-button-secondary" target="_blank" title="<?php esc_attr_e('Live Demo', 'newscrunch'); ?>">
		                        <span class="dashicons dashicons-welcome-view-site"></span>
		                        <span><?php esc_html_e('View Live Demos', 'newscrunch'); ?></span>
		                    </a>
		                    <a href="<?php echo esc_url('https://helpdoc.spicethemes.com/category/newscrunch/'); ?>" class="button theme-admin-button admin-button-secondary" target="_blank" title="<?php esc_attr_e('Help Docs', 'newscrunch'); ?>">
		                        <span class="dashicons dashicons-media-document"></span>
		                        <span><?php esc_html_e('Theme Documentation', 'newscrunch'); ?></span>
		                    </a>
			                <?php if(!class_exists('Spice_Starter_Sites')){?>
			               		<button id="install-plugin-button" data-plugin-url="<?php echo esc_url( 'https://spicethemes.com/extensions/spice-starter-sites.zip' ); ?>">
                                    <?php echo esc_html__( 'Install Plugin', 'newscrunch' ); ?>
                                </button>
			               <?php }?>
		                </div>
		            </div>
		            <div class="hero-panel-image">
		                <img src="<?php echo esc_url(get_theme_file_uri().'/admin/assets/img/welcome-banner.png');?>" alt="<?php esc_attr_e('Welcome Banner','newscrunch'); ?>">
		            </div>
	        	</div>
	        	<p><a href="#" class="dismiss-welcome-notice"><?php _e('Dismiss this notice', 'newscrunch'); ?></a></p>
	        </div>
	        
	        <script type="text/javascript">
				jQuery(function($) {
					$( document ).on( 'click', '.newscrunch-theme-notice .notice-dismiss', function () {
					    var type = $( this ).closest( '.newscrunch-theme-notice' ).data( 'notice' );
					    $.ajax( ajaxurl,
						{
					        type: 'POST',
					        data: {
					          action: 'dismissed_notice_handler',
					          type: type,
					        }
				      	});
					});
				});
	        </script>

	        <script>
		        jQuery(document).ready(function($) {
		            $('.dismiss-welcome-notice').on('click', function(e) {
		                e.preventDefault();
		                $('.newscrunch-theme-notice').fadeOut();
		                $.post(ajaxurl, {
		                    action: 'dismiss_welcome_admin_notice',
		                    security: '<?php echo wp_create_nonce("dismiss_welcome_admin_notice_nonce"); ?>'
		                });
		            });
		        });
		    </script>

	    <?php }

	    function newscrunch_dismiss_welcome_admin_notice() {
		    check_ajax_referer('dismiss_welcome_admin_notice_nonce', 'security');
		    update_user_meta(get_current_user_id(), 'welcome_admin_notice_dismissed', true);
		    wp_die();
		}
		add_action('wp_ajax_dismiss_welcome_admin_notice', 'newscrunch_dismiss_welcome_admin_notice');

		global $pagenow;
	    if ( "themes.php" == $pagenow && is_admin() ) {
	    add_action( 'admin_notices', 'newscrunch_admin_plugin_notice_warn' );
	    add_action( 'wp_ajax_dismissed_notice_handler', 'newscrunch_ajax_notice_handler');
		}
	endif;
}

if ( ! function_exists( 'newcrunch_schema_attributes' ) ) :
	function newcrunch_schema_attributes() {
		$itemtype = 'WebPage'; 
		$blog_page = ( is_home() || is_archive() || is_attachment() || is_tax() || is_single() );
		$itemtype = ( $blog_page ) ? 'Blog' : $itemtype;
		$itemtype = ( is_search() ) ? 'SearchResultsPage' : $itemtype;
		$itemtype_final = apply_filters( 'newcrunch_schema_attributes_itemtype', $itemtype );
		echo apply_filters( 'newcrunch_schema_attributes', "itemtype='https://schema.org/" . esc_attr( $itemtype_final ) . "' itemscope='itemscope'" );
	}
endif;

// Freemius snippet code
if('Newscrunch' == $newscrunch_theme->name || 'Newscrunch Child' == $newscrunch_theme->name || 'Newscrunch child' == $newscrunch_theme->name ) {
	if ( ! function_exists( 'new_fs' ) ) {
		if(class_exists('Spice_Starter_Sites') && defined( 'SPICE_STARTER_SITES_PLUGIN_PATH' ) && file_exists(SPICE_STARTER_SITES_PLUGIN_PATH . '/freemius/start.php')) {
		    // Create a helper function for easy SDK access.
		    function new_fs() {
		        global $new_fs;

		        if ( ! isset( $new_fs ) ) {
		            // Include Freemius SDK.
		            require_once SPICE_STARTER_SITES_PLUGIN_PATH . '/freemius/start.php';

		            $new_fs = fs_dynamic_init( array(
		                'id'                  => 	'12701',
		                'slug'                => 	'newscrunch',
		                'type'                => 	'theme',
		                'public_key'          => 	'pk_364d8ab336ff6a7292ae9fa7719fe',
		                'is_premium'          =>	true,
		                'has_premium_version' => 	false,
		                'has_addons'          => 	true,
		                'has_paid_plans'      => 	false,
		                'menu'                => 	array(
		                    'slug'           =>		'newscrunch-welcome',
		                    'account'        =>		true,
		                    'support'        =>		true,
		                )
		            ) );
		        }

		        return $new_fs;
		    }

		    // Init Freemius.
		    new_fs();
		    // Signal that SDK was initiated.
		    do_action( 'new_fs_loaded' );
		}
	}
}

// Update release notice to the admin dashboard
if(!class_exists('Newscrunch_Plus')) {
	if('Newscrunch' == $newscrunch_theme->name || 'Newscrunch Child' == $newscrunch_theme->name || 'Newscrunch child' == $newscrunch_theme->name ) :
		function newscrunch_add_update_admin_notice() {
			$theme = wp_get_theme(); 
	  		$dismissed = get_user_meta(get_current_user_id(), 'update_admin_notice_dismissed', true);
    
		    if ($dismissed) {
		        return;
		    } ?>
		    <div class="newscrunch-update-notice notice notice-info is-dismissible">
		        <div class="notice-content-wrap">
		            <div class="notice-content">
		            	<h2><?php printf( '%1$s ' . __('Current','newscrunch') . ' %2$s', esc_html($theme->name), '<span>Version' . ' ' . esc_html($theme->get('Version')) . '</span>'); ?></h2>
		                
		                <p class="notice-des">
		                	<?php printf( '%1$s %2$s %3$s', esc_html__("We've consistently aimed to meet our users' needs and demands. In order to address specific requirements and rectify issues from our previous version, we've rolled out version","newscrunch"), esc_html($theme->get('Version')), esc_html__('complete with exciting new features. Take a look now!','newscrunch')); ?>
		                </p>

		                <ol class="admin-notice-up-list">
		                     <li><?php echo "Fixed Container width issue with sticky sidebar."; ?></li>
		                </ol>

		                <div class="admin-notice-up-btn-wrap">
		                	<a href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>" class="button theme-admin-button admin-button-secondary" target="_blank" title="<?php esc_attr_e('Try Now', 'newscrunch'); ?>">
		                		<span class="dashicons dashicons-admin-customizer"></span>
		                		<span><?php esc_html_e('Try It Now', 'newscrunch'); ?></span>
		                	</a>

		                	<a href="<?php echo esc_url('https://spicethemes.com/newscrunch-changelog/'); ?>" class="button theme-admin-button admin-button-secondary" target="_blank" title="<?php esc_attr_e('Changelog', 'newscrunch'); ?>">
		                		<span class="dashicons dashicons-visibility"></span>
		                		<span><?php esc_html_e('See Changelog', 'newscrunch'); ?></span>
		                	</a>

			                <a href="<?php echo esc_url('https://youtube.com/playlist?list=PLTfjrb24Pq_DeJOZdKEaP3rZPbHuOCLtZ&si=rsDRjg6uD5J_LFkv'); ?>" class="button theme-admin-button admin-button-secondary" target="_blank" title="<?php esc_attr_e('Watch Videos', 'newscrunch'); ?>">
			                	<span class="dashicons dashicons-youtube"></span>
			                	<span><?php esc_html_e('Watch Videos', 'newscrunch'); ?></span>
			                </a>

			                <a href="<?php echo esc_url('https://spicethemes.com/newscrunch/'); ?>" class="button theme-admin-button admin-button-secondary" target="_blank" title="<?php esc_attr_e('Upgrade To Pro', 'newscrunch'); ?>">
			                	<span class="dashicons dashicons-update"></span>
			                	<span><?php esc_html_e('Upgrade To Pro', 'newscrunch'); ?></span> 
			               	</a>			               	
		                </div>
		            </div>
		        </div>
		        <p><a href="#" class="dismiss-update-notice"><?php _e('Dismiss this notice', 'newscrunch'); ?></a></p>
		    </div>

		    <script>
		        jQuery(document).ready(function($) {
		            $('.dismiss-update-notice').on('click', function(e) {
		                e.preventDefault();
		                $('.newscrunch-update-notice').fadeOut();
		                $.post(ajaxurl, {
		                    action: 'dismiss_update_admin_notice',
		                    security: '<?php echo wp_create_nonce("dismiss_update_admin_notice_nonce"); ?>'
		                });
		            });
		        });
		    </script>

		<?php
		}

		function newscrunch_dismiss_update_admin_notice() {
		    check_ajax_referer('dismiss_update_admin_notice_nonce', 'security');
		    update_user_meta(get_current_user_id(), 'update_admin_notice_dismissed', true);
		    wp_die();
		}
		add_action('wp_ajax_dismiss_update_admin_notice', 'newscrunch_dismiss_update_admin_notice');

		global $pagenow;
	    if("themes.php" == $pagenow && is_admin()) {
			add_action('admin_notices', 'newscrunch_add_update_admin_notice');
		}
	endif;	
}


// Get the post date
if ( ! function_exists( 'newcrunch_post_date_time' ) ) :
	function newcrunch_post_date_time( $post_id='', $tag='' ) 
	{
	    if(get_theme_mod('select_date_format','date_format_by_wp')== 'date_format_by_theme')
	    {
	    	if (is_rtl()) { $rtl = 'dir="rtl"'; } else { $rtl =''; }
	    	$display_date = (get_theme_mod('select_display_date','publish')=='publish') ? 'get_the_time' : 'get_the_modified_time';
	    	return '<span '.$rtl.' class="display-time">'.human_time_diff($display_date('U',$post_id), current_time('timestamp')) . " " . __('ago','newscrunch').'</span>';	
	    }
	    else
	    {	
	    	if($tag == 'no')
	    	{
	    		if (is_rtl()) { $rtl = 'dir="rtl"'; } else { $rtl =''; }
		    	$post_date = (get_theme_mod('select_display_date','publish')=='publish') ? get_the_date() : get_the_modified_date();
		    	return '<time '.$rtl.' itemprop="'.$post_date.'" class="entry-date">'.esc_html($post_date).'</time>';
	    	}
	    	else
	    	{
	    		if (is_rtl()) { $rtl = 'dir="rtl"'; } else { $rtl =''; }
		    	$post_date = (get_theme_mod('select_display_date','publish')=='publish') ? get_the_date() : get_the_modified_date();
		    	return '<a '.$rtl.' itemprop="url" href="'.esc_url(home_url('/')).esc_html(date('Y/m', strtotime(get_the_date()))).'" title="'.esc_attr__('date-time','newscrunch').'"><time itemprop="'.$post_date.'" class="entry-date">'.esc_html($post_date).'</time></a>';
	    	}
	    	
	    }
	}
endif;

// Hook the AJAX action for logged-in users
add_action('wp_ajax_newscrunch_check_plugin_status', 'newscrunch_check_plugin_status');

function newscrunch_check_plugin_status() {
	
	// Check if user is authorized (must be an admin)
 	if (!current_user_can('install_plugins')) {
        wp_send_json_error(esc_html__('You do not have permission to manage plugins.', 'newscrunch'));
        return;
    }

    if (!isset($_POST['plugin_slug'])) {
        wp_send_json_error(esc_html__('No plugin slug provided.', 'newscrunch'));
        return;
    }

    $plugin_slug = sanitize_text_field($_POST['plugin_slug']);
    $plugin_main_file = $plugin_slug . '/' . $plugin_slug . '.php'; // Adjust this based on your plugin structure

    // Check if the plugin exists
    $plugins = get_plugins();
    if (isset($plugins[$plugin_main_file])) {
        if (is_plugin_active($plugin_main_file)) {
            wp_send_json_success(array('status' => 'activated'));
        } else {
            wp_send_json_success(array('status' => 'installed'));
        }
    } else {
        wp_send_json_success(array('status' => 'not_installed'));
    }
}

// Existing AJAX installation function for installing and activating
add_action('wp_ajax_newscrunch_install_activate_plugin', 'newscrunch_install_and_activate_plugin');

function newscrunch_install_and_activate_plugin() {
	
	// Check if user is authorized (must be an admin)
    if (!current_user_can('install_plugins')) {
        wp_send_json_error(esc_html__('You do not have permission to install plugins.', 'newscrunch'));
        return;
    }

  	// Verify nonce for CSRF protection
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'plugin_installer_nonce')) {
        wp_send_json_error(esc_html__('Security check failed.', 'newscrunch'));
        return;
    }
	
    if (!isset($_POST['plugin_url'])) {
        wp_send_json_error(esc_html__('No plugin URL provided.', 'newscrunch'));
        return;
    }

    // Include necessary WordPress files for plugin installation
    include_once(ABSPATH . 'wp-admin/includes/file.php');
    include_once(ABSPATH . 'wp-admin/includes/misc.php');
    include_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');

    $plugin_url = esc_url($_POST['plugin_url']);
    $plugin_slug = sanitize_text_field($_POST['plugin_slug']);
    $plugin_main_file = $plugin_slug . '/' . $plugin_slug . '.php'; // Ensure this matches your plugin structure
	
	// Ensure the file being downloaded is a zip file
    if (pathinfo($plugin_url, PATHINFO_EXTENSION) !== 'zip') {
       	wp_send_json_error(esc_html__('Invalid file type.', 'newscrunch'));
        return;
    }

    WP_Filesystem();

    // Download the plugin file
    $temp_file = download_url($plugin_url);

    if (is_wp_error($temp_file)) {
        wp_send_json_error($temp_file->get_error_message());
        return;
    }

    // Unzip the plugin to the plugins folder
    $plugin_folder = WP_PLUGIN_DIR;
    $result = unzip_file($temp_file, $plugin_folder);

    // Clean up temporary file
    unlink($temp_file);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
        return;
    }

    // Activate the plugin if it was installed
    $activate_result = activate_plugin($plugin_main_file);

    // Return success with redirect URL
    if ( class_exists('Newscrunch_Plus') ){
    	wp_send_json_success(array('redirect_url' => admin_url('admin.php?page=newscrunch-plus-welcome')));
    }else{
    	wp_send_json_success(array('redirect_url' => admin_url('admin.php?page=newscrunch-welcome')));
    }
}

// Enqueue JavaScript for the button functionality
add_action('admin_enqueue_scripts', 'newscrunch_enqueue_plugin_installer_script');

function newscrunch_enqueue_plugin_installer_script() {
    wp_enqueue_script('newscrunch-plugin-installer-js',  NEWSCRUNCH_TEMPLATE_DIR_URI . '/admin/assets/js/plugin-installer.js', array('jquery'), null, true);
    wp_localize_script('newscrunch-plugin-installer-js', 'pluginInstallerAjax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('plugin_installer_nonce')
    ));
}

/*
 * Add WooCommerce Compatibility
 */
if ( class_exists( 'WooCommerce' ) ) :
	
	//Add WooCommerce theme support
	function newscrunch_wc_setup() {
	   add_theme_support( 'wc-product-gallery-zoom' );
	   add_theme_support( 'wc-product-gallery-lightbox' );
	   add_theme_support( 'wc-product-gallery-slider' );
	}
	add_action( 'after_setup_theme', 'newscrunch_wc_setup' );

	//Enqueue Scripts & Styles
	function newscrunch_wc_enqueue_scripts() {
	    if (is_product()) {
	    	wp_enqueue_script('newscrunch-quantity-script',get_template_directory_uri() . '/assets/js/wc-quantity.js', ['jquery'],'1.0',true);
	    }

	    // Check if we're on the cart page
	    if (is_cart()) {
	        wp_enqueue_script('newscrunch-cart-customizations',get_template_directory_uri() . '/assets/js/wc-cart.js', array('jquery'),null,true);

	        // Pass variables to JavaScript
	        wp_localize_script('newscrunch-cart-customizations', 'customCartData', array('ajax_url' => admin_url('admin-ajax.php'),'nonce'    => wp_create_nonce('woocommerce-cart'),));
	    }
	}
	add_action('wp_enqueue_scripts', 'newscrunch_wc_enqueue_scripts');

	// Function to check if we are editing the Cart page
	function newscrunch_wc_check_if_cart_page_editor() {
	    global $post;

	    // Ensure we're in the admin area and the current post is the Cart page
	    if (is_admin() && isset($post) && $post->ID == wc_get_page_id('cart')) {
	        return true; // It's the Cart page editor
	    }

	    return false; // Not the Cart page
	}

	// Function to remove the meta box on specific pages
	function newscrunch_wc_remove_meta_box() {
	    global $post;

	    // Check if it's the Cart page editor
	    if (newscrunch_wc_check_if_cart_page_editor()) {
	        // Remove a specific meta box from the Cart page editor
	        remove_meta_box('newscrunch_meta_id', 'page', 'normal');
	    }

	    // Check if it's the Product page editor
	    if (newscrunch_wc_check_if_product_page_editor()) {
	        // Remove a specific meta box from the Product page editor
	        remove_meta_box('newscrunch_meta_id', 'product', 'normal'); // Replace 'newscrunch_meta_id' with the actual meta box ID
	    }
	}

	// Helper function to check if the current page is the Product page editor
	function newscrunch_wc_check_if_product_page_editor() {
	    global $post;
	    
	    // Ensure $post exists and check the post type
	    return isset($post) && $post->post_type === 'product';
	}

	// Hook into the add_meta_boxes action to remove the meta box
	add_action('add_meta_boxes', 'newscrunch_wc_remove_meta_box', 10);



	// Add product image, title, and both prices in separate anchor tags in the mini-cart
	function newscrunch_wc_mini_cart_item_product_info( $item_name, $cart_item, $cart_item_key ) {
	    $_product = $cart_item['data'];
	    
	    // Get product details
	    $product_permalink = $_product->get_permalink( $cart_item );
	    $product_title = $_product->get_name();
	    $product_image = $_product->get_image( 'thumbnail' );
	    
	    // Get prices
	    $regular_price = $_product->get_regular_price();
	    $sale_price = $_product->get_sale_price();

	    // Format prices
	    $formatted_regular_price = wc_price( $regular_price );
	    $formatted_sale_price = wc_price( $sale_price );

	    // Start building the HTML
	    $html = '<a href="'  . esc_url( $product_permalink ) . '" class="product-image">' . wp_kses_post( $product_image ) . '</a>';
	    $html .= '<div class="spnc-title-price-wrap"><h6><a href="' . esc_url( $product_permalink ) . '" class="product-title">' . esc_html( $product_title ) . '</a></h6>';

	    // Display prices
	    if ( $_product->is_on_sale() ) {
	        $html .= '<div class="spnc-cart-header-price"><span class="product-price">';
	        $html .= '<del class="regular-price">' . wp_kses_post( $formatted_regular_price ) . '</del>';
	        $html .= '<ins class="sale-price">' . wp_kses_post( $formatted_sale_price ) . '</ins>';
	        $html .= '</span></div></div>';
	    } else {
	        $html .= '<span class="product-price">' . wp_kses_post( $formatted_regular_price ) . '</span>';
	    }

	    return $html;
	}
	add_filter( 'woocommerce_cart_item_name', 'newscrunch_wc_mini_cart_item_product_info', 10, 3 );

	// Optional: Modify the cart item thumbnail size
	function newscrunch_wc_mini_cart_item_thumbnail_size() {
	    return 'thumbnail';
	}
	add_filter( 'woocommerce_cart_item_thumbnail', 'newscrunch_wc_mini_cart_item_thumbnail_size' );


	// Display custom cart quantity and subtotal at the top of the mini-cart
	function newscrunch_wc_add_custom_cart_summary_top() {
		if( ! WC()->cart->is_empty() )
		{	
		    // Get the cart contents and subtotal
		    $cart_contents = WC()->cart->get_cart();
		    $total_items = 0;
		    $cart_subtotal = WC()->cart->get_cart_subtotal();

		    // Loop through cart contents to calculate the total items
		    foreach ( $cart_contents as $cart_item ) {
		        $total_items += $cart_item['quantity'];
		    }

		    // Display the total items and subtotal
		    echo sprintf( 
		        '<div class="spnc-mini-cart-summary">
		            <span class="spnc-cart-item-quantity">%d item%s</span>
		            <span class="spnc-cart-subtotal">Subtotal: %s</span>
		        </div>',
		        $total_items,
		        $total_items > 1 ? 's' : '',
		        $cart_subtotal
		    );
	    }
	}
	add_action( 'woocommerce_before_mini_cart', 'newscrunch_wc_add_custom_cart_summary_top' );


	// Remove subtotal from WooCommerce mini-cart
	function newscrunch_wc_remove_mini_cart_subtotal() {
	    remove_action( 'woocommerce_widget_shopping_cart_total', 'woocommerce_widget_shopping_cart_subtotal', 10 );
	}
	add_action( 'woocommerce_before_mini_cart', 'newscrunch_wc_remove_mini_cart_subtotal' );


	// Remove quantity × price from WooCommerce mini-cart
	function newscrunch_wc_remove_quantity_price_in_mini_cart( $item_quantity, $cart_item, $cart_item_key ) {
	    // Return an empty string to remove the quantity × price
	    return '';
	}
	add_filter( 'woocommerce_widget_cart_item_quantity', 'newscrunch_wc_remove_quantity_price_in_mini_cart', 10, 3 );


	function newscrunch_wc_add_icon_before_empty_cart_message() {
	    // Only show the icon if the cart is empty
	    if ( WC()->cart->is_empty() ) {
	        echo '<span class="spnc-empty-cart"><i class="fa-solid fa-cart-shopping"></i></span>';
	    }
	}
	add_action( 'woocommerce_before_mini_cart', 'newscrunch_wc_add_icon_before_empty_cart_message' );


	// Automatically update the mini cart via AJAX when a product is added
	add_action( 'wp_footer', 'newscrunch_wc_update_mini_cart_dropdown' );

	function newscrunch_wc_update_mini_cart_dropdown() {
	    if ( ! class_exists( 'WooCommerce' ) ) {
	        return;
	    }
	    ?>
	    <script type="text/javascript">
	        jQuery(document).ready(function($) {
	            // Trigger mini-cart dropdown when an item is added
	            $(document.body).on('added_to_cart', function(event, fragments, cart_hash) {
	                // Refresh mini cart contents
	                $('.cart-dropdown').load(window.location.href + ' .cart-dropdown > *');
	                
	                // Optionally show the dropdown
	                $('.header-cart .menu-item.dropdown').addClass('open');
	            });
	        });
	    </script>
	    <?php
	}


add_filter( 'woocommerce_before_shop_loop_item_title', 'newscrunch_wc_replace_product_title_tag_start', 0 );
add_filter( 'woocommerce_after_shop_loop_item_title', 'newscrunch_wc_replace_product_title_tag_end', 999 );

function newscrunch_wc_replace_product_title_tag_start() {
    ob_start(); // Start output buffering
}

function newscrunch_wc_replace_product_title_tag_end() {
    $output = ob_get_clean(); // Get the buffered content

    // Replace <h2> with <h4>
    $output = str_replace(
        '<h2 class="woocommerce-loop-product__title">',
        '<h4 class="woocommerce-loop-product__title">',
        $output
    );

    // Replace closing </h2> with </h4>
    $output = str_replace('</h2>', '</h4>', $output);

    echo $output; // Output the modified content
}


add_action( 'init', 'newscrunch_wc_replace_single_product_title_tag' );

function newscrunch_wc_replace_single_product_title_tag() {
    // Remove the default product title (which uses <h1>)
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );

    // Add a custom product title with <h2> instead of <h1>
    add_action( 'woocommerce_single_product_summary', 'newscrunch_wc_single_product_title', 5 );
}

function newscrunch_wc_single_product_title() {
    // Output the product title wrapped in an <h2> tag
    echo '<h2 class="product_title entry-title">' . esc_html( get_the_title() ) . '</h2>';
}


// Remove default related products title
remove_action( 'woocommerce_product_related_products_heading', 'woocommerce_related_products_heading' );

// Add custom related products title
add_action( 'woocommerce_product_related_products_heading', 'newscrunch_wc_related_products_heading' );

function newscrunch_wc_related_products_heading() {
    echo '<div class="related_product spnc-common-widget-area">
        <div class="spnc-main-wrapper">
            <div class="spnc-main-wrapper-heading">
                <h2>' . esc_html( __( 'Related products', 'newscrunch' ) ) . '</h2>
            </div>
    </div>';
}


// Remove the default upsell heading
remove_action( 'woocommerce_product_upsells_products_heading', 'woocommerce_upsell_display_heading', 10 );

// Add custom upsell heading with your custom markup
add_action( 'woocommerce_product_upsells_products_heading', 'newscrunch_wc_upsell_title' );

function newscrunch_wc_upsell_title() {
    echo '<div class="spnc-upsell spnc-common-widget-area">
        <div class="spnc-main-wrapper">
            <div class="spnc-main-wrapper-heading">
                <h2>' . esc_html( __( 'You may also like…', 'newscrunch' ) ) . '</h2>
            </div>
    </div>';
}

//woocommerce endif 
endif;