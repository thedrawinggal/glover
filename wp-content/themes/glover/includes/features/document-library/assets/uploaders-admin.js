/*
 * Document Library > Settings: the Uploaders tab's user dialog.
 *
 * Add User and Edit User open WordPress's own Add New User and Edit
 * User screens in the dialog. When a save there lands on a page marked
 * glover_saved, the dialog closes and the settings page reloads so the list
 * shows the change. It never clicks or submits anything on its own.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var dialog = document.getElementById('glover-user-dialog');
        var openers = document.querySelectorAll('[data-glover-user-dialog]');

        if (!dialog || !openers.length || typeof dialog.showModal !== 'function') {
            return;
        }

        var frame = dialog.querySelector('iframe');
        var title = document.getElementById('glover-user-dialog-title');
        var lastOpener = null;
        var saved = false;

        openers.forEach(function (opener) {
            opener.setAttribute('aria-haspopup', 'dialog');
            opener.addEventListener('click', function () {
                lastOpener = opener;
                title.textContent = opener.getAttribute('data-glover-user-dialog-title');
                frame.src = opener.getAttribute('data-glover-user-dialog');
                dialog.showModal();
                title.focus();
            });
        });

        frame.addEventListener('load', function () {
            var search = '';

            try {
                search = frame.contentWindow.location.search;
            } catch (error) {
                return;
            }

            if (new URLSearchParams(search).has('glover_saved')) {
                saved = true;
                dialog.close();
            }
        });

        dialog.querySelectorAll('[data-glover-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                dialog.close();
            });
        });

        dialog.addEventListener('close', function () {
            frame.src = 'about:blank';

            if (saved) {
                window.location.reload();
            } else if (lastOpener) {
                lastOpener.focus();
            }
        });
    });
}());
