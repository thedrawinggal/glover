(function () {
    'use strict';

    var isMeeting = document.body.classList.contains('post-type-meeting');
    var rootSelector = isMeeting ? '#acf-group_glover_meeting_fields' : '#acf-group_glover_event_fields';
    var keyPrefix = isMeeting ? 'field_glover_meeting_' : 'field_glover_';
    var keys = {
        startDate: keyPrefix + 'start_date',
        startTime: keyPrefix + 'start_time',
        endTime: keyPrefix + 'end_time',
        scheduleType: keyPrefix + 'recurrence_pattern',
        specificOccurrences: keyPrefix + 'specific_occurrences',
        specificDate: keyPrefix + 'specific_occurrence_date',
        specificStatus: keyPrefix + 'specific_occurrence_status',
        specificTimeTbd: keyPrefix + 'specific_occurrence_all_day',
        specificStartTime: keyPrefix + 'specific_occurrence_start_time',
        specificEndTime: keyPrefix + 'specific_occurrence_end_time',
        occurrenceExceptions: keyPrefix + 'occurrence_exceptions',
        exceptionStatus: keyPrefix + 'exception_status',
        exceptionStartTime: keyPrefix + 'exception_new_start_time',
        exceptionEndTime: keyPrefix + 'exception_new_end_time',
        interval: keyPrefix + 'recurrence_interval',
        unit: keyPrefix + 'recurrence_unit',
        days: keyPrefix + 'recurrence_days',
        monthlyBasis: keyPrefix + 'recurrence_monthly_basis',
        endType: keyPrefix + 'recurrence_end_type',
        recurrenceEndDate: keyPrefix + 'recurrence_end_date',
        recurrenceEndCount: keyPrefix + 'recurrence_end_count',
        scheduleTab: keyPrefix + 'tab_schedule'
    };
    var dayNames = {
        sunday: 'Sunday',
        monday: 'Monday',
        tuesday: 'Tuesday',
        wednesday: 'Wednesday',
        thursday: 'Thursday',
        friday: 'Friday',
        saturday: 'Saturday'
    };
    var dayKeys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    var summaryElement = null;
    var updateTimer = null;
    var listenersBound = false;

    function getRoot() {
        return document.querySelector(rootSelector);
    }

    function getField(key, scope) {
        return (scope || getRoot()).querySelector('[data-key="' + key + '"]');
    }

    function getControl(key, scope) {
        var field = getField(key, scope);
        var select;

        if (!field) {
            return null;
        }

        select = field.querySelector('select[name^="acf["]');

        if (select) {
            return select;
        }

        return field.querySelector('input[name^="acf["]:not([type="radio"]):not([type="checkbox"])') ||
            field.querySelector('select, input');
    }

    function getValue(key, scope) {
        var control = getControl(key, scope);

        return control ? String(control.value || '').trim() : '';
    }

    function getCheckedValue(key, scope) {
        var field = getField(key, scope);
        var control = field ? field.querySelector('input[type="radio"]:checked, input[type="checkbox"]:checked') : null;

        return control ? String(control.value || '').trim() : '';
    }

    function isChecked(key, scope) {
        var field = getField(key, scope);
        var control = field ? field.querySelector('input[type="checkbox"]') : null;

        return Boolean(control && control.checked);
    }

    function parseDate(value) {
        var match;
        var parsed;

        if (!value) {
            return null;
        }

        match = value.match(/^(\d{4})(\d{2})(\d{2})$/);
        if (match) {
            return validDate(Number(match[1]), Number(match[2]), Number(match[3]));
        }

        match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (match) {
            return validDate(Number(match[1]), Number(match[2]), Number(match[3]));
        }

        parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? null : new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
    }

    function validDate(year, month, day) {
        var date = new Date(year, month - 1, day);

        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
            return null;
        }

        return date;
    }

    function parseTime(value) {
        var match;
        var hour;
        var minute;
        var suffix;

        if (!value) {
            return null;
        }

        match = value.trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]m)?$/i);
        if (!match) {
            return null;
        }

        hour = Number(match[1]);
        minute = Number(match[2]);
        suffix = (match[3] || '').toLowerCase();

        if (suffix === 'pm' && hour < 12) {
            hour += 12;
        } else if (suffix === 'am' && hour === 12) {
            hour = 0;
        }

        if (hour > 23 || minute > 59) {
            return null;
        }

        return { hour: hour, minute: minute };
    }

    function timeInMinutes(time) {
        return time ? (time.hour * 60) + time.minute : null;
    }

    function endTimeIsBeforeStart(startTime, endTime) {
        return Boolean(startTime && endTime && timeInMinutes(endTime) < timeInMinutes(startTime));
    }

    function setTimeOrderValidity(startKey, endKey, scope, shouldValidate) {
        var startTime = parseTime(getValue(startKey, scope));
        var endTime = parseTime(getValue(endKey, scope));
        var endControl = getControl(endKey, scope);
        var message = shouldValidate && endTimeIsBeforeStart(startTime, endTime) ?
            'End Time must be the same as or later than Start Time.' : '';

        if (endControl && typeof endControl.setCustomValidity === 'function') {
            endControl.setCustomValidity(message);
        }
    }

    function validateTimeOrder() {
        var root = getRoot();
        var repeater = getField(keys.specificOccurrences);
        var exceptions = getField(keys.occurrenceExceptions);
        var type = scheduleType();

        setTimeOrderValidity(keys.startTime, keys.endTime, root, type === 'recurring');

        if (repeater) {
            Array.prototype.forEach.call(repeater.querySelectorAll('.acf-row:not(.acf-clone)'), function (row) {
                setTimeOrderValidity(
                    keys.specificStartTime,
                    keys.specificEndTime,
                    row,
                    type === 'specific_dates' && !isChecked(keys.specificTimeTbd, row)
                );
            });
        }

        if (!exceptions) {
            return;
        }

        Array.prototype.forEach.call(exceptions.querySelectorAll('.acf-row:not(.acf-clone)'), function (row) {
            setTimeOrderValidity(
                keys.exceptionStartTime,
                keys.exceptionEndTime,
                row,
                type === 'recurring' && getValue(keys.exceptionStatus, row) === 'rescheduled'
            );
        });
    }

    function locale() {
        return (document.documentElement.lang || 'en-US').replace('_', '-');
    }

    function formatDate(date, options) {
        return new Intl.DateTimeFormat(locale(), options || {
            month: 'long',
            day: 'numeric',
            year: 'numeric'
        }).format(date);
    }

    function formatTime(time) {
        var date;

        if (!time) {
            return '';
        }

        date = new Date(2000, 0, 1, time.hour, time.minute);
        return new Intl.DateTimeFormat(locale(), {
            hour: 'numeric',
            minute: '2-digit'
        }).format(date);
    }

    function formatMonthDay(date) {
        return formatDate(date, { month: 'long', day: 'numeric' });
    }

    function weekdayName(date) {
        return formatDate(date, { weekday: 'long' });
    }

    function weekdayPosition(date) {
        var position = Math.ceil(date.getDate() / 7);
        var nextWeek = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 7);
        var words = ['first', 'second', 'third', 'fourth', 'fifth'];

        if (nextWeek.getMonth() !== date.getMonth()) {
            return 'last';
        }

        return words[position - 1] || String(position);
    }

    function listFormat(items) {
        if (window.Intl && Intl.ListFormat) {
            return new Intl.ListFormat(locale(), { style: 'long', type: 'conjunction' }).format(items);
        }

        if (items.length < 2) {
            return items[0] || '';
        }

        return items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1];
    }

    function selectedWeekdays() {
        var field = getField(keys.days);

        if (!field) {
            return [];
        }

        return Array.prototype.map.call(
            field.querySelectorAll('input[type="checkbox"]:checked'),
            function (input) {
                return dayNames[input.value] || input.value;
            }
        );
    }

    function scheduleType() {
        return getCheckedValue(keys.scheduleType) || 'specific_dates';
    }

    function specificOccurrences() {
        var repeater = getField(keys.specificOccurrences);
        var occurrences = [];

        if (!repeater) {
            return occurrences;
        }

        Array.prototype.forEach.call(repeater.querySelectorAll('.acf-row:not(.acf-clone)'), function (row) {
            var date = parseDate(getValue(keys.specificDate, row));

            if (!date) {
                return;
            }

            occurrences.push({
                date: date,
                status: getValue(keys.specificStatus, row) || 'scheduled',
                timeTbd: isChecked(keys.specificTimeTbd, row),
                startTime: parseTime(getValue(keys.specificStartTime, row)),
                endTime: parseTime(getValue(keys.specificEndTime, row))
            });
        });

        return occurrences.sort(function (first, second) {
            return first.date.getTime() - second.date.getTime();
        });
    }

    function timePhrase(startTime, endTime) {
        if (startTime && endTime) {
            return ', ' + formatTime(startTime) + '–' + formatTime(endTime);
        }

        if (startTime) {
            return ' at ' + formatTime(startTime);
        }

        return '';
    }

    function scheduleBeginning(startDate, startTime, endTime) {
        return 'Starts ' + formatDate(startDate) + timePhrase(startTime, endTime) + '.';
    }

    function specificOccurrencePhrase(occurrence) {
        var phrase;

        if (occurrence.timeTbd) {
            phrase = formatDate(occurrence.date) + ', time TBD';
        } else {
            phrase = formatDate(occurrence.date) + timePhrase(occurrence.startTime, occurrence.endTime);
        }

        return occurrence.status === 'cancelled' ? phrase + ' — canceled' : phrase;
    }

    function specificOccurrencesSummary() {
        var occurrences = specificOccurrences();
        var incomplete = occurrences.some(function (occurrence) {
            return !occurrence.timeTbd && (
                !occurrence.startTime ||
                !occurrence.endTime ||
                endTimeIsBeforeStart(occurrence.startTime, occurrence.endTime)
            );
        });
        var phrases;

        if (!occurrences.length) {
            return {
                text: 'Add at least one date to complete this schedule.',
                incomplete: true
            };
        }

        phrases = occurrences.map(specificOccurrencePhrase);

        return {
            text: occurrences.length === 1 ?
                phrases[0] + '.' :
                occurrences.length + ' specific occurrences: ' + listFormat(phrases) + '.',
            incomplete: incomplete
        };
    }

    function customRepeatPhrase(startDate) {
        var interval = Math.max(1, Number(getValue(keys.interval)) || 1);
        var unit = getValue(keys.unit) || 'week';
        var weekdays = selectedWeekdays();
        var monthlyBasis = getCheckedValue(keys.monthlyBasis) || 'day_of_month';
        var endType = getCheckedValue(keys.endType) || 'never';
        var endDate = parseDate(getValue(keys.recurrenceEndDate));
        var endCount = Math.max(0, Number(getValue(keys.recurrenceEndCount)) || 0);
        var unitWord = unit + (interval === 1 ? '' : 's');
        var simpleFrequencies = {
            day: 'daily',
            week: 'weekly',
            month: 'monthly',
            year: 'annually'
        };
        var phrase = interval === 1 ? 'Repeats ' + simpleFrequencies[unit] : 'Repeats every ' + interval + ' ' + unitWord;

        if (unit === 'week') {
            phrase += weekdays.length ? ' on ' + listFormat(weekdays) : '; choose at least one weekday';
        } else if (unit === 'month') {
            phrase += monthlyBasis === 'weekday_position' ?
                ' on the ' + weekdayPosition(startDate) + ' ' + weekdayName(startDate) :
                ' on day ' + startDate.getDate();
        } else if (unit === 'year') {
            phrase += ' on ' + formatMonthDay(startDate);
        }

        if (endType === 'on_date') {
            phrase += endDate ? ' through ' + formatDate(endDate) : '; choose an end date';
        } else if (endType === 'after_count') {
            phrase += endCount ? ' for ' + endCount + ' occurrence' + (endCount === 1 ? '' : 's') : '; enter the number of occurrences';
        } else {
            phrase += ' with no end date';
        }

        return phrase + '.';
    }

    function buildSummary() {
        var type = scheduleType();
        var startDate = parseDate(getValue(keys.startDate));
        var startTime = parseTime(getValue(keys.startTime));
        var endTime = parseTime(getValue(keys.endTime));
        var beginning;

        if (type === 'specific_dates') {
            return specificOccurrencesSummary();
        }

        if (!startDate) {
            return {
                text: 'Choose a start date to complete this schedule.',
                incomplete: true
            };
        }

        beginning = scheduleBeginning(startDate, startTime, endTime);

        return {
            text: beginning + ' ' + customRepeatPhrase(startDate),
            incomplete: !startTime || !endTime || endTimeIsBeforeStart(startTime, endTime)
        };
    }

    function ensureSummaryElement() {
        var section;
        var heading;
        var output;

        if (summaryElement && document.body.contains(summaryElement)) {
            return summaryElement;
        }

        section = document.createElement('section');
        section.className = 'glover-event-schedule-summary';
        section.setAttribute('aria-labelledby', 'glover-event-schedule-summary-title');

        heading = document.createElement('h3');
        heading.id = 'glover-event-schedule-summary-title';
        heading.textContent = 'Schedule summary';

        output = document.createElement('output');
        output.id = 'glover-event-schedule-summary-output';
        output.setAttribute('aria-live', 'polite');
        output.setAttribute('aria-atomic', 'true');

        section.appendChild(heading);
        section.appendChild(output);
        summaryElement = section;

        return summaryElement;
    }

    function placeSummary() {
        var type = scheduleType();
        var target = type === 'recurring' ?
            getField(keys.recurrenceEndCount) : getField(keys.specificOccurrences);
        var summary = ensureSummaryElement();

        if (!target) {
            target = getField(keys.scheduleType);
        }

        if (target) {
            target.insertAdjacentElement('afterend', summary);
        }
    }

    function syncSummaryVisibility() {
        var root = getRoot();
        var scheduleTab = root ? root.querySelector('.acf-tab-button[data-key="' + keys.scheduleTab + '"]') : null;
        var scheduleIsActive = Boolean(scheduleTab && scheduleTab.parentElement && scheduleTab.parentElement.classList.contains('active'));

        if (summaryElement) {
            summaryElement.classList.toggle('acf-hidden', !scheduleIsActive);
        }
    }

    function chooseStartWeekdayWhenEmpty() {
        var startDate = parseDate(getValue(keys.startDate));
        var field = getField(keys.days);
        var checkbox;

        if (!startDate || !field || selectedWeekdays().length) {
            return;
        }

        checkbox = field.querySelector('input[type="checkbox"][value="' + dayKeys[startDate.getDay()] + '"]');

        if (checkbox) {
            checkbox.checked = true;
        }
    }

    function promoteMessageLabelsToHeadings() {
        var root = getRoot();

        if (!root) {
            return;
        }

        Array.prototype.forEach.call(root.querySelectorAll('.acf-field-message > .acf-label'), function (labelWrapper) {
            var label = labelWrapper.querySelector('label');
            var heading;

            if (!label || labelWrapper.querySelector('h3')) {
                return;
            }

            heading = document.createElement('h3');
            heading.textContent = label.textContent;
            label.replaceWith(heading);
        });
    }

    function render() {
        var root = getRoot();
        var result;
        var output;

        if (!root) {
            return;
        }

        promoteMessageLabelsToHeadings();
        placeSummary();
        syncSummaryVisibility();
        validateTimeOrder();

        result = buildSummary();
        output = summaryElement.querySelector('output');
        summaryElement.classList.toggle('is-incomplete', result.incomplete);

        if (output.textContent !== result.text) {
            output.textContent = result.text;
        }
    }

    function scheduleRender() {
        window.clearTimeout(updateTimer);
        updateTimer = window.setTimeout(render, 120);
    }

    function handleChange(event) {
        var scheduleTypeField = getField(keys.scheduleType);
        var unitField = getField(keys.unit);
        var type = scheduleType();
        var unit = getValue(keys.unit);

        if (type === 'recurring' && unit === 'week' &&
            ((scheduleTypeField && scheduleTypeField.contains(event.target)) || (unitField && unitField.contains(event.target)))) {
            chooseStartWeekdayWhenEmpty();
        }

        scheduleRender();
    }

    function bindListeners() {
        var root = getRoot();

        if (!root || listenersBound) {
            return;
        }

        if (window.jQuery) {
            window.jQuery(root).on('change.gloverEventSchedule', 'input, select', function (event) {
                handleChange({ target: event.currentTarget });
            });
            window.jQuery(root).on('input.gloverEventSchedule', 'input', scheduleRender);
            window.jQuery(root).on('click.gloverEventSchedule', '.acf-tab-button', scheduleRender);
        } else {
            root.addEventListener('change', handleChange);
            root.addEventListener('input', scheduleRender);
            root.addEventListener('click', scheduleRender);
        }
        listenersBound = true;
    }

    function initialize() {
        if (!getRoot()) {
            return;
        }

        bindListeners();
        render();
    }

    function useMinutePrecisionTimePicker(args) {
        args.timeFormat = 'h:mm tt';
        args.showHour = true;
        args.showMinute = true;
        args.showSecond = false;
        args.showMillisec = false;
        args.showMicrosec = false;
        args.showTimezone = false;

        return args;
    }

    if (window.acf && typeof window.acf.addFilter === 'function') {
        window.acf.addFilter('time_picker_args', useMinutePrecisionTimePicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    if (window.acf && typeof window.acf.addAction === 'function') {
        window.acf.addAction('ready', initialize);
        window.acf.addAction('append', scheduleRender);
        window.acf.addAction('show_field', scheduleRender);
    }
}());
