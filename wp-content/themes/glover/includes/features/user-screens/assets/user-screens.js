/*
 * Profile, Edit User and Add New User: the town's layout.
 *
 * Moves WordPress's own rows out of its tables into open sections under a
 * header (printed by user-screens.php as #glover-user-header). Rows are
 * moved, never copied, and keep their classes and ids, so WordPress's
 * password and application password scripts and ACF work as before and the
 * form posts the same fields. Runs as soon as it loads, before
 * DOMContentLoaded, so submitter-fields.js and WordPress's scripts find the
 * finished layout. Without JavaScript the screen keeps WordPress's layout.
 */
(function () {
    'use strict';

    var settings = window.gloverUserScreens || {};
    var form = document.getElementById(settings.screen === 'new' ? 'createuser' : 'your-profile');
    var template = document.getElementById('glover-user-header');
    var wrap = form ? form.closest('.wrap') : null;

    if (!form || !template || !wrap) {
        return;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text) {
            node.textContent = text;
        }

        return node;
    }

    // A row's name: its user-<name>-wrap (or user-<name>) class, else its
    // control's id.
    function rowName(row) {
        var match = row.className.match(/(?:^|\s)user-([a-z0-9-]+?)(?:-wrap)?(?:\s|$)/);
        var control;

        if (match) {
            return match[1];
        }

        if (row.classList.contains('pw-weak')) {
            return 'pw-weak';
        }

        control = row.querySelector('input[id], select[id], textarea[id]');

        return control ? control.id.replace(/_/g, '-') : '';
    }

    // A table row as a field: its heading cell, then its control cell.
    function toField(row, name, isAction) {
        var field = el('div', (row.className + ' glover-user-field').trim());
        var title = el('div', 'glover-user-field__title');
        var control = el('div', 'glover-user-field__control');
        var heading = row.querySelector(':scope > th');
        var cell = row.querySelector(':scope > td');

        if (row.id) {
            field.id = row.id;
        }

        field.dataset.gloverField = name;

        while (heading && heading.firstChild) {
            title.appendChild(heading.firstChild);
        }

        while (cell && cell.firstChild) {
            control.appendChild(cell.firstChild);
        }

        if (isAction) {
            field.classList.add('glover-user-field--action');
            control.querySelectorAll(':scope > p.description').forEach(function (description) {
                title.appendChild(description);
            });
        } else if (!title.querySelector('label') && control.querySelector('input[type="checkbox"]')) {
            field.classList.add('glover-user-field--check');
        }

        if (title.textContent.trim() !== '' || title.children.length) {
            field.appendChild(title);
        }

        field.appendChild(control);
        row.remove();

        return field;
    }

    function section(id, title, intro) {
        var node = el('section', 'glover-user-section');
        var text = el('div', 'glover-user-section__text');
        var heading = el('h2', '', title);
        var body = el('div', 'glover-user-section__fields');

        node.id = id;
        heading.id = id + '-heading';
        node.setAttribute('aria-labelledby', heading.id);
        text.appendChild(heading);

        if (intro) {
            text.appendChild(el('p', 'glover-user-section__intro', intro));
        }

        node.appendChild(text);
        node.appendChild(body);

        return node;
    }

    try {
        var rows = {};
        var holder = el('div', 'glover-user-hidden');
        var layout = el('div', 'glover-user-sections');
        var built = [];
        var header = template.content.firstElementChild.cloneNode(true);
        var actions = header.querySelector('.glover-user-header__actions');
        var submit = form.querySelector('p.submit');
        var headerEnd = wrap.querySelector('.wp-header-end');

        holder.hidden = true;

        form.querySelectorAll('table.form-table > tbody > tr, table.form-table > tr').forEach(function (row) {
            var name = rowName(row);

            if (name && !rows[name]) {
                rows[name] = row;
            }
        });

        (settings.hidden || []).forEach(function (name) {
            if (rows[name]) {
                holder.appendChild(rows[name]);
                delete rows[name];
            }
        });

        (settings.sections || []).forEach(function (config) {
            var node = section(config.id, config.title, config.intro);
            var body = node.querySelector('.glover-user-section__fields');

            (config.rows || []).forEach(function (name) {
                if (rows[name]) {
                    body.appendChild(toField(rows[name], name, (config.actions || []).indexOf(name) !== -1));
                    delete rows[name];
                }
            });

            (config.blocks || []).forEach(function (selector) {
                var block = form.querySelector(selector);

                if (block) {
                    block.classList.add('glover-user-block');
                    body.appendChild(block);
                }
            });

            if (body.children.length) {
                built.push(node);
            }
        });

        // Every other group (Document Submitter, a plugin's fields) keeps its
        // own table and becomes a section titled by its heading, after
        // Profile. A group left empty is removed with its heading.
        var extras = [];

        form.querySelectorAll('table.form-table').forEach(function (table) {
            var heading = table.previousElementSibling;
            var title = heading && heading.tagName === 'H2' ? heading.textContent.trim() : '';
            var node;

            if (!table.querySelector('tr')) {
                if (title) {
                    heading.remove();
                }

                table.remove();

                return;
            }

            if (!title) {
                return;
            }

            node = section('glover-user-' + title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''), title, '');
            table.classList.add('glover-user-table');
            node.querySelector('.glover-user-section__fields').appendChild(table);
            heading.remove();
            extras.push(node);
        });

        Array.prototype.splice.apply(built, [1, 0].concat(extras));

        // Headings WordPress left with nothing under them.
        form.querySelectorAll(':scope > h2, :scope > h3').forEach(function (heading) {
            heading.remove();
        });

        built.forEach(function (node) {
            layout.appendChild(node);
        });

        var firstTable = form.querySelector(':scope > table.form-table');

        form.insertBefore(layout, firstTable || (submit && submit.parentNode === form ? submit : null));
        form.appendChild(holder);
        form.classList.add('glover-user-screen');

        // The header replaces WordPress's heading and, on Add New User, its
        // introduction. The save button moves into it and stays tied to the
        // form with the form attribute.
        wrap.querySelectorAll(':scope > h1, :scope > .page-title-action, :scope > #create-new-user').forEach(function (node) {
            node.remove();
        });

        if (settings.screen === 'new' && form.previousElementSibling && form.previousElementSibling.tagName === 'P') {
            form.previousElementSibling.remove();
        }

        if (submit) {
            submit.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(function (button) {
                button.setAttribute('form', form.id);
                actions.appendChild(button);
            });
            submit.remove();
        }

        wrap.insertBefore(header, wrap.firstChild);

        // WordPress places notices after .wp-header-end.
        if (headerEnd) {
            header.after(headerEnd);
        }

        // Section links, with the one in view marked current.
        var list = header.querySelector('.glover-user-header__sections ul');

        if (list) {
            var links = built.map(function (node) {
                var item = el('li');
                var link = el('a', '', node.querySelector('h2').textContent);

                link.href = '#' + node.id;
                item.appendChild(link);
                list.appendChild(item);

                return link;
            });

            var mark = function (id) {
                links.forEach(function (link) {
                    if (link.getAttribute('href') === '#' + id) {
                        link.setAttribute('aria-current', 'true');
                    } else {
                        link.removeAttribute('aria-current');
                    }
                });
            };

            mark(built.length ? built[0].id : '');

            if ('IntersectionObserver' in window) {
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            mark(entry.target.id);
                        }
                    });
                }, { rootMargin: '0px 0px -70% 0px' });

                built.forEach(function (node) {
                    observer.observe(node);
                });
            }
        }

        // The Role label in the header follows the Role menu.
        var role = document.getElementById('role');
        var roleLabel = header.querySelector('[data-glover-role-label]');

        if (role && roleLabel) {
            role.addEventListener('change', function () {
                roleLabel.textContent = role.options[role.selectedIndex].text;
            });
        }
    } finally {
        // Shown either way, so a failure never leaves the form hidden.
        document.documentElement.classList.add('glover-user-ready');
    }
}());
