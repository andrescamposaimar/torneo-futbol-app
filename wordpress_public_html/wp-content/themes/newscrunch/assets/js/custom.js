(function($){
    
    $(document).ready(function() {

        
       /* ---------------------------------------------- /*
         * Home section height
         /* ---------------------------------------------- */

        function buildHomeSection(homeSection) {
            if (homeSection.length > 0) {
                if (homeSection.hasClass('home-full-height')) {
                    homeSection.height($(window).height());
                } else {
                    homeSection.height($(window).height() * 0.85);
                }
            }
        }
        

        /* ---------------------------------------------- /*
         * News highlight section 
        /* ---------------------------------------------- */ 
          $('#spnc-marquee-right').on('click', function() {
                 $('.spnc_highlights').addClass('right');
             })
             $('#spnc-marquee-left').on('click', function() {
                 $('.spnc_highlights').removeClass('right');
             })
                    
        /* ---------------------------------------------- /*
         * Scroll top
         /* ---------------------------------------------- */

        $(window).scroll(function() {
            if ($(this).scrollTop() > 100) {
                $('.scroll-up').fadeIn();
            } else {
                $('.scroll-up').fadeOut();
            }
        });

        $('a[href="#totop"]').click(function() {
            $('html, body').animate({ scrollTop: 0 }, 'slow');
            return false;
        });
    
        // Accodian Js
        function toggleIcon(e) {
            $(e.target)
            .prev('.panel-heading')
            .find(".more-less")
            .toggleClass('fa-plus-square-o fa-minus-square-o');
        }
        $('.panel-group').on('hidden.bs.collapse', toggleIcon);
        $('.panel-group').on('shown.bs.collapse', toggleIcon);

        // top date time
        var timeElement = $( ".newscrunch-topbar-time" )
        if( timeElement.length > 0 ) {
            setInterval(function() {
                timeElement.html(new Date().toLocaleTimeString())
            },1000);
        }

        /* ---------------------------------------------- /*
         * Sticky Header
         /* ---------------------------------------------- */
        jQuery(window).bind('scroll', function () {
            if ( jQuery(window).scrollTop() > 100) {
                jQuery('.header-sticky').addClass('stickymenu');
                jQuery('.wow-callback').addClass('wow-sticky');
                jQuery('body').addClass('spnc-ad-sticky');
            } else {
                jQuery('.header-sticky').removeClass('stickymenu');
                jQuery('.wow-callback').removeClass('wow-sticky');
                jQuery('body').removeClass('spnc-ad-sticky');
            }
        });

        /* ---------------------------------------------- /*
         * Banner carousel 
        /* ---------------------------------------------- */  
        $("#spnc-banner-carousel-1").owlCarousel({
            navigation : true, // Show next and prev buttons        
            autoplay: true,
            autoplayTimeout: 3000,
            autoplayHoverPause: true,
            smartSpeed: 2300,
            loop:true, // loop is true up to 1199px screen.
            nav:true, // is true across all sizes
            margin:10, // margin 10px till 960 breakpoint
            autoHeight: true,
            responsiveClass:true, // Optional helper class. Add 'owl-reponsive-' + 'breakpoint' class to main element.
            //items: 3,
            dots: false,
            navText: ["<i class='fa fa-arrow-left'></i>","<i class='fa fa-arrow-right'></i>"],
            responsive:{    
                200:{ items:1 },
                480:{ items:1 },
                768:{ items:1 },
                1000:{ items:1 }            
            }
        });

        // Video section popup
        $('.popup-youtube1, .popup-youtube2, .popup-youtube3').magnificPopup({
            disableOn: 700,
            type: 'iframe',
            mainClass: 'mfp-fade',
            removalDelay: 160,
            preloader: false,
            fixedContentPos: false
        });


        /* ---------------------------------------------- /*
         * Sticky sidebar
        /* ---------------------------------------------- */    
        var topLimit = ($('#spnc-sidebar-fixed').offset() || { "top": NaN }).top;
        //var bottomLimit=$(".spnc-blog-section").height();
        $(window).scroll(function() {
            if (topLimit <= $(window).scrollTop()){
                $('#spnc-sidebar-fixed').addClass('spnc_sticky')
            } else {
                $('#spnc-sidebar-fixed').removeClass('spnc_sticky')
            }
        });
        $(document).ready(function() {
            $('.spnc-sticky-sidebar, .spnc-sticky-content')
                .theiaStickySidebar({
                    additionalMarginTop: 30
            });
        });

        /* ---------------------------------------------- /*
         *Add animation class in widget
        /* ---------------------------------------------- */ 
        //add css for post title hover effect
        var className = $('#wrapper #page').attr('class');
        if (typeof className !== 'undefined') {
        // Access properties or methods of someVariable here

        className= className.split(' ');
        $(".wp-block-latest-posts__post-title").addClass(className[1]);

        //add css for img hover effect
        const footerClass = className[2].split('-');
        $(".wp-block-latest-posts__featured-image").addClass(footerClass[1]);
        }

    }); 

    /* Preloader */
    jQuery(window).on('load', function() {
        var preloaderFadeOutTime = 500;
        function newscrunch_hidePreloader() {
            var preloader = jQuery('.newscrunch-loader');
            setTimeout(function() {
                preloader.fadeOut(preloaderFadeOutTime);
            }, 500);
        }
        newscrunch_hidePreloader();
    });

})(jQuery); 

/* ---------------------------------------------- /*
         * Sidebar Panel
/* ---------------------------------------------- */
function spncOpenPanel() {
    document.getElementById("spnc_panelSidebar").style.transform = "translate3d(0,0,0)";
    document.getElementById("spnc_panelSidebar").style.visibility = "visible";
    document.getElementById("wrapper").style.marginLeft = "0px";
    document.body.classList.add("spnc_body_sidepanel");
    const panel_elm = document.getElementById('spnc_panelSidebar');
    panel_elm.addEventListener('focusout', (event1) => {
        if(panel_elm.contains(event1.relatedTarget)==false){
            document.getElementById("spnc_panelSidebar").style.transform = "";
            document.getElementById("spnc_panelSidebar").style.visibility = "hidden";
            document.getElementById("wrapper").style.marginLeft = "0px";
            document.body.classList.remove("spnc_body_sidepanel");
        }
    })
}
function spncClosePanel() {
    document.getElementById("spnc_panelSidebar").style.transform = "";
    document.getElementById("spnc_panelSidebar").style.visibility = "hidden";
    document.getElementById("wrapper").style.marginLeft = "0px";
    document.body.classList.remove("spnc_body_sidepanel");
}


jQuery(document).ready(function($) {
     // When the window resizes
    $(window).on('resize', function () {
         // Get the height + padding + border of `#masthead`
         var headerHeight = $('header').outerHeight();
         var incrseHeight=  headerHeight-185;
       //  Add the height to `.page-title-section`
        $('.page-title-section').css('padding-top', incrseHeight+230);
        $('.newscrunch-plus:not(.newsblogger) .page-title-section.breadcrumb-2').css('padding-top', incrseHeight+210);
        $('.newsblogger .page-title-section').css('padding-top', 'unset');
        $('.newscrunch-plus.newsblogger .page-title-section').css('padding-top', incrseHeight+230);
        $('.newscrunch-plus.newsblogger .page-title-section.breadcrumb-2').css('padding-top', incrseHeight+210);
        $('.newscrunch-plus section.bread-nt-2.disable').css('padding-top', incrseHeight+210);
    });
     // Trigger the function on document load.
    $(window).trigger('resize');
});


/* ---------------------------------------------- /*
         * open and close sidemenu in responsive
/* ---------------------------------------------- */
let MenucodeVisible = false;
function openNav() {
    MenucodeVisible= true;
    updateMenuFocusVisibility();
    var element = document.getElementById("spnc-menu-open");
    element.classList.add("open");
    jQuery('body').addClass('off-canvas');
     const panel_elm1 = document.getElementById('spnc-menu-open');
    panel_elm1.addEventListener('focusout', (event) => {
        if(panel_elm1.contains(event.relatedTarget)==false){
            panel_elm1.classList.remove("open");
             jQuery('body').removeClass('off-canvas');
            document.getElementsByClassName("spnc-menu-open")[0].focus();
            MenucodeVisible= false;
            updateMenuFocusVisibility();
        }
    })
}
function closeNav() {
     MenucodeVisible=false;
    updateMenuFocusVisibility();
    var element = document.getElementById("spnc-menu-open");
    element.classList.remove("open");
    jQuery('body').removeClass('off-canvas');
    document.getElementsByClassName("spnc-menu-open")[0].focus();
}
jQuery(".spnc-nav-menu-overlay").on('click', function () {
    var element124 = document.getElementById("spnc-menu-open");
    element124.classList.remove("open");
    jQuery('body').removeClass('off-canvas');
})
function updateMenuFocusVisibility() {
    if (MenucodeVisible) {
        // Show focus
       document.getElementById("spnc-menu-open").style.display = 'block';
    } else {
        // Hide focus
        document.getElementById("spnc-menu-open").style.display = 'none';
    }
}
updateMenuFocusVisibility();
// open and close sidemenu