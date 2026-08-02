/**
 * Security Settings page toggles
 * Handles showing/hiding CAPTCHA provider fields based on select value.
 * Lightswitch toggles are handled natively by Craft's `toggle` parameter.
 */
(function() {
    'use strict';

    /**
     * Setup CAPTCHA provider toggle
     */
    function setupCaptchaProviderToggle() {
        var $provider = $('#captchaProvider');
        if (!$provider.length) return;

        function updateProviderVisibility() {
            var selectedProvider = $provider.val();
            $('#recaptcha-settings, #hcaptcha-settings, #turnstile-settings').hide().addClass('hidden');
            if (selectedProvider) {
                $('#' + selectedProvider + '-settings').show().removeClass('hidden');
            }
        }

        updateProviderVisibility();
        $provider.on('change', updateProviderVisibility);
    }

    function init() {
        if (!$('#enableCaptcha').length) return;
        setupCaptchaProviderToggle();
    }

    $(document).ready(init);
})();
