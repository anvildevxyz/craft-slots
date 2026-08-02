/**
 * Booking Time Calculator - Auto-calculate end time based on start time
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const startTimeField = document.getElementById('startTime');
        const endTimeField = document.getElementById('endTime');

        if (startTimeField && endTimeField) {
            startTimeField.addEventListener('change', function() {
                const startTime = this.value;

                if (startTime && !endTimeField.value) {
                    const start = new Date('2000-01-01 ' + startTime);
                    start.setMinutes(start.getMinutes() + 60); // Default 60 minutes

                    const hours = start.getHours().toString().padStart(2, '0');
                    const minutes = start.getMinutes().toString().padStart(2, '0');
                    endTimeField.value = hours + ':' + minutes;
                }
            });
        }
    });
})();
