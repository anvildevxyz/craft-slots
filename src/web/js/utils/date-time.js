/**
 * Date/Time Utilities
 * 
 * Shared date and time formatting functions for the Slots plugin
 */
window.SlotsDateTime = {
    /**
     * Format a date string to locale date string
     * 
     * @param {string} dateString - Date string in YYYY-MM-DD format
     * @param {object} options - Intl.DateTimeFormat options
     * @returns {string} - Formatted date string
     */
    formatDate(dateString, options = {}) {
        if (!dateString) {
            return '';
        }

        try {
            // Parse YYYY-MM-DD format
            const date = new Date(dateString + 'T00:00:00');
            
            // Default options for date formatting
            const defaultOptions = {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            };
            
            return date.toLocaleDateString(undefined, { ...defaultOptions, ...options });
        } catch (error) {
            console.error('SlotsDateTime: Error formatting date', error);
            return dateString;
        }
    },

    /**
     * Format a time string to locale time string
     * 
     * @param {string} timeString - Time string in HH:MM or HH:MM:SS format
     * @param {object} options - Intl.DateTimeFormat options
     * @returns {string} - Formatted time string
     */
    formatTime(timeString, options = {}) {
        if (!timeString) {
            return '';
        }

        try {
            // Parse HH:MM or HH:MM:SS format
            const [hours, minutes] = timeString.split(':');
            const date = new Date();
            date.setHours(parseInt(hours, 10), parseInt(minutes, 10), 0);
            
            // Default options for time formatting
            const defaultOptions = {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            };
            
            return date.toLocaleTimeString(undefined, { ...defaultOptions, ...options });
        } catch (error) {
            console.error('SlotsDateTime: Error formatting time', error);
            return timeString;
        }
    },

    /**
     * Summarize which days of the week are enabled in a working hours object.
     *
     * @param {object} workingHours - Keyed by day number (1-7), each with `enabled` boolean
     * @param {object} dayNames     - Map of day number to full name (e.g. {1:'Monday',...})
     * @returns {string} e.g. "Mon, Tue, Wed, Thu, Fri" or "None"
     */
    getActiveDaysSummary(workingHours, dayNames) {
        if (!workingHours) return 'None';
        var active = [];
        for (var d = 1; d <= 7; d++) {
            var h = workingHours[d] || workingHours[String(d)];
            if (h && h.enabled) {
                var name = (dayNames && dayNames[d]) || ('Day' + d);
                active.push(name.substring(0, 3));
            }
        }
        return active.length > 0 ? active.join(', ') : 'None';
    },

    /**
     * Summarize the unique hour ranges across enabled days.
     *
     * @param {object} workingHours - Keyed by day number (1-7)
     * @returns {string} e.g. "09:00-17:00 (break: 12:00-13:00)" or "No hours set"
     */
    getHoursSummary(workingHours) {
        if (!workingHours) return 'N/A';
        var hours = [];
        for (var d = 1; d <= 7; d++) {
            var h = workingHours[d] || workingHours[String(d)];
            if (h && h.enabled) {
                var str = (h.start || '09:00') + '-' + (h.end || '17:00');
                if (h.breakStart && h.breakEnd) {
                    str += ' (break: ' + h.breakStart + '-' + h.breakEnd + ')';
                }
                if (hours.indexOf(str) === -1) {
                    hours.push(str);
                }
            }
        }
        return hours.length > 0 ? hours.join('; ') : 'No hours set';
    },

    /**
     * Format a date range
     *
     * @param {string} startDate - Start date string
     * @param {string} endDate - End date string
     * @param {object} options - Formatting options
     * @returns {string} - Formatted date range string
     */
    formatDateRange(startDate, endDate, options = {}) {
        if (startDate && endDate) {
            return this.formatDate(startDate, options) + ' - ' + this.formatDate(endDate, options);
        } else if (startDate) {
            return 'From ' + this.formatDate(startDate, options);
        } else if (endDate) {
            return 'Until ' + this.formatDate(endDate, options);
        }
        return '';
    }
};
