(function () {
    'use strict';

    var settings = window.gloverEventCalendar;
    var container = document.getElementById('glover-event-calendar');

    if (!container || !settings || !window.FullCalendar) {
        return;
    }

    var i18n = settings.i18n;
    var locale = document.documentElement.lang || undefined;

    function isoDate(date) {
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');

        return date.getFullYear() + '-' + month + '-' + day;
    }

    function longDate(date) {
        return date.toLocaleDateString(locale, { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function addEventLink(date) {
        var url = new URL(settings.newEventUrl);
        var link = document.createElement('a');

        url.searchParams.set(settings.dateArg, isoDate(date));
        link.className = 'glover-calendar-add';
        link.href = url.toString();
        link.setAttribute('aria-label', i18n.addOn.replace('%s', longDate(date)));
        link.innerHTML = '<span aria-hidden="true">+</span> ' + i18n.add;

        return link;
    }

    function eventLabel(event, timeText) {
        var props = event.extendedProps;
        var prefixes = [];

        if (props.status === 'cancelled') {
            prefixes.push(i18n.cancelled);
        } else if (props.status === 'rescheduled') {
            prefixes.push(i18n.rescheduled);
        }

        if (props.postStatus !== 'publish') {
            prefixes.push(i18n.draft);
        }

        return {
            prefix: prefixes.join(', '),
            time: props.timeTbd ? i18n.timeTbd : timeText
        };
    }

    function showError() {
        var notice = document.createElement('div');

        notice.className = 'notice notice-error';
        notice.innerHTML = '<p></p>';
        notice.firstChild.textContent = i18n.loadError;
        container.parentNode.insertBefore(notice, container);
    }

    var calendar = new FullCalendar.Calendar(container, {
        initialView: 'dayGridMonth',
        initialDate: settings.initialDate || undefined,
        locale: locale,
        firstDay: settings.firstDay,
        height: 'auto',
        fixedWeekCount: false,
        headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
        buttonText: { today: 'Today' },
        editable: false,
        selectable: false,
        dayMaxEvents: false,
        displayEventEnd: false,
        eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
        eventDisplay: 'block',

        events: function (info, success, failure) {
            var url = new URL(settings.restUrl);

            url.searchParams.set('start', info.startStr.slice(0, 10));
            url.searchParams.set('end', info.endStr.slice(0, 10));

            fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': settings.nonce }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(response.statusText);
                    }
                    return response.json();
                })
                .then(success)
                .catch(function (error) {
                    showError();
                    failure(error);
                });
        },

        loading: function (isLoading) {
            container.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        },

        eventClassNames: function (arg) {
            var props = arg.event.extendedProps;
            var classes = ['glover-calendar-event'];

            if (props.status === 'cancelled') {
                classes.push('glover-calendar-event--cancelled');
            }
            if (props.postStatus !== 'publish') {
                classes.push('glover-calendar-event--draft');
            }

            return classes;
        },

        eventContent: function (arg) {
            var label = eventLabel(arg.event, arg.timeText);
            var wrapper = document.createElement('span');
            var parts = [];

            if (label.prefix) {
                parts.push(['glover-calendar-event__status', label.prefix + ':']);
            }
            if (label.time) {
                parts.push(['glover-calendar-event__time', label.time]);
            }
            parts.push(['glover-calendar-event__title', arg.event.title]);

            wrapper.className = 'glover-calendar-event__content';
            parts.forEach(function (part) {
                var span = document.createElement('span');

                span.className = part[0];
                span.textContent = part[1];
                wrapper.appendChild(span);
                wrapper.appendChild(document.createTextNode(' '));
            });

            return { domNodes: [wrapper] };
        },

        dayCellDidMount: function (arg) {
            var top = arg.el.querySelector('.fc-daygrid-day-top');

            if (settings.canCreate && top) {
                top.appendChild(addEventLink(arg.date));
            }
        },

        datesSet: function (info) {
            // Keep the visible month in the address so reloading, or returning
            // from an event editor, lands on the same month.
            var url = new URL(window.location.href);

            url.searchParams.set('month', isoDate(info.view.currentStart).slice(0, 7));
            window.history.replaceState(null, '', url.toString());
        }
    });

    calendar.render();
})();
