(function($) {
    'use strict';

    function run($form, dryRun) {
        var $progress = $form.find('.katina-migrate-progress');
        var $bar      = $form.find('.katina-progress-bar');
        var $status   = $form.find('.katina-migrate-status');
        var $result   = $form.find('.katina-migrate-result');
        var $buttons  = $form.find('input[type=submit], button[type=submit]');
        var source    = $form.find('input[name=katina_migrate_source]').val();

        var payload = {
            action: 'katina_migrate_step',
            nonce: katinaMigration.nonce,
            source: source,
            dry_run: dryRun ? 1 : 0,
            scan: $form.find('input[name=katina_scan_content]').is(':checked') ? 1 : 0,
            keep_history: $form.find('input[name=katina_keep_history]').is(':checked') ? 1 : 0,
            phase: 'links',
            cursor: 0
        };

        $buttons.prop('disabled', true);
        $result.empty();
        $bar.css('inline-size', '4%');
        $status.text(katinaMigration.i18n.starting);
        $progress.prop('hidden', false);

        function fail() {
            $status.text(katinaMigration.i18n.failed);
            $buttons.prop('disabled', false);
        }

        function step() {
            $.post(katinaMigration.ajaxUrl, payload).done(function(response) {
                if (!response || !response.success) {
                    fail();
                    return;
                }
                var data = response.data;
                $status.text(data.status);
                if (data.done) {
                    $bar.css('inline-size', '100%');
                    $progress.prop('hidden', true);
                    $result.html(data.html);
                    $buttons.prop('disabled', false);
                    return;
                }
                payload.phase  = data.next.phase;
                payload.cursor = data.next.cursor;
                var width = parseFloat($bar[0].style.inlineSize) || 4;
                $bar.css('inline-size', Math.min(95, width + (100 - width) * 0.15) + '%');
                step();
            }).fail(fail);
        }

        step();
    }

    $(document).on('submit', '.katina-migrate-form', function(e) {
        e.preventDefault();
        run($(this), false);
    });

    $(document).on('click', '.katina-migrate-form input[name=katina_dry_run]', function(e) {
        e.preventDefault();
        run($(this).closest('form'), true);
    });

    $(function() {
        var auto = katinaMigration.autostart;
        if (!auto) {
            return;
        }
        var $form = $('.katina-migrate-form').filter(function() {
            return $(this).find('input[name=katina_migrate_source]').val() === auto.source;
        }).first();
        if (!$form.length) {
            return;
        }
        $form.find('input[name=katina_scan_content]').prop('checked', !!auto.scan);
        $form[0].scrollIntoView({ block: 'center' });
        if (auto.run && window.confirm(katinaMigration.i18n.confirm)) {
            run($form, false);
        }
    });

})(jQuery);
