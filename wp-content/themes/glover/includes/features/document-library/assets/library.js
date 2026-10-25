/*
 * Document Library live filtering.
 *
 * The page works as an ordinary GET form without this script. With it,
 * results update in place, the URL follows every change, the result count
 * is announced through the existing live region, and focus stays on the
 * control the person just used.
 */
(function () {
    'use strict';

    var config = window.gloverDocumentLibrary || {};
    var form = document.getElementById('glover-library-filters');
    var library = document.querySelector('.documents');

    if (!form || !library || !config.endpoint || !window.fetch || !window.URLSearchParams) {
        return;
    }

    var results = document.getElementById('glover-library-results');
    var chips = document.getElementById('glover-library-chips');
    var summary = document.getElementById('glover-library-summary');
    var yearFieldset = form.querySelector('.year-range');
    var minYear = yearFieldset ? yearFieldset.getAttribute('data-min') : '';
    var maxYear = yearFieldset ? yearFieldset.getAttribute('data-max') : '';
    var searchTimer = null;
    var requestId = 0;
    var sortSelect = document.getElementById('glover-library-sort');
    // Until someone picks a sort, it follows the search: Best match while
    // searching, Most recent otherwise.
    var sortChosen = false;
    // Set by the Filters panel below.
    var updateFilterSummary = function () {};

    function defaultSort(query) {
        return query && query.trim() !== '' ? 'relevance' : 'recent';
    }

    library.classList.add('is-enhanced');

    function paramsFromForm() {
        var params = new URLSearchParams();
        var data = new FormData(form);

        data.forEach(function (value, key) {
            if (value === '') {
                return;
            }
            if ((key === 'from' && value === minYear) || (key === 'to' && value === maxYear)) {
                return;
            }
            if (key === 'sort' && value === defaultSort(data.get('q') || '')) {
                return;
            }
            params.append(key, value);
        });

        return params;
    }

    function setFormFromParams(params) {
        Array.prototype.forEach.call(form.elements, function (element) {
            if (!element.name) {
                return;
            }
            var name = element.name;
            var values = params.getAll(name);

            if (!values.length && name.slice(-2) !== '[]') {
                values = params.getAll(name.replace('[]', ''));
            }
            if (!values.length) {
                // URLSearchParams from PHP may use indexed keys such as category[0].
                params.forEach(function (value, key) {
                    if (key.replace(/\[\d*\]$/, '[]') === name) {
                        values.push(value);
                    }
                });
            }

            if (element.type === 'checkbox' || element.type === 'radio') {
                element.checked = values.indexOf(element.value) !== -1;
            } else if (name === 'from') {
                element.value = values[0] || minYear;
            } else if (name === 'to') {
                element.value = values[0] || maxYear;
            } else if (name === 'sort') {
                element.value = values[0] || defaultSort(params.get('q') || '');
            } else {
                element.value = values[0] || '';
            }
        });

        syncSlider();
    }

    function updateCounts(counts) {
        ['category', 'topic'].forEach(function (facet) {
            var map = (counts && counts[facet]) || {};

            form.querySelectorAll('[data-count-' + facet + ']').forEach(function (element) {
                var slug = element.getAttribute('data-count-' + facet);
                if (element.firstChild && element.firstChild.nodeType === 3) {
                    element.firstChild.nodeValue = String(map[slug] || 0);
                }
            });
        });
    }

    function load(params, options) {
        var current = ++requestId;
        options = options || {};
        results.setAttribute('aria-busy', 'true');

        return fetch(config.endpoint + (config.endpoint.indexOf('?') === -1 ? '?' : '&') + params.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (current !== requestId) {
                    return;
                }

                results.innerHTML = data.results;
                chips.innerHTML = data.chips;
                summary.textContent = data.summary.replace(/\s+/g, ' ').trim();

                if (sortSelect && data.sort) {
                    sortSelect.value = data.sort;
                }
                updateCounts(data.counts);
                updateFilterSummary(data.total);

                if (options.history === 'push') {
                    window.history.pushState({ gloverLibrary: true }, '', data.url);
                } else if (options.history === 'replace') {
                    window.history.replaceState({ gloverLibrary: true }, '', data.url);
                }

                if (options.focusSummary) {
                    summary.setAttribute('tabindex', '-1');
                    summary.focus();
                }
            })
            .catch(function () {
                // Fall back to a normal page load so the person still gets results.
                window.location.href = form.action + '?' + params.toString();
            })
            .then(function () {
                if (current === requestId) {
                    results.removeAttribute('aria-busy');
                }
            });
    }

    function refresh(historyMode) {
        load(paramsFromForm(), { history: historyMode || 'push' });
    }

    function belongsToForm(element) {
        return element && element.form === form;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(searchTimer);
        refresh('push');
    });

    document.addEventListener('change', function (event) {
        if (event.target === sortSelect) {
            sortChosen = true;
        }
        if (belongsToForm(event.target) && event.target.type !== 'search') {
            refresh('push');
        }
    });

    document.addEventListener('input', function (event) {
        if (!belongsToForm(event.target) || event.target.type !== 'search') {
            return;
        }
        window.clearTimeout(searchTimer);
        if (sortSelect && !sortChosen) {
            sortSelect.value = defaultSort(event.target.value);
        }
        // Wait for a pause in typing so screen readers aren't flooded.
        searchTimer = window.setTimeout(function () {
            refresh('replace');
        }, 500);
    });

    library.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-library-link]') : null;

        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        var params = new URL(link.href, window.location.href).searchParams;
        var clearsPanel = link.hasAttribute('data-filters-clear');

        if (clearsPanel) {
            var search = document.getElementById('glover-library-search');
            params = new URLSearchParams();
            if (search && search.value.trim() !== '') {
                params.set('q', search.value);
            }
            if (sortChosen && sortSelect) {
                params.set('sort', sortSelect.value);
            }
        }

        setFormFromParams(params);
        load(params, { history: 'push', focusSummary: !clearsPanel });
    });

    window.addEventListener('popstate', function () {
        var params = new URLSearchParams(window.location.search);
        setFormFromParams(params);
        load(params, { history: false });
    });

    /* Year range slider, built on top of the year dropdowns. */
    var slider = form.querySelector('.year-range__slider');
    var fromSelect = document.getElementById('glover-filter-from');
    var toSelect = document.getElementById('glover-filter-to');
    var fromRange = form.querySelector('[data-year-range="from"]');
    var toRange = form.querySelector('[data-year-range="to"]');

    function syncSlider() {
        if (!fromRange || !toRange) {
            return;
        }
        fromRange.value = fromSelect.value;
        toRange.value = toSelect.value;
        paintSlider();
    }

    function paintSlider() {
        if (!slider) {
            return;
        }
        var span = (Number(maxYear) - Number(minYear)) || 1;
        var start = ((Number(fromRange.value) - Number(minYear)) / span) * 100;
        var end = ((Number(toRange.value) - Number(minYear)) / span) * 100;
        slider.style.setProperty('--range-start', start + '%');
        slider.style.setProperty('--range-end', end + '%');
    }

    if (slider && fromRange && toRange && fromSelect && toSelect && minYear !== maxYear) {
        slider.hidden = false;

        fromRange.addEventListener('input', function () {
            // The handles can't pass each other.
            if (Number(fromRange.value) > Number(toRange.value)) {
                fromRange.value = toRange.value;
            }
            fromSelect.value = fromRange.value;
            paintSlider();
        });

        toRange.addEventListener('input', function () {
            if (Number(toRange.value) < Number(fromRange.value)) {
                toRange.value = fromRange.value;
            }
            toSelect.value = toRange.value;
            paintSlider();
        });

        // The ranges have no name, so the document-level change listener
        // above reloads results from the synced dropdowns when a handle is released.

        [fromSelect, toSelect].forEach(function (select) {
            select.addEventListener('change', function () {
                if (Number(fromSelect.value) > Number(toSelect.value)) {
                    if (select === fromSelect) {
                        toSelect.value = fromSelect.value;
                    } else {
                        fromSelect.value = toSelect.value;
                    }
                }
                syncSlider();
            });
        });

        syncSlider();
    }

    /* "Show all topics" disclosure. */
    var topicsToggle = form.querySelector('[data-topics-toggle]');
    var extraTopics = form.querySelectorAll('[data-extra-topic]');

    function setTopicsExpanded(expanded) {
        topicsToggle.setAttribute('aria-expanded', String(expanded));
        topicsToggle.textContent = expanded ? 'Show fewer topics' : topicsToggle.getAttribute('data-label');
        extraTopics.forEach(function (item) {
            var input = item.querySelector('input');
            item.hidden = !expanded && !(input && input.checked);
        });
    }

    if (topicsToggle && extraTopics.length) {
        topicsToggle.setAttribute('data-label', topicsToggle.textContent.trim());
        topicsToggle.hidden = false;
        setTopicsExpanded(false);
        topicsToggle.addEventListener('click', function () {
            setTopicsExpanded(topicsToggle.getAttribute('aria-expanded') !== 'true');
        });
    }

    /* Filters panel on narrow screens: the Filters button opens the form as a
       full-screen modal panel. Results update as filters change, so Show
       just closes it. */
    var filtersToggle = document.querySelector('.documents__filters-toggle');
    var filtersPanel = document.getElementById('glover-library-filters-panel');
    var filtersFooter = form.querySelector('.filters__footer');
    var filtersCount = document.querySelector('[data-filters-count]');
    var filtersShow = form.querySelector('[data-filters-show]');
    var narrow = window.matchMedia('(max-width: 991.98px)');
    var inerted = [];

    function activeFilterCount() {
        var count = form.querySelectorAll('input[type="checkbox"]:checked').length;
        var board = form.querySelector('[name="board"]');

        if (board && board.value !== '') {
            count++;
        }
        if (fromSelect && toSelect && (fromSelect.value !== minYear || toSelect.value !== maxYear)) {
            count++;
        }

        return count;
    }

    updateFilterSummary = function (total) {
        if (filtersCount) {
            var count = activeFilterCount();
            filtersCount.textContent = count ? ' (' + count + ')' : '';
        }
        if (filtersShow && typeof total === 'number') {
            filtersShow.textContent = 'Show ' + total.toLocaleString() + (total === 1 ? ' Result' : ' Results');
        }
    };

    function isPanelOpen() {
        return filtersToggle.getAttribute('aria-expanded') === 'true';
    }

    // Everything outside the panel, at every level up to <body>, is made
    // inert while the panel is a modal.
    function setModal(on) {
        if (on) {
            filtersPanel.setAttribute('role', 'dialog');
            filtersPanel.setAttribute('aria-modal', 'true');
            filtersPanel.setAttribute('aria-labelledby', 'glover-filters-heading');
            for (var node = filtersPanel; node && node !== document.body; node = node.parentElement) {
                Array.prototype.forEach.call(node.parentElement.children, function (sibling) {
                    if (sibling !== node && !sibling.inert) {
                        sibling.inert = true;
                        inerted.push(sibling);
                    }
                });
            }
            document.documentElement.classList.add('documents-filters-open');
        } else {
            filtersPanel.removeAttribute('role');
            filtersPanel.removeAttribute('aria-modal');
            filtersPanel.removeAttribute('aria-labelledby');
            inerted.forEach(function (element) {
                element.inert = false;
            });
            inerted = [];
            document.documentElement.classList.remove('documents-filters-open');
        }
    }

    function openPanel() {
        filtersToggle.setAttribute('aria-expanded', 'true');
        filtersPanel.removeAttribute('data-collapsed');
        if (narrow.matches) {
            setModal(true);
            var close = filtersPanel.querySelector('.filters__close');
            if (close) {
                close.focus();
            }
        }
    }

    function closePanel() {
        var wasModal = filtersPanel.getAttribute('aria-modal') === 'true';
        filtersToggle.setAttribute('aria-expanded', 'false');
        filtersPanel.setAttribute('data-collapsed', '');
        setModal(false);
        if (wasModal) {
            filtersToggle.focus();
        }
    }

    if (filtersToggle && filtersPanel) {
        filtersToggle.hidden = false;
        filtersPanel.setAttribute('data-collapsed', '');
        form.querySelectorAll('[data-filters-close], .filters__footer').forEach(function (element) {
            element.hidden = false;
        });
        if (filtersFooter) {
            filtersFooter.hidden = false;
        }

        filtersToggle.addEventListener('click', function () {
            if (isPanelOpen()) {
                closePanel();
            } else {
                openPanel();
            }
        });

        form.addEventListener('click', function (event) {
            var close = event.target.closest ? event.target.closest('[data-filters-close]') : null;
            if (close) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && filtersPanel.getAttribute('aria-modal') === 'true') {
                closePanel();
            }
        });

        // Widening the window shows the filters as a sidebar again.
        narrow.addEventListener('change', function () {
            if (!narrow.matches && isPanelOpen()) {
                closePanel();
            }
        });

        updateFilterSummary();
    }
})();
