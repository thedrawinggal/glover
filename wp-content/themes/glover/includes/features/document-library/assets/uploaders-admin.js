/*
 * Document Library > Settings: the Add a new person dialog on the Uploaders tab.
 *
 * Opens the dialog and keeps the board list in step with All boards. It
 * never clicks or reloads anything on its own.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var dialog = document.getElementById('glover-add-uploader');
        var opener = document.querySelector('[data-glover-add-uploader]');

        if (dialog && opener && typeof dialog.showModal === 'function') {
            var all = dialog.querySelector('[data-glover-all-boards]');
            var list = dialog.querySelector('[data-glover-board-list]');

            opener.setAttribute('aria-haspopup', 'dialog');
            opener.addEventListener('click', function () {
                dialog.showModal();
                dialog.querySelector('input[name="uploader_name"]').focus();
            });

            dialog.querySelectorAll('[data-glover-close]').forEach(function (button) {
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
                opener.focus();
            });

            all.addEventListener('change', function () {
                list.hidden = all.checked;
            });
        }
    });
}());
