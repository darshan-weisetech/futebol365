// Poll Maker welcome page simple JS
(function($){
    $(function(){
        // Changelog toggle
        var $toggle = $('#wn-toggle');
        var $chg = $('.ays-pm-w-changelog');
        $toggle.on('click', function(){
        if($chg.hasClass('ays-pm-w-collapsed')){
            $chg.removeClass('ays-pm-w-collapsed').addClass('ays-pm-w-expanded');
            $('.ays-pm-w-wn-arrow').html('▴');
            $toggle.text(poll_maker_ays_welcome.show_less).append($('<span/>',{class:'ays-pm-w-wn-arrow',html:'▴'}));
        }else{
            $chg.removeClass('ays-pm-w-expanded').addClass('ays-pm-w-collapsed');
            $('.ays-pm-w-wn-arrow').html('▾');
            $toggle.text(poll_maker_ays_welcome.show_more).append($('<span/>',{class:'ays-pm-w-wn-arrow',html:'▾'}));
        }
        });

        // Video lightbox
        var $lightbox = $('#ays-pm-w-video-lightbox');
        var $iframe = $('#ays-pm-w-video-iframe');

        function closeLightbox() {
        $lightbox.removeClass('ays-pm-w-show');
        setTimeout(function() {
            $iframe.attr('src', '');
        }, 300); // Wait for fade out transition
        }
        
        $('.ays-pm-w-video-card').on('click', function(){
        var videoId = $(this).data('video-id');
        if (videoId) {
            $iframe.attr('src', 'https://www.youtube.com/embed/' + videoId);
            $lightbox.addClass('ays-pm-w-show');
        }
        });

        $('#ays-pm-w-video-lightbox-close').on('click', closeLightbox);

        $lightbox.on('click', function(e){
        if ($(e.target).is($lightbox)) {
            closeLightbox();
        }
        });
    });
})(jQuery);