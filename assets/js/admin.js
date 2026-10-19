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
            $label.text('Copied!');
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
        var text = window.location.origin + $el.text().trim();

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

})(jQuery);
