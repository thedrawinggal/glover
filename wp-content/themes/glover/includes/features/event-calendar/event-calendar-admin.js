(function () {
    'use strict';

    var rootSelector = '#acf-group_glover_event_fields';
    var keys = {
        dateTimeSection: 'field_glover_schedule_date_time_section',
        customSection: 'field_glover_schedule_custom_repeat_section',
        startDate: 'field_glover_start_date',
        endDate: 'field_glover_end_date',
        startTime: 'field_glover_start_time',
        endTime: 'field_glover_end_time',
        allDay: 'field_glover_all_day',
        pattern: 'field_glover_recurrence_pattern',
        interval: 'field_glover_recurrence_interval',
        unit: 'field_glover_recurrence_unit',
        days: 'field_glover_recurrence_days',
        monthlyBasis: 'field_glover_recurrence_monthly_basis',
        endType: 'field_glover_recurrence_end_type',
        recurrenceEndDate: 'field_glover_recurrence_end_date',
        recurrenceEndCount: 'field_glover_recurrence_end_count',
        selectedDates: 'field_glover_selected_dates',
        selectedDate: 'field_glover_selected_date'
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

    function getControl(key) {
        var field = getField(key);
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

    function getValue(key) {
        var control = getControl(key);

        return control ? String(control.value || '').trim() : '';
    }

    function getCheckedValue(key) {
        var field = getField(key);
        var control = field ? field.querySelector('input[type="radio"]:checked, input[type="checkbox"]:checked') : null;

        return control ? String(control.value || '').trim() : '';
    }

    function isChecked(key) {
        var field = getField(key);
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

    function sameDay(first, second) {
        return first && second && first.getFullYear() === second.getFullYear() &&
            first.getMonth() === second.getMonth() && first.getDate() === second.getDate();
    }

    function formatDateRange(startDate, endDate) {
        if (!endDate || sameDay(startDate, endDate)) {
            return formatDate(startDate);
        }

        if (startDate.getFullYear() === endDate.getFullYear() && startDate.getMonth() === endDate.getMonth()) {
            return formatDate(startDate, { month: 'long', day: 'numeric' }) + '–' +
                formatDate(endDate, { day: 'numeric' }) + ', ' + formatDate(endDate, { year: 'numeric' });
        }

        if (startDate.getFullYear() === endDate.getFullYear()) {
            return formatDate(startDate, { month: 'long', day: 'numeric' }) + '–' +
                formatDate(endDate, { month: 'long', day: 'numeric', year: 'numeric' });
        }

        return formatDate(startDate) + '–' + formatDate(endDate);
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

    function selectedDateValues() {
        var repeater = getField(keys.selectedDates);
        var dates = [];

        if (!repeater) {
            return dates;
        }

        Array.prototype.forEach.call(repeater.querySelectorAll('[data-key="' + keys.selectedDate + '"]'), function (field) {
            var input = field.querySelector('input[name^="acf["]') || field.querySelector('input');
            var date = input ? parseDate(String(input.value || '').trim()) : null;

            if (date) {
                dates.push(date);
            }
        });

        return dates;
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

    function scheduleBeginning(startDate, endDate, startTime, endTime, allDay, repeating) {
        var dates = formatDateRange(startDate, endDate);

        if (allDay) {
            return (repeating && !endDate ? 'Starts ' : '') + dates + ', all day.';
        }

        return (repeating && !endDate ? 'Starts ' : '') + dates + timePhrase(startTime, endTime) + '.';
    }

    function presetRepeatPhrase(pattern, startDate) {
        if (pattern === 'daily') {
            return 'Repeats daily with no end date.';
        }

        if (pattern === 'weekly') {
            return 'Repeats weekly on ' + weekdayName(startDate) + ' with no end date.';
        }

        if (pattern === 'monthly') {
            return 'Repeats monthly on the ' + weekdayPosition(startDate) + ' ' + weekdayName(startDate) + ' with no end date.';
        }

        if (pattern === 'annually') {
            return 'Repeats annually on ' + formatMonthDay(startDate) + ' with no end date.';
        }

        if (pattern === 'weekdays') {
            return 'Repeats every weekday, Monday through Friday, with no end date.';
        }

        return '';
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

    function selectedDatesSummary(startDate, startTime, endTime, allDay) {
        var dates = [startDate].concat(selectedDateValues());
        var unique = {};

        dates.forEach(function (date) {
            unique[date.getFullYear() + '-' + date.getMonth() + '-' + date.getDate()] = date;
        });

        dates = Object.keys(unique).map(function (key) {
            return unique[key];
        }).sort(function (first, second) {
            return first.getTime() - second.getTime();
        });

        if (dates.length < 2) {
            return scheduleBeginning(startDate, null, startTime, endTime, allDay, true) + ' Add at least one additional selected date.';
        }

        return (allDay ? 'All day on ' : 'Occurs on ') + dates.length + ' selected dates, from ' +
            formatDate(dates[0]) + ' through ' + formatDate(dates[dates.length - 1]) +
            (allDay ? '.' : timePhrase(startTime, endTime) + '.');
    }

    function buildSummary() {
        var startDate = parseDate(getValue(keys.startDate));
        var endDate = parseDate(getValue(keys.endDate));
        var startTime = parseTime(getValue(keys.startTime));
        var endTime = parseTime(getValue(keys.endTime));
        var allDay = isChecked(keys.allDay);
        var pattern = getValue(keys.pattern) || 'none';
        var beginning;

        if (!startDate) {
            return {
                text: 'Choose a start date to complete this schedule.',
                incomplete: true
            };
        }

        if (pattern === 'selected_dates') {
            return {
                text: selectedDatesSummary(startDate, startTime, endTime, allDay),
                incomplete: selectedDateValues().length < 1
            };
        }

        beginning = scheduleBeginning(startDate, endDate, startTime, endTime, allDay, pattern !== 'none');

        if (pattern === 'none') {
            return {
                text: beginning + ' Does not repeat.',
                incomplete: !allDay && !startTime
            };
        }

        if (pattern === 'custom') {
            return {
                text: beginning + ' ' + customRepeatPhrase(startDate),
                incomplete: false
            };
        }

        return {
            text: beginning + ' ' + presetRepeatPhrase(pattern, startDate),
            incomplete: false
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
        var pattern = getValue(keys.pattern) || 'none';
        var target = pattern === 'custom' ?
            getField(keys.recurrenceEndCount) : getField(keys.pattern);
        var summary = ensureSummaryElement();

        if (target) {
            target.insertAdjacentElement('afterend', summary);
        }
    }

    function syncSummaryVisibility() {
        var root = getRoot();
        var scheduleTab = root ? root.querySelector('.acf-tab-button[data-key="field_glover_tab_schedule"]') : null;
        var scheduleIsActive = Boolean(scheduleTab && scheduleTab.parentElement && scheduleTab.parentElement.classList.contains('active'));

        if (summaryElement) {
            summaryElement.classList.toggle('acf-hidden', !scheduleIsActive);
        }
    }

    function updateRepeatLabels(startDate) {
        var select = getControl(keys.pattern);
        var labels;

        if (!select || select.tagName !== 'SELECT') {
            return;
        }

        labels = {
            weekly: startDate ? 'Weekly on ' + weekdayName(startDate) : 'Weekly',
            monthly: startDate ? 'Monthly on the ' + weekdayPosition(startDate) + ' ' + weekdayName(startDate) : 'Monthly',
            annually: startDate ? 'Annually on ' + formatMonthDay(startDate) : 'Annually'
        };

        Object.keys(labels).forEach(function (value) {
            var option = select.querySelector('option[value="' + value + '"]');

            if (option) {
                option.textContent = labels[value];
            }
        });

        if (select.options[select.selectedIndex]) {
            var rendered = getField(keys.pattern).querySelector('.select2-selection__rendered');

            if (rendered) {
                rendered.textContent = select.options[select.selectedIndex].textContent;
                rendered.setAttribute('title', select.options[select.selectedIndex].textContent);
            }
        }
    }

    function decorateWeekdays() {
        var field = getField(keys.days);

        if (!field) {
            return;
        }

        Array.prototype.forEach.call(field.querySelectorAll('label'), function (label) {
            var input = label.querySelector('input[type="checkbox"]');
            var fullName = input ? dayNames[input.value] : '';

            if (fullName) {
                label.setAttribute('data-short-day', fullName.charAt(0));
                label.setAttribute('title', fullName);
            }
        });
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
            if (checkbox.closest('label')) {
                checkbox.closest('label').classList.add('selected');
            }
        }
    }

    function labelCondensedInputs() {
        var labels = {};

        labels[keys.startDate] = 'Start date';
        labels[keys.endDate] = 'End date';
        labels[keys.startTime] = 'Start time';
        labels[keys.endTime] = 'End time';

        Object.keys(labels).forEach(function (key) {
            var field = getField(key);
            var input = field ? field.querySelector('input.input:not([type="hidden"])') : null;

            if (input) {
                input.setAttribute('placeholder', labels[key]);
                input.setAttribute('aria-label', labels[key]);
            }
        });
    }

    function decorateSectionHeadings() {
        var root = getRoot();

        if (!root) {
            return;
        }

        Array.prototype.forEach.call(root.querySelectorAll('.glover-event-section-heading > .acf-label'), function (labelWrapper) {
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

        decorateSectionHeadings();
        decorateWeekdays();
        labelCondensedInputs();
        updateRepeatLabels(parseDate(getValue(keys.startDate)));
        placeSummary();
        syncSummaryVisibility();

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
        var patternField = getField(keys.pattern);
        var unitField = getField(keys.unit);
        var pattern = getValue(keys.pattern);
        var unit = getValue(keys.unit);

        if (pattern === 'custom' && unit === 'week' &&
            ((patternField && patternField.contains(event.target)) || (unitField && unitField.contains(event.target)))) {
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
