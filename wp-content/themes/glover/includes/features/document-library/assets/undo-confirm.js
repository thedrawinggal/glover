/*
 * Undo confirmation (Move back to Pending, Undo replacement).
 *
 * Each undo form opens the shared admin dialog instead of submitting: the
 * heading names the document, focus starts on Cancel, and Escape, Cancel,
 * the close button or a click on the backdrop closes it, returning focus to
 * the button that opened it. Confirming submits the form.
 */
(function () {
    'use strict';

    function init() {
        var dialog = document.getElementById('glover-undo-dialog');

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        var label = dialog.querySelector('[data-glover-undo-label]');
        var heading = dialog.querySelector('[data-glover-undo-heading]');
        var message = dialog.querySelector('[data-glover-undo-message]');
        var note = dialog.querySelector('[data-glover-undo-note]');
        var defaultNote = note ? note.textContent : '';
        var ok = dialog.querySelector('[data-glover-undo-ok]');
        var cancel = dialog.querySelector('.glover-compare__actions [data-glover-undo-close]');
        var activeForm = null;
        var opener = null;

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!form.classList || !form.classList.contains('glover-undo-form') || form.classList.contains('glover-undo-form--direct') || form.gloverConfirmed) {
                return;
            }

            event.preventDefault();
            activeForm = form;
            opener = event.submitter || form.querySelector('button[type="submit"]');
            label.textContent = form.getAttribute('data-glover-undo-label');
            heading.textContent = form.getAttribute('data-glover-undo-title');
            message.textContent = form.getAttribute('data-glover-undo-message');
            ok.textContent = form.getAttribute('data-glover-undo-confirm');
            ok.classList.toggle('glover-dialog-danger', form.hasAttribute('data-glover-undo-danger'));
            if (note) {
                note.textContent = form.getAttribute('data-glover-undo-note') || defaultNote;
            }
            dialog.showModal();
            cancel.focus();
        });

        ok.addEventListener('click', function () {
            if (!activeForm) {
                return;
            }
            var form = activeForm;
            form.gloverConfirmed = true;
            opener = null;
            ok.disabled = true;
            dialog.close();
            form.submit();
        });

        dialog.querySelectorAll('[data-glover-undo-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                dialog.close();
            });
        });

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', function () {
            activeForm = null;
            if (opener) {
                opener.focus();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
