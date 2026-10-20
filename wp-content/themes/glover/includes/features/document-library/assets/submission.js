/*
 * Submission form: show "Minutes status" only when the Minutes category is
 * chosen. The PHP validation still requires it for minutes either way.
 */
(function () {
    'use strict';

    var config = window.gloverDocumentSubmission || {};

    function init() {
        var form = document.getElementById('glover-document-submission');
        var statusField = form ? form.querySelector('.acf-field[data-key="field_glover_document_minutes_status"]') : null;
        var categoryField = form ? form.querySelector('.acf-field[data-key="field_glover_document_category"]') : null;

        if (!statusField || !categoryField || !config.minutesTermId) {
            return;
        }

        function update() {
            var checked = categoryField.querySelector('input[type="radio"]:checked');
            statusField.hidden = !(checked && Number(checked.value) === Number(config.minutesTermId));
        }

        categoryField.addEventListener('change', update);
        update();
    }

    if (window.acf && typeof window.acf.addAction === 'function') {
        window.acf.addAction('ready', init);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
