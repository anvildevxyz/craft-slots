/**
 * Booking Settings - Currency selectize
 * Lightswitch toggles are handled natively by Craft's `toggle` parameter.
 */
(function() {
    'use strict';

    function init() {
        // Initialize selectize for currency dropdown (searchable select)
        var $currency = $('#defaultCurrency');
        if ($currency.length) {
            $currency.selectize({
                dropdownParent: 'body',
            });
        }
    }

    $(document).ready(init);
})();
