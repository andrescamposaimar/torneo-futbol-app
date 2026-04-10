=== User Info Login Shortcode ===
Contributors: dnorman
Tags: shortcode, wpmu, login
Requires at least: 2.6
Tested up to: 4.4.2
Stable tag: 0.2.1

This plugin provides a [user_info_login] shortcode to let you embed a User Info or Login section without farting around with page templates or widgets

== Description ==

I needed a way to display login form on the front page of a WPMU network if a person wasn't already logged in, and a bit of info about the user, a list of their sites, a link to create a new one, and a link to logout. I could have done it by hacking the theme's templates, but that locks you into a specific theme. With the shortcode, any theme can display this, on any page (or post, but why would you do that? whatever. it's your site...)

== Installation ==

This section describes how to install the plugin and get it working.

1. Download the userinfologinshortcode.zip file to a directory of your 
choice (preferably the wp-content/plugins folder)

2. Unzip the userinfologinshortcode.zip file into the wordpress 
plugins directory: 'wp-content/plugins/' 

3. Activate the plugin through the 'Plugins' menu in WordPress

4. Include the [user_info_login] shortcode in any page you wish to include the blog_members display. It may make the most sense to do this on the front page of the site.

== Frequently Asked Questions ==

= How do I use the plugin? =

When you write or edit the content of a page, simply include 
[user_info_login] (along with the brackets) whenever you want the info/login to 
be displayed. Make sure you activate the plugin before you use the 
shortcode.

= Why is the Info/Login stuff not displayed, even though I included the shorttag ? =

The plugin probably has not yet been activated.

== Screenshots ==

* User login form, displayed if a person is not logged in yet.
* User info, blogs list, create site, and logout link. Displayed if a person is already logged in.

== Changelog ==
= 0.2.1 =
* fixing minor bug in multisite url generation for login/signup links

= 0.2 =
* cleaned up for non-multisite use (but really, this plugin is kind of useless outside of multisite)
* updated some of the display code so that it actually works now. again.

= 0.1 =
* First draft of the plugin. It's not parameterizable yet.

== Upgrade Notice ==
* nothing to see here