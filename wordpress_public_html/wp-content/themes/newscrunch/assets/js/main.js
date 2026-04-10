(function($){
  
    $(document).ready(function() {
    
        // Fullwidth Serach Box
        $(function(){
            var $searchlink = $('#search_toggle_fullwidth');
            var $searchbar_fullwidth  = $('#searchbar_fullwidth');
          
            $('#search_toggle_fullwidth').on('click', function(e){
              e.preventDefault();
                if($(this).attr('id') == 'search_toggle_fullwidth') {
                    if(!$searchbar_fullwidth.is(":visible")) { 
                        // if invisible we switch the icon to appear collapsable
                        $searchlink.removeClass('fa-search').addClass('fa-search-minus');
                    } else {
                        // if visible we switch the icon to appear as a toggle
                        $searchlink.removeClass('fa-search-minus').addClass('fa-search');
                    }
                    $searchbar_fullwidth.slideToggle(300, function(){
                    // callback after search bar animation
                    });
                }
            });
          
          $('#searchform_fullwidth').submit(function(e){
              e.preventDefault(); // stop form submission
          });
        });


        // Fullscreen Serach Box 
        $(function() {      
            $('a[href="#searchbar_fullscreen"]').on("click", function(event) {    
                event.preventDefault();
                $("#searchbar_fullscreen").addClass("open");
                $('#searchbar_fullscreen > form > input[type="search"]').focus();
            });

            $("#searchbar_fullscreen, #searchbar_fullscreen button.close").on("click", function(event) {
                if (
                  event.target == this ||
                  event.target.className == "close" ||
                  event.keyCode == 27
                ) {
                    $(this).removeClass("open");
                }
            });
 
        });

    });
})(jQuery);