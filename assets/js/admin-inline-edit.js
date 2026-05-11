// Repopulate Quick Edit fields with the current row's values when the
// inline editor opens for an ial_product. WP only renders the form once;
// our payload comes from hidden divs in the Frontend column.
(function ($) {
    'use strict';

    if (typeof window.inlineEditPost === 'undefined') {
        return;
    }

    var original = window.inlineEditPost.edit;

    window.inlineEditPost.edit = function (id) {
        original.apply(this, arguments);

        var postId = 0;
        if (typeof id === 'object') {
            postId = parseInt(this.getId(id), 10);
        }
        if (!postId) {
            return;
        }

        var $row     = $('#post-' + postId);
        var $editRow = $('#edit-' + postId);
        if (!$editRow.length) {
            return;
        }

        $row.find('.ial-qedit-data').each(function () {
            var key = $(this).data('key');
            var val = String($(this).data('value') == null ? '' : $(this).data('value'));

            if (key === 'frontend_enable') {
                $editRow.find('input[name="frontend_enable"]').prop('checked', val === '1');
            } else if (key === 'acymailing_list_id') {
                $editRow.find('select[name="acymailing_list_id"]').val(val);
            } else if (key === 'assign_roles') {
                var arr = val ? val.split(',').filter(Boolean) : [];
                $editRow.find('select[name="assign_roles[]"] option').each(function () {
                    $(this).prop('selected', arr.indexOf(this.value) !== -1);
                });
            }
        });
    };
})(jQuery);
