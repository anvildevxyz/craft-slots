/**
 * Calendar Filters - Handle status filtering for calendar views
 *
 * Note: Filtering currently only works in month view (data-legend-status / data-booking-status
 * attributes). Week and day views do not support status filtering yet.
 */

(function() {
    'use strict';

    // Get status filter state from localStorage or default to all visible
    function getStatusFilters() {
        const stored = localStorage.getItem('slots-calendar-status-filters');
        if (stored) {
            try {
                return JSON.parse(stored);
            } catch (e) {
                return { confirmed: true, pending: true, cancelled: true };
            }
        }
        return { confirmed: true, pending: true, cancelled: true };
    }

    // Save status filter state to localStorage
    function saveStatusFilters(filters) {
        localStorage.setItem('slots-calendar-status-filters', JSON.stringify(filters));
    }

    // Apply filters to calendar bookings
    function applyFilters() {
        const filters = getStatusFilters();
        const bookings = document.querySelectorAll('[data-booking-status]');
        
        bookings.forEach(function(booking) {
            const status = booking.getAttribute('data-booking-status');
            const isVisible = filters[status] !== false; // Default to true if undefined
            
            if (isVisible) {
                booking.style.display = '';
                booking.classList.remove('calendar-booking-hidden');
            } else {
                booking.style.display = 'none';
                booking.classList.add('calendar-booking-hidden');
            }
        });

        // Update legend visual state
        updateLegendState(filters);
    }

    // Update legend visual state
    function updateLegendState(filters) {
        const legendItems = document.querySelectorAll('[data-legend-status]');
        legendItems.forEach(function(item) {
            const status = item.getAttribute('data-legend-status');
            const isActive = filters[status] !== false;
            
            if (isActive) {
                item.classList.remove('calendar-legend-inactive');
                item.classList.add('calendar-legend-active');
            } else {
                item.classList.remove('calendar-legend-active');
                item.classList.add('calendar-legend-inactive');
            }
        });
    }

    // Initialize when DOM is ready
    function init() {
        // Apply filters on page load
        applyFilters();

        // Add click handlers to legend items
        const legendItems = document.querySelectorAll('[data-legend-status]');
        legendItems.forEach(function(item) {
            item.style.cursor = 'pointer';
            item.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const status = this.getAttribute('data-legend-status');
                const filters = getStatusFilters();
                
                // Toggle filter state: if currently false, set to true; otherwise set to false
                // (treating undefined as true/visible)
                filters[status] = filters[status] === false ? true : false;
                saveStatusFilters(filters);
                applyFilters();
            });
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
