(function ($) {
    'use strict';
   
    // Quick poll submit function
    $(document).find('#ays-save-quick-poll').on('click', function (e) {
        var $this = $(this);
        var title = $(document).find('#ays-quick-poll-title').val();

        $this.attr('disabled', true);
        $this.addClass('quick-poll-save-disabled');
        if (title == '') {
            swal.fire({
                type: 'error',
                html: "<h2>Poll title can't be empty.</h2>",
                onAfterClose: function() {
                    $this.removeClass('quick-poll-save-disabled');
                    $this.attr('disabled', false);
                }
            });

            return false;
        }

        var questions = $(document).find('#ays-quick-poll-question').val();
        var answersArr = $(document).find('.quick_poll_answer');

        for (var i = 0; i < answersArr.length; i++) {
            if (answersArr.eq(i).val() == '') {
                swal.fire({
                    type: 'error',
                    html: '<h2>You must fill all answers</h2>',
                    onAfterClose: function() {
                        $this.removeClass('quick-poll-save-disabled');
                        $this.attr('disabled', false);
                    }
                });
                return false;
            } else {
                answersArr[i] = answersArr.eq(i).val();
            }
        }

        // var allowAnonimity = $(document).find('#allow_anonimity_switch').is(':checked') ? 1 : 0;
        var allowMultivote = $(document).find('#allow_multivote_switch').is(':checked') ? 'on' : 'off';

        if (allowMultivote == 'on') {
            var multivote_min_count = $(document).find('#quick-poll-multivote-min-count').val();
            var multivote_max_count = $(document).find('#quick-poll-multivote-max-count').val();
        } else {
            var multivote_min_count = 1;
            var multivote_max_count = 1;
        }

        var previewButtonSvgIcon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">'+
            '<path d="M11.9999 7.21325C11.8231 7.21325 11.6535 7.28349 11.5285 7.40851C11.4035 7.53354 11.3333 7.70311 11.3333 7.87992V12.6666C11.3333 12.8434 11.263 13.013 11.138 13.138C11.013 13.263 10.8434 13.3333 10.6666 13.3333H3.33325C3.15644 13.3333 2.98687 13.263 2.86185 13.138C2.73682 13.013 2.66659 12.8434 2.66659 12.6666V5.33325C2.66659 5.15644 2.73682 4.98687 2.86185 4.86185C2.98687 4.73682 3.15644 4.66658 3.33325 4.66658H8.11992C8.29673 4.66658 8.4663 4.59635 8.59132 4.47132C8.71635 4.3463 8.78658 4.17673 8.78658 3.99992C8.78658 3.82311 8.71635 3.65354 8.59132 3.52851C8.4663 3.40349 8.29673 3.33325 8.11992 3.33325H3.33325C2.80282 3.33325 2.29411 3.54397 1.91904 3.91904C1.54397 4.29411 1.33325 4.80282 1.33325 5.33325V12.6666C1.33325 13.197 1.54397 13.7057 1.91904 14.0808C2.29411 14.4559 2.80282 14.6666 3.33325 14.6666H10.6666C11.197 14.6666 11.7057 14.4559 12.0808 14.0808C12.4559 13.7057 12.6666 13.197 12.6666 12.6666V7.87992C12.6666 7.70311 12.5963 7.53354 12.4713 7.40851C12.3463 7.28349 12.1767 7.21325 11.9999 7.21325ZM14.6133 1.74659C14.5456 1.58369 14.4162 1.45424 14.2533 1.38659C14.1731 1.35242 14.087 1.33431 13.9999 1.33325H9.99992C9.82311 1.33325 9.65354 1.40349 9.52851 1.52851C9.40349 1.65354 9.33325 1.82311 9.33325 1.99992C9.33325 2.17673 9.40349 2.3463 9.52851 2.47132C9.65354 2.59635 9.82311 2.66659 9.99992 2.66659H12.3933L5.52659 9.52658C5.4641 9.58856 5.4145 9.66229 5.38066 9.74353C5.34681 9.82477 5.32939 9.91191 5.32939 9.99992C5.32939 10.0879 5.34681 10.1751 5.38066 10.2563C5.4145 10.3375 5.4641 10.4113 5.52659 10.4733C5.58856 10.5357 5.66229 10.5853 5.74353 10.6192C5.82477 10.653 5.91191 10.6705 5.99992 10.6705C6.08793 10.6705 6.17506 10.653 6.2563 10.6192C6.33754 10.5853 6.41128 10.5357 6.47325 10.4733L13.3333 3.60659V5.99992C13.3333 6.17673 13.4035 6.3463 13.5285 6.47132C13.6535 6.59635 13.8231 6.66658 13.9999 6.66658C14.1767 6.66658 14.3463 6.59635 14.4713 6.47132C14.5963 6.3463 14.6666 6.17673 14.6666 5.99992V1.99992C14.6655 1.9128 14.6474 1.82673 14.6133 1.74659Z" fill="#007DCB"/>'+
            '</svg>';

        var wp_nonce = $(document).find('#ays_poll_ajax_quick_poll_nonce').val();

        var showTitle = $(document).find('#quick-poll-show-title').is(':checked') ? 'on' : 'off';

        var quickPollFormData = $('#ays-quick-poll-form').serializeFormJSON();
        quickPollFormData.action = 'ays_poll_maker_quick_start';
        quickPollFormData['quick-poll-show-title'] = showTitle;
        quickPollFormData._ajax_nonce = wp_nonce;

        $.ajax({
            url: poll_maker_ajax.ajax_url,
            method: 'post',
            dataType: 'json',
            data: quickPollFormData,
            success: function (response) {
                $(document).find('div.ays-poll-preloader').css('display', 'none');

                if (response.status === true) {
                    var link = "#";
                    if( typeof response.preview_url != 'undefined' ){
                        link = response.preview_url;
                    }

                    $(document).find('#ays-quick-poll-form')[0].reset();
                    $(document).find('#ays-poll-quick-create .ays-modal-content').addClass('animated bounceOutRight');
                    $(document).find('#ays-poll-quick-create').modal('hide');
                    swal({
                        title: '<strong>Great job</strong>',
                        type: 'success',
                        html: '<p>Your Poll is Created!<br>Copy the generated shortcode and paste it into any post or page to display Poll.</p><input type="text" id="quick_poll_shortcode" onClick="this.setSelectionRange(0, this.value.length)" readonly value="[ays_poll id=\'' + response.poll_id + '\']" /><p style="margin-top:1rem;">For more detailed configuration visit <a href="admin.php?page=poll-maker-ays&action=edit&poll=' + response.poll_id + '">edit poll page.</a></p>',                        
                        showCloseButton: true,
                        showCancelButton: true,
                        focusConfirm: false,
                        cancelButtonClass: "ays-poll-preview-popup-cancel-button",
                        confirmButtonText: '<i class="ays_poll_fas ays_poll_fa_thumbs_up "></i> Done',
                        cancelButtonText: '<a href="'+ link +'" target="_blank">Preview Poll ' + previewButtonSvgIcon +'</a>',
                        confirmButtonAriaLabel: 'Thumbs up, Done',
                        onAfterClose: function() {
                            $(document).find('#ays-poll-quick-create').removeClass('animated bounceOutRight');
                            $(document).find('#ays-poll-quick-create').css('display', 'none');
                            window.location.href = "admin.php?page=poll-maker-ays";
                            location.reload();
                        }
                    })
                }
            }
        })
    
    })

    function updateProgress() {
        var total = $(document).find(".ays-poll-checklist-step").length;
        var done = $(document).find(".ays-poll-checklist-step input:checked").length;
        var percent = Math.round((done / total) * 100);

        $(document).find(".ays-poll-checklist-progress-text").text(percent + "%");
        $(document).find(".ays-poll-checklist-progress-bar span").css("width", percent + "%");
    }

    function saveChecklist() {
        var checked = [];
        $(document).find(".ays-poll-checklist-step input:checked").each(function () {
                checked.push($(this).closest(".ays-poll-checklist-step").data("step"));
            });

        $.post(poll_maker_ajax.ajax_url, {
            action: "ays_poll_save_checklist_progress",
            nonce: poll_maker_ajax.nonce,
            steps: checked,
        });
    }

    $(document).find(".ays-poll-checklist-step").each(function () {
        var $step = $(this);
        var $checkbox = $step.find('input[type="checkbox"]');
        var $content = $step.find(".ays-poll-checklist-step-content");
        var $button = $step.find(".ays-poll-checklist-mark-done");

        $checkbox.on("change", function () {
            if ($(this).is(':checked')) {
                $step.addClass('completed');
                $button.text(poll_maker_ajax.checklistUnmarkAsDone);
            } else {
                $step.removeClass('completed');
                $button.text(poll_maker_ajax.checklistMarkAsDone);
            }

            updateProgress();
            saveChecklist();
        });
    });

    $(document).on('click', '.ays-poll-checklist-mark-done', function () {
        var $button = $(this);
        var $step = $button.closest('.ays-poll-checklist-step');
        var $checkbox = $step.find('input[type="checkbox"]');

        var isChecked = $checkbox.prop('checked');

        $checkbox.prop('checked', !isChecked);
        $step.toggleClass('completed', !isChecked);

        if (isChecked) {
          $button.text(poll_maker_ajax.checklistMarkAsDone);
        } else {
          $button.text(poll_maker_ajax.checklistUnmarkAsDone);
        }

        updateProgress();
        saveChecklist();
    });

    $(document).on('click', '.ays-poll-checklist-toggle-step', function () {
        var $step = $(this).closest('.ays-poll-checklist-step');
        var $content = $step.find('.ays-poll-checklist-step-content');

        $(document).find('.ays-poll-checklist-step-content').not($content).slideUp(200);
        $(document).find('.ays-poll-checklist-toggle-step').not(this).removeClass('open');

        $content.slideToggle(200);
        $(this).toggleClass('open');
    });

    // Reopen checklist on checklist icon click
    $(document).on('click', '.ays-poll-checklist-open-icon', function () {
        var $checklist = $(document).find('#ays-poll-checklist-container');
        $checklist.show();

        $.ajax({
            type: 'POST',
            url: poll_maker_ajax.ajax_url,
            data: {
                action: 'ays_poll_checklist_reopen_callback',
                nonce: poll_maker_ajax.nonce
            },
            success: function () {
            }
        });
    });

    // Hide popup permanently
    $(document).on('click', '.ays-poll-checklist-popup-close', function () {
        var $checklist = $(this).closest('.ays-poll-checklist-popup');
        $checklist.fadeOut(200);
    });

    $(document).on('click', '.ays-poll-checklist-minimize-btn', function () {
        var $panel = $(document).find('.ays-poll-checklist-panel');
        var $expandedIcon = $(this).find('.ays-poll-checklist-minimize-icon-expanded');
        var $collapsedIcon = $(this).find('.ays-poll-checklist-minimize-icon-collapsed');

        $panel.toggleClass('ays-poll-checklist-minimized');

        if ($panel.hasClass('ays-poll-checklist-minimized')) {
            $expandedIcon.hide();
            $collapsedIcon.show();
        } else {
            $expandedIcon.show();
            $collapsedIcon.hide();
        }
    });

    $(document).on('click', '.ays-poll-checklist-close-btn', function () {
        var $checklist = $(this).closest('#ays-poll-checklist-container');
        $.ajax({
            type: 'POST',
            url: poll_maker_ajax.ajax_url,
            data: {
                action: 'ays_poll_checklist_close_popup',
                nonce: poll_maker_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    $checklist.fadeOut(200);
                } else {
                    console.error(response.data);
                    $checklist.fadeOut(200);
                }

                if (localStorage.getItem('ays_poll_checklist_popup_closed') !== '1') {
                    $(document).find('.ays-poll-checklist-popup').fadeIn(200);
                }

                localStorage.setItem('ays_poll_checklist_popup_closed', '1');
            },
            error: function() {
                $checklist.fadeOut(200);
            }
        });
    });

    updateProgress();

})(jQuery)
