<?php
/**
 * Footer Widget Area
 *
 * @package Newscrunch
 */
?>
<div data-wow-delay=".5s" class="wow-callback zoomIn spnc-row footer-sidebar">
	<?php
        $newscrunch_footer_layout = get_theme_mod('footer_widget_layout', 3);
        switch ( $newscrunch_footer_layout )
        {   
            case 2:
                newscrunch_footer_layout('2');
                break;

            case 3:
                newscrunch_footer_layout('3');
                break;

            case 4:
                newscrunch_footer_layout('4');
                break;
        }
    ?>
</div>