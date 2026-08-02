/**
 * User Selector - Disable Already Assigned Users
 * Prevents selecting users that are already assigned to other employees
 */
(function() {
    function init() {
        // Get assigned user IDs from global config
        const assignedUserIds = window.SlotsConfig?.assignedUserIds || [];
        if (assignedUserIds.length === 0) return;

        Garnish.on(Craft.BaseElementSelectInput, 'selectElements', function(ev) {
            if (ev.target.$input && ev.target.$input.attr('id') === 'userId') {
                setTimeout(function() {
                    const modal = ev.target.elementSelect.modal;
                    if (modal && modal.$container) {
                        modal.$container.find('.element').each(function() {
                            const $element = $(this);
                            const elementId = parseInt($element.attr('data-id'));

                            if (assignedUserIds.includes(elementId)) {
                                $element.addClass('disabled');
                                $element.css('opacity', '0.5');
                                $element.css('cursor', 'not-allowed');
                                $element.on('click', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    Craft.cp.displayNotice('This user is already assigned to another employee.');
                                    return false;
                                });
                            }
                        });
                    }
                }, 100);
            }
        });
    }

    // Initialize when ready
    if (typeof Craft !== 'undefined' && Craft.cp) {
        Craft.cp.on('init', init);
    }
    $(document).ready(init);
})();
