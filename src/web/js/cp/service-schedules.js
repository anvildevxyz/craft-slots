/**
 * Service Schedules Management
 * Handles CRUD operations for service availability schedules
 * 
 * Requires window.SlotsServiceSchedules config object with:
 * - serviceId: number
 * - hasServiceId: boolean
 * - strings: object (translations)
 * - locale: string
 * - days: object (day names)
 */
(function() {
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function init() {
        const config = window.SlotsServiceSchedules;
        if (!config) return;
        
        const serviceId = config.serviceId;
        const hasServiceId = config.hasServiceId;
        const strings = config.strings;
        const locale = config.locale;
        const days = config.days;
        const schedulesList = document.getElementById('schedules-list');
        const addBtn = document.getElementById('add-schedule-btn');
        const pendingSchedulesInput = document.getElementById('pending-schedules');
        
        if (!schedulesList || !addBtn) return;
        
        let schedules = [];
        
        // Load existing schedules or pending schedules
        function loadSchedules() {
            if (hasServiceId && serviceId) {
                const formData = new FormData();
                formData.append('serviceId', serviceId);
                formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
                
                fetch(Craft.getActionUrl('slots/cp/services/get-schedules'), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Server error: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        schedules = data.schedules || [];
                        renderSchedules();
                    } else {
                        console.error('API returned error:', data.message || 'Unknown error');
                        schedules = [];
                        renderSchedules();
                    }
                })
                .catch(error => {
                    console.error('Error loading schedules:', error);
                    schedules = [];
                    renderSchedules();
                });
            } else {
                // Load pending schedules from hidden input
                if (pendingSchedulesInput && pendingSchedulesInput.value) {
                    try {
                        schedules = JSON.parse(pendingSchedulesInput.value);
                        renderSchedules();
                    } catch (e) {
                        console.error('Error parsing pending schedules:', e);
                        schedules = [];
                        renderSchedules();
                    }
                } else {
                    schedules = [];
                    renderSchedules();
                }
            }
        }
        
        // Render schedule list
        function renderSchedules() {
            if (schedules.length === 0) {
                schedulesList.innerHTML = '<p class="light">' + strings.noSchedules + '</p>';
                return;
            }
            
            let html = '';
            schedules.forEach(function(schedule, index) {
                const enabled = schedule.enabled !== false;
                const title = schedule.title || (strings.untitledSchedule || 'Untitled Schedule');
                const startDate = schedule.startDate || '';
                const endDate = schedule.endDate || '';
                const scheduleId = schedule.id || ('pending-' + index);
                const availabilitySchedule = schedule.availabilitySchedule || {};
                const activeDays = SlotsDateTime.getActiveDaysSummary(availabilitySchedule, days);
                const hoursSummary = SlotsDateTime.getHoursSummary(availabilitySchedule);
                const unlimitedLabel = strings.unlimited || 'Unlimited';
                const dateRange = (!startDate && !endDate) ? unlimitedLabel :
                                 (!startDate) ? (strings.until || 'Until') + ' ' + escapeHtml(endDate) :
                                 (!endDate) ? (strings.from || 'From') + ' ' + escapeHtml(startDate) :
                                 escapeHtml(startDate) + ' ' + (strings.to || 'to') + ' ' + escapeHtml(endDate);

                html += '<div class="schedule-item" data-schedule-id="' + escapeHtml(String(scheduleId)) + '" style="border: 1px solid #e3e5e8; padding: 16px; margin-bottom: 16px; border-radius: 4px; background: ' + (enabled ? '#fafafa' : '#f5f5f5') + ';">';
                html += '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">';
                html += '<div style="flex: 1;">';
                html += '<div style="margin-bottom: 8px;">';
                html += '<strong style="font-size: 16px;">' + escapeHtml(title) + '</strong>';
                html += '<span class="light" style="margin-left: 12px; padding: 2px 8px; background: ' + (enabled ? '#d4edda' : '#f8d7da') + '; color: ' + (enabled ? '#155724' : '#721c24') + '; border-radius: 3px; font-size: 12px;">' + (enabled ? (strings.enabled || 'Enabled') : (strings.disabled || 'Disabled')) + '</span>';
                html += '</div>';
                html += '<div class="light" style="font-size: 13px; line-height: 1.6;">';
                html += '<div><strong>' + (strings.daysLabel || 'Days:') + '</strong> ' + escapeHtml(activeDays) + '</div>';
                html += '<div><strong>' + (strings.hoursLabel || 'Hours:') + '</strong> ' + escapeHtml(hoursSummary) + '</div>';
                html += '<div><strong>' + (strings.dateRangeLabel || 'Date Range:') + '</strong> ' + dateRange + '</div>';
                const capacity = schedule.capacity !== null && schedule.capacity !== undefined ? schedule.capacity : null;
                html += '<div><strong>' + (strings.capacityLabel || 'Capacity:') + '</strong> ' + (capacity !== null ? escapeHtml(String(capacity)) + ' ' + (strings.people || 'people') : unlimitedLabel) + '</div>';
                html += '</div>';
                html += '</div>';
                html += '<div>';
                html += '<button type="button" class="btn small edit-schedule" data-id="' + escapeHtml(String(scheduleId)) + '" data-index="' + index + '">' + escapeHtml(strings.editSchedule) + '</button>';
                html += '<button type="button" class="btn small delete-schedule" data-id="' + escapeHtml(String(scheduleId)) + '" data-index="' + index + '" style="margin-left: 8px;">' + escapeHtml(strings.deleteSchedule) + '</button>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
            schedulesList.innerHTML = html;
            
            // Attach event listeners
            schedulesList.querySelectorAll('.edit-schedule').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const id = btn.dataset.id;
                    const index = parseInt(btn.dataset.index);
                    if (id.toString().startsWith('pending-')) {
                        editSchedule(null, index);
                    } else {
                        editSchedule(parseInt(id));
                    }
                });
            });
            
            schedulesList.querySelectorAll('.delete-schedule').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const id = btn.dataset.id;
                    const index = parseInt(btn.dataset.index);
                    if (id.toString().startsWith('pending-')) {
                        deletePendingSchedule(index);
                    } else {
                        deleteSchedule(parseInt(id));
                    }
                });
            });
            
            // Save pending schedules to hidden input if no service ID
            if (!hasServiceId && pendingSchedulesInput) {
                pendingSchedulesInput.value = JSON.stringify(schedules);
            }
        }
        
        // Show schedule editor modal using Craft CMS modal system
        function showScheduleEditor(schedule, pendingIndex) {
            const isNew = !schedule || (!schedule.id && pendingIndex === undefined);
            const scheduleData = schedule || {
                title: '',
                enabled: true,
                startDate: '',
                endDate: '',
                capacity: null,
                availabilitySchedule: {}
            };
            
            // Build availability schedule table
            let availabilityHtml = '<table class="data fullwidth"><thead><tr>';
            availabilityHtml += '<th>' + escapeHtml(days[1] ? days[1].split(' ')[0] : (strings.dayLabel || 'Day')) + '</th>';
            availabilityHtml += '<th>' + (strings.availableLabel || 'Available') + '</th><th>' + (strings.startLabel || 'Start') + '</th><th>' + (strings.endLabel || 'End') + '</th><th>' + (strings.breakStartLabel || 'Break Start') + '</th><th>' + (strings.breakEndLabel || 'Break End') + '</th>';
            availabilityHtml += '</tr></thead><tbody>';
            
            for (let dayNum = 1; dayNum <= 7; dayNum++) {
                const daySchedule = scheduleData.availabilitySchedule && scheduleData.availabilitySchedule[dayNum] ? scheduleData.availabilitySchedule[dayNum] : {enabled: false, start: '09:00', end: '17:00', breakStart: '', breakEnd: ''};
                availabilityHtml += '<tr data-day="' + dayNum + '">';
                availabilityHtml += '<td><strong>' + escapeHtml(days[dayNum] || '') + '</strong></td>';
                availabilityHtml += '<td><input type="checkbox" name="availabilitySchedule[' + dayNum + '][enabled]" ' + (daySchedule.enabled ? 'checked' : '') + '></td>';
                availabilityHtml += '<td><input type="time" name="availabilitySchedule[' + dayNum + '][start]" value="' + (daySchedule.start || '09:00') + '" class="text fullwidth"></td>';
                availabilityHtml += '<td><input type="time" name="availabilitySchedule[' + dayNum + '][end]" value="' + (daySchedule.end || '17:00') + '" class="text fullwidth"></td>';
                availabilityHtml += '<td><input type="time" name="availabilitySchedule[' + dayNum + '][breakStart]" value="' + (daySchedule.breakStart || '') + '" class="text fullwidth"></td>';
                availabilityHtml += '<td><input type="time" name="availabilitySchedule[' + dayNum + '][breakEnd]" value="' + (daySchedule.breakEnd || '') + '" class="text fullwidth"></td>';
                availabilityHtml += '</tr>';
            }
            availabilityHtml += '</tbody></table>';
            
            // Create modal using Craft CMS structure with proper scrolling
            const $form = $('<form class="modal schedule-editor-modal" style="padding-bottom: 58px;">' +
                '<div class="header">' +
                    '<h1>' + escapeHtml(isNew ? strings.addSchedule : strings.editSchedule) + '</h1>' +
                '</div>' +
                '<div class="body" style="height: 100%; overflow-y: auto; overflow-x: hidden; padding: 24px; padding-bottom: 100px;">' +
                    '<div class="field">' +
                        '<div class="heading">' +
                            '<label>' + escapeHtml(strings.title) + '</label>' +
                        '</div>' +
                        '<div class="input">' +
                            '<input type="text" id="schedule-title" class="text fullwidth">' +
                        '</div>' +
                    '</div>' +
                    '<div class="field">' +
                        '<div class="heading">' +
                            '<label>' +
                                '<input type="checkbox" id="schedule-enabled" ' + (scheduleData.enabled !== false ? 'checked' : '') + '> ' + escapeHtml(strings.enabled) +
                            '</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="field">' +
                        '<div class="heading">' +
                            '<label>' + escapeHtml(strings.startDate) + '</label>' +
                        '</div>' +
                        '<div class="input">' +
                            '<input type="date" id="schedule-start-date" value="' + (scheduleData.startDate || '') + '" class="text fullwidth">' +
                        '</div>' +
                    '</div>' +
                    '<div class="field">' +
                        '<div class="heading">' +
                            '<label>' + escapeHtml(strings.endDate) + '</label>' +
                        '</div>' +
                        '<div class="input">' +
                            '<input type="date" id="schedule-end-date" value="' + (scheduleData.endDate || '') + '" class="text fullwidth">' +
                        '</div>' +
                    '</div>' +
                    '<div class="field">' +
                        '<div class="heading">' +
                            '<label>' + escapeHtml(strings.capacity) + '</label>' +
                        '</div>' +
                        '<div class="instructions">' +
                            '<p class="light">' + escapeHtml(strings.capacityInstructions) + '</p>' +
                        '</div>' +
                        '<div class="input">' +
                            '<input type="number" id="schedule-capacity" value="' + (scheduleData.capacity || '') + '" class="text fullwidth" min="1" placeholder="' + escapeHtml(strings.unlimited || 'Unlimited') + '">' +
                        '</div>' +
                    '</div>' +
                    '<div class="field" style="margin-bottom: 40px;">' +
                        '<div class="heading">' +
                            '<label>' + escapeHtml(strings.availabilitySchedule) + '</label>' +
                        '</div>' +
                        '<div class="input">' + availabilityHtml + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="footer" style="position: absolute; left: 0; right: 0; bottom: 0; border-top: 1px solid #e3e5e8; padding: 14px 24px; background: #fafafa;">' +
                    '<div class="buttons right">' +
                        '<div class="btn cancel-btn">' + escapeHtml(strings.cancel) + '</div>' +
                        '<input type="submit" class="btn submit" value="' + escapeHtml(strings.saveSchedule) + '">' +
                    '</div>' +
                '</div>' +
            '</form>');
            
            const modal = new Garnish.Modal($form, {
                resizable: true,
                maxWidth: 900,
                maxHeight: '85vh',
                onHide: function() {
                    modal.destroy();
                    $form.remove();
                }
            });
            
            const $modal = $form;

            // Set title via DOM API to prevent XSS
            $modal.find('#schedule-title').val(scheduleData.title || '');

            $form.on('submit', function(ev) {
                ev.preventDefault();
                
                const availabilitySchedule = {};
                $modal.find('tbody tr').each(function() {
                    const $row = $(this);
                    const dayNum = parseInt($row.data('day'));
                    const enabled = $row.find('input[type="checkbox"]').is(':checked');
                    const start = $row.find('input[name*="[start]"]').val();
                    const end = $row.find('input[name*="[end]"]').val();
                    const breakStart = $row.find('input[name*="[breakStart]"]').val();
                    const breakEnd = $row.find('input[name*="[breakEnd]"]').val();
                    
                    availabilitySchedule[dayNum] = {
                        enabled: enabled,
                        start: start,
                        end: end,
                        breakStart: breakStart || null,
                        breakEnd: breakEnd || null,
                    };
                });
                
                const capacityValue = $modal.find('#schedule-capacity').val();
                const capacity = capacityValue && capacityValue.trim() !== '' ? parseInt(capacityValue) : null;
                
                const newScheduleData = {
                    id: isNew ? null : schedule.id,
                    serviceId: hasServiceId ? serviceId : null,
                    title: $modal.find('#schedule-title').val(),
                    enabled: $modal.find('#schedule-enabled').is(':checked'),
                    startDate: $modal.find('#schedule-start-date').val() || null,
                    endDate: $modal.find('#schedule-end-date').val() || null,
                    capacity: capacity,
                    availabilitySchedule: availabilitySchedule,
                    _pendingIndex: pendingIndex,
                };
                
                if (hasServiceId && serviceId) {
                    saveSchedule(newScheduleData, function() {
                        modal.hide();
                    });
                } else {
                    savePendingSchedule(newScheduleData, pendingIndex, function() {
                        modal.hide();
                    });
                }
            });
            
            $modal.find('.cancel-btn').on('click', function() {
                modal.hide();
            });
        }
        
        function addSchedule() {
            showScheduleEditor(null, undefined);
        }
        
        function editSchedule(id, pendingIndex) {
            if (pendingIndex !== undefined) {
                const schedule = schedules[pendingIndex];
                if (schedule) {
                    showScheduleEditor(schedule, pendingIndex);
                }
            } else if (id !== null) {
                const schedule = schedules.find(s => s.id === id);
                if (schedule) {
                    showScheduleEditor(schedule);
                }
            }
        }
        
        function savePendingSchedule(scheduleData, pendingIndex, callback) {
            delete scheduleData._pendingIndex;
            delete scheduleData.serviceId;
            delete scheduleData.id;
            
            if (pendingIndex !== undefined) {
                // Update existing pending schedule
                schedules[pendingIndex] = scheduleData;
            } else {
                // Add new pending schedule
                schedules.push(scheduleData);
            }
            
            // Save to hidden input
            if (pendingSchedulesInput) {
                pendingSchedulesInput.value = JSON.stringify(schedules);
            }
            
            renderSchedules();
            Craft.cp.displayNotice(strings.saved);
            if (callback) callback();
        }
        
        function deletePendingSchedule(index) {
            if (!confirm(strings.confirmDelete || 'Are you sure you want to delete this schedule?')) {
                return;
            }
            
            schedules.splice(index, 1);
            
            // Save to hidden input
            if (pendingSchedulesInput) {
                pendingSchedulesInput.value = JSON.stringify(schedules);
            }
            
            Craft.cp.displayNotice(strings.deleted);
            renderSchedules();
        }
        
        function saveSchedule(scheduleData, callback) {
            const formData = new FormData();
            Object.keys(scheduleData).forEach(function(key) {
                if (key === 'availabilitySchedule') {
                    // Send as JSON string - controller will decode it
                    formData.append(key, JSON.stringify(scheduleData[key]));
                } else if (key === 'enabled') {
                    // Explicitly send '1' or '0' as string for boolean
                    formData.append(key, scheduleData[key] ? '1' : '0');
                } else if (scheduleData[key] !== null && scheduleData[key] !== undefined) {
                    formData.append(key, scheduleData[key]);
                }
            });
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            
            fetch(Craft.getActionUrl('slots/cp/services/save-schedule'), {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('Server error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    Craft.cp.displayNotice(strings.saved);
                    loadSchedules();
                    if (callback) callback();
                } else {
                    Craft.cp.displayError(data.message || strings.errorSaving);
                }
            })
            .catch(error => {
                Craft.cp.displayError(strings.errorSaving);
                console.error('Error saving schedule:', error);
            });
        }
        
        function deleteSchedule(id) {
            if (!confirm(strings.confirmDelete || 'Are you sure you want to delete this schedule?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('id', id);
            formData.append(Craft.csrfTokenName, Craft.csrfTokenValue);
            
            fetch(Craft.getActionUrl('slots/cp/services/delete-schedule'), {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('Server error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    Craft.cp.displayNotice(strings.deleted);
                    loadSchedules();
                } else {
                    Craft.cp.displayError(data.message || strings.errorDeleting);
                }
            })
            .catch(error => {
                Craft.cp.displayError(strings.errorDeleting);
                console.error('Error deleting schedule:', error);
            });
        }
        
        addBtn.addEventListener('click', addSchedule);
        
        loadSchedules();
        
        // Save pending schedules to hidden input before form submission (if creating new service)
        if (!hasServiceId) {
            const $mainForm = $('form[action*="services/save"]');
            if ($mainForm.length) {
                $mainForm.on('submit', function() {
                    // Save pending schedules to hidden input before form submission
                    // The controller will process these schedules after saving the service
                    if (pendingSchedulesInput) {
                        pendingSchedulesInput.value = JSON.stringify(schedules);
                    }
                });
            }
        }
    }

    // Initialize when ready
    if (typeof Craft !== 'undefined' && Craft.cp) {
        Craft.cp.on('init', init);
    }
    $(document).ready(init);
})();
