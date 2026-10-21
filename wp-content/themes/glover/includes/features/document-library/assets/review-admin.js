/*
 * Documents screen: the replacement compare dialog.
 *
 * Compare links open their native <dialog> (without JavaScript they open the
 * same comparison as a page). Escape, the Close button or a click on the
 * backdrop closes it, and focus returns to the link that opened it.
 *
 * In each comparison form: choosing a replacement shows its details, the
 * Keep current / Use proposed choices restyle their row, the title can be
 * edited in place, and the footer sentence follows the choices. Return opens
 * a reason box in place of the approve controls.
 */
(function () {
    'use strict';

    function names(list) {
        if (list.length < 2) {
            return list.join('');
        }
        return list.slice(0, -1).join(', ') + ' and ' + list[list.length - 1];
    }

    function plural(count, one, many) {
        return count + ' ' + (count === 1 ? one : many);
    }

    function setUpDialogs() {
        document.querySelectorAll('[data-glover-open]').forEach(function (link) {
            var dialog = document.getElementById(link.getAttribute('data-glover-open'));

            if (!dialog || typeof dialog.showModal !== 'function') {
                return;
            }

            link.addEventListener('click', function (event) {
                event.preventDefault();
                dialog.showModal();
                var heading = dialog.querySelector('h2');
                if (heading) {
                    heading.focus();
                }
                dialog.gloverOpener = link;
            });
        });

        document.querySelectorAll('.glover-compare-dialog').forEach(function (dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });

            // Escape while reading Check text goes back to the comparison first.
            // Handled on keydown: browsers may refuse to cancel the close itself.
            dialog.addEventListener('keydown', function (event) {
                var form = dialog.querySelector('[data-glover-compare]');
                if (event.key === 'Escape' && form && form.gloverCheckOpen && form.gloverCheckOpen()) {
                    event.preventDefault();
                    form.gloverCheckHide();
                }
            });

            dialog.addEventListener('close', function () {
                if (dialog.gloverOpener) {
                    dialog.gloverOpener.focus();
                }
            });

            dialog.querySelectorAll('[data-glover-close]').forEach(function (button) {
                button.addEventListener('click', function () {
                    dialog.close();
                });
            });
        });
    }

    function setUpTitle(row) {
        var view = row.querySelector('[data-glover-title-view]');
        var text = row.querySelector('[data-glover-title-text]');
        var tag = row.querySelector('[data-glover-title-tag]');
        var editButton = row.querySelector('[data-glover-title-edit]');
        var field = row.querySelector('[data-glover-title-field]');
        var input = field.querySelector('input');
        var buttons = field.querySelector('.glover-compare__title-buttons');
        var proposed = input.getAttribute('data-proposed');
        var saved = input.value;

        function show(editing) {
            view.hidden = editing;
            field.hidden = !editing;
            if (editing) {
                input.focus();
            } else {
                editButton.focus();
            }
        }

        editButton.hidden = false;
        buttons.hidden = false;
        field.hidden = true;

        editButton.addEventListener('click', function () {
            saved = input.value;
            show(true);
        });

        field.querySelector('[data-glover-title-done]').addEventListener('click', function () {
            input.value = input.value.trim() || proposed;
            text.textContent = input.value;
            tag.textContent = input.value === proposed ? 'Changed' : 'Edited';
            row.querySelector('input[value="proposed"]').checked = true;
            row.querySelector('input[value="proposed"]').dispatchEvent(new Event('change', { bubbles: true }));
            show(false);
        });

        field.querySelector('[data-glover-title-cancel]').addEventListener('click', function () {
            input.value = saved;
            show(false);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                field.querySelector('[data-glover-title-done]').click();
            }
        });
    }

    // Preview and Check text swap the comparison for the file (PDFs in a
    // frame, other files as text) or its text, inside the same dialog (or
    // page); Back to comparison or Escape swaps it back.
    function setUpCheck(form, approveArea, returnArea) {
        var view = form.querySelector('[data-glover-check-view]');
        var body = form.querySelector('[data-glover-check-body]');
        var heading = form.querySelector('[data-glover-check-heading]');
        var compareBody = form.querySelector('[data-glover-compare-body]');
        var opener = null;
        var footer = null;
        var request = 0;

        function show(link, mode) {
            opener = link;
            footer = approveArea.hidden ? returnArea : approveArea;
            compareBody.hidden = true;
            approveArea.hidden = true;
            returnArea.hidden = true;
            view.hidden = false;
            heading.textContent = (mode === 'preview' ? 'Preview: ' : 'Check text: ') + link.getAttribute('data-glover-check-title');
            heading.focus();

            var current = ++request;
            var id = link.getAttribute(mode === 'preview' ? 'data-glover-view-file' : 'data-glover-check');
            var lead = '';

            if (mode === 'preview' && link.getAttribute('data-ext') === 'pdf') {
                var frame = document.createElement('iframe');
                frame.className = 'glover-preview-dialog__frame';
                frame.title = 'Preview of ' + link.getAttribute('data-glover-check-title');
                frame.src = link.href;
                body.innerHTML = '';
                body.appendChild(frame);
                return;
            }

            if (mode === 'preview' && isImage(link.getAttribute('data-ext'))) {
                body.innerHTML = '';
                body.appendChild(previewImage(link.href, link.getAttribute('data-glover-check-title')));
                return;
            }

            if (mode === 'preview') {
                lead = '<p class="glover-compare__muted">Word and Excel files can\u2019t be shown in the browser, so this is the text inside the file. <a href="' + link.href + '" target="_blank" rel="noopener">Open in new tab</a></p>';
            }

            body.setAttribute('aria-busy', 'true');
            body.innerHTML = lead + '<p class="glover-compare__muted">Reading the file\u2026</p>';

            var url = form.getAttribute('data-check-url') + '?action=glover_document_check_text&post=' +
                encodeURIComponent(id) + '&_wpnonce=' + encodeURIComponent(form.getAttribute('data-check-nonce'));

            fetch(url, { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(response.status);
                    }
                    return response.text();
                })
                .then(function (html) {
                    if (current === request) {
                        body.innerHTML = lead + html;
                    }
                })
                .catch(function () {
                    if (current === request) {
                        body.innerHTML = '<p>The text could not be read here. <a href="' + link.href + '">Open Check text as a page</a>.</p>';
                    }
                })
                .then(function () {
                    body.removeAttribute('aria-busy');
                });
        }

        function hide() {
            request++;
            view.hidden = true;
            compareBody.hidden = false;
            footer.hidden = false;
            body.innerHTML = '';
            if (opener) {
                opener.focus();
            }
        }

        form.gloverCheckOpen = function () {
            return !view.hidden;
        };
        form.gloverCheckHide = hide;

        form.querySelectorAll('[data-glover-check]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                show(link, 'check');
            });
        });

        form.querySelectorAll('[data-glover-view-file]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                show(link, 'preview');
            });
        });

        form.querySelector('[data-glover-check-back]').addEventListener('click', hide);
    }

    function setUpForm(form) {
        var multiple = form.getAttribute('data-multiple') === '1';
        var picks = Array.prototype.slice.call(form.querySelectorAll('input[name="replacement"][type="radio"]'));
        var details = Array.prototype.slice.call(form.querySelectorAll('.glover-compare__details'));
        var summary = form.querySelector('[data-glover-summary]');
        var approveArea = form.querySelector('[data-glover-approve-area]');
        var returnArea = form.querySelector('[data-glover-return-area]');
        var reason = returnArea.querySelector('textarea');
        var returnButton = returnArea.querySelector('button[type="submit"]');
        var othersReason = form.querySelector('input[name="others_reason"]');

        form.classList.add('is-enhanced');

        function chosen() {
            var checked = picks.filter(function (radio) {
                return radio.checked;
            })[0];
            return checked || picks[0] || null;
        }

        function currentDetails() {
            var pick = chosen();
            if (!pick) {
                return details[0];
            }
            return details.filter(function (block) {
                return block.getAttribute('data-replacement') === pick.value;
            })[0];
        }

        function update() {
            var pick = chosen();
            var block = currentDetails();

            details.forEach(function (item) {
                item.hidden = item !== block;
            });

            var proposed = 0;
            var kept = 0;

            block.querySelectorAll('tr[data-detail]').forEach(function (row) {
                var keep = row.querySelector('input[value="current"]').checked;
                row.classList.toggle('glover-compare__row--keep', keep);
                if (keep) {
                    kept++;
                } else {
                    proposed++;
                }
            });

            var text = 'Approving publishes ' + (multiple ? pick.getAttribute('data-name') + '’s file' : 'the new file');
            if (proposed) {
                text += ' and ' + plural(proposed, 'proposed detail', 'proposed details');
            }
            text += '.';
            if (kept) {
                text += ' ' + plural(kept, 'detail stays', 'details stay') + ' as published.';
            }
            text += ' The page keeps its address, and the current file moves to Previous versions.';

            if (multiple) {
                var others = picks.filter(function (radio) {
                    return radio !== pick;
                });
                var otherNames = others.map(function (radio) {
                    return radio.getAttribute('data-name');
                });
                var people = others.map(function (radio) {
                    return radio.getAttribute('data-person');
                }).filter(function (name, index, list) {
                    return list.indexOf(name) === index;
                });

                text += ' ' + names(otherNames) + (others.length === 1 ? ' goes back to its submitter' : ' go back to their submitters') + ' with the reason below.';
                form.querySelector('[data-glover-others-label]').textContent = 'Reason for returning ' + names(otherNames);
                form.querySelector('[data-glover-others-people]').textContent = names(people);
                form.querySelector('[data-glover-approve-label]').textContent = 'Approve ' + pick.getAttribute('data-name');
                form.querySelector('[data-glover-edit-link]').href = pick.getAttribute('data-edit-url');
                form.querySelector('[data-glover-edit-name]').textContent = ' ' + pick.getAttribute('data-name');
            }

            summary.textContent = text;
        }

        function showReturn(open) {
            approveArea.hidden = open;
            returnArea.hidden = !open;
            reason.required = open;
            if (othersReason) {
                othersReason.required = !open;
            }
            if (open) {
                reason.focus();
            } else {
                form.querySelector('[data-glover-return-open]').focus();
            }
        }

        form.querySelectorAll('tr[data-detail="title"]').forEach(setUpTitle);
        setUpCheck(form, approveArea, returnArea);

        form.addEventListener('change', function (event) {
            if (event.target.matches('input[type="radio"]')) {
                update();
            }
        });

        returnButton.removeAttribute('formnovalidate');
        returnArea.hidden = true;
        form.querySelector('[data-glover-return-open]').hidden = false;
        form.querySelector('[data-glover-return-cancel]').hidden = false;

        form.querySelector('[data-glover-return-open]').addEventListener('click', function () {
            showReturn(true);
        });
        form.querySelector('[data-glover-return-cancel]').addEventListener('click', function () {
            showReturn(false);
        });

        update();
    }

    // Preview document: PDFs in a frame, Word and Excel as their text (from
    // the Check text report), with the row's actions in the footer.
    function isImage(ext) {
        return ext === 'jpg' || ext === 'jpeg';
    }

    // An image file shown whole in a preview. Its alt text names the document;
    // the image itself is what is being reviewed.
    function previewImage(src, title) {
        var image = document.createElement('img');
        image.className = 'glover-preview-dialog__image';
        image.src = src;
        image.alt = 'Preview of ' + title;
        return image;
    }

    function setUpPreview() {
        var dialog = document.getElementById('glover-preview-dialog');

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        var body = dialog.querySelector('[data-glover-preview-body]');
        var request = 0;

        function set(selector, attribute, value) {
            dialog.querySelector(selector).setAttribute(attribute, value);
        }

        dialog.addEventListener('close', function () {
            request++;
            body.innerHTML = '';
        });

        document.querySelectorAll('[data-glover-preview]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var title = link.getAttribute('data-title');
                var current = ++request;
                var checking = link.getAttribute('data-glover-preview-mode') === 'check';

                event.preventDefault();
                dialog.querySelector('[data-glover-preview-label]').textContent = checking ? 'Check text' : 'Preview';
                dialog.querySelector('[data-glover-preview-title]').textContent = title;
                dialog.querySelector('[data-glover-preview-file]').textContent = link.getAttribute('data-file');
                dialog.querySelector('[data-glover-preview-meta]').textContent = link.getAttribute('data-meta');
                var note = link.getAttribute('data-note') || '';
                dialog.querySelector('[data-glover-preview-note]').textContent = note;
                dialog.querySelector('[data-glover-preview-note-wrap]').hidden = note === '';
                set('[data-glover-preview-open]', 'href', link.getAttribute('data-open-url') || link.href);
                set('[data-glover-preview-edit]', 'href', link.getAttribute('data-edit-url'));
                set('[data-glover-preview-return]', 'href', link.getAttribute('data-return-url'));
                set('[data-glover-preview-approve]', 'href', link.getAttribute('data-approve-url'));
                // On the edit screen there is no Edit button, and Return and
                // Approve only for a pending document.
                ['edit', 'return', 'approve'].forEach(function (name) {
                    dialog.querySelector('[data-glover-preview-' + name + ']').hidden = !link.getAttribute('data-' + name + '-url');
                });
                dialog.querySelector('[data-glover-preview-sep]').hidden = !link.getAttribute('data-edit-url') || !link.getAttribute('data-return-url');
                set('[data-glover-preview-edit]', 'aria-label', 'Edit "' + title + '"');
                set('[data-glover-preview-return]', 'aria-label', 'Return "' + title + '" to its submitter');
                set('[data-glover-preview-approve]', 'aria-label', 'Approve: ' + title);

                body.innerHTML = '';

                if (isImage(link.getAttribute('data-ext')) && !checking) {
                    body.appendChild(previewImage(link.href, title));
                } else if (link.getAttribute('data-ext') === 'pdf' && !checking) {
                    var frame = document.createElement('iframe');
                    frame.className = 'glover-preview-dialog__frame';
                    frame.title = 'Preview of ' + title;
                    frame.src = link.href;
                    body.appendChild(frame);
                } else {
                    body.setAttribute('aria-busy', 'true');
                    var lead = checking ? '' : '<p class="glover-compare__muted">Word and Excel files can\u2019t be shown in the browser, so this is the text inside the file.</p>';
                    body.innerHTML = '<p class="glover-compare__muted">Reading the file\u2026</p>';
                    fetch(dialog.getAttribute('data-check-url') + '?action=glover_document_check_text&post=' +
                        encodeURIComponent(link.getAttribute('data-glover-preview')) + '&_wpnonce=' + encodeURIComponent(dialog.getAttribute('data-check-nonce')), { credentials: 'same-origin' })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error(response.status);
                            }
                            return response.text();
                        })
                        .then(function (html) {
                            if (current === request) {
                                body.innerHTML = lead + html;
                            }
                        })
                        .catch(function () {
                            if (current === request) {
                                body.innerHTML = '<p>The file could not be read here. Use Open in new tab to download it.</p>';
                            }
                        })
                        .then(function () {
                            body.removeAttribute('aria-busy');
                        });
                }

                dialog.gloverOpener = link;
                dialog.showModal();
                dialog.querySelector('[data-glover-preview-title]').focus();
            });
        });
    }

    // Return for new documents: a reason box in a dialog, posting to the
    // same handler as the Return page.
    function openReturn(source, prefix, opener) {
        var dialog = document.getElementById('glover-return-dialog');

        if (!dialog || typeof dialog.showModal !== 'function') {
            return false;
        }

        dialog.querySelector('[data-glover-return-post]').value = source.getAttribute(prefix + 'id');
        dialog.querySelector('[data-glover-return-nonce]').value = source.getAttribute(prefix + 'nonce');
        dialog.querySelector('[data-glover-return-heading]').textContent = source.getAttribute(prefix + 'title');
        dialog.querySelector('[data-glover-return-person]').textContent = source.getAttribute(prefix + 'person');
        dialog.querySelector('textarea').value = '';
        dialog.gloverOpener = opener;
        dialog.showModal();
        dialog.querySelector('textarea').focus();
        return true;
    }

    function setUpReturn() {
        document.querySelectorAll('[data-glover-return-id]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (openReturn(link, 'data-glover-return-', link)) {
                    event.preventDefault();
                }
            });
        });

        // From the preview: close it first, so dialogs never stack.
        var preview = document.getElementById('glover-preview-dialog');
        var previewReturn = preview && preview.querySelector('[data-glover-preview-return]');

        if (previewReturn) {
            previewReturn.addEventListener('click', function (event) {
                var source = preview.gloverOpener;
                if (!source || !source.hasAttribute('data-return-id')) {
                    return;
                }
                event.preventDefault();
                preview.gloverOpener = null;
                preview.close();
                openReturn(source, 'data-return-', source);
            });
        }
    }

    // Edit screen: the back link above the heading, the status tag beside
    // it, and Copy link in the address row.
    function setUpEditScreen() {
        var heading = document.querySelector('.wrap > h1.wp-heading-inline');
        var back = document.querySelector('[data-glover-edit-back]');
        var status = document.querySelector('[data-glover-edit-status]');

        if (heading && back) {
            heading.parentNode.insertBefore(back, heading);
        }

        if (heading && status) {
            heading.appendChild(document.createTextNode(' '));
            heading.appendChild(status);
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-glover-copy]');

            if (!button || !navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(button.getAttribute('data-glover-copy')).then(function () {
                button.textContent = 'Copied';
                setTimeout(function () {
                    button.textContent = 'Copy link';
                }, 2000);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setUpEditScreen();
        setUpDialogs();
        setUpPreview();
        setUpReturn();
        document.querySelectorAll('[data-glover-compare]').forEach(setUpForm);
    });
}());
