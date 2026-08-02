# Tutorial: Your First Booking in 15 Minutes

A hands-on guide to setting up a working booking system. By the end, you'll have a service, an employee with a schedule, and a functional booking form.

---

## Prerequisites

- Craft CMS 5.x installed and running
- Slots plugin installed (`composer require anvildev/craft-slots && php craft plugin/install slots`)
- Access to the Craft Control Panel

---

## Step 1: Create a Service

Services are what customers book - haircuts, consultations, tours, classes, etc.

1. Go to **Slots → Services** in the Control Panel
2. Click **New Service**
3. Fill in:
   - **Title**: `Consultation`
   - **Duration**: `60` (minutes)
   - **Price**: `100`
   - **Buffer Before**: `0`
   - **Buffer After**: `15` (cleanup time between appointments)
4. Click **Save**

You now have a 60-minute consultation service with 15 minutes buffer after each booking.

---

## Step 2: Create an Employee

Employees are the people who perform services. Skip this step if you're creating a service-based booking (like a tour with capacity).

1. Go to **Slots → Employees**
2. Click **New Employee**
3. Fill in:
   - **Name**: `Sarah Johnson`
   - **Email**: `sarah@example.com`
4. Under **Services**, check `Consultation`
5. Click **Save**

> **Tip: Staff access & managed employees**
> If you want Sarah to log in and manage her own bookings, link her to a Craft user account via the **User** field on her employee page. She'll then see her bookings in the control panel.
>
> If Sarah also manages other employees (e.g. a team lead overseeing junior staff), use the **Managed Employees** field on her employee page to assign them. She'll see their bookings too — without those employees needing their own Craft accounts. See the [Developer Guide](DEVELOPER_GUIDE.md#staff-permissions--managed-employees) for details.

---

## Step 3: Create a Schedule

Schedules define when employees (or services) are available.

1. Go to **Slots → Schedules**
2. Click **New Schedule**
3. Fill in:
   - **Title**: `Regular Hours`
4. Under **Working Hours**, configure Monday-Friday:
   - **Enabled**: Yes
   - **Start**: `09:00`
   - **End**: `17:00`
   - **Break Start**: `12:00`
   - **Break End**: `13:00`
5. Leave Saturday and Sunday disabled
6. Click **Save**

### Assign Schedule to Employee

1. Go back to **Slots → Employees**
2. Edit **Sarah Johnson**
3. Under **Schedules**, click **Add Schedule**
4. Select `Regular Hours`
5. Click **Save**

Sarah is now available Monday-Friday, 9 AM - 5 PM, with a lunch break at noon.

---

## Step 4: Add the Booking Form

Create a simple booking page in your templates.

### Option A: Use the Built-in Wizard

Create `templates/book.twig`:

```twig
{% extends "_layout" %}

{% block content %}
    <h1>Book an Appointment</h1>
    {{ craft.slots.getWizard() }}
{% endblock %}
```

The wizard automatically handles service selection, extras (optional add-ons), employee selection, date/time picking, and form submission.

A step is only shown when it offers a real choice, so it is skipped when:

| Step | Skipped when |
| --- | --- |
| Service | Exactly one service exists (it is selected automatically) |
| Extras | The selected service has no add-ons |
| Location | Zero or one location serves the service |
| Employee | Zero or one employee offers the service |

With one service, one location and one employee, the wizard opens directly on the calendar. Auto-selected values still appear on the review step, so the customer confirms them before booking.

### Option B: Build a Custom Form

For more control, build a step-by-step form: pick an employee and date, see available slots, click one to select it, then fill in your details and confirm.

Create `templates/book.twig`:

```twig
{% extends "_layout" %}

{% block content %}
    <h1>Book a Consultation</h1>

    {% set service = craft.slots.services().slug('consultation').one() %}
    {% set employees = craft.slots.employees().serviceId(service.id).all() %}

    {# Step 1: Employee + Date #}
    <label>
        <span>Select Staff</span>
        <select id="employee">
            <option value="">Choose...</option>
            {% for employee in employees %}
                <option value="{{ employee.id }}">{{ employee.title }}</option>
            {% endfor %}
        </select>
    </label>

    <label>
        <span>Date</span>
        <input type="date" id="date" min="{{ 'now'|date('Y-m-d') }}">
    </label>

    {# Step 2: Available slots appear here #}
    <div id="slots-container" style="display:none">
        <p><strong>Pick a time</strong></p>
        <div id="slots"></div>
    </div>

    {# Step 3: Customer info (shown after picking a slot) #}
    <div id="customer-form" style="display:none">
        <label>
            <span>Your Name</span>
            <input type="text" id="name" required>
        </label>

        <label>
            <span>Your Email</span>
            <input type="email" id="email" required>
        </label>

        <label>
            <span>Phone (optional)</span>
            <input type="tel" id="phone">
        </label>

        <form method="post" id="booking-form">
            {{ csrfInput() }}
            {{ actionInput('slots/booking/create-booking') }}
            {{ hiddenInput('serviceId', service.id) }}
            {{ redirectInput('/book/confirmation') }}
            <input type="hidden" name="employeeId" id="form-employee">
            <input type="hidden" name="date" id="form-date">
            <input type="hidden" name="startTime" id="form-time">
            <input type="hidden" name="userName" id="form-name">
            <input type="hidden" name="userEmail" id="form-email">
            <input type="hidden" name="userPhone" id="form-phone">
            <button type="submit">Confirm Booking</button>
        </form>
    </div>

    <script>
    const serviceId = {{ service.id }};
    const dateInput = document.getElementById('date');
    const employeeSelect = document.getElementById('employee');
    const slotsContainer = document.getElementById('slots-container');
    const slotsDiv = document.getElementById('slots');
    const customerForm = document.getElementById('customer-form');
    let selectedTime = null;

    async function fetchSlots() {
        const date = dateInput.value;
        const employeeId = employeeSelect.value;
        if (!date) return;

        slotsDiv.textContent = 'Loading...';
        slotsContainer.style.display = '';
        customerForm.style.display = 'none';
        selectedTime = null;

        const body = new FormData();
        body.append('date', date);
        body.append('serviceId', serviceId);
        if (employeeId) {
            body.append('employeeId', employeeId);
        }
        body.append('{{ craft.app.config.general.csrfTokenName }}', '{{ craft.app.request.csrfToken }}');

        const response = await fetch('/actions/slots/slot/get-available-slots', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: body,
        });
        const data = await response.json();

        slotsDiv.innerHTML = '';

        if (data.slots && data.slots.length > 0) {
            data.slots.forEach(slot => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = slot.time;
                btn.addEventListener('click', () => pickSlot(btn, slot.time));
                slotsDiv.appendChild(btn);
            });
        } else {
            slotsDiv.textContent = 'No availability for this date';
        }
    }

    function pickSlot(btn, time) {
        selectedTime = time;
        slotsDiv.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        customerForm.style.display = '';
    }

    document.getElementById('booking-form').addEventListener('submit', function() {
        document.getElementById('form-employee').value = employeeSelect.value;
        document.getElementById('form-date').value = dateInput.value;
        document.getElementById('form-time').value = selectedTime;
        document.getElementById('form-name').value = document.getElementById('name').value;
        document.getElementById('form-email').value = document.getElementById('email').value;
        document.getElementById('form-phone').value = document.getElementById('phone').value;
    });

    dateInput.addEventListener('change', fetchSlots);
    employeeSelect.addEventListener('change', fetchSlots);
    </script>
{% endblock %}
```

Create `templates/book/confirmation.twig`:

```twig
{% extends "_layout" %}

{% block content %}
    <h1>Booking Confirmed!</h1>
    <p>Thank you for your booking. A confirmation email has been sent.</p>
    <a href="/book">Book another appointment</a>
{% endblock %}
```

---

## Step 5: Test Your Booking

1. Visit `/book` in your browser
2. Select the service (if using wizard) or you'll see "Consultation"
3. Select **Sarah Johnson** as the employee
4. Pick a date (Monday-Friday)
5. Choose an available time slot
6. Enter your name and email
7. Click **Confirm Booking**

You should be redirected to the confirmation page.

### Verify the Booking

1. Go to **Slots → Reservations** in the Control Panel
2. You should see your test booking with:
   - Customer name and email
   - Service: Consultation
   - Employee: Sarah Johnson
   - Date and time
   - Status: Confirmed

---

## Step 6: Set Up Email Notifications (Optional)

### Enable Confirmation Emails

1. Go to **Slots → Settings** in the sidebar, then click the **Notifications** tab
2. Configure the confirmation email subject and review notification settings
3. Save

### Enable Reminder Emails

1. In the same settings, enable **Email Reminders**
2. Set **Reminder hours before**: `24`
3. Save

Add a cron job to send reminders. You can either send them directly or queue them for async processing:

```bash
# Queue reminders for async processing (recommended for production)
0 * * * * cd /path/to/project && php craft slots/reminders/queue

# Or send them directly (simpler, fine for small sites)
0 * * * * cd /path/to/project && php craft slots/reminders/send
```

---

## What's Next?

You now have a working booking system. Here's where to go next:

### Add More Features

- **Multiple services**: Create different services with varying durations and prices
- **Locations**: Add locations if you have multiple venues (go to **Slots → Locations**, then assign employees to a location)
- **Blackout dates**: Block holidays or vacation periods

### Customize the Experience

- **Custom email templates**: Override the default templates to match your brand ([Email Templates Guide](EMAIL_TEMPLATES.md))
- **Booking wizard styling**: Override the default wizard CSS
- **Custom validation**: Use events to add business rules

### Integrate with Other Systems

### Learn More

- [Availability System](AVAILABILITY.md) - Understand how schedules and slots work
- [Developer Guide](DEVELOPER_GUIDE.md) - Full API reference
- [Event System](EVENT_SYSTEM.md) - Hook into booking lifecycle
- [Console Commands](CONSOLE_COMMANDS.md) - CLI commands for reminders, cleanup, and diagnostics

---

## Troubleshooting

### No time slots appear

- Check the employee has an assigned schedule
- Verify the schedule covers the selected date (check day of week and date range)
- Ensure the schedule is enabled
- Check for existing bookings that might be blocking slots

### "Service not found" error

- Verify the service is enabled
- Check the service slug matches your template code
- Ensure you're on the correct site (multi-site setups)

### Emails not sending

- Check Craft's email settings are configured
- Verify notifications are enabled in Slots settings
- Check the Craft logs for email errors
- For reminders, ensure the cron job is running

### Booking form shows errors

- Check browser console for JavaScript errors
- Verify CSRF token is included (`{{ csrfInput() }}`)
- Check all required fields have values
- Look at Craft's logs for server-side errors

---

## Quick Reference

### Key Template Variables

```twig
{# Get services #}
{% set services = craft.slots.services().all() %}

{# Get employees for a service #}
{% set employees = craft.slots.employees().serviceId(service.id).all() %}

{# Get available slots #}
{% set slots = craft.slots.getAvailableSlots({
    date: '2026-03-15',
    serviceId: 1,
    employeeId: 2
}) %}

{# Render booking wizard #}
{{ craft.slots.getWizard() }}
```

### Key Actions

| Action | Purpose |
|--------|---------|
| `slots/booking/create-booking` | Create a new booking |
| `slots/booking-management/cancel-booking` | Cancel a booking |
| `slots/slot/get-available-slots` | AJAX endpoint for available slots (POST, JSON) |
| `slots/slot/get-availability-calendar` | Month of slot availability for the wizard's calendar (GET, JSON) |
| `slots/slot/create-lock` | Soft-lock a slot while the customer completes the form (POST, JSON) |
| `slots/slot/release-lock` | Release a soft lock (POST, JSON) |

