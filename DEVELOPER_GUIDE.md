# Developer Guide - Slots

Comprehensive guide for developers extending and customizing the Slots plugin.

## Architecture

### Service-Based Design

Slots uses a service-based architecture for separation of concerns:

```
anvildev\slots\
├── elements/          # Element types (Reservation, Service, Employee, etc.)
├── services/          # Business logic services
├── controllers/       # HTTP controllers
├── events/            # Event classes
├── records/           # Active Record models
├── queue/             # Background jobs
└── helpers/           # Utility classes
```

### Core Services

The plugin registers 28+ services via `setComponents()` in `Slots.php`. Key services:

| Handle | Service | Purpose |
|--------|---------|---------|
| `booking` | `BookingService` | Create, update, cancel bookings |
| `availability` | `AvailabilityService` | Calculate available time slots |
| `slotGenerator` | `SlotGeneratorService` | Generate time slots from availability windows |
| `capacity` | `CapacityService` | Capacity checking for service-level bookings |
| `scheduleAssignment` | `ScheduleAssignmentService` | Schedule-employee/service many-to-many relationships |
| `scheduleResolver` | `ScheduleResolverService` | Resolve active schedule for a date |
| `softLock` | `SoftLockService` | Race condition prevention via temporary slot locks |
| `bookingValidation` | `BookingValidationService` | Rate limits and business rule validation |
| `bookingNotification` | `BookingNotificationService` | Queue email notifications |
| `emailRender` | `EmailRenderService` | Render email templates with variables |
| `reminder` | `ReminderService` | Send automated booking reminders |
| `blackoutDate` | `BlackoutDateService` | Manage blocked date ranges |
| `serviceLocation` | `ServiceLocationService` | Direct service-to-location assignments |
| `permission` | `PermissionService` | Staff scoping — resolves the employee a user is linked to |
| `captcha` | `CaptchaService` | CAPTCHA verification (reCAPTCHA, hCaptcha, Turnstile) |
| `audit` | `AuditService` | Security and action audit logging |
| `reports` | `ReportsService` | Booking reports and statistics (uses `TagDependency`-based cache invalidation with the tag `'slots_reports'`) |
| `payments` | `PaymentService` | Take, capture, refund and reconcile payments |
| `paymentGateways` | `PaymentGatewayService` | Resolve the configured payment gateway |
| `refundPolicy` | `RefundPolicyService` | Refund tier calculation |
| `customers` | `CustomerService` | Customer list and per-customer booking history |
| `timezone` | `TimezoneService` | Timezone conversion utilities |
| `maintenance` | `MaintenanceService` | Cleanup and maintenance tasks |
| `bookingSecurity` | `BookingSecurityService` | Request security validation (CAPTCHA, honeypot, IP blocking, time-based limits) |
| `timeWindow` | `TimeWindowService` | Time window calculations |
| `mutex` | `MutexFactory` | Mutex lock factory |
| `dashboard` | `DashboardService` | Dashboard widget data |

Access services via the plugin instance:

```php
use anvildev\slots\Slots;

$booking = Slots::getInstance()->booking;
$availability = Slots::getInstance()->availability;
$payments = Slots::getInstance()->payments;
$permission = Slots::getInstance()->permission;
```

## Services API

### BookingService

Create and manage bookings.

#### Create Booking

Two methods are available: `createBooking()` is a convenience wrapper that delegates to `createReservation()`, which is the primary method with full validation, soft lock handling, availability checks, and notification dispatch.

```php
use anvildev\slots\Slots;

$bookingService = Slots::getInstance()->booking;

// Convenience wrapper
$reservation = $bookingService->createBooking([
    'serviceId' => 1,
    'employeeId' => 2,
    'bookingDate' => '2025-12-26',
    'startTime' => '14:00',
    'userName' => 'John Doe',
    'userEmail' => 'john@example.com',
]);

// Full method with all options
$reservation = $bookingService->createReservation([
    'serviceId' => 1,
    'employeeId' => 2,
    'locationId' => 1,
    'bookingDate' => '2025-12-26',
    'startTime' => '14:00',
    'endTime' => '15:00',
    'userName' => 'John Doe',
    'userEmail' => 'john@example.com',
    'userPhone' => '+1-555-0123',
    'notes' => 'First time customer',
    'quantity' => 1,
    'source' => 'web',
]);

if ($reservation) {
    echo "Booking created: {$reservation->id}";
} else {
    echo "Booking failed";
}
```

#### Cancel Booking

```php
$success = $bookingService->cancelReservation(
    $reservation->id,
    'Customer requested cancellation'
);
```

#### Update Booking

```php
$reservation->startTime = '15:00';
$reservation->endTime = '16:00';

$success = Craft::$app->elements->saveElement($reservation);
```

### AvailabilityService

Calculate available time slots.

#### Get Available Slots

```php
use anvildev\slots\Slots;

$availabilityService = Slots::getInstance()->availability;

$slots = $availabilityService->getAvailableSlots(
    date: '2025-12-26',
    employeeId: 2,           // Optional
    locationId: 1,           // Optional
    serviceId: 1,            // Optional
    requestedQuantity: 1,    // Optional
    userTimezone: 'America/New_York' // Optional
);

foreach ($slots as $slot) {
    echo "{$slot['time']} - {$slot['endTime']} ({$slot['employeeName']})\n";
}
```

#### Check Slot Availability

```php
$isAvailable = $availabilityService->isSlotAvailable(
    date: '2025-12-26',
    startTime: '14:00',
    endTime: '15:00',
    employeeId: 2,
    serviceId: 1,
    requestedQuantity: 1
);
```

#### Performance Notes

The availability system uses batch queries to minimize database round-trips:

- **Schedule resolution**: Employee schedules are loaded in a single batch query via `ScheduleAssignmentService::getActiveSchedulesForDateBatch()` instead of one query per employee.
- **Capacity enrichment**: `CapacityService::enrichSlotsWithCapacity()` pre-loads all employees, schedules, and reservations in 3-4 queries, then does in-memory lookups per slot.
- **Service schedule memoization**: `getActiveScheduleForServiceOnDate()` is memoized within a request — repeated calls with the same service/date return cached results.
- **Session handling**: AJAX controllers (`SlotController`, `BookingDataController`) close the PHP session early to prevent file lock contention during parallel requests. If you extend these controllers, be aware that the session is read-only after `init()`.

#### Time Slot Interval

The slot interval (how often slots appear) is determined by a fallback chain:

1. **Service `timeSlotLength`** (if set) - Per-service setting
2. **Global `defaultTimeSlotLength`** (if set) - From Settings
3. **Service `duration`** (always available) - Falls back to duration

```php
use anvildev\slots\Slots;

$slotGenerator = Slots::getInstance()->slotGenerator;

// Get the effective slot interval for a service
$interval = $slotGenerator->getSlotInterval(
    serviceOrId: 1,
    duration: 60 // Fallback duration if no slot length is set
);

// This will return:
// - Service's timeSlotLength if set
// - Global defaultTimeSlotLength if set
// - Service duration (60) as final fallback
```

**Note**: The slot interval determines **when** slots appear (e.g., every 15 minutes), while service duration determines **how long** each booking lasts (e.g., 60 minutes). These can be different values.

### SoftLockService

Prevent race conditions when multiple users try to book the same slot simultaneously.

#### How Soft Locks Work

Soft locks temporarily reserve a time slot while a user completes the booking form. This prevents double-bookings when multiple users select the same slot.

**Booking Flow with Soft Locks:**

```
1. User browses available slots      → No lock
2. User selects a time slot          → Frontend calls create-lock → LOCK CREATED
3. User fills in booking form        → Lock active (default 5 min)
4. User submits booking              → Booking created, lock consumed
5. If user abandons page             → Lock expires automatically
```

#### Create Soft Lock (PHP)

```php
use anvildev\slots\Slots;

$softLockService = Slots::getInstance()->softLock;

$token = $softLockService->createLock([
    'date' => '2025-12-26',
    'startTime' => '14:00',
    'endTime' => '15:00',
    'serviceId' => 1,
    'employeeId' => 2,      // Optional
    'locationId' => 1,      // Optional
]);

if ($token) {
    // Lock created successfully
    // Store token to release later or include in booking
} else {
    // Slot already locked by another user
}
```

#### Release Soft Lock (PHP)

```php
$softLockService->releaseLock($token);
```

#### Check if Slot is Locked (PHP)

```php
$isLocked = $softLockService->isLocked(
    date: '2025-12-26',
    startTime: '14:00',
    serviceId: 1,
    employeeId: 2,
    slotEndTime: '15:00',
    excludeToken: $myToken // Optional: exclude own lock
);
```

#### Frontend HTTP Endpoints

**Create Lock** - `POST slots/slot/create-lock`

Call this when the user **selects a time slot**, before they fill in the booking form.

```javascript
// When user clicks on a time slot
async function selectSlot(date, startTime, serviceId, employeeId, locationId) {
    const response = await fetch('/actions/slots/slot/create-lock', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            date,
            startTime,
            serviceId,
            employeeId,    // optional
            locationId     // optional
        })
    });

    const result = await response.json();

    if (result.success) {
        // Store token for later use
        this.softLockToken = result.token;
        // Show booking form
    } else {
        // Slot already taken
        alert(result.error);
    }
}
```

**Release Lock** - `POST slots/slot/release-lock`

Call this if the user cancels or navigates away without booking.

```javascript
async function releaseLock(token) {
    await fetch('/actions/slots/slot/release-lock', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ token })
    });
}

// Release on page unload
window.addEventListener('beforeunload', () => {
    if (this.softLockToken) {
        navigator.sendBeacon('/actions/slots/slot/release-lock',
            JSON.stringify({ token: this.softLockToken }));
    }
});
```

#### Configuration

Configure the soft lock duration in **Settings → Slots → Booking** (default: 5 minutes).

Soft locks are always enabled - there's no setting to disable them since they're essential for preventing double-bookings.

#### Best Practices

1. **Create lock on slot selection**, not on form submit
2. **Release lock** when user cancels or changes slot
3. **Include token** in booking submission for validation
4. **Handle lock failures gracefully** - show user-friendly message
5. **Use `beforeunload`** to release locks when user navigates away

### BookingValidationService

Enforce rate limits and business rules on bookings.

```php
$validation = Slots::getInstance()->bookingValidation;

// Check email rate limit (max bookings per email per hour)
$isLimited = $validation->checkEmailRateLimit('customer@example.com');

// Check IP rate limit (max bookings per IP per hour)
// @deprecated — use checkAllRateLimits() instead
$isLimited = $validation->checkIPRateLimit($ipAddress);

// Check all rate limits at once
$result = $validation->checkAllRateLimits($email, $ipAddress);
// Returns: ['allowed' => bool, 'reason' => string|null]

// Check customer booking limit for a service
$isLimited = $validation->checkCustomerBookingLimit(
    'customer@example.com',
    $service,
    '2025-12-26'
);
```

### BlackoutDateService

Check and manage blocked date ranges.

```php
$blackout = Slots::getInstance()->blackoutDate;

// Check if a date is blacked out
$isBlocked = $blackout->isDateBlackedOut('2025-12-25');

// Check for a specific employee/location
$isBlocked = $blackout->isDateBlackedOut('2025-12-25', $employeeId, $locationId);

// Get all blackout records for a date
$blackouts = $blackout->getBlackoutsForDate('2025-12-25');
```

### ReminderService

Send automated reminders for upcoming bookings.

```php
$reminder = Slots::getInstance()->reminder;

// Send all pending reminders (called by console command)
$sentCount = $reminder->sendReminders();

// Get reservations that need reminders
$pending = $reminder->getPendingReminders();
```

Reminders are typically sent via console commands:
```bash
php craft slots/reminders/send    # Send immediately
php craft slots/reminders/queue   # Queue for async processing
```

### BookingNotificationService

Queue email notifications for bookings.

```php
$notification = Slots::getInstance()->bookingNotification;

// Queue booking email (confirmation, cancellation, status_change, reminder)
$notification->queueBookingEmail($reservationId, 'confirmation');

// Queue with priority (lower = higher priority)
$notification->queueBookingEmail($reservationId, 'confirmation', null, 512);

// Queue owner notification
$notification->queueOwnerNotification($reservationId, 512);

// Queue cancellation notification
$notification->queueCancellationNotification($reservationId);

$notification->queueSmsCancellation($reservation);
```

### ServiceLocationService

Manage direct service-to-location assignments (many-to-many). This enables employee-less services (using service-level schedules) to be associated with specific locations.

```php
$serviceLocation = Slots::getInstance()->serviceLocation;

// Get locations assigned to a service
$locations = $serviceLocation->getLocationsForService($serviceId);

// Set locations for a service (replaces existing assignments)
$serviceLocation->setLocationsForService($serviceId, [10, 20, 30]);

// Batch-load location IDs for multiple services (avoids N+1)
$map = $serviceLocation->getLocationIdMapForServices([1, 2, 3]);
// Returns: [1 => [10, 20], 2 => [30], 3 => []]
```

**Element query filter:**

```php
// Find services available at a specific location
$services = Service::find()->locationId(5)->all();
```

## Event System

Slots fires events at critical points in the booking lifecycle. See [EVENT_SYSTEM.md](EVENT_SYSTEM.md) for complete documentation.

### Available Events

**BookingService Events:**
- `EVENT_BEFORE_BOOKING_SAVE` - Before saving a booking
- `EVENT_AFTER_BOOKING_SAVE` - After booking is saved
- `EVENT_BEFORE_BOOKING_CANCEL` - Before canceling a booking
- `EVENT_AFTER_BOOKING_CANCEL` - After booking is canceled

**AvailabilityService Events:**
- `EVENT_BEFORE_AVAILABILITY_CHECK` - Before calculating availability
- `EVENT_AFTER_AVAILABILITY_CHECK` - After availability is calculated

### Event Handler Example

```php
use yii\base\Event;
use anvildev\slots\services\BookingService;
use anvildev\slots\events\BeforeBookingSaveEvent;

Event::on(
    BookingService::class,
    BookingService::EVENT_BEFORE_BOOKING_SAVE,
    function(BeforeBookingSaveEvent $event) {
        // Access event data
        $reservation = $event->reservation;
        $isNew = $event->isNew;
        $bookingData = $event->bookingData;

        // Custom validation
        if ($reservation->userEmail && !filter_var($reservation->userEmail, FILTER_VALIDATE_EMAIL)) {
            $event->isValid = false;
            $event->errorMessage = 'Invalid email address';
            return;
        }

        // Send to external CRM
        $crm = new CRMService();
        $crm->createLead([
            'name' => $reservation->userName,
            'email' => $reservation->userEmail,
            'phone' => $reservation->userPhone,
        ]);

        // Modify reservation data
        $reservation->notes = 'CRM Lead ID: ' . $crm->getLeadId();

        // Log to custom system
        Craft::info("New booking created by {$reservation->userName}", 'custom-booking-log');
    }
);
```

### Register Events in Plugin

Create a custom module and bootstrap it in `config/app.php`:

```php
// modules/CustomBookingModule.php
namespace modules;

use yii\base\Event;
use yii\base\Module as BaseModule;
use anvildev\slots\services\BookingService;
use anvildev\slots\events\AfterBookingSaveEvent;

class CustomBookingModule extends BaseModule
{
    public function init()
    {
        parent::init();

        Event::on(
            BookingService::class,
            BookingService::EVENT_AFTER_BOOKING_SAVE,
            function(AfterBookingSaveEvent $event) {
                if ($event->success && $event->isNew) {
                    // Your custom logic here
                }
            }
        );
    }
}
```

```php
// config/app.php
return [
    'modules' => ['custom-booking' => \modules\CustomBookingModule::class],
    'bootstrap' => ['custom-booking'],
];
```

## REST API Endpoints

All endpoints use the Craft action URL format: `/actions/slots/{controller}/{action}`.

CSRF tokens are required by default (configurable via `enableCsrfValidation` setting).

### Create Booking

`POST /actions/slots/booking/create-booking` (anonymous)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `serviceId` | int | Yes | Service ID |
| `date` | string | Yes | Date (Y-m-d). Alias: `bookingDate` |
| `time` | string | Yes | Start time (HH:mm). Alias: `startTime` |
| `customerName` | string | Yes | Customer name. Alias: `userName` |
| `customerEmail` | string | Yes | Customer email. Alias: `userEmail` |
| `endTime` | string | No | End time (auto-calculated from service duration) |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |
| `customerPhone` | string | No | Customer phone. Alias: `userPhone` |
| `notes` | string | No | Customer-facing booking notes. Alias: `customerNotes` |
| `quantity` | int | No | Number of slots (default: 1) |
| `userTimezone` | string | No | IANA timezone (e.g. `America/New_York`). Falls back to Craft system timezone. Used for formatted date/time display. |
| `softLockToken` | string | No | Soft lock token from slot selection |
| `captchaToken` | string | No | CAPTCHA token (if enabled) |

> **Parameter aliases:** When both a parameter and its alias are sent, the primary name takes precedence (e.g. `date` wins over `bookingDate`). The aliases exist for backward compatibility.

**Response:**

```json
{
  "success": true,
  "message": "booking.created",
  "data": {
    "reservation": {
      "id": 123,
      "formattedDateTime": "Thursday, Dec 26 at 2:00 PM",
      "status": "confirmed"
    }
  }
}
```

**Error Response:**

```json
{
  "success": false,
  "message": "booking.validationError",
  "errors": { "userEmail": ["Invalid email address"] }
}
```

### Get Available Slots

`POST /actions/slots/slot/get-available-slots` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | string | Yes | Date (Y-m-d) |
| `serviceId` | int | No | Service ID |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |
| `quantity` | int | No | Requested quantity (default: 1) |

**Response:**

```json
{
  "success": true,
  "data": {
    "slots": [
      { "startTime": "09:00", "endTime": "10:00", "available": true },
      { "startTime": "10:00", "endTime": "11:00", "available": true }
    ],
  }
}
```

### Get Availability Calendar

`GET /actions/slots/slot/get-availability-calendar` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `startDate` | string | No | Start date (default: today) |
| `serviceId` | int | No | Service ID |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |

**Response:**

```json
{
  "success": true,
  "data": {
    "calendar": {
      "2025-12-26": { "hasAvailability": true, "isBlackedOut": false, "isBookable": true },
      "2025-12-27": { "hasAvailability": false, "isBlackedOut": true, "isBookable": false }
    }
  }
}
```

### Soft Lock (Create / Release)

`POST /actions/slots/slot/create-lock` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | string | Yes | Date (Y-m-d) |
| `startTime` | string | Yes | Start time (HH:mm) |
| `serviceId` | int | Yes | Service ID |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |

```json
{ "success": true, "data": { "token": "abc123...", "expiresIn": 300 } }
```

Reserves a **date range** for a day-based service while the customer completes checkout (same expiry as other soft locks).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | string | Yes | Stay start (`Y-m-d`) |
| `serviceId` | int | Yes | |
| `employeeId` | int | No | |
| `locationId` | int | No | |
| `quantity` | int | No | Default 1 |

`POST /actions/slots/slot/release-lock` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `token` | string | Yes | Lock token to release |

### Booking Data

`GET /actions/slots/booking-data/get-services` (anonymous)

Returns all enabled services with title, `duration`, price, buffers and `locationIds`.

`GET /actions/slots/booking-data/get-service-extras?serviceId=1` (anonymous)

Returns extras for a service: id, title, description, price, duration, maxQuantity, isRequired.

`GET /actions/slots/booking-data/get-employees` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `serviceId` | int | No | Filter by service |
| `locationId` | int | No | Filter by location |

Returns employees, locations, and whether employees/schedules are required.

### Booking Management (Token-Based)

These endpoints use **confirmation tokens** for authorization (see [Security & Authorization](#security--authorization)).

`POST /actions/slots/booking-management/cancel-booking`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | int | Yes | Reservation ID |
| `token` | string | Yes | Confirmation token |
| `reason` | string | No | Cancellation reason |

`GET /booking/manage/{token}` — Renders booking management page (view details, reschedule, cancel).

`GET /booking/cancel/{token}` — Renders cancellation confirmation page.

### Rescheduling

Rescheduling is handled via the booking management page. Customers access it through the token-based URL in their confirmation email.

`POST /actions/slots/booking-management/manage-booking`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | Must be `reschedule` |
| `id` | int | Yes | Reservation ID |
| `token` | string | Yes | Confirmation token |
| `newDate` | string | Yes | New date (YYYY-MM-DD) |
| `newStartTime` | string | Yes | New start time (HH:MM) |
| `newEndTime` | string | Yes | New end time (HH:MM) |

**Constraints:**
- Cannot reschedule past bookings
- Cannot reschedule cancelled or completed bookings
- The new slot must pass `AvailabilityService::isSlotAvailable()` (same employee, location, service, quantity)
- The cancellation policy deadline applies to the original booking time

**Response:**

```json
{
  "success": true,
  "message": "booking.rescheduled",
  "data": {
    "reservation": {
      "id": 123,
      "formattedDateTime": "Thursday, Mar 20 at 3:00 PM",
      "status": "confirmed"
    }
  }
}
```

### REST API Error Codes

All REST endpoints return consistent error responses:

| HTTP Status | `message` | When |
|-------------|-----------|------|
| 200 | `booking.created` | Booking created successfully |
| 200 | `booking.cancelled` | Booking cancelled successfully |
| 200 | `booking.rescheduled` | Booking rescheduled successfully |
| 400 | `booking.validationError` | Input validation failed (missing fields, invalid format) |
| 400 | `booking.conflict` | Slot is no longer available |
| 400 | `booking.capacityExceeded` | Not enough capacity for requested quantity |
| 403 | `booking.forbidden` | Invalid confirmation token or unauthorized |
| 403 | `booking.captchaFailed` | CAPTCHA verification failed |
| 404 | `booking.notFound` | Reservation not found |
| 429 | `booking.rateLimited` | Email or IP rate limit exceeded |

Error responses include an `errors` object with field-level details:

```json
{
  "success": false,
  "message": "booking.validationError",
  "errors": {
    "userEmail": ["Invalid email address"],
    "startTime": ["This time slot is no longer available"]
  }
}
```

### Customer Account

All account endpoints require user login. See [Customer Account Portal](#customer-account-portal) for customization options.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/slots/account` | GET | Dashboard with upcoming bookings and stats |
| `/slots/account/bookings` | GET | All bookings |
| `/slots/account/upcoming` | GET | Upcoming bookings |
| `/slots/account/past` | GET | Past bookings |
| `/slots/account/{id}` | GET | Single booking detail (IDOR-protected) |
| `/actions/slots/account/cancel` | POST | Cancel booking (requires `id` param) |
| `/actions/slots/account/current-user` | GET | JSON: current user info for AJAX pre-fill |

#### Notes vs Session Notes

Reservations have two separate text fields for notes:

| Field | Property | Purpose | Who can access |
|-------|----------|---------|----------------|
| **Notes** | `notes` | Customer-provided context set at booking time (e.g. "I have a dog allergy") | Everyone |
| **Session Notes** | `sessionNotes` | Staff-written post-appointment notes (e.g. "Follow-up in 2 weeks") | Assigned employee + admins only |

Session notes are access-controlled in the CP: only the employee assigned to the booking and administrators can view or edit them. They are excluded from CSV exports to preserve confidentiality.

### Service Element

```php
use anvildev\slots\elements\Service;

// Query services
$services = Service::find()
    ->enabled()
    ->orderBy('title ASC')
    ->all();

// Access properties
foreach ($services as $service) {
    echo $service->title;
    echo $service->duration;
    echo $service->price;
    echo $service->bufferBefore;
    echo $service->bufferAfter;

    // Get employees assigned to this service
    $employees = Slots::getInstance()->getScheduleAssignment()->getEmployeesForService($service->id);
}
```

### Employee Element

```php
use anvildev\slots\elements\Employee;

// Query employees
$employees = Employee::find()
    ->locationId(1)
    ->status('active')
    ->all();

// Access properties
foreach ($employees as $employee) {
    echo $employee->title;
    echo $employee->email;

    // Related elements
    $location = $employee->getLocation();
    $services = $employee->getServices();
}
```

### Element Query Methods

```php
// Reservation queries
Reservation::find()
    ->bookingDate('2025-12-26')
    ->startTime('14:00')
    ->endTime('15:00')
    ->serviceId(1)
    ->employeeId(2)
    ->locationId(1)
    ->status('confirmed') // or ['confirmed', 'pending']
    ->userId(10)
    ->userEmail('john@example.com')
    ->limit(10)
    ->orderBy('bookingDate DESC, startTime ASC')
    ->all();

// Service queries
Service::find()
    ->enabled()
    ->price(50.0)
    ->duration(60)
    ->all();

// Employee queries
Employee::find()
    ->status('active')
    ->locationId(1)
    ->serviceId(1) // Employees offering this service
    ->all();
```

### Pagination

All queries support standard Craft element arguments for pagination:

## Security & Authorization

### Confirmation Tokens

Every reservation is assigned a cryptographically secure confirmation token on creation. Tokens are 64-character hex strings generated from `random_bytes(32)` with database-level uniqueness enforcement.

Tokens authorize public-facing operations without requiring user authentication:

| Operation | Endpoint | Token Usage |
|-----------|----------|-------------|
| View/manage booking | `GET /booking/manage/{token}` | Token in URL |
| Cancel booking (REST) | `POST /actions/slots/booking-management/cancel-booking` | Token in POST body, verified against reservation |
| Download ICS | `GET /booking/ics/{token}` | Token in URL |

Token verification prevents IDOR (Insecure Direct Object Reference) attacks. Failed authorization attempts are logged via the audit service:

```php
// Example: REST cancellation verifies token matches reservation
if ($reservation->getConfirmationToken() !== $token) {
    Slots::getInstance()->getAudit()->logAuthFailure('invalid_cancel_token', [
        'reservationId' => $id,
    ]);
    throw new ForbiddenHttpException();
}
```

### CAPTCHA Verification

Three CAPTCHA providers are supported. Enable via `Settings::enableCaptcha` and configure the provider:

| Provider | Setting | Verification Endpoint |
|----------|---------|----------------------|
| Google reCAPTCHA v3 | `captchaProvider: 'recaptcha'` | `google.com/recaptcha/api/siteverify` |
| hCaptcha | `captchaProvider: 'hcaptcha'` | `hcaptcha.com/siteverify` |
| Cloudflare Turnstile | `captchaProvider: 'turnstile'` | `challenges.cloudflare.com/turnstile/v0/siteverify` |

Each provider requires a site key and secret key. reCAPTCHA v3 uses a score threshold of 0.5 (requests below this are rejected).

CAPTCHA is validated in `BookingSecurityService::validateRequest()` before the booking is processed.

### Rate Limiting

Two complementary rate limits protect against abuse:

**Email-based:** Maximum bookings per email address per day (default: 5). Counts non-cancelled reservations created today.

**IP-based:** Maximum bookings per IP address per day (default: 10). Uses Craft's cache with a 24-hour sliding window.

Configure rate limits in **Settings → Slots → Security**.

Rate limit checks run in `BookingService::createReservation()` and return specific error reasons (`email_rate_limit` or `ip_rate_limit`).

**Per-service customer limits** can also be configured on individual services to restrict how many bookings one customer can make for that service on a given date.

### Honeypot Protection

A hidden form field traps spam bots. Enabled by default with field name `website`:

```php
'enableHoneypot' => true,
'honeypotFieldName' => 'website',
```

If the honeypot field contains any value, the submission is rejected as spam. Customize the field name to match your form markup.

### CSRF Protection

CSRF validation is enabled by default on all booking controllers via Craft's built-in token validation. Disable only in development:

```php
'enableCsrfValidation' => true, // Default; disable only in devMode
```

A production warning is raised if CSRF is disabled outside of `devMode`.

### Soft Locks (Race Condition Prevention)

Soft locks temporarily reserve time slots while a customer completes a booking form, preventing double-booking in concurrent sessions.

**How it works:**

1. When a customer selects a time slot, a soft lock is created (default: 5 minutes)
2. Other customers see the slot as unavailable during the lock period
3. The lock token is sent with the booking form submission
4. On booking creation, the system checks for conflicting locks (excluding the customer's own lock)
5. Expired locks are automatically cleaned up

Configure the lock duration in **Settings → Slots → Booking**.

The booking service also uses a database-level mutex lock (`Craft::$app->getMutex()->acquire()`) during reservation creation to prevent race conditions at the database level.

### Audit Logging

When `enableAuditLog` is enabled, security events are logged to `@storage/logs/slots-audit.log`:

- Rate limit triggers (`email_rate_limit`, `ip_rate_limit`)
- Failed token authorization (`invalid_cancel_token`, `invalid_update_token`)
- CAPTCHA failures (`captcha_failed`, `captcha_missing`)
- Honeypot triggers (`honeypot_triggered`)

### Staff Permissions & Managed Employees

Slots supports a role-based staff model where a Craft user linked to an Employee record can view and manage bookings for their own employee and any additional employees assigned to them.

#### Concepts

| Concept | Description |
|---------|-------------|
| **Employee.userId** | 1:1 link between an Employee and a Craft user — "this employee IS this user". Enforced unique (one user per employee). |
| **Managed Employees** | Additional employees whose bookings a staff employee can view/manage. Configured on the employee edit page via the "Managed Employees" field. |
| **Staff member** | A Craft user with `slots-viewBookings` but NOT `slots-manageBookings`, linked to at least one Employee. Sees only their own + managed employees' bookings. |
| **Supervisor/Admin** | A Craft user with `slots-manageBookings` or admin status. Sees all bookings. |

#### How It Works

1. A Craft user account is linked to an Employee via `userId` (1:1, set on the employee edit page)
2. On that employee's edit page, additional employees are assigned under **Managed Employees**
3. When the staff user logs in, `PermissionService` resolves their visible employees:
   - The employee they ARE (via `userId`)
   - All employees assigned in **Managed Employees**
4. Booking queries, the calendar, and the dashboard are automatically scoped to those employees

This means you only need a few Craft user accounts for staff — each staff employee can manage multiple other employees who don't need their own accounts.

#### Craft Permissions

| Permission | Effect |
|------------|--------|
| `slots-viewBookings` | Can view bookings (scoped to own employees if not a manager) |
| `slots-manageBookings` | Full access to all bookings (no scoping) |
| `slots-manageEmployees` | Can edit employee records and manage assignments |

#### PermissionService API

```php
use anvildev\slots\Slots;

$permissionService = Slots::getInstance()->getPermission();

// Get the employee records the current user is linked to
$employees = $permissionService->getEmployeesForCurrentUser();

// Check if the current user is a staff member (scoped access)
$isStaff = $permissionService->isStaffMember();

// Get employee IDs for query scoping (null = full access)
$employeeIds = $permissionService->getStaffEmployeeIds();

// Automatically scope a reservation query
$query = $permissionService->scopeReservationQuery($reservationQuery);
```

## Twig API

The Slots plugin provides a comprehensive Twig API for building custom booking interfaces in your templates.

### Element Queries

Query employees, services, locations, and reservations using Craft's element query syntax:

```twig
{# Query services #}
{% set services = craft.slots.services()
    .enabled()
    .orderBy('title ASC')
    .all() %}

{# Query employees #}
{% set employees = craft.slots.employees()
    .locationId(location.id)
    .serviceId(service.id)
    .enabled()
    .status(null)
    .all() %}

{# Query locations #}
{% set locations = craft.slots.locations()
    .enabled()
    .all() %}

{# Query reservations #}
{% set reservations = craft.slots.reservations()
    .bookingDate('2025-12-26')
    .status('confirmed')
    .employeeId(employee.id)
    .locationId(location.id)
    .serviceId(service.id)
    .orderBy('startTime ASC')
    .all() %}
```

### Availability Methods

Get available time slots for booking:

```twig
{# Get available slots with simple date #}
{% set slots = craft.slots.getAvailableSlots('2025-01-15') %}

{# Get available slots with filters #}
{% set slots = craft.slots.getAvailableSlots({
    date: '2025-01-15',
    serviceId: service.id,
    employeeId: employee.id,
    locationId: location.id,
    requestedQuantity: 1,
    userTimezone: 'America/New_York'
}) %}

{# Check if a specific slot is available #}
{% if craft.slots.isSlotAvailable(
    '2025-01-15',
    '14:00',
    '15:00',
    employee.id,
    location.id,
    service.id,
    1
) %}
    {# Slot is available #}
{% endif %}

{# Get next available date #}
{% set nextDate = craft.slots.getNextAvailableDate() %}

{# Get availability calendar for date range #}
{% set calendar = craft.slots.getAvailabilityCalendar('2025-01-01', '2025-01-31') %}
```

### Helper Methods

Common helper methods for working with bookings:

```twig
{# Check if service is bookable #}
{% if craft.slots.isServiceBookable(service) %}
    {# Service has employees or its own schedule #}
{% endif %}

{# Get employee schedules #}
{% set schedules = craft.slots.getEmployeeSchedules(employee.id) %}

{# Get service employees #}
{% set employees = craft.slots.getServiceEmployees(service.id) %}

{# Get location employees #}
{% set employees = craft.slots.getLocationEmployees(location.id) %}

{# Check if employee is available on a date #}
{% if craft.slots.isEmployeeAvailable(employee.id, '2025-01-15') %}
    {# Employee has schedules for this date #}
{% endif %}

{# Get upcoming reservations #}
{% set upcoming = craft.slots.getUpcomingReservations(10) %}

{# Get booking statistics #}
{% set stats = craft.slots.getStats() %}

{# Get plugin settings #}
{% set settings = craft.slots.getSettings() %}

{# Get currency code #}
{% set currency = craft.slots.getCurrency() %}
```

### Formatting Helpers

Use Twig's built-in filters and the Twig variable methods for formatting booking data:

```twig
{# Format duration using service properties #}
{{ service.duration }} min

{# Format time using Craft's date/time filters #}
{{ slot.time|date('g:i A') }}
{# Output: "2:00 PM" #}

{# Format currency using the Twig variable #}
{{ craft.slots.getCurrency() }} {{ service.price|number_format(2) }}
{# Output: "CHF 50.00" #}

{# Format booking date #}
{{ reservation.bookingDate|date('l, M j') }}
{# Output: "Monday, Jan 15" #}

{# Format booking status (translated) #}
{{ reservation.getStatusLabel() }}
{# Output: "Confirmed" (translated label) #}
```

### Complete Example: Custom Booking Form

```twig
{# Get services #}
{% set services = craft.slots.services().enabled().all() %}

<form action="{{ actionUrl('slots/booking/create-booking') }}" method="post">
    {{ csrfInput() }}

    {# Service selection #}
    <select name="serviceId" required>
        <option value="">Select a service</option>
        {% for service in services %}
            <option value="{{ service.id }}">
                {{ service.title }} - {{ service.duration }} min - {{ craft.slots.getCurrency() }} {{ service.price|number_format(2) }}
            </option>
        {% endfor %}
    </select>

    {# Get employees for selected service #}
    {% set employees = craft.slots.getServiceEmployees(service.id) %}
    {% if employees|length > 0 %}
        <select name="employeeId">
            <option value="">Any available</option>
            {% for employee in employees %}
                <option value="{{ employee.id }}">{{ employee.title }}</option>
            {% endfor %}
        </select>
    {% endif %}

    {# Date selection #}
    <input type="date" name="date" required min="{{ 'now'|date('Y-m-d') }}">

    {# Get available slots (via JavaScript/AJAX) #}
    <div id="available-slots"></div>

    {# Customer information #}
    <input type="text" name="userName" placeholder="Your Name" required>
    <input type="email" name="userEmail" placeholder="Your Email" required>
    <input type="tel" name="userPhone" placeholder="Your Phone">

    <button type="submit">Book Appointment</button>
</form>

{# Example: Display upcoming bookings #}
<h2>Upcoming Bookings</h2>
{% set upcoming = craft.slots.getUpcomingReservations(5) %}
{% if upcoming|length > 0 %}
    <ul>
        {% for reservation in upcoming %}
            <li>
                <strong>{{ reservation.service.title }}</strong>
                on {{ reservation.bookingDate|date('l, M j') }}
                at {{ reservation.startTime|date('g:i A') }}
                with {{ reservation.employee.title }}
                - Status: {{ reservation.getStatusLabel() }}
            </li>
        {% endfor %}
    </ul>
{% else %}
    <p>No upcoming bookings.</p>
{% endif %}
```

### Customer Account Portal

#### Built-in Portal

The plugin ships with a ready-to-use account portal at the following routes (all require login):

| Route | Description |
|-------|-------------|
| `/slots/account` | Dashboard with stats and upcoming bookings |
| `/slots/account/bookings` | All bookings list |
| `/slots/account/upcoming` | Upcoming bookings only |
| `/slots/account/past` | Past bookings only |
| `/slots/account/{id}` | Single booking detail view |

These routes render the plugin's built-in templates located at `src/templates/frontend/account/`. They work out of the box for quick prototyping, but most projects will want a custom implementation that matches the site's design.

#### Customizing the Portal

You have two options:

1. **Copy & restyle the built-in templates** — Copy the plugin's `frontend/account/` templates to your site's `templates/` directory and modify them to match your layout and design.

2. **Build from scratch** — Use the Twig variables below to create fully custom account pages with complete control over markup, styling, and URL structure.

#### Available Twig Methods

```twig
{# Check if user is logged in (native Craft variable) #}
{% if currentUser %}

    {# Access user info via native Craft variable #}
    {{ currentUser.email }}
    {{ currentUser.fullName }}
    {{ currentUser.firstName }}

    {# Get all bookings for current user (returns query) #}
    {% set allBookings = craft.slots.myBookings().all() %}

    {# Get upcoming bookings (convenience method) #}
    {% set upcoming = craft.slots.myUpcomingBookings(5) %}

    {# Get past bookings (convenience method) #}
    {% set past = craft.slots.myPastBookings(10) %}

    {# Get booking count #}
    {% set totalBookings = craft.slots.myBookingCount() %}

{% endif %}
```

#### Custom Query with forCurrentUser()

For more control, use the `forCurrentUser()` query method:

```twig
{# Query with custom filters #}
{% set confirmedBookings = craft.slots.reservations()
    .forCurrentUser()
    .status('confirmed')
    .orderBy('slots_reservations.bookingDate DESC')
    .all() %}

{# Get bookings for a specific service #}
{% set massageBookings = craft.slots.reservations()
    .forCurrentUser()
    .serviceId(5)
    .all() %}

{# Upcoming bookings with custom date filter #}
{% set nextWeek = craft.slots.reservations()
    .forCurrentUser()
    .andWhere(['>=', 'slots_reservations.bookingDate', 'now'|date('Y-m-d')])
    .andWhere(['<=', 'slots_reservations.bookingDate', 'now'|date_modify('+7 days')|date('Y-m-d')])
    .all() %}
```

#### Complete Custom Account Page Example

```twig
{# templates/account/bookings.twig #}
{% extends "_layout" %}

{% requireLogin %}

{% block content %}
    <h1>My Bookings</h1>

    <p>Welcome back, {{ currentUser.fullName ?? currentUser.email }}!</p>

    {# Stats #}
    <div class="booking-stats">
        <div>Total: {{ craft.slots.myBookingCount() }}</div>
        <div>Upcoming: {{ craft.slots.myUpcomingBookings(100)|length }}</div>
    </div>

        {# Upcoming Bookings #}
        <h2>Upcoming</h2>
        {% set upcoming = craft.slots.myUpcomingBookings(10) %}
        {% if upcoming|length %}
            {% for booking in upcoming %}
                <div class="booking-card">
                    <h3>{{ booking.service.title ?? 'Booking' }}</h3>
                    <p>{{ booking.getFormattedDateTime() }}</p>
                    {% if booking.employee %}
                        <p>with {{ booking.employee.title }}</p>
                    {% endif %}
                    <span class="status status--{{ booking.status }}">
                        {{ booking.getStatusLabel() }}
                    </span>

                    {# Action buttons #}
                    <a href="{{ booking.getIcsUrl() }}">Add to Calendar</a>
                    <a href="{{ booking.getManagementUrl() }}">Manage</a>

                    {% if booking.canBeCancelled() %}
                        <form method="post">
                            {{ csrfInput() }}
                            {{ actionInput('slots/account/cancel') }}
                            {{ hiddenInput('id', booking.id) }}
                            {{ redirectInput(craft.app.request.url) }}
                            <button type="submit" onclick="return confirm('Cancel this booking?')">
                                Cancel
                            </button>
                        </form>
                    {% endif %}
                </div>
            {% endfor %}
        {% else %}
            <p>No upcoming bookings. <a href="/book">Book now</a></p>
        {% endif %}

        {# Past Bookings #}
        <h2>Past Bookings</h2>
        {% set past = craft.slots.myPastBookings(5) %}
        {% for booking in past %}
            <div class="booking-card booking-card--past">
                <p>{{ booking.service.title ?? 'Booking' }} - {{ booking.bookingDate }}</p>
            </div>
        {% endfor %}

    {% endblock %}
```

#### Pre-fill Booking Form with User Data

```twig
{# On your booking page #}
{% if currentUser %}
    <input type="hidden" name="userName" value="{{ currentUser.fullName }}">
    <input type="hidden" name="userEmail" value="{{ currentUser.email }}">
    {# Phone field depends on your user field layout #}
{% endif %}
```

#### JavaScript: Check Login Status

```javascript
// Check if user is logged in via AJAX
fetch('/actions/slots/account/current-user', {
    headers: { 'Accept': 'application/json' }
})
.then(response => response.json())
.then(data => {
    if (data.loggedIn) {
        // Pre-fill form fields
        document.querySelector('[name="userName"]').value = data.user.name;
        document.querySelector('[name="userEmail"]').value = data.user.email;
        if (data.user.phone) {
            document.querySelector('[name="userPhone"]').value = data.user.phone;
        }
    }
});
```

#### User-Linked Bookings

When a logged-in user creates a booking, it's automatically linked to their account via `userId`. This allows:

- Querying all bookings by user (even if email changes)
- Fallback to email matching for legacy bookings
- User-specific booking history in the account portal

**Employee user linking** is separate from customer user linking. An Employee's `userId` field links the employee to a Craft user account (1:1), enabling that user to log in as staff and view their employee's bookings. See [Staff Permissions & Managed Employees](#staff-permissions--managed-employees) for details on how staff users can manage multiple employees.

### JavaScript/AJAX Example: Fetch Available Slots

```javascript
// Fetch available slots when service/date changes
function fetchAvailableSlots(serviceId, employeeId, locationId, date) {
    const params = new URLSearchParams();
    params.append('date', date);
    if (serviceId) params.append('serviceId', serviceId);
    if (employeeId) params.append('employeeId', employeeId);
    if (locationId) params.append('locationId', locationId);

    fetch(`/actions/slots/slot/get-available-slots?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.slots) {
            const slotsContainer = document.getElementById('available-slots');
            slotsContainer.innerHTML = data.slots.map(slot => `
                <label>
                    <input type="radio" name="startTime" value="${slot.time}" required>
                    ${slot.time} - ${slot.endTime}
                    ${slot.employeeName ? `(${slot.employeeName})` : ''}
                </label>
            `).join('');
        }
    });
}
```

### Pre-built Templates

The plugin provides pre-built templates for quick implementation:

```twig
{# Render booking wizard #}
{{ craft.slots.getWizard() }}

{# Render booking form with options #}
{{ craft.slots.getForm({
    title: 'Book Your Appointment',
    text: 'Select your preferred date and time',
    viewMode: 'wizard'
}) }}

```

### Customizing Wizard Appearance

The booking wizard supports three levels of CSS customization:

#### 1. CSS Variable Overrides (`customStyles`)

The fastest way to retheme the wizard. Pass a `customStyles` object to override design tokens directly on the wizard element:

```twig
{# Booking wizard with custom colors #}
{{ craft.slots.getWizard({
    customStyles: {
        '--bk-black': '#1e3a5f',
        '--bk-white': '#f8fafc',
        '--bk-muted': '#64748b',
        '--bk-border-light': '#cbd5e1',
        '--bk-green': '#059669',
    }
}) }}

```

**Available CSS tokens:**

| Token | Default | Description |
|-------|---------|-------------|
| `--bk-black` | `#000000` | Primary color — buttons, borders, headings, hover fills |
| `--bk-white` | `#ffffff` | Background color for cards, inputs, wizard body |
| `--bk-dark` | `#1a1a1a` | Slightly lighter than black — secondary text |
| `--bk-hover` | `#333333` | Hover state for interactive elements |
| `--bk-muted` | `#666666` | Secondary text, labels, descriptions |
| `--bk-placeholder` | `#999999` | Placeholder text, disabled headings |
| `--bk-disabled` | `#cccccc` | Disabled borders, inactive elements |
| `--bk-border-light` | `#e0e0e0` | Light borders, dividers |
| `--bk-bg-light` | `#f0f0f0` | Light background areas |
| `--bk-bg-lighter` | `#f5f5f5` | Lighter background areas |
| `--bk-bg-soft` | `#fafafa` | Softest background tone |
| `--bk-red` | `#dc2626` | Error states, cancellation badges |
| `--bk-red-dark` | `#991b1b` | Dark red for hover states |
| `--bk-red-bg` | `#fef2f2` | Light red background for error messages |
| `--bk-green` | `#16a34a` | Success states, available indicators |
| `--bk-green-dark` | `#166534` | Dark green for hover states |
| `--bk-green-bg` | `#f0fdf4` | Light green background |
| `--bk-green-hover` | `#dcfce7` | Green hover state |

**Example: Dark theme**

```twig
{{ craft.slots.getWizard({
    customStyles: {
        '--bk-black': '#e2e8f0',
        '--bk-white': '#0f172a',
        '--bk-dark': '#cbd5e1',
        '--bk-muted': '#94a3b8',
        '--bk-placeholder': '#64748b',
        '--bk-disabled': '#475569',
        '--bk-border-light': '#334155',
        '--bk-bg-light': '#1e293b',
        '--bk-bg-lighter': '#1e293b',
        '--bk-bg-soft': '#0f172a',
    }
}) }}
```

**Example: Brand color accent**

```twig
{# Only override --bk-black to change the accent color #}
{{ craft.slots.getWizard({
    customStyles: {
        '--bk-black': '#4f46e5',
    }
}) }}
```

#### 2. Wrapper Class (`cssWrapperClass`)

Add a custom class to the wizard root element for targeted CSS overrides:

```twig
{{ craft.slots.getWizard({
    cssWrapperClass: 'my-custom-wizard'
}) }}
```

```css
/* Your stylesheet */
.my-custom-wizard .slots-card {
    border-radius: 12px;
}
.my-custom-wizard .slots-slot {
    font-family: 'My Custom Font', sans-serif;
}
```

#### 3. CSS Prefix (`cssPrefix`)

Replace the default `slots` class prefix entirely. Useful when embedding multiple wizards with different styles on the same page:

```twig
{% include 'slots/frontend/wizard' with {
    cssPrefix: 'my-events'
} %}
```

This changes all class names from `slots-wizard`, `slots-card`, etc. to `my-events-wizard`, `my-events-card`, etc. You must provide your own CSS for all classes when using a custom prefix.

### Template Variables

The `craft.slots` variable provides access to all booking functionality.

## Multi-Site Support

Slots has two categories of elements with different multi-site behavior. Understanding this distinction is critical when querying elements in custom code.

### Site-Aware Elements (Localized)

**Service** overrides `isLocalized()` to return `true` and supports Craft's propagation methods:

| Propagation Method | Behavior |
|---|---|
| `None` | Exists on one site only |
| `All` | Propagates to all sites |
| `SiteGroup` | Propagates within the same site group |
| `Language` | Propagates to sites with the same language |

These elements have translatable fields (title, description) and will return site-specific content when queried with a site ID.

### Non-Site-Aware Elements

**Employee**, **Location**, **Reservation**, **BlackoutDate**, and **Schedule** do NOT override `isLocalized()`. Craft stores them on the primary site by default.

**This has a critical implication:** when querying these elements from a non-primary site, Craft's default site scoping returns no results. You **must** use `->siteId('*')` to search across all sites:

```php
// ❌ WRONG — returns nothing on non-primary sites
$employee = Employee::find()->id($employeeId)->one();

// ✅ CORRECT — works from any site
$employee = Employee::find()->siteId('*')->id($employeeId)->one();
```

All internal services (AvailabilityService, BookingService, CapacityService, ScheduleResolverService, PermissionService, etc.) already apply `->siteId('*')` when querying non-localized elements. If you write custom queries against these elements, you must do the same.

### ElementQueryHelper

The plugin provides `ElementQueryHelper` for standardized site filtering:

```php
use anvildev\slots\helpers\ElementQueryHelper;

// Search across all sites (use for Employee, Location, Reservation, etc.)
ElementQueryHelper::forAllSites($query);   // ->siteId('*')

// Current site only (use for Service when you want localized content)
ElementQueryHelper::forCurrentSite($query);

// Specific site
ElementQueryHelper::forSite($query, $siteId);
```

### Email Language

Emails render in the language of the site where the booking originated. The `EmailRenderService` temporarily switches `Craft::$app->language` to the booking's site language before rendering templates, then restores it. This ensures customers receive emails in the correct language regardless of which site the queue worker runs on.

## Console Commands

Slots provides 20+ CLI commands for diagnostics, email previews, data management, and more.

See **[CONSOLE_COMMANDS.md](CONSOLE_COMMANDS.md)** for the full reference.

## Scheduled Tasks (Cron Jobs)

Slots requires two cron jobs for full functionality, plus an optional one for low-traffic sites.

### Required

**1. Process the Craft queue** — emails run asynchronously:

```bash
*/5 * * * * php /path/to/craft queue/run
```

Without this, booking confirmations and reminders won't be delivered. Alternatively, use a persistent queue daemon (`queue/listen`).

**2. Send appointment reminders** — checks for upcoming bookings within the reminder window and queues email notifications:

```bash
*/15 * * * * php /path/to/craft slots/reminders/queue
```

Reminders are flag-guarded (`emailReminder24hSent` / `smsReminder24hSent`), so running frequently is safe — each reminder is only sent once. The window is configured via `emailReminderHoursBefore` (default: 24) and `smsReminderHoursBefore` (default: 24) in plugin settings.

### Recommended

**3. Force Craft garbage collection** — triggers `MaintenanceService::runAll()` which handles:

| Task | What it cleans up |
|------|-------------------|
| Expired soft locks | Abandoned slot reservations in `slots_soft_locks` |
| Stale pending payments | Unpaid `pending` bookings past the payment window — never a booking with a paid payment record |

```bash
0 * * * * php /path/to/craft gc
```

Craft fires GC probabilistically during web requests (~1 in 100,000), so on high-traffic sites this cron may be unnecessary. On low-traffic sites, this ensures cleanup actually runs.

> **Note:** Soft locks also self-clean on every new lock creation, so they won't block availability even without GC.

### Minimal production setup

```bash
# /etc/cron.d/slots (adjust paths to your environment)
*/5  * * * * www-data php /path/to/craft queue/run
*/15 * * * * www-data php /path/to/craft slots/reminders/queue
0    * * * * www-data php /path/to/craft gc
```

## Best Practices

### 1. Use Events for Custom Logic

Don't modify core plugin files. Use events instead:

```php
// ❌ Bad
class BookingService extends Component
{
    public function createReservation(array $data): ?Reservation
    {
        // Modified core method
        $this->sendToCustomCRM($data); // Don't do this
        // ...
    }
}

// ✅ Good
Event::on(
    BookingService::class,
    BookingService::EVENT_AFTER_BOOKING_SAVE,
    function($event) {
        $this->sendToCustomCRM($event->reservation);
    }
);
```

### 2. Optimize Queries

Use eager loading for related elements:

```php
// ❌ Bad (N+1 problem)
$reservations = Reservation::find()->all();
foreach ($reservations as $reservation) {
    echo $reservation->getService()->title; // Extra query per reservation
}

// ✅ Good
$reservations = Reservation::find()
    ->with(['service', 'employee', 'location'])
    ->all();

foreach ($reservations as $reservation) {
    echo $reservation->service->title; // No extra queries
}
```

### General

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `defaultCurrency` | `?string` | `null` | ISO 4217 currency code; falls back to CHF |
| `softLockDurationMinutes` | `int` | `5` | Soft lock duration in minutes for race condition prevention |
| `minimumAdvanceBookingHours` | `int` | `0` | Minimum hours before appointment that booking is allowed |
| `maximumAdvanceBookingDays` | `int` | `90` | Maximum days in advance a booking can be made |
| `cancellationPolicyHours` | `int` | `24` | Hours before appointment that cancellation is allowed. Set to `0` to allow cancellation at any time. |
| `defaultTimeSlotLength` | `?int` | `null` | Default time slot length in minutes; `null` uses service duration |

### Security

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enableRateLimiting` | `bool` | `true` | Enable rate limiting for booking submissions |
| `rateLimitPerEmail` | `int` | `5` | Maximum bookings per email per day |
| `rateLimitPerIp` | `int` | `10` | Maximum bookings per IP per day |
| `enableCaptcha` | `bool` | `false` | Enable CAPTCHA verification |
| `captchaProvider` | `?string` | `null` | `'recaptcha'`, `'hcaptcha'`, or `'turnstile'` |
| `recaptchaSiteKey` | `?string` | `null` | Google reCAPTCHA v3 site key |
| `recaptchaSecretKey` | `?string` | `null` | Google reCAPTCHA v3 secret key |
| `hcaptchaSiteKey` | `?string` | `null` | hCaptcha site key |
| `hcaptchaSecretKey` | `?string` | `null` | hCaptcha secret key |
| `turnstileSiteKey` | `?string` | `null` | Cloudflare Turnstile site key |
| `turnstileSecretKey` | `?string` | `null` | Cloudflare Turnstile secret key |
| `enableHoneypot` | `bool` | `true` | Enable honeypot spam protection |
| `honeypotFieldName` | `string` | `'website'` | Hidden field name for honeypot trap |
| `enableCsrfValidation` | `bool` | `true` | Enable CSRF validation on booking forms |
| `enableIpBlocking` | `bool` | `false` | Enable IP address blocking |
| `blockedIps` | `?string` | `null` | JSON-encoded array of blocked IPs |
| `enableTimeBasedLimits` | `bool` | `true` | Enable minimum time between form submissions |
| `minimumSubmissionTime` | `int` | `3` | Minimum seconds between form submissions |
| `enableAuditLog` | `bool` | `false` | Enable security audit logging |

### Email Notifications

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `ownerNotificationEnabled` | `bool` | `true` | Send notification emails to owner on new bookings |
| `ownerEmail` | `?string` | `null` | Owner email (falls back to Craft's `fromEmail`) |
| `ownerName` | `?string` | `null` | Owner name (falls back to Craft's `fromName`) |
| `ownerNotificationSubject` | `?string` | `null` | Custom owner notification subject |
| `bookingConfirmationSubject` | `?string` | `null` | Custom booking confirmation subject |
| `reminderEmailSubject` | `?string` | `null` | Custom reminder email subject |
| `cancellationEmailSubject` | `?string` | `null` | Custom cancellation email subject |
| `emailRemindersEnabled` | `bool` | `true` | Send email reminders to customers |
| `emailReminderHoursBefore` | `int` | `24` | Hours before appointment to send reminder |
| `sendCancellationEmail` | `bool` | `true` | Send cancellation email to customer |

### Google Meet

| Setting | Type | Default | Description |
|---------|------|---------|-------------|

### Sensitive Settings

The following settings are excluded from Craft's project config and stored only in the database to protect credentials:

### Helper Methods

The `Settings` model provides convenience methods for checking feature availability:

```php
$settings = Slots::getInstance()->getSettings();

$settings->getEffectiveEmail();          // Owner email or Craft's fromEmail
$settings->getEffectiveName();           // Owner name or Craft's fromName
```

## Resources

- [Event System Documentation](EVENT_SYSTEM.md) - Complete event reference
- [Availability System](AVAILABILITY.md) - Availability calculation details
- [Payments Setup](docs/payments-setup.md) - Stripe keys, webhook, test cards
- [Console Commands](CONSOLE_COMMANDS.md) - Reminders, payments, diagnostics
- [Craft CMS Documentation](https://craftcms.com/docs) - Craft CMS reference

