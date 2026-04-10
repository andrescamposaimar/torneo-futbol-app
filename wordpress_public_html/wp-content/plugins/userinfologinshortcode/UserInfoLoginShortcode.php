<?php
/*
Plugin Name: UserInfoLoginShortcode
Plugin URI: 
Description: This plugin provides a [user_info_login] shortcode to let you embed a User Info or Login section without farting around with page templates - for cases where you can't use a widget.
Version: 0.2.1
Author: D'Arcy Norman
Author URI: http://www.darcynorman.net
*/
 
/*
== Installation ==
 
1. Download the UserInfoLoginShortcode.zip file to a directory of your choice(preferably the wp-content/plugins folder)
2. Unzip the UserInfoLoginShortcode.zip file into the wordpress plugins directory: 'wp-content/plugins/'
3. Activate the plugin through the 'Plugins' menu in WordPress
*/
 
/*
/--------------------------------------------------------------------\
|                                                                    |
| License: GPL                                                       |
|                                                                    |
| UserInfoLoginShortcode - embed Login form w/o page template edits  |
| Copyright (C) 2009, D'Arcy Norman & The University of Calgary      |
| All rights reserved.                                               |
|                                                                    |
| This program is free software; you can redistribute it and/or      |
| modify it under the terms of the GNU General Public License        |
| as published by the Free Software Foundation; either version 2     |
| of the License, or (at your option) any later version.             |
|                                                                    |
| This program is distributed in the hope that it will be useful,    |
| but WITHOUT ANY WARRANTY; without even the implied warranty of     |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the      |
| GNU General Public License for more details.                       |
|                                                                    |
| You should have received a copy of the GNU General Public License  |
| along with this program; if not, write to the                      |
| Free Software Foundation, Inc.                                     |
| 51 Franklin Street, Fifth Floor                                    |
| Boston, MA  02110-1301, USA                                        |   
|                                                                    |
\--------------------------------------------------------------------/
*/

/**
 * Creation of the UserInfoLoginShortcode
 * This class should host all the functionality that the plugin requires.
 */
/*
 * first get the options necessary to properly display the plugin
 */



if ( !class_exists( "UserInfoLoginShortcode" ) ) {
    
    class UserInfoLoginShortcode {

        /**
         * Shortcode Function
         */
         function shortcode($atts)
         {

      		$out = "";

			$out .= "<!-- user info / login block -->\r";
			$out .= "<div>\r";

			  global $user_ID, $user_identity, $wpdb;
			  get_currentuserinfo();
			  if (!$user_ID):

				  $out .= "	<form name=\"loginform\" action=\"". get_settings('siteurl'). "/wp-login.php\" method=\"post\">";

			      $out .= "<h3>Log in</h3>";

			      $out .= "<label>Username:</label>";
			      $out .= "<p><input name=\"log\" id=\"user_login\" class=\"inbox\" value=\"\"/></p>";

			      $out .= "<label>Password:</label>";
			      $out .= "<p><input name=\"pwd\" id=\"user_pass\" type=\"password\" value=\"\" class=\"inbox\"/></p>";

			      $out .= "<p><input name=\"submit\" type=\"submit\" value=\"Login\" class=\"submit-button\"/>";
			      $out .= "<input type=\"hidden\" name=\"redirect_to\" value=\"". $_SERVER['REQUEST_URI'] . "\"/>";
			      $out .= "</p>";

			      $out .= "<p class=\"chk\"><input name=\"rememberme\" id=\"rememberme\" value=\"forever\" type=\"checkbox\" checked=\"checked\" />&nbsp;remember me</p>";
			      $out .= "<p class=\"chk\"><a href=\"". get_site_url() . "/wp-login.php?action=lostpassword\" title=\"Get a new password sent to you\">Lost your password?</a></p><br />";
				  $out .= "<p class=\"chk\"><a href=\"". get_site_url() . "/wp-signup.php\" title=\"Sign up for a new account\">Create a new account</a></p><br />";
			      $out .= "</form>";

			else:

				$out .= "<div id=\"user-profile\">";
				$out .= "<h3>Your Info.</h3>";
				
				if (is_multisite()) {
					$tmp_blog_id = $wpdb->get_var("SELECT meta_value FROM " . $wpdb->base_prefix . "usermeta WHERE meta_key = 'primary_blog' AND user_id = '" . $user_ID . "'");
					$tmp_blog_domain = $wpdb->get_var("SELECT domain FROM " . $wpdb->base_prefix . "blogs WHERE blog_id = '" . $tmp_blog_id . "'");
					$tmp_blog_path = $wpdb->get_var("SELECT path FROM " . $wpdb->base_prefix . "blogs WHERE blog_id = '" . $tmp_blog_id . "'");

					if ($tmp_blog_domain == ''){
						$tmp_blog_domain = $current_site->domain;
					}

					if ($tmp_blog_path == ''){
						$tmp_blog_path = $current_site->path;
					}

					$tmp_user_url =  'http://' . $tmp_blog_domain . $tmp_blog_path;
				} else {
					$tmp_user_url = get_site_url(); // fallback to hardcoding something for non-WPMU sites.
				}
							
			$out .= "<a href=\"". $tmp_user_url . "\" title=\"Go to your blog homepage\">" . get_avatar($user_ID,'48',get_option('avatar_default')) . "</a>";
			$out .= "&nbsp;<a href=\"". $tmp_user_url . "/wp-admin/\" title=\"Dashboard\"><strong>Your dashboard</a></strong>";

			$out .= "<br />";
			$out .= "&nbsp;<a href=\"". $tmp_user_url . "/wp-admin/post-new.php\" title=\"Posting Area\">Write a post</a>";
			$out .= "<br />";
			$out .= "&nbsp;<a href=\"". $tmp_user_url ."/wp-admin/users.php?page=edit_user_avatar\" title=\"Edit your avatar\">Upload new avatar</a>";
			$out .= "<br /><br />";
			$out .= "Welcome back, ". $user_identity . ", use the links above to get started or you can <a href=\"". get_option('siteurl') . "/wp-login.php?action=logout\">logout</a>.";

			$out .= "<br /><br />";
			$out .= "</div>";

		endif;

		if (is_multisite()) {
			// this only makes sense on a multisite install.
			if (is_user_logged_in()) {
				$out .= "<span id=\"userblog_list\">";
				$out .= "<h3>Access your blogs:</h3>";
				$user_id = get_current_user_id();
				$blogs = get_blogs_of_user($user_id);
				if ( !empty($blogs) ) foreach ( $blogs as $blog ) {
					$out .= "<p><a href=\"http://". $blog->domain . $blog->path . "\">";
					$out .= $blog->domain . $blog->path . "</a> (<a href=\"http://". $blog->domain . $blog->path . "wp-admin/\">admin</a>)</p>";
				}
				$out .= "<p><a href=\"wp-signup.php\">Create a new blog site</a></p>";
				$out .= "<p><a href=\"wp-login.php?action=logout\">Logout</a></p>";
				$out .= "</span>";
			}
			$out .= "</div>";
			$out .= "<!-- end user info / login block -->";
		}

            
		return $out;
		}
	} // End Class UserInfoLoginShortcodePluginSeries
} 


/**
 * Initialize the admin panel function 
 */

if (class_exists("UserInfoLoginShortcode")) {

    $UserInfoLoginShortcodeInstance = new UserInfoLoginShortcode();
}


/**
  * Set Actions, Shortcodes and Filters
  */
// Shortcode events
if (isset($UserInfoLoginShortcodeInstance)) {
    add_shortcode('user_info_login',array(&$UserInfoLoginShortcodeInstance, 'shortcode'));
}
?>
