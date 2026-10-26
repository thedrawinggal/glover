/*
 * "Replace an earlier version of a document" lists only documents from the boards
 * chosen in the Board field. ACF's Post Object search doesn't send other
 * fields' values, so this adds them through ACF's own select2_ajax_data hook;
 * glover_document_library_replaces_query() reads them on the server.
 */
(function () {
    'use strict';

    if (!window.acf || typeof window.acf.addFilter !== 'function') {
        return;
    }

    window.acf.addFilter('select2_ajax_data', function (data, args, $input, field) {
        if (!field || field.get('key') !== 'field_glover_document_replaces') {
            return data;
        }

        var boards = window.acf.getFields({ key: 'field_glover_document_boards', parent: field.$el.closest('form') });

        data.glover_boards = boards.length ? boards[0].val() : [];

        return data;
    });
})();
