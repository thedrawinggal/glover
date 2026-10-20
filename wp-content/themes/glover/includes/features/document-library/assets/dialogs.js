/*
 * Turns [data-glover-dialog] sections into modal dialogs.
 *
 * Without JavaScript the sections stay on the page and their links jump to
 * them. With it, each section moves into a native <dialog>: focus moves to
 * its heading on open, Escape or the Close button closes it, and focus
 * returns to the link that opened it.
 */
(function () {
    'use strict';

    function focusOnLoad() {
        var target = document.querySelector('[data-focus-on-load]');
        if (target) {
            target.focus();
        }
    }

    function upgrade(section) {
        var dialog = document.createElement('dialog');
        var heading = section.querySelector('h2');
        var close = document.createElement('button');
        var opener = null;

        dialog.className = 'dialog';
        dialog.id = section.id + '-dialog';
        if (heading && heading.id) {
            dialog.setAttribute('aria-labelledby', heading.id);
        }

        close.type = 'button';
        close.className = 'dialog__close';
        close.textContent = 'Close';

        section.parentNode.insertBefore(dialog, section);
        dialog.appendChild(close);
        dialog.appendChild(section);
        section.removeAttribute('aria-labelledby');

        close.addEventListener('click', function () {
            dialog.close();
        });

        // Clicking the dimmed backdrop closes the dialog too.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener('close', function () {
            if (opener) {
                opener.focus();
            }
        });

        document.querySelectorAll('[data-dialog-open="' + section.id + '"]').forEach(function (trigger) {
            trigger.setAttribute('aria-haspopup', 'dialog');
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                opener = trigger;
                dialog.showModal();
                if (heading) {
                    heading.focus();
                }
            });
        });
    }

    function init() {
        if (typeof HTMLDialogElement === 'function') {
            document.querySelectorAll('[data-glover-dialog]').forEach(upgrade);
        }
        focusOnLoad();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
