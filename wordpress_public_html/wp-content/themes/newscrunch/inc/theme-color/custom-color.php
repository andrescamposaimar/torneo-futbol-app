<?php
/**
 * Use Custom color setting in the theme package
 *
 * @package Newscrunch
*/
function newscrunch_custom_color_css() {
$link_color = get_theme_mod('link_color','#669c9b');
list($r, $g, $b) = sscanf($link_color, "#%02x%02x%02x");
?>
<style type="text/css">
/*--------------------------------------------------------------
1.0 Common
--------------------------------------------------------------*/
a:hover , a:focus {color: <?php echo esc_attr($link_color); ?>;}
.entry-meta a:hover span,.entry-meta a:focus span {color: <?php echo esc_attr($link_color); ?>;}
dl dd a, dl dd a:hover, dl dd a:focus, ul li a:focus { color: <?php echo esc_attr($link_color); ?>; }

/*--------------------------------------------------------------
2.0 Forms
--------------------------------------------------------------*/
button,
input[type="button"],
input[type="submit"] {color: #ffffff;border:1px solid <?php echo esc_attr($link_color); ?>;}
button:hover,
button:focus,
input[type="button"]:hover,
input[type="button"]:focus,
input[type="submit"]:hover,
input[type="submit"]:focus {background: <?php echo esc_attr($link_color); ?>;}
/* Placeholder text color */
.form-control:focus { border: thin dotted <?php echo esc_attr($link_color); ?>; }

/*============================3.0 BUTTONS============================*/
.btn-default { background: <?php echo esc_attr($link_color); ?>;border: 1px solid <?php echo esc_attr($link_color); ?>;}
.btn-light:hover, .btn-light:focus { background: <?php echo esc_attr($link_color); ?>;border: 1px solid <?php echo esc_attr($link_color); ?>; }
.btn-default-dark { background: <?php echo esc_attr($link_color); ?>; }
.btn-default-light { background: <?php echo esc_attr($link_color); ?>;border: 1px solid <?php echo esc_attr($link_color); ?>; }
.btn-default-light:hover, .btn-default-light:focus { color: <?php echo esc_attr($link_color); ?>;border: 1px solid <?php echo esc_attr($link_color); ?>; }
.btn-border { border: 2px solid <?php echo esc_attr($link_color); ?>; }
.btn-border:hover, .btn-border:focus { border: 2px solid <?php echo esc_attr($link_color); ?>;}
.btn-light:not(:disabled):not(.disabled):active {
  background-color: <?php echo esc_attr($link_color); ?>;
  border-color: <?php echo esc_attr($link_color); ?>;
}
/*===================================================================================*/
/*  MENUBAR SECTION
/*===================================================================================*/
.spnc-custom .spnc-nav > li > a:focus,
.spnc-custom .spnc-nav > li > a:hover,
.spnc-custom .spnc-nav .open > a,
.spnc-custom .spnc-nav .open > a:focus,
.spnc-custom .spnc-nav .open > a:hover {
  color: <?php echo esc_attr($link_color); ?>;
    background-color: transparent;
}
.spnc-custom .spnc-nav > .active > a,
.spnc-custom .spnc-nav > .active > a:hover,
.spnc-custom .spnc-nav > .active > a:focus {
 color: #ffffff;
  background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-custom .dropdown-menu {
  border-top: 2px solid <?php echo esc_attr($link_color); ?>;
  border-bottom: 2px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-custom .spnc-nav .open .dropdown-menu > .active > a,
.spnc-custom .spnc-nav .open .dropdown-menu > .active > a:hover,
.spnc-custom .spnc-nav .open .dropdown-menu > .active > a:focus {
  background-color: transparent;
  color: <?php echo esc_attr($link_color); ?>;
}
/* spnc Classic */
.spnc-classic .spnc-nav > li > a:hover,
.spnc-classic .spnc-nav > li > a:focus {
  background-color: transparent;
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-classic .spnc-nav > .open > a,
.spnc-classic .spnc-nav > .open > a:hover,
.spnc-classic .spnc-nav > .open > a:focus {
  background-color: transparent;
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-classic .spnc-nav > .active > a,
.spnc-classic .spnc-nav > .active > a:hover,
.spnc-classic .spnc-nav > .active > a:focus {
  background-color: transparent;
  color: <?php echo esc_attr($link_color); ?>;
    border-top: 2px solid <?php echo esc_attr($link_color); ?>;
}
/*Dropdown Menus & Submenus Css----------------------------------------------------------*/
.spnc-custom .dropdown-menu {
  border-top: 2px solid <?php echo esc_attr($link_color); ?>;
  border-bottom: 2px solid <?php echo esc_attr($link_color); ?>;
}
/*===================================================================================*/
/*  CART ICON
/*===================================================================================*/
.cart-header:hover > a { color: <?php echo esc_attr($link_color); ?>; }
.cart-header > a .cart-total { background: <?php echo esc_attr($link_color); ?>; }
/*===================================================================================*/
/*  HEADER CONTACT WIDGET
/*===================================================================================*/
.head-contact-info i{color: <?php echo esc_attr($link_color); ?>;}
.contact-icon i { color: <?php echo esc_attr($link_color); ?>; }
.custom-social-icons li > a:hover,
.custom-social-icons li > a:focus {background-color: <?php echo esc_attr($link_color); ?>;color:#ffffff;border-color:<?php echo esc_attr($link_color); ?> ;}
.layout-2 .custom-social-icons li > a:hover, .layout-2 .custom-social-icons li > a:focus {background-color: #ffffff;color:<?php echo esc_attr($link_color); ?>;}
form.search-form input.search-submit, input[type="submit"], button[type="submit"] {
    background-color: <?php echo esc_attr($link_color); ?>;
  }
 .spnc.spnc-custom .header-button a{ background-color: <?php echo esc_attr($link_color); ?>;border:2px solid <?php echo esc_attr($link_color); ?>;}
 .spnc.spnc-custom .header-button a:hover,.spnc.spnc-custom .header-button a:focus{color:#fff;background-color: #eb6558;border: 2px solid #eb6558; }
 .head-contact-info li a:hover, .head-contact-info li a:focus { color: <?php echo esc_attr($link_color); ?>; }  
 .layout-2 .head-contact-info li a:hover,.layout-2 .head-contact-info li a:focus {
    color: #f5f5f5;
} 
.site-info {
    background-color: #000;
    position: relative
}
.sidebar .widget .wp-block-tag-cloud a:hover,
.sidebar .widget .wp-block-tag-cloud a:focus,
.footer-sidebar .widget .wp-block-tag-cloud a:hover,
.footer-sidebar .widget .wp-block-tag-cloud a:focus {
  color: #ffffff;
  background-color: <?php echo esc_attr($link_color); ?>;
}
.subscribe-form .btn-default:hover,
.subscribe-form .btn-default:focus {
  color: #fff;
  background: <?php echo esc_attr($link_color); ?>;
}
 .site-footer .site-info .footer-nav li a:hover, .site-footer .site-info .footer-nav li a:focus{color: <?php echo esc_attr($link_color); ?>;}
  .entry-meta i {color: <?php echo esc_attr($link_color); ?>;} 
  .post .entry-content .more-link:hover{ border:1px solid <?php echo esc_attr($link_color); ?>;background-color: <?php echo esc_attr($link_color); ?>;color: #ffffff;}
  .page-breadcrumb > li a:hover { color: <?php echo esc_attr($link_color); ?>; }
.pagination a.active {
  position: relative;
  color: <?php echo esc_attr($link_color); ?>;
}
.pagination a:hover,
.pagination a:focus,
.pagination .current,
[data-theme="spnc_dark"] .pagination .current,
.pagination .current:hover,
.pagination .current:focus,
.pagination a.prev:hover,
.pagination a.prev:focus,
.pagination a.next:hover,
.pagination a.next:focus{
  color: #fff;
  background-color: <?php echo esc_attr($link_color); ?>;
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.site-info p.copyright-section a:hover,
.site-info p.copyright-section a:focus {color: <?php echo esc_attr($link_color); ?>;}
.error-page .title {color:<?php echo esc_attr($link_color); ?>;}
.error_404 svg path{fill:<?php echo esc_attr($link_color); ?>; }
.scroll-up a {background-color: <?php echo esc_attr($link_color); ?>;}
blockquote {border-left: 5px solid <?php echo esc_attr($link_color); ?>;}
.owl-carousel .owl-prev:hover, 
.owl-carousel .owl-prev:focus { 
  background-color: <?php echo esc_attr($link_color); ?>;
  color: #fff;
}
.owl-carousel .owl-next:hover, 
.owl-carousel .owl-next:focus { 
  background-color: <?php echo esc_attr($link_color); ?>;
  color: #fff;
}
.header-1 .spnc-custom .spnc-navbar{background-color: <?php echo esc_attr($link_color); ?>;}
.layout-2{background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-custom .spnc-navbar{background-color: #ffffff;}
.spnc-widget-toggle > a.spnc-toggle-icon:hover {
    color: <?php echo esc_attr($link_color); ?>;
}
/*===================================================================================*/
/*  Footer
/*===================================================================================*/
.spnc-footer-social-wrapper { background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-footer-social-links li i{background: <?php echo esc_attr($link_color); ?>;}
.site-footer {border-top: 3px solid <?php echo esc_attr($link_color); ?>;}
.spnc-custom-social-icons li > a:hover {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-marquee-wrapper .spnc-entry-title{ color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-1 .spnc-btn-filter.active,.spnc-blog-1 .spnc-btn-filter:hover,
.widget_spncp_filter_list_view .spnc-btn-filter.active,.widget_spncp_filter_list_view .spnc-btn-filter:hover
{color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-1 .spnc-btn-filter.spnc_active{color: <?php echo esc_attr($link_color); ?>;}   
.spnc-blog-2 .spnc-blog-wrapper .owl-carousel .owl-prev:hover, .spnc-blog-2 .spnc-blog-wrapper .owl-carousel .owl-next:hover {
  background-color: <?php echo esc_attr($link_color); ?>;
  color: #ffffff;
}  
.spnc-blog-1 .spnc-blog-1-wrapper {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-1 .spnc-blog-wrapper-1 .spnc-post .spnc-entry-meta .spnc-date a:hover,
.spnc-blog-1 .spnc-blog-wrapper-1 .spnc-post .spnc-entry-meta .spnc-date a:focus,
.spnc-blog-1 .spnc-blog-wrapper h4.spnc-entry-title a:hover,.spnc-blog-1 .spnc-blog-wrapper h4.spnc-entry-title a:focus,
.spnc-blog-1 .spnc-blog-wrapper .spnc-post .spnc-entry-meta a:hover,.spnc-blog-1 .spnc-blog-wrapper .spnc-post .spnc-entry-meta a:focus,
.spnc-blog-1 .spnc-blog-wrapper-1 h4.spnc-entry-title a:hover,.spnc-blog-1 .spnc-blog-wrapper-1 h4.spnc-entry-title a:focus
{
  color: <?php echo esc_attr($link_color); ?>;
} 
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-entry-meta span.spnc-author a:hover,
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper .spnc-entry-meta span.spnc-author a:focus,
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper h3.entry-title a:hover,
.spnc-entry-meta span a:hover,.spnc-entry-meta span a:focus {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-post .spnc-entry-meta .spnc-date a {color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-1 .spnc-blog-wrapper-2 h4.spnc-entry-title a:hover,
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-entry-meta span.spnc-author a:hover,
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper .spnc-entry-meta span.spnc-author a:focus,
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper h3.entry-title a:hover,
.spnc-blog-1 .spnc.dgn-4 h3.entry-title a:focus,
.spnc-blog-1 .spnc.dgn-4 .spnc-entry-meta a:hover,
.spnc-blog-1 .spnc.dgn-4 .spnc-entry-meta a:focus {
  color: <?php echo esc_attr($link_color); ?>
}
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-blog-1-wrapper {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-entry-meta .spnc-date a:hover,
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper .spnc-entry-meta .spnc-date a:focus {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-1 .spnc-blog-wrapper-3 h4.spnc-entry-title a:hover,
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-entry-meta span.spnc-author a:hover,
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-blog-1-wrapper .spnc-entry-meta span.spnc-author a:focus,
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper h3.entry-title a:hover
 {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-post .spnc-more-link {color: <?php echo esc_attr($link_color); ?>; }
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-entry-meta .spnc-date a:hover,
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-blog-1-wrapper .spnc-entry-meta .spnc-date a:focus {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-2 .spnc-blog-1-wrapper{background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-2 .spnc-blog-wrapper h4.spnc-entry-title a:hover {color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-post .spnc-entry-meta a:hover,
.spnc-blog-2 .spnc-blog-wrapper .spnc-post .spnc-entry-meta a:hover,
.spnc-blog-2 .spnc-blog-wrapper .spnc-entry-meta span.spnc-author a:hover,.spnc-blog-wrapper .spnc-blog-1-wrapper .spnc-entry-meta span.spnc-author a:focus,.spnc-blog-wrapper .spnc-blog-1-wrapper h3.entry-title a:hover,.spnc.dgn-4 h3.entry-title a:focus,.spnc.dgn-4 .spnc-entry-meta a:hover,.spnc.dgn-4 .spnc-entry-meta a:focus {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-2 .spnc-blog-wrapper .spnc-post .spnc-more-link {color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-1 .spnc-blog-wrapper .spnc-blog-1-wrapper .spnc-author img,
.spnc-blog-1 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper .spnc-author img,
.spnc-blog-1 .spnc-blog-wrapper-3 .spnc-blog-1-wrapper .spnc-author img,
.spnc-blog-2 .spnc-blog-wrapper .spnc-entry-meta img,
.spnc-blog-2 .spnc-blog-wrapper .spnc-blog-1-wrapper .spnc-author img,
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-blog-1-wrapper .spnc-author img {
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-2 .spnc-blog-wrapper-1 .spnc-post .spnc-entry-meta .spnc-date a:hover{color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-2 .spnc-blog-wrapper-2 .spnc-post .spnc-entry-meta a:hover {color: <?php echo esc_attr($link_color); ?>;}
.spnc-label-tab.active{background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-sidebar .spnc-panel ul li a:hover{color: <?php echo esc_attr($link_color); ?>;}
.widget-recommended-post ul li a:hover{color: <?php echo esc_attr($link_color); ?>;}
.widget-sports-update .spnc-post-content a:hover{color: <?php echo esc_attr($link_color); ?>;}
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-author a:hover,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-author a:focus,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-tag-links a:hover,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-tag-links a:focus,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-date a:hover,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-date a:focus,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-comment-links a:hover,
.spnc-single-post .spnc-blog-wrapper .spnc-entry-meta span.spnc-comment-links a:focus,
.spnc-single-post .spnc-blog-wrapper  h4.spnc-entry-title a:hover
{
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-post-footer-content .spnc-entry-meta a:hover,
.spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-post-footer-content .spnc-entry-meta a:focus{
  color: #ffffff;
  background: <?php echo esc_attr($link_color); ?>;
}
.spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-post-footer-content .spnc-footer-social-links .spnc-custom-social-icons li i:hover,
.spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-post-footer-content .spnc-footer-social-links .spnc-custom-social-icons li i:focus{
  background: <?php echo esc_attr($link_color); ?>;
  color: #ffffff;
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-single-post .comment-form form input[type="text"]:hover,
.spnc-single-post .comment-form form input[type="text"]:focus,
.spnc-single-post .comment-form form input[type="email"]:hover,
.spnc-single-post .comment-form form input[type="email"]:focus,
.spnc-single-post .comment-form form textarea:hover,
.spnc-single-post .comment-form form textarea:focus{
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-single-post .comment-form form input[type="submit"]{
  background: <?php echo esc_attr($link_color); ?>;
}
.spnc-single-post .comment-form form input[type="submit"]:hover,
.spnc-single-post .comment-form form input[type="submit"]:focus{
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-single-post .pagination .spnc-post-content a{color: <?php echo esc_attr($link_color); ?>;}
.spnc-single-post .pagination .spnc-post-content h4.spnc-entry-title a:hover{color: <?php echo esc_attr($link_color); ?>;}
.page-section-space .spnc_error_404 .spnc_error_heading h2 {color: <?php echo esc_attr($link_color); ?>;}
.page-section-space .spnc_error_404 .spnc_404_link a:hover {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-contact-1 .spnc-blog-wrapper .spnc-blog-1-wrapper {background-color: <?php echo esc_attr($link_color); ?>;}
.page-section-space .spnc-col-9 .spnc_con_info .spnc_con_left .spnc_con_icon:hover {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-contact-1 .spnc-contact-col form input[type="text"]:hover,
.spnc-contact-1 .spnc-contact-col form input[type="text"]:focus,
.spnc-contact-1 .spnc-contact-col form input[type="email"]:hover,
.spnc-contact-1 .spnc-contact-col form input[type="email"]:focus,
.spnc-contact-1 .spnc-contact-col form input[type=tel]:hover,
.spnc-contact-1 .spnc-contact-col form input[type=tel]:focus,
.spnc-contact-1 .spnc-contact-col form textarea:hover,
.spnc-contact-1 .spnc-contact-col form textarea:focus{
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-contact-1 .spnc-contact-col form input[type="submit"]{
  background: <?php echo esc_attr($link_color); ?>;
}
.spnc-contact-1 .spnc-contact-col form input[type="submit"]:hover,
.spnc-contact-1 .spnc-contact-col form input[type="submit"]:focus{
    border: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-video .spnc-post .spnc-post-content .spnc-info-link {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-video .spnc-blog-1-wrapper{background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-1 .spnc-blog-wrapper .spnc-post .spnc-more-link {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-bnr-1 .spnc-post .spnc-post-content .spnc-info-link { background-color: <?php echo esc_attr($link_color); ?>; }
.spnc-category-page .spnc-blog-cat-wrapper .spnc-first-catpost .spnc-more-link{ background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-pagination a:hover,
.spnc-pagination a.active,
.spnc-navigation.spnc-pagination .spnc-nav-links .spnc-current {
    background-color: <?php echo esc_attr($link_color); ?>;
    background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-bnr-1 .spnc-post .spnc-more-link:hover {
  background-color: <?php echo esc_attr($link_color); ?>;
  color: #fff;
}
.spnc-bnr-1 h3.spnc-entry-title a:hover {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-bnr-1 .spnc-entry-meta a:hover {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-wrapper-1 h4.spnc-entry-title a:hover{
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-2 .spnc-blog-wrapper-1 h4.spnc-entry-title a:hover{
  color: <?php echo esc_attr($link_color); ?>;
}
.widget.widget_archive ul li:before,
.widget.widget_block  ul.wp-block-page-list li:before,
.widget.widget_rss ul li::before,.widget_categories .wp-block-categories-list .cat-item:before,
.spnc_widget_recent_entries .wp-block-latest-posts__list li:before{
    color: <?php echo esc_attr($link_color); ?>;
}
.custom-logo-link-url .site-title a:hover, .custom-logo-link-url .site-title a:focus { color: <?php echo esc_attr($link_color); ?>; }
.spnc-single-post .comment-form .spnc-blog-1-wrapper { background-color: <?php echo esc_attr($link_color); ?>; }
 /* Preloader */
.spnc_preloader_text {
  color: <?php echo esc_attr($link_color); ?>
}
.spnc_bounceball:before {
   background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-label-tab.active {
    background-color: <?php echo esc_attr($link_color); ?>;
}
.widget_newscrunch_grid_slider_three_column .spnc-post-grid-slider-three-column .spnc-entry-meta .spnc-date a,
.widget_newscrunch_grid_slider_three_column .spnc-post-grid-slider-three-column .spnc-entry-meta .spnc-date a:hover,
.widget_spncp_filter_three_column .spnc-entry-meta .spnc-date a,
.widget_spncp_filter_three_column .spnc-entry-meta .spnc-date a:hover{
    color: <?php echo esc_attr($link_color); ?>;
}
.widget_newscrunch_grid_slider_three_column .spnc-post-grid-slider-three-column .spnc-entry-meta a:hover
{
  color: <?php echo esc_attr($link_color); ?>;
}
.widget_newscrunch_featured_post .spnc-post .spnc-more-link:hover{color: <?php echo esc_attr($link_color); ?>; text-decoration: underline;}
.widget_newscrunch_list_view_slider .spnc-blog-wrapper .spnc-entry-meta img{border: 1px solid <?php echo esc_attr($link_color); ?>;}
.widget_newscrunch_featured_post h4.spnc-entry-title a:hover,
.widget_newscrunch_featured_post .spnc-first-post h4.spnc-entry-title a:hover,
.widget_newscrunch_featured_post .spnc-first-post .spnc-post .spnc-post-content span a:hover,
.widget_newscrunch_featured_post .spnc-post .spnc-entry-meta a:hover,
.widget_newscrunch_post_grid_two_col .widget-recommended-post li a:hover,
.widget_newscrunch_single_column .single-column .spnc-post-content a:hover,
.site-footer .footer-sidebar .widget_newscrunch_grid_slider_two_column .spnc-filter .spnc-post .spnc-entry-meta .spnc-date a:hover,
.site-footer .footer-sidebar .widget_newscrunch_grid_slider_two_column .spnc-filter h4.spnc-entry-title a:hover,
.site-footer .footer-sidebar .widget_newscrunch_grid_slider_two_column .spnc-filter .spnc-post .spnc-entry-meta a:hover,
.site-footer .footer-sidebar .widget_newscrunch_featured_post h4.spnc-entry-title a:hover,
.site-footer .footer-sidebar .spnc-blog-wrapper-1 h4.spnc-entry-title a:hover,
.site-footer .footer-sidebar .spnc-blog-wrapper-1 .spnc-post .spnc-entry-meta .spnc-date a:hover,
.site-footer .footer-sidebar .widget_newscrunch_list_view_slider .spnc-blog-wrapper h4.spnc-entry-title a:hover,
.site-footer .footer-sidebar .widget_newscrunch_list_view_slider .spnc-blog-wrapper .spnc-post .spnc-entry-meta a:hover,
.widget_spncp_filter_list_view .spnc-wrapper-4-item .spnc-post .spnc-post-content .entry-header .spnc-entry-title a:hover,
body .widget_newscrunch_overlay_slider .spnc-blog1-carousel .spnc-post .spnc-entry-meta a:hover,
.widget_newscrunch_plus_list_view .newscrunch_plus_list_view_ul .spnc-post-content a:hover,
.site-footer .footer-sidebar .widget_newscrunch_post_grid_two_col .widget-recommended-post li a:hover{color: <?php echo esc_attr($link_color); ?>;}
.widget_spncp_filter_list_view .spnc-wrapper-4-item .spnc-post .spnc-post-content .spnc-more-link:hover {
    color: #ffffff;
    background-color: #f75454;
}
.spnc-category-page .spnc-grid-catpost .spnc-entry-meta a:hover,
.spnc-category-page .spnc-blog-cat-wrapper .spnc-first-catpost .spnc-entry-meta a:hover,
.spnc-category-page .spnc-blog-cat-wrapper .spnc-first-catpost .spnc-entry-title a:hover{
  color: <?php echo esc_attr($link_color); ?>;
}
body.newscrunch .owl-carousel .owl-nav .owl-prev:hover,
body.newscrunch .owl-carousel .owl-nav .owl-next:hover{
  color: #ffffff;
  background-color: <?php echo esc_attr($link_color); ?>;
  box-shadow: 0 0 1px 1px <?php echo esc_attr($link_color); ?>;
}
.widget .wp-block-latest-posts li a:hover, 
.widget .wp-block-latest-posts li a:focus, 
.widget .wp-block-archives li a:hover, 
.widget .wp-block-archives li a:focus, 
.widget .wp-block-categories li a:hover, 
.widget .wp-block-categories li a:focus, 
.widget .wp-block-page-list li a:hover, 
.widget .wp-block-page-list li a:focus, 
.widget .wp-block-rss li a:hover, 
.widget .wp-block-rss li a:focus, 
.widget_meta ul li a:hover, 
.widget_meta ul li a:focus, 
.widget .wp-block-latest-comments li a:hover
.widget .wp-block-latest-comments li a:focus{
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-category-page .spnc-grid-catpost .spnc-post .spnc-post-wrapper .spnc-entry-meta a:hover {
  color: <?php echo esc_attr($link_color); ?>;
}
.widget .wp-block-latest-posts li::before,
.widget .wp-block-archives li::before,
.widget .wp-block-categories li::before,
.widget .wp-block-page-list li::before,
.widget .wp-block-rss li::before,
.widget_meta ul li::before,
.widget .wp-block-latest-comments li article footer::before,
.widget_nav_menu ul li::before{
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-sidebar .widget .wp-block-tag-cloud a:hover{
  background-color: <?php echo esc_attr($link_color); ?>;
}

 [data-theme="spnc_dark"] body .spnc-custom .spnc-nav .dropdown.open > a.search-icon:hover {
    color: <?php echo esc_attr($link_color); ?>;
}
 [data-theme="spnc_dark"] .spnc-dark-icon:hover, [data-theme="spnc_dark"] .spnc-widget-toggle>a.spnc-toggle-icon:hover {
    color: <?php echo esc_attr($link_color); ?>;
}
input[type="text"]:hover, input[type="text"]:focus, input[type="email"]:hover, input[type="email"]:focus, input[type="url"]:hover, input[type="url"]:focus, input[type="password"]:hover, input[type="password"]:focus, input[type="search"]:hover, input[type="search"]:focus, input[type="number"]:hover, input[type="number"]:focus, input[type="tel"]:hover,input[type="tel"]:focus, input[type="range"]:hover, input[type="range"]:focus, input[type="date"]:hover, input[type="date"]:focus, input[type="month"]:hover, input[type="month"]:focus, input[type="week"]:hover,
input[type="week"]:focus, input[type="time"]:hover, input[type="time"]:focus, input[type="datetime"]:hover, input[type="datetime"]:focus, input[type="datetime-local"]:hover, input[type="datetime-local"]:focus, input[type="color"]:hover,
input[type="color"]:focus, textarea:hover, textarea:focus {
  /* color: <?php echo esc_attr($link_color); ?>; */
  border: 1px solid <?php echo esc_attr($link_color); ?>;
}
select {
  border: 1px solid <?php echo esc_attr($link_color); ?>;
  }
  .spnc-video .spnc-post .spnc-more-link:hover {
  background-color: <?php echo esc_attr($link_color); ?>;
  color: #fff;
}
.spnc-video h3.spnc-entry-title a:hover {
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-video .spnc-entry-meta a:hover {
  color: <?php echo esc_attr($link_color); ?>;
}
.widget_newscrunch_adv .adv-img-content a{
    background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-common-widget-area .spnc-main-wrapper{
    background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-video .spnc_column-2 .spnc_grid_item-1 .spnc-post .spnc-post-content .spnc-cat-links a {
  background:  <?php echo esc_attr($link_color); ?>;
}
.spnc-category-page .spnc-grid-catpost .spnc-entry-meta i{
    color: <?php echo esc_attr($link_color); ?>;
}
.spnc-highlights-1 .spnc-highlights-title{
     background-color:<?php echo esc_attr($link_color); ?>;
}
.spnc-highlights-1 .spnc-container .spnc-row{
  border-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-marquee-wrapper .spnc-entry-title:before{
  color: <?php echo esc_attr($link_color); ?>;
}
[data-theme="spnc_dark"] .spnc_body_sidepanel a:hover{
   color: <?php echo esc_attr($link_color); ?>;
}
[data-theme="spnc_dark"] .widget_nav_menu a:hover {color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .spnc-nav > li.parent-menu a:hover,[data-theme="spnc_dark"] .spnc-custom .spnc-nav .dropdown.open > a:hover,[data-theme="spnc_dark"] .spnc-custom .spnc-nav li > a:hover {
    color: <?php echo esc_attr($link_color); ?>;
}
.footer-sidebar .wp-block-search .wp-block-search__label:after,
.footer-sidebar .widget .wp-block-heading:after {
    background: <?php echo esc_attr($link_color); ?>;
}
[data-theme="spnc_dark"] .spnc-sidebar .widget_categories .wp-block-categories-list .cat-item:before, [data-theme="spnc_dark"] .spnc-sidebar .spnc_widget_recent_entries .wp-block-latest-posts__list li:before{
   color: <?php echo esc_attr($link_color); ?>;
}
[data-theme="spnc_dark"] .spnc-sidebar .widget_categories .wp-block-categories-list .cat-item a:hover, [data-theme="spnc_dark"] .spnc-sidebar .spnc_widget_recent_entries .wp-block-latest-posts__list li a:hover{
    color: <?php echo esc_attr($link_color); ?>;
}
[data-theme="spnc_dark"] .spnc-custom .spnc-nav > .active > a:hover, [data-theme="spnc_dark"] .spnc-custom .spnc-nav > .active > a, [data-theme="spnc_dark"] .spnc-custom .spnc-nav > .active > a:hover, [data-theme="spnc_dark"] .spnc-custom .spnc-nav > .active > a:focus{
   background-color: <?php echo esc_attr($link_color); ?>;
}
[data-theme="spnc_dark"] .spnc-custom .spnc-nav > li > a:focus, [data-theme="spnc_dark"] .spnc-custom .spnc-nav > li > a:hover, [data-theme="spnc_dark"] .spnc-custom .spnc-nav .open > a, [data-theme="spnc_dark"] .spnc-custom .spnc-nav .open > a:focus, [data-theme="spnc_dark"] .spnc-custom .spnc-nav .open > a:hover{
  color: <?php echo esc_attr($link_color); ?>;
}
body .comment-form .spnc-blog-1-wrapper {background-color: <?php echo esc_attr($link_color); ?>;}
@media (max-width: 1100px) {
  .spnc-custom .spnc-widget-toggle > a.spnc-toggle-icon:focus, .spnc-custom .spnc-nav a:focus {outline-color:#fff !important;}
}
.footer-sidebar pre.wp-block-code a:hover{
  color: <?php echo esc_attr($link_color); ?>;
}
@media (max-width: 1100px){
    body .spnc-custom .spnc-nav > .active.menu-item > a, 
    body .spnc-custom .spnc-nav > .active > a:hover, 
    body .spnc-custom .spnc-nav > .active > a:focus {color: #ffffff;background-color: <?php echo esc_attr($link_color); ?>;}
}
body.newscrunch .spnc-custom .spnc-nav li > a.search-icon:hover{
   color: <?php echo esc_attr($link_color); ?>;
}
body .footer-sidebar .widget .widget-title:after{background: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .site-footer .widget .wp-block-latest-posts li a:hover, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-latest-posts li a:focus, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-archives li a:hover, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-archives li a:focus, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-categories li a:hover, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-categories li a:focus, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-page-list li a:hover, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-page-list li a:focus, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-rss li a:hover, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-rss li a:focus, 
[data-theme="spnc_dark"] .site-footer .widget_meta ul li a:hover, 
[data-theme="spnc_dark"] .site-footer .widget_meta ul li a:focus, 
[data-theme="spnc_dark"] .site-footer .widget .wp-block-latest-comments li a:hover
[data-theme="spnc_dark"] .site-footer .widget .wp-block-latest-comments li a:focus{
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-blog-page .spnc-entry-meta i, .spnc-blog-page .spnc-footer-meta .spnc-date a:hover{
     color: <?php echo esc_attr($link_color); ?>;
}
.header-2 .owl-carousel .owl-prev:hover,.header-2 .owl-carousel .owl-prev:focus,
.header-2 .owl-carousel .owl-next:hover,.header-2 .owl-carousel .owl-next:focus {
  background-color: transparent;
  color: <?php echo esc_attr($link_color); ?>;
}  
body .header-2 .spnc-custom .spnc-nav > .active > a, body .header-2 .spnc-custom .spnc-nav > .active > a:hover, body .header-2 .spnc-custom .spnc-nav > .active > a:focus {
  color: <?php echo esc_attr($link_color); ?>;
  background-color: transparent;
}
.header-2 .spnc-custom .spnc-nav li.active > a:after,.header-2 .spnc-custom .spnc-nav li a:hover:after,
.header-2 .spnc-custom .spnc-nav li.active > a:before,.header-2 .spnc-custom .spnc-nav li a:hover:before
{background-color: <?php echo esc_attr($link_color); ?>;}
#spnc-marquee-right:hover, #spnc-marquee-left:hover{background-color:<?php echo esc_attr($link_color); ?>;box-shadow: 0 0 1px 1px <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-common-widget-area .spnc-main-wrapper,
.spnc-wrapper.spnc-btn-1 .spnc-video .spnc-blog-1-wrapper{
  border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-wrapper.spnc-btn-1 .spnc-common-widget-area .spnc-main-wrapper .spnc-main-wrapper-heading,
 .spnc-wrapper.spnc-btn-1 .spnc-video .spnc-blog-1-wrapper .spnc-blog-1-heading
 {
   background-color: <?php echo esc_attr($link_color); ?>;
 }
 .spnc-wrapper.spnc-btn-1 .spnc-widget-heading{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
 .spnc-wrapper.spnc-btn-1 .spnc-sidebar .widget.side-bar-widget .spnc-widget-title, 
.spnc-wrapper.spnc-btn-1  #spnc_panelSidebar .spnc-sidebar .widget.spnc-side-panel .widget-title{
  background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-wrapper.spnc-btn-1 .spnc-sidebar .wp-block-search .wp-block-search__label,
.spnc-wrapper.spnc-btn-1 .spnc-sidebar .widget.widget_block :is(h1,h2,h3,h4,h5,h6),
.spnc-wrapper.spnc-btn-1 .spnc-page-section-space .widget .wp-block-heading
{
  background-color: <?php echo esc_attr($link_color); ?>;
}
.spnc-wrapper.spnc-btn-1 .spnc-sidebar .wp-block-search .wp-block-search__label::after{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-tabs{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-sidebar .widget.widget_block :is(h1,h2,h3,h4,h5,h6)::after,
.spnc-wrapper.spnc-btn-1 .spnc-page-section-space .widget .wp-block-heading::after
{
  border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;
}
.spnc-wrapper.spnc-btn-1 .spncp-missed-section .spncp-blog-1-wrapper{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spncp-missed-section .spncp-blog-1-wrapper .spncp-blog-1-heading{background-color: <?php echo esc_attr($link_color); ?>;}
#spnc-marquee-right:hover, #spnc-marquee-left:hover{background-color:<?php echo esc_attr($link_color); ?>;box-shadow: 0 0 1px 1px <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-head-wrap-line{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.header-2 .spnc-topbar {background-color: <?php echo esc_attr($link_color); ?>;}
.widget_spncp_filter_two_column .spnc-filter .spnc-entry-meta i{color:<?php echo esc_attr($link_color); ?> ;}
.widget_newscrunch_list_view_slider .spnc-blog-wrapper .spnc-entry-meta::before {background: <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-contact-1 .spnc-blog-wrapper {border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-contact-1 .spnc-blog-wrapper .spnc-blog-1-wrapper .spnc-blog-1-heading{background-color: <?php echo esc_attr($link_color); ?>;}
.header-2 .spnc-widget-toggle > a.spnc-toggle-icon {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .comment-form .spnc-blog-1-wrapper{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .comment-form .spnc-blog-1-heading{background-color: <?php echo esc_attr($link_color); ?>;}
body .site-footer .widget_newscrunch_plus_list_view .newscrunch_plus_list_view_ul .spnc-post-content a:hover{color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] body .spnc-wrapper a.wp-block-latest-comments__comment-author:hover,
[data-theme="spnc_dark"] body .spnc-wrapper a.wp-block-latest-comments__comment-link:hover{color: <?php echo esc_attr($link_color); ?>;}
.spnc-video .spnc-video-popup a.spncOpenVideo:hover {color:<?php echo esc_attr($link_color); ?>;border-color:<?php echo esc_attr($link_color); ?>;}
.spnc-sidebar .widget ul li::before,.spnc-sidebar .widget ol li::before{color: <?php echo esc_attr($link_color); ?>;}
.widget_newscrunch_adv .adv-img-content a {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-bnr-1 .spnc_column-1 .spnc_grid_item-1 .spnc-post .spnc-post-content .spnc-cat-links a {background: <?php echo esc_attr($link_color); ?>;}
.spnc-video .spnc_column-2 .spnc_grid_item-1 .spnc-post .spnc-post-content .spnc-cat-links a {background: <?php echo esc_attr($link_color); ?>;}
.comment-form .spnc-blog-1-wrapper {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-single-post .spnc-pagination-single .spnc-post-content a{color: <?php echo esc_attr($link_color); ?>;}
.spnc-single-post .spnc-pagination-single .spnc-post-content h4.spnc-entry-title a:hover{color: <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-related-posts .spnc-main-wrapper { border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-related-posts .spnc-main-wrapper .spnc-main-wrapper-heading,
.spnc-wrapper.spnc-btn-2 .spnc-related-posts .spnc-main-wrapper { background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-related-posts .spnc-related-post-wrapper .spnc-post .spnc-post-content .spnc-entry-meta span a:is(:hover, :focus){
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-related-posts .spnc-related-post-wrapper .spnc-post .spnc-post-content .spnc-entry-meta span i{color: <?php echo esc_attr($link_color); ?>;}
.spnc-related-posts .spnc-related-post-wrapper .spnc-post .spnc-entry-title a:is(:hover, :focus){
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-related-posts .spnc-related-post-wrapper .spnc-post .spnc-entry-meta i{
  color: <?php echo esc_attr($link_color); ?>;
}

@media (max-width: 1100px){
    .header-2 .spnc-toggle {background-color: <?php echo esc_attr($link_color); ?>;}
    .header-sidebar  .spnc-custom .dropdown-menu > .active > a,
    [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar .spnc-custom .spnc-collapse .spnc-nav .dropdown-menu > .active > a{color: <?php echo esc_attr($link_color); ?>;}
   [data-theme="spnc_dark"] body.newscrunch #wrapper .header-sidebar.header-2 .spnc-custom .spnc-collapse .spnc-nav li.active > a{color: <?php echo esc_attr($link_color); ?>;background-color: transparent;}
}
[data-theme="spnc_dark"] .spnc-nav > li.parent-menu .dropdown-menu .open > a{color: <?php echo esc_attr($link_color); ?>;}
.spnc-common-widget-area.widget_newscrunch_post_tabs .spnc-panel ul li a:hover{color: <?php echo esc_attr($link_color); ?>;}
.a_effect2 .wp-block-latest-posts li a:before{color: <?php echo esc_attr($link_color); ?>;}
.spncmc-1 h4.spnc-entry-title a:hover{color: <?php echo esc_attr($link_color); ?>;}
.spncmc-1 .spnc-entry-meta .spnc-date a{color: <?php echo esc_attr($link_color); ?>;}
.spncmc-1 .owl-carousel .owl-dots .owl-dot.active span {background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-blog-page .spnc-entry-content .spnc-more-link:hover {color: #fff;background-color: <?php echo esc_attr($link_color); ?>;border:1px solid <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .spnc-missed-section .spnc-entry-meta span.spnc-author a:hover{color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .spnc-category-page .spnc-grid-catpost .spnc-entry-meta a:hover, 
[data-theme="spnc_dark"] .spnc-category-page .spnc-grid-catpost .spnc-footer-meta .spnc-date a:hover{color:<?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] body .spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-entry-meta .spnc-tag-links a:not(.spnc-post-footer-content .spnc-tag-links a):hover,
[data-theme="spnc_dark"] body .spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-entry-meta .spnc-author a:hover,
[data-theme="spnc_dark"] .spnc-single-post .spnc-blog-wrapper .spnc-post .spnc-entry-meta .single.spnc-date a:hover,
[data-theme="spnc_dark"] .spnc-single-post .spnc-blog-wrapper .spnc-related-posts .spnc-post .spnc-entry-meta .spnc-date a:not(.spnc-entry-meta .spnc-cat-links a):hover,
[data-theme="spnc_dark"] .spnc-single-post .spnc-blog-wrapper .spnc-related-posts .spnc-post .spnc-entry-meta a:not(.spnc-entry-meta .spnc-cat-links a):hover,
[data-theme="spnc_dark"] .spnc-blog-page .spnc-entry-meta a:hover{color: <?php echo esc_attr($link_color); ?>;}
body.newscrunch .spnc-custom .spnc-dark-icon:hover,
[data-theme="spnc_dark"] body th a:hover,
body #wrapper .widget.f-w-c .spnc-entry-title a:not(.spnc-first-post .spnc-entry-title a):hover,
body #wrapper .widget.f-w-c.widget_newscrunch_single_column .spnc-post-content a:hover,
body #wrapper .widget.f-w-c.widget_recent_comments a.wp-block-latest-comments__comment-link:hover,
body #wrapper .widget.f-w-c.widget_recent_comments a.wp-block-latest-comments__comment-author:hover,
[data-theme="spnc_light"] body.newscrunch .header-2 .spnc-custom .spnc-nav li > a.search-icon:hover,
[data-theme="spnc_dark"] body.newscrunch .spnc-custom .spnc-nav li > a.search-icon:hover,
body #wrapper .widget.f-w-c.widget_nav_menu .menu-primary-menu-container a:hover{color: <?php echo esc_attr($link_color); ?>;}
body .spnc-category-page .spnc-grid-catpost .spnc-footer-meta .spnc-more-link:is(:hover, :focus), body .spnc-post-list-view-section .spnc-post .spnc-post-content .spnc-more-link:is(:hover, :focus) {
    background-color: <?php echo esc_attr($link_color); ?>;
    color: #fff;
    border: 1px solid <?php echo esc_attr($link_color); ?>;
}
body .spnc-post-list-view-section .spnc-post .spnc-post-content .spnc-entry-content .spnc-entry-meta a:is(:hover, :focus), body .spnc-post-list-view-section .spnc-post .spnc-post-content .spnc-entry-title a:is(:hover, :focus), body .spnc-post-list-view-section .spnc-post .spnc-post-content .spnc-entry-meta i, body .spnc-cat-first-post .spnc-post .spnc-post-content .spnc-entry-title a:is(:hover, :focus), body .spnc-cat-first-post .spnc-post .spnc-post-content .spnc-footer-meta .spnc-entry-meta span a:is(:hover, :focus) {
    color: <?php echo esc_attr($link_color); ?>;
}
body .spnc-post-list-view-section .spnc-post .spnc-post-content .spnc-entry-content figure {
    border: 1px solid <?php echo esc_attr($link_color); ?>;
}
body .spnc-post-list-view-section .spnc-post .spnc-post-overlay .spnc-date a, body .spnc-cat-first-post .spnc-post .spnc-post-content .spnc-more-link { background-color: <?php echo esc_attr($link_color); ?>; }
body .spnc-edit-link a {
    color: <?php echo esc_attr($link_color); ?>;
}
.spnc-author-box .spnc-author a:is(:hover, :focus), [data-theme="spnc_dark"] .spnc-author-box .spnc-author a:is(:hover, :focus){
  color: <?php echo esc_attr($link_color); ?>;
}
.spnc-author-box-two .spnc-author .spnc-author-bio,
.spnc-author-box-one .spnc-author .spnc-author-bio,
.spnc-author-box  .spnc-author-posts-link{
    color: <?php echo esc_attr($link_color); ?>;
}
.spnc-author-box.spnc-author-box-two .spnc-author figure {
   border-color:  <?php echo esc_attr( "rgba(" . $r . ", " . $g . ", " . $b . ", 0.3)" ) ?>; 
}
.sticky-post-icon, .spnc-post.format-quote .spnc-quote-wrap, .spnc-post .spnc-post-btn {
    background-color: <?php echo esc_attr($link_color); ?>;
}
.woocommerce-info {border-top-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-info::before {color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-cart .woocommerce-error::before, .woocommerce-cart .woocommerce-info::before, .woocommerce-cart .woocommerce-message::before{color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-cart .return-to-shop-button{background-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-store-notice, p.demo_store{background-color:<?php echo esc_attr($link_color); ?> }

input[type="text"]:hover,input[type="text"]:focus,input[type="email"]:hover,input[type="email"]:focus,input[type="url"]:hover,input[type="url"]:focus,input[type="password"]:hover,input[type="password"]:focus,input[type="search"]:hover,input[type="search"]:focus,input[type="number"]:hover,input[type="number"]:focus,input[type="tel"]:hover,input[type="tel"]:focus,input[type="range"]:hover,input[type="range"]:focus,input[type="date"]:hover,input[type="date"]:focus,input[type="month"]:hover,input[type="month"]:focus,input[type="week"]:hover,input[type="week"]:focus,input[type="time"]:hover,input[type="time"]:focus,input[type="datetime"]:hover,input[type="datetime"]:focus,input[type="datetime-local"]:hover,input[type="datetime-local"]:focus,input[type="color"]:hover,input[type="color"]:focus,textarea:hover,textarea:focus {border: 1px solid <?php echo esc_attr($link_color); ?>;}
body.woocommerce .spnc-post .spnc-entry-content ul.products li.product a.add_to_cart_button,.woocommerce ul.products li.product .button,.woocommerce ul.products li.product .added_to_cart {background-color: <?php echo esc_attr($link_color); ?>;}
body.woocommerce ul.products li.product .price ins .woocommerce-Price-amount.amount{color: <?php echo esc_attr($link_color); ?>;}
.woocommerce nav.woocommerce-pagination ul li a:focus, .woocommerce nav.woocommerce-pagination ul li a:hover, .woocommerce nav.woocommerce-pagination ul li span.current {
    background-color: <?php echo esc_attr($link_color); ?>;border: 1px solid <?php echo esc_attr($link_color); ?>;}
    .woocommerce-loop-product__title:hover{color: <?php echo esc_attr($link_color); ?>;}
    body .wc-block-components-totals-footer-item-tax-value{color: <?php echo esc_attr($link_color); ?>;}  
    body.woocommerce-cart .wc-block-components-button__text,body.woocommerce-cart  table.wc-block-cart-items .wc-block-cart-item__quantity .wc-block-cart-item__remove-link{
    background-color: <?php echo esc_attr($link_color); ?>;}
    body.woocommerce-cart .wc-block-components-button__text, body.woocommerce-cart table.wc-block-cart-items .wc-block-cart-item__quantity .wc-block-cart-item__remove-link {
    background-color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce.single-product div.product p.price ins .woocommerce-Price-amount.amount, .woocommerce.single-product div.product span.price ins {color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce.woocommerce-wishlist .wishlist_table .product-add-to-cart a.button:hover {background-color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce.single-product  div.product form.cart .button {background-color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce.single-product .product_meta .posted_in a,[data-theme="spnc_dark"] .woocommerce.single-product .product_meta .posted_in a{color: <?php echo esc_attr($link_color); ?>;}
.woocommerce.single-product div.product .woocommerce-tabs ul.tabs li.active {background: <?php echo esc_attr($link_color); ?>;}
.woocommerce.single-product div.product .woocommerce-tabs ul.tabs::before {border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;} 
.woocommerce-cart table.wc-block-cart-items thead {border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.xoo-qv-plink {background-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-checkout .wc-block-components-title.wc-block-components-title,.woocommerce-checkout .wc-block-components-order-summary__button-text{
   background-color: <?php echo esc_attr($link_color); ?>;}
   .spnc-navbar .header-cart .cart-count{ background: <?php echo esc_attr($link_color); ?>;}
   .spnc-custom .cart-dropdown.dropdown-menu  {width: 340px;border-top: 4px solid <?php echo esc_attr($link_color); ?>;}
   .spnc-custom .header-cart .dropdown-menu {
    border-top: 3px solid <?php echo esc_attr($link_color); ?>;}
    body .spnc-custom .spnc-nav .dropdown-menu .woocommerce-mini-cart__buttons a.checkout{background-color: <?php echo esc_attr($link_color); ?>;}
    body .spnc-custom .spnc-nav .dropdown-menu .sale-price{color: <?php echo esc_attr($link_color); ?>;}
    .spnc-custom .cart-dropdown .mini_cart_item a.remove:hover {background-color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce-cart .spnc-entry-content .shop_table tr:first-child:not(.cart_item){border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
    .woocommerce-cart .product-subtotal .woocommerce-Price-amount.amount{color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce-cart .cart-collaterals .cart_totals .checkout-button{background-color: <?php echo esc_attr($link_color); ?>;}
    .woocommerce.woocommerce-page span.onsale,.woocommerce-page .onsale {background-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-page .woocommerce-message {border-top-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-page .woocommerce-message::before {color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-cart .cart-collaterals .add_to_cart_button{background-color: <?php echo esc_attr($link_color); ?>;}

.woocommerce-checkout .wc-block-components-checkout-place-order-button{background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-sidebar .wp-block-woocommerce-featured-product .wp-block-button__link{ background-color: <?php echo esc_attr($link_color); ?>;}
.spnc-sidebar .wc-block-grid__product-add-to-cart.wp-block-button .wp-block-button__link{background-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-cart .woocommerce-info {border-top-color: <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-sidebar .widget.woocommerce :is(h1,h2,h3,h4,h5,h6)::after{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}
.spnc-wrapper.spnc-btn-1 .spnc-sidebar .widget.woocommerce :is(h1,h2,h3,h4,h5,h6){background-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce-checkout .wc-block-checkout__contact-fields .wc-block-components-checkout-step__heading, .woocommerce-checkout .wc-block-components-checkout-step__heading, .woocommerce-checkout .wc-block-components-order-summary .wc-block-components-panel__button{border-bottom: 1px solid <?php echo esc_attr($link_color); ?>;}

.woocommerce .spnc-sidebar  div.product form.cart .button,.woocommerce .spnc-sidebar .woocommerce-checkout-payment .button{background-color:<?php echo esc_attr($link_color); ?>;}
.woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) #respond input#submit.alt:hover, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) a.button.alt:hover, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) button.button.alt:hover, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) input.button.alt:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce #respond input#submit.alt:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce a.button.alt:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce button.button.alt:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce input.button.alt:hover,
.woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) #respond input#submit.alt, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) a.button.alt, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) button.button.alt, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) input.button.alt, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce #respond input#submit.alt, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce a.button.alt, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce button.button.alt, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce input.button.alt,
.woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) #respond input#submit:hover, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) a.button:hover, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) button.button:hover, .woocommerce:where(body:not(.woocommerce-block-theme-has-button-styles)) input.button:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce #respond input#submit:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce a.button:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce button.button:hover, :where(body:not(.woocommerce-block-theme-has-button-styles)):where(:not(.edit-post-visual-editor)) .woocommerce input.button:hover{background-color:<?php echo esc_attr($link_color); ?>;color:#fff;}
.spnc-sidebar .wc-block-featured-category__wrapper .wp-block-button__link:hover {color: #fff;background-color: <?php echo esc_attr($link_color); ?>;}
.woocommerce .star-rating::before,.woocommerce .star-rating span::before {color: <?php echo esc_attr($link_color); ?>;}
body .wc-block-components-review-list-item__rating>.wc-block-components-review-list-item__rating__stars span:before,
.woocommerce p.stars a{color: <?php echo esc_attr($link_color); ?>;}
body .spnc-sidebar .wp-block-woocommerce-featured-category .wp-block-button__link{color: #fff;background-color: <?php echo esc_attr($link_color); ?>;}
body .product-category .woocommerce-loop-category__title:hover{color:<?php echo esc_attr($link_color); ?>;}

[data-theme="spnc_dark"] .newsblogger .woocommerce-loop-product__title:hover {color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .newsblogger.woocommerce .star-rating::before {color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .newsblogger.woocommerce .star-rating span::before {color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .newsblogger.woocommerce.single-product .product_meta .posted_in a {color: <?php echo esc_attr($link_color); ?>;}
.newsblogger.woocommerce #review_form #respond .form-submit input{background-color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .woocommerce-loop-product__title:hover {color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .woocommerce .star-rating::before {color: <?php echo esc_attr($link_color); ?>;}
[data-theme="spnc_dark"] .woocommerce .star-rating span::before {color: <?php echo esc_attr($link_color); ?>;}
</style>
<?php 
}