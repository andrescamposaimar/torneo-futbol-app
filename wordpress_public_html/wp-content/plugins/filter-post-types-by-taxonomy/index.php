<?php
/*
Plugin Name: Filter Post Types by Taxonomy
Plugin URI: http://wordpress.org/extend/plugins/filter-posts-by-taxonomy
Description: Add the ability to filter post types by a custom selector
Author: Ionuț Staicu
Version: 1.0.0
Author URI: http://iamntz.com
*/

if ( !defined( 'ABSPATH' ) ) exit;

require_once 'inc/FilterEntriesByTaxonomy.php';

new FilterEntriesByTaxonomy;
