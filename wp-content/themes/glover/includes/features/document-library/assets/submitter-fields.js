/*
 * Add New User, Edit User and Profile: show the Document Submitter fields
 * only for the roles that use them.
 *
 * ACF evaluates its User Role location rule only when the page loads, so
 * this shows the section as soon as Role is set to Document Submitter or
 * Administrator, and hides it for any other role. While hidden, its inputs
 * are disabled so they aren't saved. Opened with Add as Document Submitter,
 * it chooses the Document Submitter role for the person, which the admin
 * then saves or changes. It never submits anything on its own.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var settings = window.gloverSubmitterFields || {};
        var field = document.querySelector('.acf-field-glover-user-submitter-all-boards');
        var table = field ? field.closest('table') : null;
        var heading = table ? table.previousElementSibling : null;
        var role = document.getElementById('role');

        // No Role menu (your own profile): leave the section as ACF shows it.
        if (!table || !role) {
            return;
        }

        // In the User Screens layout the fields are their own section, with
        // a link to it in the header.
        var section = table.closest('.glover-user-section');
        var link = section ? document.querySelector('.glover-user-header__sections a[href="#' + section.id + '"]') : null;
        var parts = section ? [section] : [table];

        if (link) {
            parts.push(link.parentNode);
        }

        if (!section && heading && heading.tagName === 'H2') {
            parts.push(heading);
        }

        function update() {
            var show = (settings.roles || []).indexOf(role.value) !== -1;

            parts.forEach(function (part) {
                part.hidden = !show;
            });

            table.querySelectorAll('input, select, textarea').forEach(function (input) {
                if (!show) {
                    if (!input.disabled) {
                        input.disabled = true;
                        input.dataset.gloverDisabled = 'yes';
                    }
                } else if (input.dataset.gloverDisabled === 'yes') {
                    input.disabled = false;
                    delete input.dataset.gloverDisabled;
                }
            });
        }

        if (settings.chooseSubmitter && role.querySelector('option[value="' + settings.submitterRole + '"]')) {
            role.value = settings.submitterRole;
        }

        role.addEventListener('change', update);
        update();
    });
}());
