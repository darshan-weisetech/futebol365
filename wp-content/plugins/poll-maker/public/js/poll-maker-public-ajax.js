(function ($) {
    // String.prototype.stripSlashes = function () {
    //     return this.replace(/\\(.)/mg, "$1");
    // }
    function stripSlashes(str) {
        return str.replace(/\\(.)/mg, "$1");
    }
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

    $.fn.goToPoll = function(enabelAnimation, scroll) {
        var pollAnimationTop = (scroll && scroll != 0) ? parseInt(scroll) : 100;
        
        if( enabelAnimation ){
            $('html, body').animate({
                scrollTop: $(this).offset().top - scroll + 'px'
            }, 'slow');
        }
        return this; // for chaining...
    }

    $.fn.aysModal = function(action){
        var $this = $(this);
        switch(action){
            case 'hide':
                $(this).find('.ays-poll-avatars-modal-content').css('animation-name', 'zoomOut');
                setTimeout(function(){
                    $(document.body).removeClass('modal-open');
                    $(document).find('.ays-modal-backdrop').remove();
                    $this.hide();
                }, 250);
                break;
            case 'show':
            default:
                $this.show();
                $(this).find('.ays-poll-avatars-modal-content').css('animation-name', 'zoomIn');
                $(document).find('.modal-backdrop').remove();
                $(document.body).append('<div class="ays-modal-backdrop"></div>');
                $(document.body).addClass('modal-open');
                break;
        }
    }

    $(document).find(".ays-poll-main .apm-info-form").each(function(e){
        var infoForm = $(this);
        var mainContainer = infoForm.parents('.ays-poll-main');
        var id = mainContainer.attr('id').match(/\d+$/)[0];
        var pollBox = mainContainer.find('div[data-id="' + id + '"]');
        var uniqueId = pollBox.attr('id');
        var pollOptions = JSON.parse(window.atob(window.aysPollOptions[uniqueId]));
    
        if (pollOptions.autofill_user_data && pollOptions.autofill_user_data == "on") {
            var userData = {};
            userData.action = 'ays_poll_get_user_information';
            userData._ajax_nonce = poll_maker_ajax_public.nonce;
            $.ajax({
                url: poll_maker_ajax_public.ajax_url,
                method: 'post',
                dataType: 'json',
                data: userData,
                success: function (response) {
                    if(response !== null){
                        infoForm.find("input[name='apm_name']").val(response.data.display_name);
                        infoForm.find("input[name='apm_email']").val(response.data.user_email);
                    }
                }
            });
        }
    })

    function socialBtnAdd(formId, buttons) {
        var socialDiv = $("<div class='apm-social-btn'></div>");
        if(buttons.heading != ""){
            socialDiv.append("<div class='ays-survey-social-shares-heading'>"+
                                    buttons.heading
                                +"</div>");
        }
        if(buttons.faceBook){
            socialDiv.append("<a class='fb-share-button ays-share-btn ays-share-btn-branded ays-share-btn-facebook'"+
                                        "title='Share on Facebook'>"+
                                        "<span class='ays-share-btn-text'>Facebook</span>"+
                                    "</a>");
        }
        if(buttons.twitter){
            socialDiv.append("<a class='twt-share-button ays-share-btn ays-share-btn-branded ays-share-btn-twitter'"+
                                    "title='Share on Twitter'>"+
                                    "<span class='ays-share-btn-text'>Twitter</span>"+
                                "</a>");
        }
        if(buttons.linkedIn){
            socialDiv.append("<a class='linkedin-share-button ays-share-btn ays-share-btn-branded ays-share-btn-linkedin'"+
                                    "title='Share on LinkedIn'>"+
                                    "<span class='ays-share-btn-text'>LinkedIn</span>"+
                                "</a>");
        }
        if(buttons.vkontakte){
            socialDiv.append("<a class='vkontakte-share-button ays-share-btn ays-share-btn-branded ays-share-btn-vkontakte'"+
                                    "title='Share on VKontakte'>"+
                                    "<span class='ays-survey-share-btn-icon'></span>"+
                                    "<span class='ays-share-btn-text'>VKontakte</span>"+
                                "</a>");
        }
        $("#"+formId).append(socialDiv);
        $(document).on('click', '.fb-share-button', function (e) {
            window.open('https://www.facebook.com/sharer/sharer.php?u=' + window.location.href,
                'facebook-share-dialog',
                'width=650,height=450'
            );
            return false;
        })
        $(document).on('click', '.twt-share-button', function (e) {
            window.open('https://twitter.com/intent/tweet?url=' + window.location.href,
                'twitter-share-dialog',
                'width=650,height=450'
            );
            return false;
        })
        $(document).on('click', '.linkedin-share-button', function (e) {
            window.open('https://www.linkedin.com/shareArticle?mini=true&url=' + window.location.href,
                'linkedin-share-dialog',
                'width=650,height=450'
            );
            return false;
        })
        $(document).on('click', '.vkontakte-share-button', function (e) {
            window.open('https://vk.com/share.php?url=' + window.location.href,
                'vkontakte-share-dialog',
                'width=650,height=450'
            );
            return false;
        })
        setTimeout(function() {
            $("#"+formId+" .apm-social-btn").css('opacity', '1');
        }, 1000);
    }

    function socialLinksAdd(formId, buttons) {
        var socialLinksDiv = $("<div class='apm-social-btn'></div>");
        if(buttons.heading != ""){
            socialLinksDiv.append("<div class='ays-survey-social-shares-heading'>"+
                                    buttons.heading
                                +"</div>");
        }
        if(buttons.faceBookLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-share-btn-facebook'"+
                                        "title='Facebook Link'  target='_blank' href=" + buttons.faceBookLink + ">"+
                                        "<div class='ays-poll-link-icon'><?xml version='1.0'?><svg fill='#fff' xmlns='http://www.w3.org/2000/svg'  viewBox='0 0 24 24' width='48px' height='48px'><path d='M19,3H5C3.895,3,3,3.895,3,5v14c0,1.105,0.895,2,2,2h7.621v-6.961h-2.343v-2.725h2.343V9.309 c0-2.324,1.421-3.591,3.495-3.591c0.699-0.002,1.397,0.034,2.092,0.105v2.43h-1.428c-1.13,0-1.35,0.534-1.35,1.322v1.735h2.7 l-0.351,2.725h-2.365V21H19c1.105,0,2-0.895,2-2V5C21,3.895,20.105,3,19,3z'/></svg></div>"+
                                    "</a>");
        }
        if(buttons.twitterLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-share-btn-x'"+
                                    "title='Twitter Link'  target='_blank' href=" + buttons.twitterLink + ">"+
                                    "<div class='ays-poll-link-icon'><svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='48px' height='48px' fill='currentColor'><path d='M18.244 2H21.552L14.325 10.26L22.8 22H16.17L10.96 14.82L4.7 22H1.39L9.14 13.14L1 2H7.8L12.52 8.46L18.244 2ZM17.1 20H18.95L6.8 4H4.82L17.1 20Z'/></svg></div>"+
                                "</a>");
        }
        if(buttons.linkedInLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-share-btn-linkedin'"+
                                    "title='LinkedIn Link' target='_blank' href=" + buttons.linkedInLink + ">"+
                                    "<div class='ays-poll-link-icon'><?xml version='1.0'?><svg fill='#fff' xmlns='http://www.w3.org/2000/svg'  viewBox='0 0 24 24' width='48px' height='48px'>    <path d='M21,3H3v18h18V3z M9,17H6.477v-7H9V17z M7.694,8.717c-0.771,0-1.286-0.514-1.286-1.2s0.514-1.2,1.371-1.2 c0.771,0,1.286,0.514,1.286,1.2S8.551,8.717,7.694,8.717z M18,17h-2.442v-3.826c0-1.058-0.651-1.302-0.895-1.302 s-1.058,0.163-1.058,1.302c0,0.163,0,3.826,0,3.826h-2.523v-7h2.523v0.977C13.93,10.407,14.581,10,15.802,10 C17.023,10,18,10.977,18,13.174V17z'/></svg></div>"+
                                "</a>");
        }
        if(buttons.vkontakteLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-share-btn-vkontakte'"+
                                    "title='VKontakte Link'  target='_blank' href=" + buttons.vkontakteLink + ">"+
                                    "<div class='ays-poll-link-icon'><svg fill='#fff' xmlns='http://www.w3.org/2000/svg'  viewBox='0 0 48 48' width='48px' height='48px'><path d='M45.763,35.202c-1.797-3.234-6.426-7.12-8.337-8.811c-0.523-0.463-0.579-1.264-0.103-1.776 c3.647-3.919,6.564-8.422,7.568-11.143C45.334,12.27,44.417,11,43.125,11l-3.753,0c-1.237,0-1.961,0.444-2.306,1.151 c-3.031,6.211-5.631,8.899-7.451,10.47c-1.019,0.88-2.608,0.151-2.608-1.188c0-2.58,0-5.915,0-8.28 c0-1.147-0.938-2.075-2.095-2.075L18.056,11c-0.863,0-1.356,0.977-0.838,1.662l1.132,1.625c0.426,0.563,0.656,1.248,0.656,1.951 L19,23.556c0,1.273-1.543,1.895-2.459,1.003c-3.099-3.018-5.788-9.181-6.756-12.128C9.505,11.578,8.706,11.002,7.8,11l-3.697-0.009 c-1.387,0-2.401,1.315-2.024,2.639c3.378,11.857,10.309,23.137,22.661,24.36c1.217,0.12,2.267-0.86,2.267-2.073l0-3.846 c0-1.103,0.865-2.051,1.977-2.079c0.039-0.001,0.078-0.001,0.117-0.001c3.267,0,6.926,4.755,8.206,6.979 c0.368,0.64,1.056,1.03,1.8,1.03l4.973,0C45.531,38,46.462,36.461,45.763,35.202z'/></svg></div>"+
                                "</a>");
        }
        if(buttons.youtubeLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-poll-share-btn-youtube'"+
                                    "title='Youtube Link'  target='_blank' href=" + buttons.youtubeLink + ">"+
                                    "<div class='ays-poll-link-icon'><svg xmlns='http://www.w3.org/2000/svg' fill='#FF0000' viewBox='0 0 28 28' width='48px' height='48px'>    <path d='M 15 4 C 10.814 4 5.3808594 5.0488281 5.3808594 5.0488281 L 5.3671875 5.0644531 C 3.4606632 5.3693645 2 7.0076245 2 9 L 2 15 L 2 15.001953 L 2 21 L 2 21.001953 A 4 4 0 0 0 5.3769531 24.945312 L 5.3808594 24.951172 C 5.3808594 24.951172 10.814 26.001953 15 26.001953 C 19.186 26.001953 24.619141 24.951172 24.619141 24.951172 L 24.621094 24.949219 A 4 4 0 0 0 28 21.001953 L 28 21 L 28 15.001953 L 28 15 L 28 9 A 4 4 0 0 0 24.623047 5.0546875 L 24.619141 5.0488281 C 24.619141 5.0488281 19.186 4 15 4 z M 12 10.398438 L 20 15 L 12 19.601562 L 12 10.398438 z'/></svg></div>"+
                                "</a>");
        }
        if(buttons.tiktokLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-poll-share-btn-tiktok'"+
                                    "title='Tiktok Link'  target='_blank' href=" + buttons.tiktokLink + ">"+
                                    "<div class='ays-poll-link-icon'><svg fill='none' viewBox='0 0 32 32' height='24px' width='24px' xmlns='http://www.w3.org/2000/svg'><path d='M8.45095 19.7926C8.60723 18.4987 9.1379 17.7743 10.1379 17.0317C11.5688 16.0259 13.3561 16.5948 13.3561 16.5948V13.2197C13.7907 13.2085 14.2254 13.2343 14.6551 13.2966V17.6401C14.6551 17.6401 12.8683 17.0712 11.4375 18.0775C10.438 18.8196 9.90623 19.5446 9.7505 20.8385C9.74562 21.5411 9.87747 22.4595 10.4847 23.2536C10.3345 23.1766 10.1815 23.0889 10.0256 22.9905C8.68807 22.0923 8.44444 20.7449 8.45095 19.7926ZM22.0352 6.97898C21.0509 5.90039 20.6786 4.81139 20.5441 4.04639H21.7823C21.7823 4.04639 21.5354 6.05224 23.3347 8.02482L23.3597 8.05134C22.8747 7.7463 22.43 7.38624 22.0352 6.97898ZM28 10.0369V14.293C28 14.293 26.42 14.2312 25.2507 13.9337C23.6179 13.5176 22.5685 12.8795 22.5685 12.8795C22.5685 12.8795 21.8436 12.4245 21.785 12.3928V21.1817C21.785 21.6711 21.651 22.8932 21.2424 23.9125C20.709 25.246 19.8859 26.1212 19.7345 26.3001C19.7345 26.3001 18.7334 27.4832 16.9672 28.28C15.3752 28.9987 13.9774 28.9805 13.5596 28.9987C13.5596 28.9987 11.1434 29.0944 8.96915 27.6814C8.49898 27.3699 8.06011 27.0172 7.6582 26.6277L7.66906 26.6355C9.84383 28.0485 12.2595 27.9528 12.2595 27.9528C12.6779 27.9346 14.0756 27.9528 15.6671 27.2341C17.4317 26.4374 18.4344 25.2543 18.4344 25.2543C18.5842 25.0754 19.4111 24.2001 19.9423 22.8662C20.3498 21.8474 20.4849 20.6247 20.4849 20.1354V11.3475C20.5435 11.3797 21.2679 11.8347 21.2679 11.8347C21.2679 11.8347 22.3179 12.4734 23.9506 12.8889C25.1204 13.1864 26.7 13.2483 26.7 13.2483V9.91314C27.2404 10.0343 27.7011 10.0671 28 10.0369Z' fill='#EE1D52'/><path d='M26.7009 9.91314V13.2472C26.7009 13.2472 25.1213 13.1853 23.9515 12.8879C22.3188 12.4718 21.2688 11.8337 21.2688 11.8337C21.2688 11.8337 20.5444 11.3787 20.4858 11.3464V20.1364C20.4858 20.6258 20.3518 21.8484 19.9432 22.8672C19.4098 24.2012 18.5867 25.0764 18.4353 25.2553C18.4353 25.2553 17.4337 26.4384 15.668 27.2352C14.0765 27.9539 12.6788 27.9357 12.2604 27.9539C12.2604 27.9539 9.84473 28.0496 7.66995 26.6366L7.6591 26.6288C7.42949 26.4064 7.21336 26.1717 7.01177 25.9257C6.31777 25.0795 5.89237 24.0789 5.78547 23.7934C5.78529 23.7922 5.78529 23.791 5.78547 23.7898C5.61347 23.2937 5.25209 22.1022 5.30147 20.9482C5.38883 18.9122 6.10507 17.6625 6.29444 17.3494C6.79597 16.4957 7.44828 15.7318 8.22233 15.0919C8.90538 14.5396 9.6796 14.1002 10.5132 13.7917C11.4144 13.4295 12.3794 13.2353 13.3565 13.2197V16.5948C13.3565 16.5948 11.5691 16.028 10.1388 17.0317C9.13879 17.7743 8.60812 18.4987 8.45185 19.7926C8.44534 20.7449 8.68897 22.0923 10.0254 22.991C10.1813 23.0898 10.3343 23.1775 10.4845 23.2541C10.7179 23.5576 11.0021 23.8221 11.3255 24.0368C12.631 24.8632 13.7249 24.9209 15.1238 24.3842C16.0565 24.0254 16.7586 23.2167 17.0842 22.3206C17.2888 21.7611 17.2861 21.1978 17.2861 20.6154V4.04639H20.5417C20.6763 4.81139 21.0485 5.90039 22.0328 6.97898C22.4276 7.38624 22.8724 7.7463 23.3573 8.05134C23.5006 8.19955 24.2331 8.93231 25.1734 9.38216C25.6596 9.61469 26.1722 9.79285 26.7009 9.91314Z' fill='#000000'/><path d='M4.48926 22.7568V22.7594L4.57004 22.9784C4.56076 22.9529 4.53074 22.8754 4.48926 22.7568Z' fill='#69C9D0'/><path d='M10.5128 13.7916C9.67919 14.1002 8.90498 14.5396 8.22192 15.0918C7.44763 15.7332 6.79548 16.4987 6.29458 17.354C6.10521 17.6661 5.38897 18.9168 5.30161 20.9528C5.25223 22.1068 5.61361 23.2983 5.78561 23.7944C5.78543 23.7956 5.78543 23.7968 5.78561 23.798C5.89413 24.081 6.31791 25.0815 7.01191 25.9303C7.2135 26.1763 7.42963 26.4111 7.65924 26.6334C6.92357 26.1457 6.26746 25.5562 5.71236 24.8839C5.02433 24.0451 4.60001 23.0549 4.48932 22.7626C4.48919 22.7605 4.48919 22.7584 4.48932 22.7564V22.7527C4.31677 22.2571 3.95431 21.0651 4.00477 19.9096C4.09213 17.8736 4.80838 16.6239 4.99775 16.3108C5.4985 15.4553 6.15067 14.6898 6.92509 14.0486C7.608 13.4961 8.38225 13.0567 9.21598 12.7484C9.73602 12.5416 10.2778 12.3891 10.8319 12.2934C11.6669 12.1537 12.5198 12.1415 13.3588 12.2575V13.2196C12.3808 13.2349 11.4148 13.4291 10.5128 13.7916Z' fill='#69C9D0'/><path d='M20.5438 4.04635H17.2881V20.6159C17.2881 21.1983 17.2881 21.76 17.0863 22.3211C16.7575 23.2167 16.058 24.0253 15.1258 24.3842C13.7265 24.923 12.6326 24.8632 11.3276 24.0368C11.0036 23.823 10.7187 23.5594 10.4844 23.2567C11.5962 23.8251 12.5913 23.8152 13.8241 23.341C14.7558 22.9821 15.4563 22.1734 15.784 21.2774C15.9891 20.7178 15.9864 20.1546 15.9864 19.5726V3H20.4819C20.4819 3 20.4315 3.41188 20.5438 4.04635ZM26.7002 8.99104V9.9131C26.1725 9.79263 25.6609 9.61447 25.1755 9.38213C24.2352 8.93228 23.5026 8.19952 23.3594 8.0513C23.5256 8.1559 23.6981 8.25106 23.8759 8.33629C25.0192 8.88339 26.1451 9.04669 26.7002 8.99104Z' fill='#69C9D0'/></svg></div>"+
                                "</a>");
        }
        if(buttons.instagramLink != ''){
            socialLinksDiv.append("<a class='ays-share-btn ays-share-btn-branded ays-poll-share-btn-instagram'"+
                                    "title='Instagram Link'  target='_blank' href=" + buttons.instagramLink + ">"+
                                    "<div class='ays-poll-link-icon'><svg xmlns='http://www.w3.org/2000/svg' xmlns:xlink='http://www.w3.org/1999/xlink' width='24px' height='24px' viewBox='0 0 264.583 264.583'><defs><radialGradient xlink:href='#a' id='f' cx='158.429' cy='578.088' r='52.352' fx='158.429' fy='578.088' gradientTransform='matrix(0 -4.03418 4.28018 0 -2332.227 942.236)' gradientUnits='userSpaceOnUse'/><radialGradient xlink:href='#b' id='g' cx='172.615' cy='600.692' r='65' fx='172.615' fy='600.692' gradientTransform='matrix(.67441 -1.16203 1.51283 .87801 -814.366 -47.835)' gradientUnits='userSpaceOnUse'/><radialGradient xlink:href='#c' id='h' cx='144.012' cy='51.337' r='67.081' fx='144.012' fy='51.337' gradientTransform='matrix(-2.3989 .67549 -.23008 -.81732 464.996 -26.404)' gradientUnits='userSpaceOnUse'/><radialGradient xlink:href='#d' id='e' cx='199.788' cy='628.438' r='52.352' fx='199.788' fy='628.438' gradientTransform='matrix(-3.10797 .87652 -.6315 -2.23914 1345.65 1374.198)' gradientUnits='userSpaceOnUse'/><linearGradient id='d'><stop offset='0' stop-color='#ff005f'/><stop offset='1' stop-color='#fc01d8'/></linearGradient><linearGradient id='c'><stop offset='0' stop-color='#780cff'/><stop offset='1' stop-color='#820bff' stop-opacity='0'/></linearGradient><linearGradient id='b'><stop offset='0' stop-color='#fc0'/><stop offset='1' stop-color='#fc0' stop-opacity='0'/></linearGradient><linearGradient id='a'><stop offset='0' stop-color='#fc0'/><stop offset='.124' stop-color='#fc0'/><stop offset='.567' stop-color='#fe4a05'/><stop offset='.694' stop-color='#ff0f3f'/><stop offset='1' stop-color='#fe0657' stop-opacity='0'/></linearGradient></defs><path fill='url(#e)' d='M204.15 18.143c-55.23 0-71.383.057-74.523.317-11.334.943-18.387 2.728-26.07 6.554-5.922 2.942-10.592 6.351-15.201 11.13-8.394 8.716-13.481 19.439-15.323 32.184-.895 6.188-1.156 7.45-1.209 39.056-.02 10.536 0 24.4 0 42.999 0 55.2.062 71.341.326 74.476.916 11.032 2.645 17.973 6.308 25.565 7 14.533 20.37 25.443 36.12 29.514 5.453 1.404 11.476 2.178 19.208 2.544 3.277.142 36.669.244 70.081.244s66.826-.04 70.02-.203c8.954-.422 14.153-1.12 19.901-2.606 15.852-4.09 28.977-14.838 36.12-29.575 3.591-7.409 5.412-14.614 6.236-25.07.18-2.28.255-38.626.255-74.924 0-36.304-.082-72.583-.26-74.863-.835-10.625-2.656-17.77-6.364-25.32-3.042-6.182-6.42-10.799-11.324-15.519-8.752-8.361-19.455-13.45-32.21-15.29-6.18-.894-7.41-1.158-39.033-1.213z' transform='translate(-71.816 -18.143)'/><path fill='url(#f)' d='M204.15 18.143c-55.23 0-71.383.057-74.523.317-11.334.943-18.387 2.728-26.07 6.554-5.922 2.942-10.592 6.351-15.201 11.13-8.394 8.716-13.481 19.439-15.323 32.184-.895 6.188-1.156 7.45-1.209 39.056-.02 10.536 0 24.4 0 42.999 0 55.2.062 71.341.326 74.476.916 11.032 2.645 17.973 6.308 25.565 7 14.533 20.37 25.443 36.12 29.514 5.453 1.404 11.476 2.178 19.208 2.544 3.277.142 36.669.244 70.081.244s66.826-.04 70.02-.203c8.954-.422 14.153-1.12 19.901-2.606 15.852-4.09 28.977-14.838 36.12-29.575 3.591-7.409 5.412-14.614 6.236-25.07.18-2.28.255-38.626.255-74.924 0-36.304-.082-72.583-.26-74.863-.835-10.625-2.656-17.77-6.364-25.32-3.042-6.182-6.42-10.799-11.324-15.519-8.752-8.361-19.455-13.45-32.21-15.29-6.18-.894-7.41-1.158-39.033-1.213z' transform='translate(-71.816 -18.143)'/><path fill='url(#g)' d='M204.15 18.143c-55.23 0-71.383.057-74.523.317-11.334.943-18.387 2.728-26.07 6.554-5.922 2.942-10.592 6.351-15.201 11.13-8.394 8.716-13.481 19.439-15.323 32.184-.895 6.188-1.156 7.45-1.209 39.056-.02 10.536 0 24.4 0 42.999 0 55.2.062 71.341.326 74.476.916 11.032 2.645 17.973 6.308 25.565 7 14.533 20.37 25.443 36.12 29.514 5.453 1.404 11.476 2.178 19.208 2.544 3.277.142 36.669.244 70.081.244s66.826-.04 70.02-.203c8.954-.422 14.153-1.12 19.901-2.606 15.852-4.09 28.977-14.838 36.12-29.575 3.591-7.409 5.412-14.614 6.236-25.07.18-2.28.255-38.626.255-74.924 0-36.304-.082-72.583-.26-74.863-.835-10.625-2.656-17.77-6.364-25.32-3.042-6.182-6.42-10.799-11.324-15.519-8.752-8.361-19.455-13.45-32.21-15.29-6.18-.894-7.41-1.158-39.033-1.213z' transform='translate(-71.816 -18.143)'/><path fill='url(#h)' d='M204.15 18.143c-55.23 0-71.383.057-74.523.317-11.334.943-18.387 2.728-26.07 6.554-5.922 2.942-10.592 6.351-15.201 11.13-8.394 8.716-13.481 19.439-15.323 32.184-.895 6.188-1.156 7.45-1.209 39.056-.02 10.536 0 24.4 0 42.999 0 55.2.062 71.341.326 74.476.916 11.032 2.645 17.973 6.308 25.565 7 14.533 20.37 25.443 36.12 29.514 5.453 1.404 11.476 2.178 19.208 2.544 3.277.142 36.669.244 70.081.244s66.826-.04 70.02-.203c8.954-.422 14.153-1.12 19.901-2.606 15.852-4.09 28.977-14.838 36.12-29.575 3.591-7.409 5.412-14.614 6.236-25.07.18-2.28.255-38.626.255-74.924 0-36.304-.082-72.583-.26-74.863-.835-10.625-2.656-17.77-6.364-25.32-3.042-6.182-6.42-10.799-11.324-15.519-8.752-8.361-19.455-13.45-32.21-15.29-6.18-.894-7.41-1.158-39.033-1.213z' transform='translate(-71.816 -18.143)'/><path fill='#fff' d='M132.345 33.973c-26.716 0-30.07.117-40.563.594-10.472.48-17.62 2.136-23.876 4.567-6.47 2.51-11.958 5.87-17.426 11.335-5.472 5.464-8.834 10.948-11.354 17.412-2.44 6.252-4.1 13.397-4.57 23.858-.47 10.486-.593 13.838-.593 40.535s.119 30.037.594 40.522c.482 10.465 2.14 17.609 4.57 23.859 2.515 6.465 5.876 11.95 11.346 17.414 5.466 5.468 10.955 8.834 17.42 11.345 6.26 2.431 13.41 4.088 23.881 4.567 10.493.477 13.844.594 40.559.594 26.719 0 30.061-.117 40.555-.594 10.472-.48 17.63-2.136 23.888-4.567 6.468-2.51 11.948-5.877 17.414-11.345 5.472-5.464 8.834-10.949 11.354-17.412 2.419-6.252 4.079-13.398 4.57-23.858.472-10.486.595-13.828.595-40.525s-.123-30.047-.594-40.533c-.492-10.465-2.152-17.608-4.57-23.858-2.521-6.466-5.883-10.799-11.355-17.414-5.472-5.468-10.944-8.827-17.42-11.335-6.271-2.431-13.424-4.088-23.897-4.567-10.493-.477-13.834-.594-40.558-.594zm-8.825 17.715c2.62-.004 5.542 0 8.825 0 26.266 0 29.38.094 39.752.565 9.591.438 14.797 2.04 18.264 3.385 4.591 1.782 7.864 3.912 11.305 7.352 3.443 3.44 5.575 6.717 7.362 11.305 1.346 3.46 2.951 8.663 3.388 18.247.47 10.363.573 13.475.573 39.71 0 26.233-.102 29.346-.573 39.709-.44 9.584-2.042 14.786-3.388 18.247-1.783 4.587-3.919 7.854-7.362 11.292-3.443 3.441-6.712 5.57-11.305 7.352-3.463 1.352-8.673 2.95-18.264 3.388-10.37.47-13.486.573-39.752.573-26.268 0-29.38-.102-39.751-.573-9.592-.443-14.797-2.044-18.267-3.39-4.59-1.781-7.87-3.911-11.313-7.352-3.443-3.44-5.574-6.709-7.362-11.298-1.346-3.461-2.95-8.663-3.387-18.247-.472-10.363-.566-13.476-.566-39.726s.094-29.347.566-39.71c.438-9.584 2.04-14.786 3.387-18.25 1.783-4.588 3.919-7.865 7.362-11.305 3.443-3.441 6.722-5.57 11.313-7.357 3.468-1.351 8.675-2.949 18.267-3.389 9.075-.41 12.592-.532 30.926-.553zm61.337 16.322c-6.518 0-11.805 5.277-11.805 11.792 0 6.512 5.287 11.796 11.805 11.796s11.804-5.284 11.804-11.796c0-6.513-5.287-11.796-11.805-11.796zm-52.512 13.782c-27.9 0-50.519 22.603-50.519 50.482s22.62 50.471 50.52 50.471 50.51-22.592 50.51-50.471-22.613-50.482-50.513-50.482zm0 17.715c18.11 0 32.792 14.67 32.792 32.767 0 18.096-14.683 32.767-32.792 32.767s-32.791-14.671-32.791-32.767c0-18.098 14.68-32.767 32.791-32.767'/></svg></div>"+
                                "</a>");
        }
        $("#"+formId).append(socialLinksDiv);

        setTimeout(function() {
            $("#"+formId+" .apm-social-btn").css('opacity', '1');
        }, 1000);
    }

    function loadEffect(formId, onOff , fontSize,message) {
        var loadFontSize = fontSize.length > 0 ? fontSize+"px" : '100%';
        var form = $("#"+formId),
            effect = form.attr('data-loading');
        switch (effect) {
            case 'blur':
                form.css({
                    WebkitFilter: onOff ? 'blur(5px)' : 'unset',
                    filter: onOff ? 'blur(5px)' : 'unset',
                })
                break;
            case 'load_gif':
                if (onOff) {
                    var loadSvg = '';
                    switch (form.attr('data-load-gif')) {
                        case 'plg_1':
                            loadSvg = '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width='+loadFontSize+' height='+loadFontSize+' viewBox="0 0 24 30" style="enable-background:new 0 0 50 50;" xml:space="preserve">'+
                            '<rect x="0" y="0" width="4" height="10" fill="#333">'+
                              '<animateTransform attributeType="xml"'+
                                'attributeName="transform" type="translate"'+
                                'values="0 0; 0 20; 0 0"'+
                                'begin="0" dur="0.8s" repeatCount="indefinite" />'+
                            '</rect>'+
                            '<rect x="10" y="0" width="4" height="10" fill="#333">'+
                              '<animateTransform attributeType="xml"'+
                                'attributeName="transform" type="translate"'+
                                'values="0 0; 0 20; 0 0"'+
                                'begin="0.2s" dur="0.8s" repeatCount="indefinite" />'+
                            '</rect>'+
                            '<rect x="20" y="0" width="4" height="10" fill="#333">'+
                              '<animateTransform attributeType="xml"'+
                                'attributeName="transform" type="translate"'+
                                'values="0 0; 0 20; 0 0"'+
                                'begin="0.4s" dur="0.8s" repeatCount="indefinite" />'+
                            '</rect>'+
                        '</svg>';
                            break;
                        case 'plg_2':
                            loadSvg = '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"  width='+loadFontSize+' height='+loadFontSize+' viewBox="0 0 24 30" style="enable-background:new 0 0 50 50;" xml:space="preserve">'+
                            '<rect x="0" y="10" width="4" height="10" fill="#333" opacity="0.2">'+
                                '<animate attributeName="opacity" attributeType="XML" values="0.2; 1; .2" begin="0s" dur="0.7s" repeatCount="indefinite" />'+
                                '<animate attributeName="height" attributeType="XML" values="10; 20; 10" begin="0s" dur="0.7s" repeatCount="indefinite" />'+
                                '<animate attributeName="y" attributeType="XML" values="10; 5; 10" begin="0s" dur="0.7s" repeatCount="indefinite" />'+
                            '</rect>'+
                            '<rect x="8" y="10" width="4" height="10" fill="#333"  opacity="0.2">'+
                                '<animate attributeName="opacity" attributeType="XML" values="0.2; 1; .2"    begin="0.15s" dur="0.7s" repeatCount="indefinite" />'+
                                '<animate attributeName="height" attributeType="XML" values="10; 20; 10" begin="0.15s" dur="0.7s" repeatCount="indefinite" />'+
                                '<animate attributeName="y" attributeType="XML" values="10; 5; 10" begin="0.15s"   dur="0.7s" repeatCount="indefinite" />'+
                            '</rect>'+
                            '<rect x="16" y="10" width="4" height="10" fill="#333"  opacity="0.2">'+
                                '<animate attributeName="opacity" attributeType="XML" values="0.2; 1; .2" begin="0.3s" dur="0.7s" repeatCount="indefinite" />'+
                                '<animate attributeName="height" attributeType="XML" values="10; 20; 10" begin="0.3s" dur="0.7s" repeatCount="indefinite" />'+
                                '<animate attributeName="y" attributeType="XML" values="10; 5; 10" begin="0.3s" dur="0.7s" repeatCount="indefinite" />'+
                            '</rect>'+
                        '</svg>';
                            break;
                        case 'plg_3':
                            loadSvg = '<svg width='+loadFontSize+' height='+loadFontSize+' viewBox="0 0 105 105" xmlns="http://www.w3.org/2000/svg" fill="#000">'+
                            '<circle cx="12.5" cy="12.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="0s" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="12.5" cy="52.5" r="12.5" fill-opacity=".5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="100ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="52.5" cy="12.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="300ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="52.5" cy="52.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="600ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="92.5" cy="12.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="800ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="92.5" cy="52.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="400ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="12.5" cy="92.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="700ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="52.5" cy="92.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="500ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                            '<circle cx="92.5" cy="92.5" r="12.5">'+
                                '<animate attributeName="fill-opacity"'+
                                 'begin="200ms" dur="0.9s"'+
                                 'values="1;.2;1" calcMode="linear"'+
                                 'repeatCount="indefinite" />'+
                            '</circle>'+
                        '</svg>';
                            break;
                        case 'plg_4':
                            loadSvg = '<svg width='+loadFontSize+' height='+loadFontSize+' viewBox="0 0 57 57" xmlns="http://www.w3.org/2000/svg"  stroke="#000">'+
                            '<g fill="none" fill-rule="evenodd">'+
                                '<g transform="translate(1 1)" stroke-width="2">'+
                                    '<circle cx="5" cy="50" r="5">'+
                                        '<animate attributeName="cy"'+
                                             'begin="0s" dur="2.2s"'+
                                             'values="50;5;50;50"'+
                                             'calcMode="linear"'+
                                             'repeatCount="indefinite" />'+
                                        '<animate attributeName="cx"'+
                                             'begin="0s" dur="2.2s"'+
                                             'values="5;27;49;5"'+
                                             'calcMode="linear"'+
                                             'repeatCount="indefinite" />'+
                                    '</circle>'+
                                    '<circle cx="27" cy="5" r="5">'+
                                        '<animate attributeName="cy"'+
                                             'begin="0s" dur="2.2s"'+
                                             'from="5" to="5"'+
                                             'values="5;50;50;5"'+
                                             'calcMode="linear"'+
                                             'repeatCount="indefinite" />'+
                                        '<animate attributeName="cx"'+
                                             'begin="0s" dur="2.2s"'+
                                             'from="27" to="27"'+
                                             'values="27;49;5;27"'+
                                             'calcMode="linear"'+
                                             'repeatCount="indefinite" />'+
                                    '</circle>'+
                                    '<circle cx="49" cy="50" r="5">'+
                                        '<animate attributeName="cy"'+
                                             'begin="0s" dur="2.2s"'+
                                             'values="50;50;5;50"'+
                                             'calcMode="linear"'+
                                             'repeatCount="indefinite" />'+
                                        '<animate attributeName="cx"'+
                                             'from="49" to="49"'+
                                             'begin="0s" dur="2.2s"'+
                                             'values="49;5;27;49"'+
                                             'calcMode="linear"'+
                                             'repeatCount="indefinite" />'+
                                    '</circle>'+
                                '</g>'+
                            '</g>'+
                        '</svg>';
                            break;
                        case 'plg_5':
                            loadSvg = '<svg width='+loadFontSize+' height='+loadFontSize+' viewBox="0 0 135 140" xmlns="http://www.w3.org/2000/svg"  stroke="#000">'+
                            '<rect y="10" width="15" height="120" rx="6">'+
                                '<animate attributeName="height" begin="0.5s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite" />'+
                                '<animate attributeName="y" begin="0.5s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite" />'+
                            '</rect>'+
                            '<rect x="30" y="10" width="15" height="120" rx="6">'+
                                '<animate attributeName="height" begin="0.25s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/>'+
                                '<animate attributeName="y" begin="0.25s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/>'+
                            '</rect>'+
                           ' <rect x="60" width="15" height="140" rx="6">'+
                                '<animate attributeName="height" begin="0s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/>'+
                                '<animate attributeName="y" begin="0s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/>'+
                            '</rect>'+
                            '<rect x="90" y="10" width="15" height="120" rx="6">'+
                                '<animate attributeName="height" begin="0.25s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/>'+
                                '<animate attributeName="y" begin="0.25s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/>'+
                            '</rect>'+
                            '<rect x="120" y="10" width="15" height="120" rx="6">'+
                                '<animate attributeName="height" begin="0.5s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/>'+
                                '<animate attributeName="y" begin="0.5s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/>'+
                            '</rect>'+
                        '</svg>';
                            break;
                        default:
                            loadSvg = '<svg version="1.1" id="loader-1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"'+
                            'width='+loadFontSize+' height='+loadFontSize+' viewBox="0 0 50 50" style="enable-background:new 0 0 50  50;" xml:space="preserve">'+
                                '<path fill="#000" d="M43.935,25.145c0-10.318-8.364-18.683-18.683-18.683c-10.318, 0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615c8.072,0, 14.615, 6.543,14.615,14.615H43.935z">'+
                                    '<animateTransform attributeType="xml"'+
                                        'attributeName="transform"'+
                                        'type="rotate"'+
                                        'from="0 25 25"'+
                                        'to="360 25 25"'+
                                        'dur="0.6s"'+
                                        'repeatCount="indefinite"/>'+
                                '</path>'+
                            '</svg>';
                    }
                    var layer = $('<div class="apm-opacity-layer-light" style="margin-top: 0">'+
                        '<div class="apm-loading-gif">'+
                            '<div class="apm-loader loader--style3">'+
                                loadSvg+
                            '</div>'+
                        '</div>'+
                    '</div>');
                    form.css({
                        position: 'relative'
                    });
                    form.append(layer);
                    layer.css('opacity', 1);
                } else {
                    $('.apm-opacity-layer-light').css('opacity', 0).empty();
                    setTimeout(function() {
                        $('.apm-opacity-layer-light').remove();
                    }, 500);
                }
                break;
            case 'message':
                if (onOff) {
                    var layer = $('<div class="apm-opacity-layer-light apm-load-message-container"><span>'+message+'</span></div>');
                    form.css({
                        position: 'relative'
                    });
                    form.append(layer);
                    layer.css('opacity', 1);
                    setTimeout(function() {
                        $('.apm-load-message-container').remove();
                    }, 500);
                }
                else{
                     $('.apm-opacity-layer-light').css('opacity', 0).empty();
                    setTimeout(function() {
                        $('.apm-opacity-layer-dark').remove();
                    }, 500);
                }
                break;    
            default:
                if (onOff) {
                    var layer = $('<div class="apm-opacity-layer-dark"></div>');
                    form.css({
                        position: 'relative'
                    });
                    form.append(layer);
                    layer.css('opacity', 1);
                } else {
                    $('.apm-opacity-layer-dark').css('opacity', 0);
                    setTimeout(function() {
                        $('.apm-opacity-layer-dark').remove();
                    }, 500);
                }
                break;
        }
    }

    function sortDate(rateCount, votesSum, answers, formId) {
        var form = $("#"+formId),
            sortable = form.attr('data-res-sort'),
            widths = [];
        for (var i = 0; i < rateCount; i++) {
            var answer = answers[i];
            widths[i] = {};
            var width = (answer.votes * 100 / votesSum).toFixed(0);
            widths[i].width = width;
            widths[i].index = i;
        }
        if (sortable === "ASC") {
            for (var i = 0; i < rateCount; i++) {
                for (var j = (i + 1); j < rateCount; j++) {
                    if (Number(widths[i].width) > Number(widths[j].width)) {
                        var temp = widths[i].width;
                        widths[i].width = widths[j].width;
                        widths[j].width = temp;
                        temp = widths[i].index;
                        widths[i].index = widths[j].index;
                        widths[j].index = temp;
                    }
                }
            }
        } else if (sortable === "DESC") {
            for (var i = 0; i < rateCount; i++) {
                for (var j = (i + 1); j < rateCount; j++) {
                    if (Number(widths[i].width) < Number(widths[j].width)) {
                        var temp = widths[i].width;
                        widths[i].width = widths[j].width;
                        widths[j].width = temp;
                        temp = widths[i].index;
                        widths[i].index = widths[j].index;
                        widths[j].index = temp;
                    }
                }
            }
        }
        return widths;
    }

    var apmIcons = {
        star: "<i class='ays_poll_far ays_poll_fa-star'></i>",
        star1: "<i class='ays_poll_fas ays_poll_fa-star'></i>",
        emoji: [
            "<i class='ays_poll_far ays_poll_fa-dizzy'></i>",
            "<i class='ays_poll_far ays_poll_fa-smile'></i>",
            "<i class='ays_poll_far ays_poll_fa-meh'></i>",
            "<i class='ays_poll_far ays_poll_fa-frown'></i>",
            "<i class='ays_poll_far ays_poll_fa-tired'></i>",
        ],
        emoji1: [
            "<i class='ays_poll_fas ays_poll_fa-dizzy'></i>",
            "<i class='ays_poll_fas ays_poll_fa-smile'></i>",
            "<i class='ays_poll_fas ays_poll_fa-meh'></i>",
            "<i class='ays_poll_fas ays_poll_fa-frown'></i>",
            "<i class='ays_poll_fas ays_poll_fa-tired'></i>",
        ],
        hand: [
            "<i class='ays_poll_far ays_poll_fa-thumbs-up'></i>",
            "<i class='ays_poll_far ays_poll_fa-thumbs-down'></i>"
        ],
        hand1: [
            "<i class='ays_poll_fas ays_poll_fa-thumbs-up'></i>",
            "<i class='ays_poll_fas ays_poll_fa-thumbs-down'></i>"
        ],
    };

    function showInfoForm($form) {
        $form.find('.ays_question, .apm-answers').fadeOut(0);
        $infoForm = $form.find('.apm-info-form');
        $infoForm.fadeIn();
        $form.find('.ays_finish_poll').val($infoForm.attr('data-text'));
        $form.find('.ays_finish_poll').attr('style', 'display:initial !important');
        $form.find('.ays-see-res-button-show').attr('style', 'display:none');
        $form.attr('data-info-form', '');
    }

    var emailValivatePattern = /^[a-zA-Z0-9\._-]+@[a-zA-Z0-9\._-]+\.\w{2,}$/;

    function voting(btnId , type) {
        if (typeof btnId == "undefined"){
            btnId = 0;
        }
        var btn;
        if( btnId === 0 ){
            btn = $(this);
        }else{
            btn = btnId;
        }
        var seeRes = btn.attr('data-seeRes'),
            isLimitation = btn.attr('data-limitation') == 'true';
            formId = btn.attr('data-form'),
            form = $("#"+formId),
            pollId = form.attr('data-id'),
            isRestart = form.attr('data-restart'),
            voteURLRedirection = form.attr('data-redirect-check'),
            voteRedirection = form.attr('data-redirection'),
            infoForm = form.attr('data-info-form'),
            resultColorsRgba = form.attr('data-res-rgba'),
            hideBgImage = form.attr('data-hide-bg-image'),
            hideBgImageDefColor = form.data('hide-bg-image-def-color'),
            backgroundGradientCheck = form.data('gradient-check'),
            backgroundGradientC1 = form.data('gradient-c1'),
            backgroundGradientC2 = form.data('gradient-c2'),
            backgroundGradientDir = form.data('gradient-dir'),
            loadEffectFontSize = form.attr('data-load-gif-font-size');
            enableTopAnimation = form.attr('data-enable-top-animation');
            topAnimationScroll = form.attr('data-top-animation-scroll');
            loadEffectMessage  = typeof form.data('loadMessage') != "undefined" ? form.data('loadMessage') : "";

        var pollOptions = JSON.parse(window.atob(window.aysPollOptions[formId]));
        var pollEnableLn = typeof pollOptions.poll_show_social_ln != "undefined" && pollOptions.poll_show_social_ln.length > 0 && pollOptions.poll_show_social_ln == "on" ? true : false;
        var pollEnableFb = typeof pollOptions.poll_show_social_fb != "undefined" && pollOptions.poll_show_social_fb.length > 0 && pollOptions.poll_show_social_fb == "on" ? true : false;
        var pollEnableTr = typeof pollOptions.poll_show_social_tr != "undefined" && pollOptions.poll_show_social_tr.length > 0 && pollOptions.poll_show_social_tr == "on" ? true : false;
        var pollEnableVk = typeof pollOptions.poll_show_social_vk != "undefined" && pollOptions.poll_show_social_vk.length > 0 && pollOptions.poll_show_social_vk == "on" ? true : false;
        var pollSocialButtons = {
            linkedIn  : pollEnableLn,
            faceBook  : pollEnableFb,
            twitter   : pollEnableTr,
            vkontakte : pollEnableVk,
        };

        if( typeof pollOptions.social_links != "undefined" ) {
            var pollLinkedInLink = pollOptions.social_links.linkedin_link != "" ? pollOptions.social_links.linkedin_link : "";
            var pollFacebookInLink = pollOptions.social_links.facebook_link != "" ? pollOptions.social_links.facebook_link : "";
            var pollTwitterLink = pollOptions.social_links.twitter_link != "" ? pollOptions.social_links.twitter_link : "";
            var pollVkontakteLink = pollOptions.social_links.vkontakte_link != "" ? pollOptions.social_links.vkontakte_link : "";
            var pollYoutubeLink = pollOptions.social_links.youtube_link != "" ? pollOptions.social_links.youtube_link : "";
            var pollTiktokLink = pollOptions.social_links.tiktok_link != "" ? pollOptions.social_links.tiktok_link : "";
            var pollInstagramLink = pollOptions.social_links.instagram_link != "" ? pollOptions.social_links.instagram_link : "";
        }
        var pollSocialLinks = {
            linkedInLink  : pollLinkedInLink,
            faceBookLink  : pollFacebookInLink,
            twitterLink   : pollTwitterLink,
            vkontakteLink : pollVkontakteLink,
            youtubeLink   : pollYoutubeLink,
            tiktokLink   : pollTiktokLink,
            instagramLink   : pollInstagramLink,
        }

        var data = form.parent().serializeFormJSON();
        
        if(form.hasClass('choosing-poll') && !seeRes){
            var allowMultivoteCheck = $('#'+formId).find('input#ays_poll_multivote_min_count').data("allow");
            if(allowMultivoteCheck){
                var numberCheckedAnswers  = $('#'+formId).find('input:checkbox:checked').length;
                var numberAllAnswers  = $('#'+formId).find('input:checkbox').length;
                var minimumVotesCount = $('#'+formId).find('input#ays_poll_multivote_min_count').val();
                var otherAnswer = $('#'+formId).find('input.ays-poll-new-answer-apply-text');
                var otherAnswerVal = otherAnswer.val();
                if(otherAnswer.length > 0 && otherAnswerVal != ""){
                    numberCheckedAnswers++;
                }
                if( numberAllAnswers < minimumVotesCount){
                    minimumVotesCount = numberAllAnswers;
                    form.find('.ays-poll-multivote-message').html("Minimum votes count shoulde be "+minimumVotesCount);
                }
                if(minimumVotesCount > numberCheckedAnswers){
                    form.find('.ays-poll-multivote-message').show();
                    return false;
                }
            }
        }

        if (infoForm) {
            if (type == "text" && data.answer == ""){
                return;
            }

            if ('answer' in data && !seeRes || (('ays_poll_new_answer' in data) && data.ays_poll_new_answer != '')) {
                return showInfoForm(form);
            } else if(!seeRes) {
                return false;
            }
        }

        var valid = true;
        form.find('.apm-info-form input[name]').each(function () {
            $(this).removeClass('ays_poll_shake');
            if ($(this).attr('data-required') == 'true' && $(this).val() == "" && !seeRes) {
                $(this).addClass('apm-invalid');
                $(this).addClass('ays_red_border');
                $(this).addClass('ays_poll_shake');
                valid = false;
            }
        });
        
        var email_val = $('[check_id="'+formId+'"]');
        if (email_val.attr('type') !== 'hidden' && email_val.attr('check_id') == formId) {
            if(email_val.val() != ''){
                if (!(emailValivatePattern.test(email_val.val())) && !seeRes) {
                    email_val.addClass('ays_red_border');
                    email_val.addClass('ays_poll_shake');
                    valid = false;
                }else{
                    email_val.addClass('ays_green_border');
                }
            }
        }

        var phoneInput = $(document).find("#"+formId).find('input[name="apm_phone"]');
        var phoneInputVal = phoneInput.val();
        if(phoneInputVal != '' && typeof phoneInputVal !== 'undefined'){
            phoneInput.removeClass('ays_red_border');
            phoneInput.removeClass('ays_green_border');
            if (!validatePhoneNumber(phoneInput.get(0))) {
                if (phoneInput.attr('type') !== 'hidden') {
                    phoneInput.addClass('ays_red_border');
                    phoneInput.addClass('ays_poll_shake');
                    valid = false;
                }
            }else{
                phoneInput.addClass('ays_green_border');
            }
        }
        
        if (!valid && !seeRes) {
            return false;
        }

        if ((!('answer' in data) && !seeRes) && (!('ays_poll_new_answer' in data) || (('ays_poll_new_answer' in data) && data.ays_poll_new_answer == ''))) return;
        var userAnswerValue;
        if (data.answer) {
            var userAnswerId = data.answer;

            if ($.isArray(userAnswerId)) {
                var userEachAnswerValue = [];

                for (var i = 0; i < userAnswerId.length; i++) {
                    var userEachAnswer = form.find('input[name="answer"][value="'+userAnswerId[i]+'"]');
                    var userEachAnswerBox = userEachAnswer.parent();
                    userEachAnswerValue.push(userEachAnswerBox.find('span.ays-poll-each-answer-grid').text());
                }

                userAnswerValue = userEachAnswerValue;

                if (data.ays_poll_new_answer) {
                    userAnswerValue.push(data.ays_poll_new_answer);
                }
            } else {
                var userAnsweInput = form.find('input[name="answer"][value="'+userAnswerId+'"]');
                var userAnswerBox = userAnsweInput.parent();
                userAnswerValue = userAnswerBox.find('span.ays-poll-each-answer-grid').text();
            }
        } else if(data.ays_poll_new_answer) {
            userAnswerValue = data.ays_poll_new_answer;
        }
        if (seeRes && ('answer' in data)) delete data['answer'];
        loadEffect(formId, true , loadEffectFontSize,loadEffectMessage);
        btn.off();
        data.action = 'ays_finish_poll';
        data.poll_id = pollId;

        var endDate = GetFullDateTime();
        data.end_date = endDate;

        // Mute answer sound button
        var $this = $(document).find('.ays_finish_poll').data("form");
        var currentContainer = $(document).find("#"+$this);
        var soundEls = currentContainer.find('.ays_music_sound');
        if(soundEls.hasClass("ays_music_sound")){
            soundEls.removeClass("ays_sound_active");
            soundEls.addClass("ays_poll_display_none");
        }
        if($(document).scrollTop() >= form.offset().top){
            form.goToPoll(enableTopAnimation,topAnimationScroll);
        }

        $.ajax({
            url: poll_maker_ajax_public.ajax_url,
            dataType: 'json',
            method:'post',
            data: data,
            success: function(res) {
                form.find(".ays-poll-vote-reason").hide();
                var answers_sounds = $("#"+formId).parent().find('.ays_poll_ans_sound').get(0);
                if(answers_sounds){
                    setTimeout(function() {
                        resetPlaying(answers_sounds);
                    }, 1000);
                }
                if(hideBgImage == 'true'){
                    if(!backgroundGradientCheck){
                        $(document).find("#"+formId).css("background-image", "none");
                        $(document).find("#"+formId).css("background-color", hideBgImageDefColor);
                    }
                    else{
                        $(document).find("#"+formId).css("background-image", "linear-gradient("+backgroundGradientDir+", "+backgroundGradientC1+", "+backgroundGradientC2+")");
                    }
                }
                $("#"+formId+" .ays_poll_cb_and_a").hide();
                $("#"+formId+" .ays_poll_show_timer").hide();
                var delay = $('.ays-poll-main').find('div.box-apm[data-delay]').attr('data-delay');
                delayCountDown(delay);
                loadEffect(formId, false , loadEffectFontSize,loadEffectMessage);
                form.parent().next().prop('disabled', false);
                $('.answer-' + formId).parent().remove(); //for removing apm-answer
                form.find('.ays_poll_passed_count').remove();
                form.find('.apm-info-form').remove();
                var redirectMessage = voteRedirection ? form.find('.redirectionAfterVote').clone(true) : '';
                $("#"+formId+" .apm-button-box").remove();
                var hideRes = form.attr('data-res');
                var resultContainer = $("#"+formId).parent().find('.box-apm');

                var hideResOption = false;
                var pollSocialLinksHeading = "";
                var pollSocialButtonsHeading = "";
                if(typeof res.styles != "undefined"){

                    if(typeof res.styles['hide_results'] != "undefined"){
                        hideResOption = res.styles['hide_results'].length > 0 && res.styles['hide_results'] != 1 ? true : false;
                    }

                    if(typeof res.styles['poll_social_links_heading'] != "undefined"){
                        pollSocialLinksHeading = typeof res.styles['poll_social_links_heading'] != "undefined" && res.styles['poll_social_links_heading'].length > 0 && res.styles['poll_social_links_heading'] != "" ? res.styles['poll_social_links_heading'] : "";
                    }

                    if(typeof res.styles['poll_social_buttons_heading'] != "undefined"){
                        pollSocialButtonsHeading = typeof res.styles['poll_social_buttons_heading'] != "undefined" && res.styles['poll_social_buttons_heading'].length > 0 && res.styles['poll_social_buttons_heading'] != "" ? res.styles['poll_social_buttons_heading'] : "";
                    }

                    var result_message = typeof res.styles['result_message'] != "undefined" && res.styles['result_message'] != "" ? res.styles['result_message'] : "";

                    if (!isLimitation) {
                        form.find('.apm-title-box.ays_res_mess').html(result_message);
                    }

                    var hide_results_text = typeof res.styles['hide_results_text'] != "undefined" && res.styles['hide_results_text'] != "" ? res.styles['hide_results_text'] : "";
                    form.find('.ays-poll-hide-result-box').html(hide_results_text);

                }
                pollSocialLinks.heading = pollSocialLinksHeading;
                pollSocialButtons.heading = pollSocialButtonsHeading;
                    
                if( !res.voted_status && !seeRes && hideResOption){
                    var content = '';
                    var limitation_message = (res.styles['limitation_message'] && res.styles['limitation_message'] != '') ? res.styles['limitation_message'] : poll_maker_ajax_public.alreadyVoted;
                    limitation_message = limitation_message.replace(/\\/g, '');

                    content += '<div class="ays-poll-vote-message">';
                        content += '<p>'+ limitation_message +'</p>';
                    content += '</div>';

                    resultContainer.append(content);
                }

                if (hideRes != 0) {
                    $("#"+formId+" .ays_question").remove();
                    $("#"+formId+" .hideResults").css("display", "block");
                }
                else if ( type == "text" ) {
                    $("#"+formId+" .ays_question").remove();
                    $("#"+formId+" .hideResults").css("display", "block");
                    $("#"+formId+" .ays_res_mess").fadeIn();
                }
                else if ( !res.voted_status ) {
                    $("#"+formId+" .hideResults").css("display", "block");
                }
                else {
                    form.append('<div class="results-apm" id="pollResultId' +data.poll_id+ '">' + '</div>');
                    var votesSum = 0;
                    var votesMax = 0;
                    var answer;
                    for ( answer in res.answers) {
                        votesSum = Math.abs(res.answers[answer].votes) + votesSum;
                        if (+res.answers[answer].votes > votesMax) {
                            votesMax = +res.answers[answer].votes;
                        }
                    }
                    var answer2 = res.answers;

                    // Answer Numbering
                    
                    var widths = sortDate(res.answers.length, votesSum, answer2, formId );
                    //show votes count 
                    var showvotescounts = true;
                    if (res.styles.show_votes_count == 0) {
                        showvotescounts = false;
                    }

                    //show result percent 
                    var showrespercent = true;
                    if (res.styles.show_res_percent == 0) {
                        showrespercent = false;
                    }

                    var barChartType = typeof res.styles['show_chart_type'] != "undefined" && res.styles['show_chart_type'].length > 0 && res.styles['show_chart_type'] != "" ? res.styles['show_chart_type'] : "default_bar_chart";

                    if(barChartType == "google_bar_chart") {
                        var chartHeight = typeof res.styles['show_chart_type_google_height'] != "undefined" && res.styles['show_chart_type_google_height'] != "" ? res.styles['show_chart_type_google_height'] : 400;
                        var aysBarChartData = new Array(['', '']);
                        $("#"+formId+" .ays_res_mess").fadeIn();
                        for (var tox in widths) {
                            var chartRealVotes = +answer2[widths[tox].index].votes;
                            var answerTextVal   = answer2[widths[tox].index].answer;
                            //var finalAnswerText = ays_poll_restriction_string( 'words', answerTextVal, 2 );
                            answerTextVal   = answerTextVal.replace(/\\/g, '');
                            aysBarChartData.push([
                              answerTextVal,
                              parseInt(chartRealVotes)
                            ]);
                        }

                        google.charts.load('current', {packages: ['corechart', 'bar']});
                        google.charts.setOnLoadCallback(drawBasic);

                        function drawBasic() {
                            var data = google.visualization.arrayToDataTable(aysBarChartData);

                            var groupData = google.visualization.data.group(
                                        data,
                                        [{column: 0, modifier: function () {return 'total'}, type:'string'}],
                                        [{column: 1, aggregation: google.visualization.data.sum, type: 'number'}]
                            );
  
                            var formatPercent = new google.visualization.NumberFormat({
                                pattern: '#%'
                            });
                    
                            var formatShort = new google.visualization.NumberFormat({
                                pattern: 'short'
                            });
                            
                            var view = new google.visualization.DataView(data);
                            view.setColumns([0, 1, {
                                calc: function (dt, row) {
                                    if( groupData.getValue(0, 1) == 0 ){
                                        return amount;
                                    }
                                    var amount =  formatShort.formatValue(dt.getValue(row, 1));
                                    var percent = formatPercent.formatValue(dt.getValue(row, 1) / groupData.getValue(0, 1));
                                    return amount + ' (' + percent + ')';
                                },
                                type: 'string',
                                role: 'annotation'
                            }]);
                            var options = {
                                maxWidth: '100%',
                                height: chartHeight,
                                legend: { position: 'none' },
                                axes: {
                                    x: {
                                      0: { side: 'bottom'}
                                    }
                                },
                                bars: 'horizontal',
                                bar: { groupWidth: "90%" },
                            };

                            var chart = new google.visualization.BarChart(document.getElementById('pollResultId' + pollId));
                            google.visualization.events.addListener(chart, 'ready', function () {
                                $.each($('text[text-anchor="end"]'), function (index, label) {
                                    var labelText = $(label).text();
                                    if ($.isArray(userAnswerValue)) {
                                        for (var i = 0; i < userAnswerValue.length; i++) {
                                            if (labelText == userAnswerValue[i]) {
                                                $(this).css('font-weight', '600');
                                            }
                                        }
                                    } else {
                                      if (labelText == userAnswerValue) {
                                        $(this).css('font-weight', '600');
                                      }
                                    }
                                });
                            });
                            chart.draw(view,options);
                        }
                    } else if (barChartType == "default_bar_chart") {
                        if(typeof data.answer == "string") {
                            var dataAnswerArr = data.answer.split(",")
                        }
                        else {
                            dataAnswerArr = data.answer;
                        }

                        for (var i = 0; i < res.answers.length; i++) {
                            var rightAnswerCheck = (jQuery.inArray(res.answers[widths[i].index].id, dataAnswerArr) !== -1) ? 'ays_check' : '';
                            var starAnswerCheck = (data.answer == res.answers[widths[i].index].id) ? apmIcons.star1 : apmIcons.star;
                            var emojiAnswerCheck = (data.answer == res.answers[widths[i].index].id) ? apmIcons.emoji1 : apmIcons.emoji;
                            var handAnswerCheck = (data.answer == res.answers[widths[i].index].id) ? apmIcons.hand1 : apmIcons.hand;
                            var answer = res.answers;
                            var percentColor = form.attr('data-percent-color');
                            
                            var answerDiv = $('<div class="answer-title flex-apm"></div>'),
                            answerBar = $('<div class="answer-percent" data-percent="'+widths[i].width+'"></div>');
                    if( answerDiv.find('.ayspoll-answers-votes-count-before-voting-width').css('display') == 'none'){
                    answerDiv.find('.ayspoll-answers-votes-count-before-voting-width > span').css('width', '0%');
                    answerDiv.find('.ayspoll-answers-votes-count-before-voting').css('display', 'flex');
                    answerDiv.find('.ayspoll-answers-votes-count-before-voting-width').css('display', 'flex');
                }
                    // answerDiv.find('.ayspoll-answers-votes-count-before-voting').text(res_count);
                    answerDiv.css({
                        'pointer-events': 'none'
                    });
                    setTimeout(function(){
                        answerDiv.find('.ayspoll-answers-votes-count-before-voting-width > span').css('width', notLeloadingRes[answerID].width + '%');
                    }, 300);
                            var userMoreImage;
                            if(res.check_user_pic && res.answers[i].avatar){
                                var userpicsMore = res.answers[widths[i].index].avatar;
                                var userPicsCount = res.check_user_pic_count;
                                var addedMoreImage = "<div class='ays-users-profile-pics'><img src="+res.check_user_pic_url+" width='24' height='24' class='ays-user-image-more' data-answer-id="+res.answers[widths[i].index].id+"></div>";                                
                                if(userpicsMore.length != 0){
                                    userpicsMore = userpicsMore.splice(0 , userPicsCount);
                                    userpicsMore.push(addedMoreImage);
                                }
                                userMoreImage = $('<div class="ays-user-count">'+userpicsMore.join(' ')+'</div>');
                            }

                            if (resultColorsRgba) {
                                answerBar.attr('style', 'background-color:'+hexToRgba(percentColor, widths[i].width/100)+'  !important; border: 1px solid ' + percentColor +' !important;');
                            }
                            else{
                                answerBar.attr('style', 'background-color:'+percentColor);
                            }

                            answerBar.css({
                                width: '1%'
                            });

                            var answerText = '';
                            var pollShowAnswerImage = false;
                            switch (type) {
                                case 'choose':
                                    pollShowAnswerImage = (res.styles.poll_enable_answer_image_after_voting == "on") ? true : false;
                                    if(pollShowAnswerImage){
                                        var answerImage = typeof answer[widths[i].index].answer_img != "undefined" || typeof (answer[widths[i].index].answer_img) != "" ? answer[widths[i].index].answer_img : "";
                                        var answerImageBox = $("<div class='ays-poll-answers-image-box-empty-image'></div>");
                                        var answerImageIsEmptyClass = "ays-poll-answers-box-no-image";
                                        if(answerImage != ""){
                                            answerImageIsEmptyClass = "ays-poll-answers-box";
                                            answerImageBox = $("<div class='ays-poll-answers-image-box'><img src="+answerImage+" class='ays-poll-answers-current-image'></div>");
                                        }
                                        var answerTextAndPercent = $("<div class='ays-poll-answer-text-and-percent-box'></div>");
                                        var answerMainDiv = $('<div class='+answerImageIsEmptyClass+'></div>');
                                    }

                                    answerText = $('<span class="answer-text '+rightAnswerCheck+'"></span>');
                                    var htmlstr = stripSlashes(answer[widths[i].index].answer);

                                    answerText.html(htmlstr);
                                    break;
                                case 'rate':
                                    switch (res.view_type) {
                                        case 'emoji':
                                            answerText = emojiAnswerCheck[res.answers.length / 2 + 1.5 - widths[i].index];
                                            break;

                                        case 'star':
                                            for (var j = 0; j <= widths[i].index; j++) {
                                                answerText += starAnswerCheck;
                                            }
                                            break;
                                    }
                                    answerText = $('<span class="answer-text">'+answerText+'</span>');
                                    break;
                                case 'vote':
                                    switch (res.view_type) {
                                        case 'hand':
                                            answerText = handAnswerCheck[widths[i].index];
                                            break;

                                        case 'emoji':
                                            answerText = emojiAnswerCheck[2 * widths[i].index + 1];
                                            break;
                                    }
                                    answerText = $('<span class="answer-text">'+answerText+'</span>');
                                    break;
                            }
                            
                            var answerVotes = $('<span class="answer-votes"></span>');
                            if(showvotescounts){
                            answerVotes.text(answer[widths[i].index].votes);
                            }
                            if(res.check_admin_approval){
                                if(type == 'choose'){
                                    answerDiv.append("<span class='ays_grid_answer_span' >"+poll_maker_ajax_public.thank_message+"</span>").appendTo("#"+formId+" .results-apm");
                                    break;
                                }
                            }

                            if(!pollShowAnswerImage){
                                answerDiv.append(answerText).append(answerVotes).appendTo("#"+formId+" .results-apm");
                                $("#"+formId+" .results-apm").append(userMoreImage).append(answerBar);
                            }
                            else{
                                answerMainDiv.appendTo("#"+formId+" .results-apm");
                                answerImageBox.appendTo(answerMainDiv);
                                answerTextAndPercent.appendTo(answerMainDiv);
                                answerDiv.append(answerText).append(answerVotes).appendTo(answerTextAndPercent);

                                if(typeof userMoreImage != "undefined"){
                                    answerTextAndPercent.append(userMoreImage);
                                }
                                
                                answerBar.appendTo(answerTextAndPercent); 
                            }

                            $("#"+formId+" .ays_res_mess").fadeIn();
                            $('.redirectionAfterVote').show();

                        }

                        setTimeout(function() {
                            form.find('.answer-percent').each(function () {
                                var percent = $(this).attr('data-percent');

                                $(this).css({
                                    width: (percent || 1) + '%'
                                });

                                if (showrespercent) {
                                    var aaa = $(this);
                                    setTimeout(function() {
                                        aaa.text(percent > 5 ? percent + '%' : '');
                                    }, 200);
                                }
                            });
                        }, 100);
                    }
                }

                // Remove disable from next button for category polls start
                form.parents('.ays_poll_category-container').find('.ays-poll-next-btn').prop('disabled', false);
                var vvv = form.parents('.ays_poll_category-container').attr("data-var");
                window['showNext' + vvv] = true;
                if (typeof(window['catIndex' + vvv]) != 'undefined') {
                    if (typeof(window['pollsGlobalPool'+vvv]) != 'undefined') {
                        if (window['catIndex' + vvv] == window['pollsGlobalPool' + vvv].length - 1) {
                            form.parents('.ays_poll_category-container').find('.ays-poll-next-btn').prop('disabled', true);
                        }
                    }

                    if (window['catIndex' + vvv] == 0 && form.parents('.ays_poll_category-container').find('.results-apm').length > 0) {
                        form.parents('.ays_poll_category-container').find('.ays-poll-previous-btn').prop('disabled', true);
                    }
                }
                // Remove disable from next button for category polls end

                if (form.attr('data-show-social') == 1) {
                    socialBtnAdd(formId, pollSocialButtons);
                }
                if (form.attr('data-enable-social-links') == 1) {
                    socialLinksAdd(formId, pollSocialLinks);
                }
                if (voteURLRedirection == 1) {
                    var url = form.attr('data-url-href');
                    var answerRedirectDelay = +form.attr('data-delay');
                    form.append(redirectMessage);
                    if (url !== '') {
                        setTimeout(function() {
                            location.href = url;
                        } , answerRedirectDelay * 1000);
                    }else{
                        $('.redirectionAfterVote').hide();
                    }                    
                }else{
                    voteURLRedirection = false;
                }
                if (voteRedirection == 1 && voteURLRedirection == false) {
                    var url = form.attr('data-href');
                    var delay = +form.attr('data-delay');
                    form.append(redirectMessage);
                    setTimeout(function() {
                        location.href = url;
                    }, delay * 1000);
                }
                if (isRestart == 'true') {
                    showRestart(formId);
                }

                if(res.check_user_pic){
                    var checkModal = $(document).find(".ays-poll-avatars-modal-main");
                    if(checkModal.length < 1){
                    var avatarsModal = "<div class='ays-poll-avatars-modal-main'>" +
                                            "<div class='ays-poll-avatars-modal-content'>" +
                                                "<div class='ays-poll-avatars-preloader'>" +
                                                    "<img class='ays-poll-avatar-pic-loader' src="+res.check_user_pic_loader+">" +
                                                "</div>" +
                                                "<div class='ays-poll-avatars-modal-header'>" +
                                                    "<span class='ays-close' id='ays-poll-close-avatars-modal'>&times;</span>" +
                                                    "<span style='font-weight: bold;'></span>" +
                                                "</div>" +
                                                "<div class='ays-poll-modal-body' id='ays-poll-avatars-body'></div>" +
                                            "</div>" +
                                        "</div>";
                    $(document.body).append(avatarsModal);
                    }
                }
            },
            error: function () {
                loadEffect(formId, false , loadEffectFontSize,loadEffectMessage);
                $(".user-form-"+formId).fadeOut();
                form.parent().next().prop('disabled', false);
                $('.answer-' + formId).parent().parent().find('.apm-button-box').remove();
                $('.answer-' + formId).remove();
                btn.remove();
                $("#"+formId+" .ays_question").text("Something went wrong. Please reload page.");
            }
        });

    }

    function showRestart(formId) {
        var restartBtn = $('<div class="apm-button-box"><input type="button" class="btn ays-poll-btn btn-restart" onclick="location.reload()" value="'  + poll_maker_ajax_public.restart + '"></div>');
        $("#"+formId).append(restartBtn);
    }

    if ($(document).find('#ays_res_without_see').length > 0) {
        
        let btn = $(document).find('#ays_res_without_see'),
            type = $(document).find('#ays_res_without_see').attr('data-polltype');
            
        voting( btn, type );
    }  

    $(document).on('click', '.ays-poll-btn.choosing-btn', function () {
        voting( $(this), 'choose' );
    });
    $(document).on('click', '.ays-poll-btn.rating-btn', function () {
        voting( $(this), 'rate' );
    });
    $(document).on('click', '.ays-poll-btn.voting-btn', function () {
        voting( $(this), 'vote' );
    });
    $(document).on('click', '.ays-poll-btn.text-btn', function () {
        voting( $(this), 'text' );
    });

    $(document).on('change', '.apm-answers-without-submit input', function () {
        if ($(this).parent().hasClass('apm-rating')) {
            voting($(this).parents('.box-apm').find('.apm-button-box input.ays_finish_poll'), 'rate');
        } else if ($(this).parent().hasClass('apm-voting')) {
            voting($(this).parents('.box-apm').find('.apm-button-box input.ays_finish_poll'), 'vote');
        } else if ($(this).parent().hasClass('apm-choosing')) {
            voting($(this).parents('.box-apm').find('.apm-button-box input.ays_finish_poll'), 'choose');
        }
    })

    function delayCountDown(sec) {
        delaySec = parseInt(sec);
        var intervalSec = setInterval(function() {
            if (delaySec > 0) {
                delaySec--;
                $('.ays-poll-main').find('p.redirectionAfterVote span').text(delaySec);
            } else {
                clearInterval(intervalSec);
            }
        }, 1000);
    }

    function resetPlaying(audelems) {
        audelems.pause();
        audelems.currentTime = 0;
    }

    function validatePhoneNumber(input) {
        var phoneno = /^[+ 0-9-]+$/;
        if (typeof input !== 'undefined') {
            if (input.value.match(phoneno)) {
                return true;
            } else {
                return false;
            }

        }
    }

    /**
     * @return {string}
     */
    function GetFullDateTime(){
        var now = new Date();
        return [[now.getFullYear(), AddZero(now.getMonth() + 1), AddZero(now.getDate())].join("-"), [AddZero(now.getHours()), AddZero(now.getMinutes()), AddZero(now.getSeconds())].join(":")].join(" ");
    }

    /**
     * @return {string}
     */
    function AddZero(num) {
        return (num >= 0 && num < 10) ? "0" + num : num + "";
    }

    function hexToRgba(hex, alfa) {
        var c;
        if (alfa == null) {
            alfa = 1;
        }
        if (/^#([A-Fa-f0-9]{3}){1,2}$/.test(hex)) {
            c= hex.substring(1).split('');
            if(c.length== 3){
                c= [c[0], c[0], c[1], c[1], c[2], c[2]];
            }
            c= '0x'+c.join('');
            return 'rgba('+[(c>>16)&255, (c>>8)&255, c&255].join(',')+','+alfa+')';
        }
    }

        function ays_poll_restriction_string( type, x, length ){
        var tval = '';
        if( x.length > 0 && x != null){
            tval = x.trim();
        }
        switch ( type ) {
            case 'characters':
                break;
            case 'words':
                if(tval.length > 0 && tval != null && tval.length != ''){
                    var wordsLength = tval.match(/\S+/g).length;
                    if (wordsLength > length) {
                        var trimmed = tval.split(/\s+/, length).join(" ");
                        x = trimmed + '...';
                    }
                }
                break;
            default:
                break;
        }
        return x;
    }

    // Avatars modal start

    // Open users avatars modal
    $(document).on('click', '.ays-user-image-more', function(e){
        $(document).find('div.ays-poll-avatars-preloader').css('display', 'flex');
        $(document).find('.ays-poll-avatars-modal-main').aysModal('show');
        var $this = $(this);
        var answer_id = $(this).data('answerId');
        var action = 'ays_poll_get_current_answer_users_pics';
        data = {};
        data.action = action;
        data.answer_id = answer_id;
        $.ajax({
            url: poll_maker_ajax_public.ajax_url,
            dataType: 'json',
            method:'post',
            data: data,
            success: function(response){
                    for(var avatars of response){
                        $('div#ays-poll-avatars-body').append(avatars);

                    }
                    var answerTitle = $this.parents(".ays-user-count").prev().find(".answer-text").html();
                    $(document).find('div.ays-poll-avatars-preloader').css('display', 'none');
                    $(document).find('div.ays-poll-avatars-modal-header span:nth-child(2)').append(answerTitle);
            }
        });
    });

    // Close users avatars modal
    $(document).on('click', '.ays-close', function () {
        $(document).find('.ays-poll-avatars-modal-main').aysModal('hide');
        setTimeout(function(){
            $(document).find('div#ays-poll-avatars-body').html('');
            $(document).find('div.ays-poll-avatars-modal-header span:nth-child(2)').html('');
        }, 250);
    });

    // Cldoe users avatars modal with ESC button
    $(document).on("keydown", function(e){
        if(e.keyCode === 27){
            $(document).find('.ays-close').trigger('click');
            return false;
        }
    });

    if(typeof idChecker !== 'undefined'){
        var checkResShow = $(document).find("#"+idChecker);
        if(checkResShow.data("loadMethod")){
            var checkModal = $(document).find(".ays-poll-avatars-modal-main");
            if(checkModal.length < 1){
                var avatarsModal = "<div class='ays-poll-avatars-modal-main'>" +
                                        "<div class='ays-poll-avatars-modal-content'>" +
                                            "<div class='ays-poll-avatars-preloader'>" +
                                            "<img class='ays-poll-avatar-pic-loader' src="+resLoader+">" +
                                            "</div>" +
                                            "<div class='ays-poll-avatars-modal-header'>" +
                                                "<span class='ays-close' id='ays-poll-close-avatars-modal'>&times;</span>" +
                                                "<span style='font-weight: bold;'></span>" +
                                            "</div>" +
                                            "<div class='ays-poll-modal-body' id='ays-poll-avatars-body'></div>" +
                                        "</div>" +
                                    "</div>";
                $(document.body).append(avatarsModal);
            }
        }
    }
    // Avatars modal end

})(jQuery);