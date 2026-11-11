(function($) {
    'use strict';

    /* Copy-to-clipboard for cloaked URL button */
    $(document).on('click', '.katina-copy-btn', function(e) {
        e.preventDefault();
        var $btn   = $(this);
        var $label = $btn.find('.katina-copy-label');
        var $code  = $( $btn.data('clipboard-target') );
        var text   = $code.length ? $code.text().trim() : '';

        if ( ! text ) {
            return;
        }

        navigator.clipboard.writeText(text).then(function() {
            var original = $label.text();
            $btn.addClass('katina-copied');
            $label.text(katinaAdmin.i18n.copied);
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');

            setTimeout(function() {
                $btn.removeClass('katina-copied');
                $label.text(original);
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        });
    });

    /* Click-to-copy on cloaked URL codes in list table */
    $(document).on('click', '.column-katina_cloaked_url code', function() {
        var $el  = $(this);
        var text = $el.data('url');

        navigator.clipboard.writeText(text).then(function() {
            $el.addClass('katina-code-copied');
            setTimeout(function() {
                $el.removeClass('katina-code-copied');
            }, 800);
        });
    });

    /* Show/hide toggle for password-style fields (e.g. AI API key) */
    $(document).on('click', '.katina-toggle-visibility', function(e) {
        e.preventDefault();
        var $btn   = $(this);
        var $field = $('#' + $btn.data('target'));
        if ( ! $field.length ) return;
        var showing = $field.attr('type') === 'text';
        $field.attr('type', showing ? 'password' : 'text');
        $btn.find('.dashicons')
            .removeClass('dashicons-visibility dashicons-hidden')
            .addClass(showing ? 'dashicons-visibility' : 'dashicons-hidden');
    });

    /* Slug field: live URL preview, short slug generator and conflict feedback */
    var $slug = $('#katina_slug');
    if ($slug.length) {
        var $preview  = $('#katina-slug-preview');
        var $feedback = $('#katina-slug-feedback');
        var timer;

        var clean = function(value) {
            return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        };

        var check = function() {
            var slug = clean($slug.val());
            $preview.text(katinaAdmin.homeUrl + katinaAdmin.prefix + '/' + (slug || '...') + '/');
            clearTimeout(timer);
            timer = setTimeout(function() {
                $.post(katinaAdmin.ajaxUrl, {
                    action: 'katina_check_slug',
                    nonce: katinaAdmin.slugNonce,
                    slug: slug,
                    post_id: $slug.data('post-id')
                }).done(function(response) {
                    $feedback.text(response && response.success ? response.data.messages.join(' ') : '');
                });
            }, 350);
        };

        $slug.on('input', check);
        $('#katina-generate-slug').on('click', function() {
            var chars = 'abcdefghijkmnpqrstuvwxyz23456789';
            var out = '';
            for (var i = 0; i < 6; i++) {
                out += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            $slug.val(out);
            check();
        });
    }

})(jQuery);
