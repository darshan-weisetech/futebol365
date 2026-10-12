(function($) {
    'use strict';

    $.fn.serializeFormJSON = function () {
        var o = {},
            a = this.serializeArray();
        $.each(a, function () {
            if (o[this.name]) {
                if (!o[this.name].push) {
                    o[this.name] = [o[this.name]];
                }
                o[this.name].push(this.value || '');
            } else {
                o[this.name] = this.value || '';
            }
        });
        return o;
    };

    window.temporary_deactivation_flag = false;

    $(document).on('click', '[data-slug="poll-maker"] .deactivate a', function () {
        swal({
            html: "<h2>Do you want to upgrade to Pro version or permanently delete the plugin?</h2><ul><li>Upgrade: Your data will be saved for upgrade.</li><li>Deactivate: Your data will be deleted completely.</li></ul>",
            footer: '<a href="" class="ays-poll-temporary-deactivation">Temporary deactivation</a>',
            type: 'question',
            showCancelButton: true,
            showCloseButton: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            allowEnterKey: false,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Upgrade',
            cancelButtonText: 'Deactivate',
            confirmButtonClass: "ays-poll-upgrade-button",
            cancelButtonClass: "ays-poll-cancel-button",
            customClass: ".ays-poll-deactivate-popup",
        }).then((result) => {
            let upgrade_plugin = false;
            if (result.value) upgrade_plugin = true;

            if( result.dismiss && result.dismiss == 'close' ){
                return false;
            }

            var wp_nonce = $(document).find('#ays_poll_maker_ajax_deactivate_plugin_nonce').val();
            var feedback_container = $(document).find('.ays-poll-dialog-widget');

            let data = {
                action: 'apm_deactivate_plugin_option_pm',
                upgrade_plugin,
                _ajax_nonce: wp_nonce,
            };
            $.ajax({
                url: apm_admin_ajax_obj.ajaxUrl,
                method: "post",
                dataType: 'json',
                data,
                beforeSend: function( xhr ) {
                    if(window.temporary_deactivation_flag === false && feedback_container.length > 0){
                        if(!feedback_container.hasClass('ays-poll-dialog-widget-show')){
                            feedback_container.css('display', 'flex');
                            feedback_container.addClass('ays-poll-dialog-widget-show');
                        }
                    }
                },
                success() {
                    if(window.temporary_deactivation_flag === false && feedback_container.length > 0){
                        if(!feedback_container.hasClass('ays-poll-dialog-widget-show')){
                            feedback_container.css('display', 'flex');
                        }
                    } else {
                        window.location = $(document).find('[data-slug="poll-maker"]').find('.deactivate').find('a').attr('href');
                    }
                },
                error: function(){
                    swal.fire({
                        type: 'info',
                    html: "<h2>"+ apm_admin_ajax_obj.errorMsg +"</h2><p>"+ apm_admin_ajax_obj.somethingWentWrong +"</p>"
                    }).then( function(res) {
                        window.location = $(document).find('[data-slug="poll-maker"]').find('.deactivate').find('a').attr('href');
                    });
                }
            });
        });
        return false;
    });

    $(document).on('click', '.ays-poll-temporary-deactivation', function (e) {
        e.preventDefault();

        window.temporary_deactivation_flag = true;
        $(document).find('.ays-poll-upgrade-button').trigger('click');

    });

    $('.ays-poll-upgrade-plugin-btn').hover(
        function() {
            $(this).css('color', '#3B8D3F');
        },
        function() {
            $(this).css('color', '#01A32A');
        }
    );

    $(document).on('click', '.ays-poll-dialog-button', function (e) {
        e.preventDefault();

        var _this  = $(this);
        var parent = _this.parents('.ays-poll-dialog-widget');
        var form   = parent.find('form');

        var data = form.serializeFormJSON();

        var type = _this.attr('data-type');
        data.type = type;
        data._ajax_nonce = data.ays_poll_deactivate_feedback_nonce;

        $.ajax({
            url: apm_admin_ajax_obj.ajaxUrl,
            method: 'post',
            dataType: 'json',
            data: data,
            success:function () {
                parent.css('display', 'none');
                window.location = $(document).find('[data-slug="poll-maker"]').find('.deactivate').find('a').attr('href');
            },
            error: function(){
                parent.css('display', 'none');
                window.location = $(document).find('[data-slug="poll-maker"]').find('.deactivate').find('a').attr('href');
            }
        });
    });

    // Close Feedback popup clicking outside
    $(document).find('.ays-poll-dialog-widget').on('click', function(e){
        var modalBox = $(e.target).attr('class');
        var feedback_container = $(document).find('.ays-poll-dialog-widget');
        if (typeof modalBox != 'undefined' && modalBox != "" && modalBox.indexOf('ays-poll-dialog-widget-show') != -1) {
            if(feedback_container.hasClass('ays-poll-dialog-widget-show')){
                feedback_container.removeClass('ays-poll-dialog-widget-show');
            }
            feedback_container.css('display', 'none');
            window.temporary_deactivation_flag = false;
        }
    });

})(jQuery);