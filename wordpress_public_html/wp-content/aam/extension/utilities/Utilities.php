<?php

/**
 * ======================================================================
 * LICENSE: This file is subject to the terms and conditions defined in *
 * file 'license.txt', which is part of this source code package.       *
 * ======================================================================
 */

/**
 * AAM Utilities extension
 *
 * @package AAM
 * @author Vasyl Martyniuk <vasyl@vasyltech.com>
 */
class AAM_Utilities {

    /**
     * 
     */
    const CAPABILITY_OPTION = 'aam.feature.utility.capability';
    
    /**
     * Instance of itself
     * 
     * @var AAM_PlusPackage 
     * 
     * @access private
     */
    private static $_instance = null;
    
    /**
     * Cache
     * 
     * @var array
     * 
     * @access protected 
     */
    protected $cache = null;
    
    /**
     *
     * @var type 
     */
    protected $save = false;

    /**
     * Initialize the extension
     * 
     * @return void
     * 
     * @access protected
     */
    protected function __construct() {
        if (is_admin()) {
            add_action('aam-feature-registration', array($this, 'registerUI'));
            //print required JS & CSS
            add_action('admin_print_scripts', array($this, 'printJavascript'));
            add_action('admin_print_styles', array($this, 'printStylesheet'));
            //add custom ajax handler
            add_filter('aam-ajax-filter', array($this, 'ajax'), 10, 3);
            add_filter(
                'aam-cap-row-actions-filter', array($this, 'capActions'), 10, 2
            );
            //clear cache hook
            add_filter('aam-cache-status-filter', array($this, 'isCacheOn'));
            add_action('aam-clear-cache-action', array($this, 'clearCache'), 10, 1);
        }
        
        add_action('shutdown', array($this, 'shutdown'));
        
        //caching filter & action
        add_filter('aam-read-cache-filter', array($this, 'readCache'), 10, 3);
        add_action('aam-write-cache-action', array($this, 'writeCache'), 10, 3);
        
        //utilities option
        add_filter('aam-utility-property', array($this, 'getProperty'), 10, 2);
        
        //initialize the cache
        if ($this->isCacheOn()) {
            $this->cache = AAM_Core_API::getOption('aam-cache', array());
        }
    }
    
    /**
     * 
     */
    public function shutdown() {
        if ($this->save) {
            AAM_Core_API::updateOption('aam-cache', $this->cache);
        }
    }
    
    /**
     * Print javascript libraries
     *
     * @return void
     *
     * @access public
     */
    public function printJavascript() {
        if (AAM::isAAM()) {
            $baseurl = $this->getBaseurl('/js');
            wp_enqueue_script('aam-utl-tg', $baseurl . '/bootstrap-toggle.min.js');
            wp_enqueue_script(
                    'aam-utl', $baseurl . '/aam-utilities.js', array('aam-main')
            );
        }
    }
    
    /**
     * Print necessary styles
     *
     * @return void
     *
     * @access public
     */
    public function printStylesheet() {
        if (AAM::isAAM()) {
            $baseurl = $this->getBaseurl('/css');
            wp_enqueue_style('aam-utl-tg', $baseurl . '/bootstrap-toggle.min.css');
        }
    }
    
    /**
     * Get extension base URL
     * 
     * @param string $path
     * 
     * @return string
     * 
     * @access protected
     */
    protected function getBaseurl($path = '') {
        $contentDir = str_replace('\\', '/', WP_CONTENT_DIR);
        $baseDir    = str_replace('\\', '/', dirname(__FILE__));
        
        $relative = str_replace($contentDir, '', $baseDir);
        
        return content_url() . $relative . $path;
    }

    /**
     * Custom ajax handler
     * 
     * @param mixed            $response
     * @param AAM_Core_Subject $subject
     * @param string           $action
     * 
     * @return string
     * 
     * @access public
     */
    public function ajax($response, $subject, $action) {
        $parts = explode('.', $action);
        $cap   = AAM_Core_ConfigPress::get(self::CAPABILITY_OPTION, 'administrator');
        $can   = AAM::getUser()->hasCapability($cap);
        
        if ($parts[0] == 'Utility' && !empty($parts[1]) && $can) {
            if (method_exists($this, $parts[1])) {
                $response = call_user_func(array($this, $parts[1]), $subject);
            }
        }
        
        return $response;
    }
    
    /**
     * 
     * @return type
     */
    protected function updateCapability() {
        $capability = AAM_Core_Request::post('capability');
        $updated    = AAM_Core_Request::post('updated');
        $roles      = AAM_Core_API::getRoles();
        
        //first make sure that similar capability does not exist already
        $allcaps = array();
        foreach ($roles->role_objects as $role) {
            $allcaps = array_merge($allcaps, $role->capabilities);
        }
        
        if (!isset($allcaps[$updated])) {
            foreach($roles->role_objects as $role) {
                //check if capability is present for current role! Please notice, we
                //can not use the native WP_Role::has_cap function because it will
                //return false if capability exists but not checked
                if (isset($role->capabilities[$capability])) {
                    $role->add_cap($updated, $role->capabilities[$capability]);
                    $role->remove_cap($capability);
                }
            }
            $response = array('status' => 'success');
        } else {
            $response = array(
                'status' => 'failure', 
                'message' => __('Capability already exists', AAM_KEY)
            );
        }
        
        return json_encode($response);
    }
    
    /**
     * 
     * @param AAM_Core_Subject $subject
     * @return type
     */
    protected function deleteCapability(AAM_Core_Subject $subject) {
        $capability = AAM_Core_Request::post('capability');
        $roles      = AAM_Core_API::getRoles();
        
        if (is_a($subject, 'AAM_Core_Subject_Role')) {
            $roles->remove_cap($subject->getId(), $capability);
            $response = array('status' => 'success');
        } else {
            $response = array(
                'status' => 'failure', 
                'message' => __('Can not remove the capability', AAM_KEY)
            );
        }
        
        return json_encode($response);
    }
    
    /**
     * 
     * @param type $actions
     * @param type $subject
     * @return type
     */
    public function capActions($actions, AAM_Core_Subject $subject) {
        //allow to delete or update capability only for roles!
        if ($this->getProperty('manage-capability', false) 
                && is_a($subject, 'AAM_Core_Subject_Role')) {
            $actions[] = 'edit';
            $actions[] = 'delete';
        }
        
        return $actions;
    }
    
    /**
     * Save AAM utility options
     * 
     * Important notice! This function excepts "value" to be only boolean value
     *
     * @return string
     *
     * @access protected
     */
    protected function save() {
        $param = AAM_Core_Request::post('param');
        $value = filter_var(
                AAM_Core_Request::post('value'), FILTER_VALIDATE_BOOLEAN
        );
        
        $data = AAM_Core_API::getOption('aam-utilities', array());
        $data[$param] = $value;
        
        AAM_Core_API::updateOption('aam-utilities', $data);
        
        if ($param == 'caching' && !$value) { //clear cache if turned off
            AAM_Core_API::updateOption('aam-cache', array());
        }
        
        return json_encode(array('status' => 'success'));
    }
    
    /**
     * Clear all AAM settings
     * 
     * @global wpdb $wpdb
     * 
     * @return string
     * 
     * @access protected
     */
    protected function clear() {
        global $wpdb;
        
        //clear wp_options
        $oquery = "DELETE FROM {$wpdb->options} WHERE `option_name` LIKE %s";
        $wpdb->query($wpdb->prepare($oquery, 'aam%' ));
        
        //clear wp_postmeta
        $pquery = "DELETE FROM {$wpdb->postmeta} WHERE `meta_key` LIKE %s";
        $wpdb->query($wpdb->prepare($pquery, 'aam%' ));
        
        //clear wp_usermeta
        $uquery = "DELETE FROM {$wpdb->usermeta} WHERE `meta_key` LIKE %s";
        $wpdb->query($wpdb->prepare($uquery, 'aam%' ));
        
        $uquery = "DELETE FROM {$wpdb->usermeta} WHERE `meta_key` LIKE %s";
        $wpdb->query($wpdb->prepare($uquery, $wpdb->prefix . 'aam%' ));
        
        return json_encode(array('status' => 'success'));
    }
    
    /**
     * 
     * @param type $name
     * @param type $default
     * @return type
     */
    public function getProperty($name, $default = null) {
        static $properties = null;
        
        if (empty($properties)) {
            $properties = AAM_Core_API::getOption('aam-utilities', array());
        }
        
        return (isset($properties[$name]) ? $properties[$name] : $default);
    }
    
    /**
     * Get HTML content
     * 
     * @return string
     * 
     * @access public
     */
    public function getContent() {
        ob_start();
        require_once(dirname(__FILE__) . '/view/utilities.phtml');
        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }

    /**
     * Register Utilities UI feature
     * 
     * @return void
     * 
     * @access public
     */
    public function registerUI() {
        $cap = AAM_Core_ConfigPress::get(self::CAPABILITY_OPTION, 'administrator');
        
        AAM_Backend_Feature::registerFeature((object) array(
            'uid'        => 'utilities',
            'position'   => 998,
            'title'      => __('Utilities', AAM_KEY),
            'capability' => $cap,
            'subjects'   => array(
                'AAM_Core_Subject_Role',
                'AAM_Core_Subject_User',
                'AAM_Core_Subject_Visitor'
            ),
            'view'       => $this
        ));
    }
    
    /**
     * Check if caching is on
     * 
     * @return boolean
     * 
     * @access public
     */
    public function isCacheOn($default = false) {
        return $this->getProperty('caching', $default);
    }
    
    /**
     * Read cache
     * 
     * @param mixed            $response
     * @param string           $option
     * @param AAM_Core_Subject $subject
     * 
     * @return mixed
     * 
     * @access public
     */
    public function readCache($response, $option, AAM_Core_Subject $subject) {
        if ($this->isCacheOn() && !AAM::isAAM()) {
            $group = $subject->getUID() . '-' . $subject->getId();
            if (isset($this->cache[$group][$option])) {
                $response = $this->cache[$group][$option];
            }
        }
        
        return $response;
    }
    
    /**
     * Write cache
     * 
     * @param string           $option
     * @param mixed            $value
     * @param AAM_Core_Subject $subject
     * 
     * @return void
     * 
     * @access public
     */
    public function writeCache($option, $value, AAM_Core_Subject $subject) {
        if ($this->isCacheOn() && !AAM::isAAM()) {
            $group = $subject->getUID() . '-' . $subject->getId();
            $this->cache[$group][$option] = $value;
            $this->save = true;
        }
    }
    
    /**
     * Clear cache
     * 
     * @param AAM_Core_Subject $subject
     * 
     * @return void
     * 
     * @access public
     */
    public function clearCache(AAM_Core_Subject $subject) {
        if ($this->isCacheOn()) {
            $group = $subject->getUID() . '-' . $subject->getId();
            if (isset($this->cache[$group])) {
                unset($this->cache[$group]);
            }
            AAM_Core_API::updateOption('aam-cache', $this->cache);
        }
    }

    /**
     * Bootstrap the extension
     * 
     * @return AAM_PlusPackage
     * 
     * @access public
     */
    public static function bootstrap() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self;
        }

        return self::$_instance;
    }

}