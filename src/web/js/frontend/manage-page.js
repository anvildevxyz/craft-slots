/**
 * Slots — self-service booking management page (`/booking/manage/<token>`).
 *
 * This is the page customers reach from the link in their confirmation email.
 * It used to carry its two behaviours as inline <script> blocks, which a strict
 * Content-Security-Policy blocks outright — so the emailed link was the one
 * front-end surface that could not run under the CSP posture the wizard is
 * built for. Everything the markup needs now arrives in a JSON data block
 * (`<script type="application/json">` is inert, so CSP leaves it alone) and the
 * behaviour lives here, in a file the browser can be told to trust.
 *
 * Both sections are optional: the template renders them only when the booking
 * can actually be changed, so each initializer bails when its markup is absent.
 */
(function () {
    'use strict';

    var configEl = document.querySelector('[data-slots-manage-config]');
    if (!configEl) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return;
    }

    var csrfName = config.csrf.name;
    var csrfToken = config.csrf.value;
    var labels = config.labels || {};

    /** Show or clear a section's error line. */
    function errorSetter(errorEl) {
        return function (msg) {
            errorEl.textContent = msg || '';
            errorEl.style.display = msg ? 'block' : 'none';
        };
    }

    function postForm(url, params) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: params.toString()
        }).then(function (r) { return r.json(); });
    }

    // ── Quantity ────────────────────────────────────────────────────────────
    (function () {
        var input = document.getElementById('slots-new-quantity');
        var submitBtn = document.getElementById('slots-quantity-submit');
        var errorEl = document.getElementById('slots-quantity-error');
        if (!input || !submitBtn || !errorEl) {
            return;
        }

        var currentQty = config.quantity;
        var showError = errorSetter(errorEl);

        input.addEventListener('input', function () {
            showError('');
            var newQty = parseInt(input.value, 10);
            submitBtn.disabled = isNaN(newQty) || newQty < 1 || newQty === currentQty;
        });

        submitBtn.addEventListener('click', function () {
            var newQty = parseInt(input.value, 10);
            if (isNaN(newQty) || newQty < 1 || newQty === currentQty) return;

            showError('');
            submitBtn.disabled = true;

            var isReduce = newQty < currentQty;
            var action = isReduce ? config.actions.reduceQuantity : config.actions.increaseQuantity;

            var params = new URLSearchParams();
            params.append(csrfName, csrfToken);
            params.append('id', config.reservationId);
            params.append('token', config.token);
            params.append(isReduce ? 'reduceBy' : 'increaseBy', Math.abs(newQty - currentQty));

            postForm(action, params)
                .then(function (data) {
                    if (data.success) {
                        window.location.href = config.redirectUrl;
                    } else {
                        showError(data.message || data.error || labels.quantityFailed);
                        submitBtn.disabled = false;
                    }
                })
                .catch(function () {
                    showError(labels.quantityFailed);
                    submitBtn.disabled = false;
                });
        });
    })();

    // ── Reschedule ──────────────────────────────────────────────────────────
    (function () {
        var dateInput = document.getElementById('slots-reschedule-date');
        var slotsEl = document.getElementById('slots-reschedule-slots');
        var submitBtn = document.getElementById('slots-reschedule-submit');
        var errorEl = document.getElementById('slots-reschedule-error');
        if (!dateInput || !slotsEl || !submitBtn || !errorEl) {
            return;
        }

        var showError = errorSetter(errorEl);
        var selected = null;

        function setSelected(slot, button) {
            selected = slot;
            Array.prototype.forEach.call(
                slotsEl.querySelectorAll('.slots-reschedule-slot'),
                function (el) {
                    el.classList.remove('is-selected');
                    el.setAttribute('aria-pressed', 'false');
                }
            );
            if (button) {
                button.classList.add('is-selected');
                button.setAttribute('aria-pressed', 'true');
            }
            submitBtn.disabled = !slot;
        }

        function renderSlots(slots) {
            slotsEl.textContent = '';
            setSelected(null, null);

            if (!slots || !slots.length) {
                var empty = document.createElement('p');
                empty.className = 'slots-manage-notice';
                empty.textContent = labels.none;
                slotsEl.appendChild(empty);
                return;
            }

            slots.forEach(function (slot) {
                // The slot the booking already occupies is not a move.
                var isCurrent = dateInput.value === config.currentDate
                    && slot.time.substring(0, 5) === config.currentStart.substring(0, 5);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'slots-reschedule-slot';
                btn.setAttribute('aria-pressed', 'false');
                btn.textContent = slot.time.substring(0, 5);

                if (isCurrent) {
                    btn.disabled = true;
                    btn.classList.add('is-current');
                    btn.title = labels.current;
                } else {
                    btn.addEventListener('click', function () {
                        showError('');
                        setSelected(slot, btn);
                    });
                }

                slotsEl.appendChild(btn);
            });
        }

        function loadSlots() {
            showError('');
            setSelected(null, null);
            slotsEl.textContent = labels.loading;

            var params = new URLSearchParams();
            params.append('date', dateInput.value);
            params.append('quantity', config.quantity);
            // Discount this booking from its own availability, so the grid shows
            // where it can actually move to.
            params.append('manageToken', config.token);
            if (config.employeeId) { params.append('employeeId', config.employeeId); }
            if (config.locationId) { params.append('locationId', config.locationId); }
            if (config.serviceId) { params.append('serviceId', config.serviceId); }
            params.append(csrfName, csrfToken);

            postForm(config.actions.availableSlots, params)
                .then(function (data) {
                    if (!data || data.success === false) {
                        slotsEl.textContent = '';
                        showError((data && (data.message || data.error)) || labels.failed);
                        return;
                    }
                    renderSlots(data.slots || (data.data && data.data.slots) || []);
                })
                .catch(function () {
                    slotsEl.textContent = '';
                    showError(labels.failed);
                });
        }

        submitBtn.addEventListener('click', function () {
            if (!selected) { return; }
            submitBtn.disabled = true;
            showError('');

            var params = new URLSearchParams();
            params.append('action', 'reschedule');
            params.append('manageToken', config.token);
            params.append('newDate', dateInput.value);
            params.append('newStartTime', selected.time);
            params.append('newEndTime', selected.endTime);
            params.append(csrfName, csrfToken);

            postForm(config.actions.manageBooking, params)
                .then(function (data) {
                    if (data && data.success) {
                        window.location.href = config.redirectUrl;
                        return;
                    }
                    submitBtn.disabled = false;
                    showError((data && (data.message || data.error)) || labels.failed);
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    showError(labels.failed);
                });
        });

        dateInput.addEventListener('change', loadSlots);
        loadSlots();
    })();
})();
