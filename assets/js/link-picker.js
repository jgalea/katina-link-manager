(function($) {
    'use strict';

    $(document).on('tinymce-editor-setup', function(event, editor) {
        editor.addButton('katina-link-manager', {
            title: 'Insert Katina Link Manager Link',
            icon: 'link',
            onclick: function() {
                openLinkPicker(editor);
            }
        });
    });

    function openLinkPicker(editor) {
        var $modal = $('<div class="katina-picker-modal"><div class="katina-picker-inner">' +
            '<h2>Insert Katina Link Manager Link</h2>' +
            '<input type="text" class="katina-picker-search" placeholder="Search links&hellip;" autofocus />' +
            '<div class="katina-picker-results"><p>Type to search your links.</p></div>' +
            '<div class="katina-picker-footer"><button class="button katina-picker-close">Cancel</button></div>' +
            '</div></div>');

        $('body').append($modal);

        $modal.on('click', '.katina-picker-close', function() { $modal.remove(); });
        $modal.on('click', function(e) { if (e.target === this) $modal.remove(); });
        $(document).on('keydown.katina-picker', function(e) {
            if (e.key === 'Escape') { $modal.remove(); $(document).off('keydown.katina-picker'); }
        });

        $modal.on('input', '.katina-picker-search', function() {
            var search = $(this).val();
            if (search.length < 2) {
                $modal.find('.katina-picker-results').empty();
                return;
            }
            $.get(katinaEditor.ajaxUrl, {
                action: 'katina_search_links',
                nonce: katinaEditor.nonce,
                search: search
            }, function(response) {
                if (!response.success) return;
                var html = '';
                response.data.forEach(function(item) {
                    html += '<div class="katina-picker-item" data-url="' + item.url + '" data-title="' + item.title + '">' +
                        '<strong>' + item.title + '</strong> <code>' + item.url + '</code></div>';
                });
                $modal.find('.katina-picker-results').html(html || '<p>No links found.</p>');
            });
        });

        $modal.on('click', '.katina-picker-item', function() {
            var url = $(this).data('url');
            var title = $(this).data('title');
            var selected = editor.selection.getContent() || title;
            editor.insertContent('<a href="' + url + '">' + selected + '</a>');
            $modal.remove();
        });
    }

})(jQuery);
