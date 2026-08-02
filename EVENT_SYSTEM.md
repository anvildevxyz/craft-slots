# Event System

The Slots plugin fires events at key points in the booking lifecycle using Yii's event system. Use `Before*` events for validation and data modification (cancelable), and `After*` events for side effects like notifications and integrations.

## Booking Events

All booking events inherit from `BookingEvent` which provides:
- `ReservationInterface $reservation` — the reservation
- `bool $isNew` — whether this is a new reservation

### `BeforeBookingSaveEvent`

**Service:** `BookingService::EVENT_BEFORE_BOOKING_SAVE`
**Cancelable:** Yes (`$event->isValid = false`)

| Property | Type | Description |
|----------|------|-------------|
| `$bookingData` | array | Raw booking data submitted |
| `$source` | string\|null | Source: `'web'`, `'api'`, `'admin'` |
| `$isValid` | bool | Set to `false` to prevent save |
| `$errorMessage` | string\|null | Error message if validation fails |

```php
use yii\base\Event;
use anvildev\slots\services\BookingService;
use anvildev\slots\events\BeforeBookingSaveEvent;

Event::on(
    BookingService::class,
    BookingService::EVENT_BEFORE_BOOKING_SAVE,
    function(BeforeBookingSaveEvent $event) {
        if (empty($event->reservation->userPhone)) {
            $event->isValid = false;
            $event->errorMessage = 'Phone number is required';
        }
    }
);
```

### `AfterBookingSaveEvent`

**Service:** `BookingService::EVENT_AFTER_BOOKING_SAVE`
**Cancelable:** No

| Property | Type | Description |
|----------|------|-------------|
| `$success` | bool | Whether the save succeeded |
| `$errors` | array | Validation errors if save failed |

```php
Event::on(
    BookingService::class,
    BookingService::EVENT_AFTER_BOOKING_SAVE,
    function(AfterBookingSaveEvent $event) {
        if ($event->success && $event->isNew) {
            // Queue CRM sync to avoid blocking the booking flow
            Craft::$app->queue->push(new SyncToCrmJob([
                'reservationId' => $event->reservation->getId(),
            ]));
        }
    }
);
```

### `BeforeBookingCancelEvent`

**Service:** `BookingService::EVENT_BEFORE_BOOKING_CANCEL`
**Cancelable:** Yes (`$event->isValid = false`)

| Property | Type | Description |
|----------|------|-------------|
| `$reason` | string\|null | Cancellation reason |
| `$cancelledBy` | string\|null | User or system that initiated cancellation |
| `$sendNotification` | bool | Whether to send cancellation notification (default: `true`) |
| `$isValid` | bool | Set to `false` to prevent cancellation |
| `$errorMessage` | string\|null | Error message if prevented |

```php
Event::on(
    BookingService::class,
    BookingService::EVENT_BEFORE_BOOKING_CANCEL,
    function(BeforeBookingCancelEvent $event) {
        // Prevent cancellation less than 24 hours before appointment
        $bookingDateTime = new \DateTime(
            $event->reservation->bookingDate . ' ' . $event->reservation->startTime
        );
        $hoursUntil = ($bookingDateTime->getTimestamp() - time()) / 3600;

        if ($hoursUntil < 24) {
            $event->isValid = false;
            $event->errorMessage = 'Cancellations must be made at least 24 hours in advance';
        }
    }
);
```

### `AfterBookingCancelEvent`

**Service:** `BookingService::EVENT_AFTER_BOOKING_CANCEL`
**Cancelable:** No

| Property | Type | Description |
|----------|------|-------------|
| `$reason` | string\|null | Cancellation reason |
| `$success` | bool | Whether the cancellation succeeded |
| `$wasPaid` | bool | Whether the booking had been paid |
| `$shouldRefund` | bool | Whether a refund should be processed |

```php
Event::on(
    BookingService::class,
    BookingService::EVENT_AFTER_BOOKING_CANCEL,
    function(AfterBookingCancelEvent $event) {
        if ($event->success && $event->wasPaid) {
            // Issue a refund via the payment service
                ->getOrderByReservationId($event->reservation->getId());
            // Implement your refund logic here
        }
    }
);
```

### `BeforeQuantityChangeEvent`

**Service:** `BookingService::EVENT_BEFORE_QUANTITY_CHANGE`
**Cancelable:** Yes (`$event->isValid = false`)

| Property | Type | Description |
|----------|------|-------------|
| `$reservation` | ReservationInterface | The reservation being modified |
| `$isNew` | bool | Whether this is a new reservation (inherited from `BookingEvent`) |
| `$previousQuantity` | int | Previous quantity |
| `$newQuantity` | int | Requested new quantity |
| `$reduceBy` | int | Amount being reduced (0 if increasing) |
| `$increaseBy` | int | Amount being increased (0 if reducing) |
| `$reason` | string\|null | Reason for the change |
| `$isValid` | bool | Set to `false` to prevent the change (inherited from `CancelableEvent`) |

```php
Event::on(
    BookingService::class,
    BookingService::EVENT_BEFORE_QUANTITY_CHANGE,
    function(BeforeQuantityChangeEvent $event) {
        // Prevent reducing quantity below a minimum
        if ($event->newQuantity < 2) {
            $event->isValid = false;
        }
    }
);
```

### `AfterQuantityChangeEvent`

**Service:** `BookingService::EVENT_AFTER_QUANTITY_CHANGE`
**Cancelable:** No

| Property | Type | Description |
|----------|------|-------------|
| `$reservation` | ReservationInterface | The modified reservation |
| `$isNew` | bool | Whether this is a new reservation (inherited from `BookingEvent`) |
| `$previousQuantity` | int | Previous quantity |
| `$newQuantity` | int | New quantity |
| `$reduceBy` | int | Amount that was reduced (0 if increased) |
| `$increaseBy` | int | Amount that was increased (0 if reduced) |
| `$reason` | string\|null | Reason for the change |
| `$originalTotalPrice` | float | Total price before the change |

## Availability Events

### `BeforeAvailabilityCheckEvent`

**Service:** `AvailabilityService::EVENT_BEFORE_AVAILABILITY_CHECK`
**Cancelable:** Yes (`$event->isValid = false`)

| Property | Type | Description |
|----------|------|-------------|
| `$date` | string | Date being checked (Y-m-d) |
| `$serviceId` | int\|null | Service ID |
| `$employeeId` | int\|null | Employee ID |
| `$locationId` | int\|null | Location ID |
| `$quantity` | int | Requested quantity |
| `$criteria` | array | Additional search criteria |
| `$isValid` | bool | Set to `false` to prevent check |
| `$errorMessage` | string\|null | Error message if prevented |

### `AfterAvailabilityCheckEvent`

**Service:** `AvailabilityService::EVENT_AFTER_AVAILABILITY_CHECK`
**Cancelable:** No

| Property | Type | Description |
|----------|------|-------------|
| `$date` | string | Date that was checked |
| `$serviceId` | int\|null | Service ID |
| `$employeeId` | int\|null | Employee ID |
| `$locationId` | int\|null | Location ID |
| `$slots` | array | Available slots (modifiable) |
| `$availableSlots` | array | Alias for `$slots` (auto-synced) |
| `$slotCount` | int | Number of slots found |
| `$calculationTime` | float | Calculation time in seconds |
| `$duration` | float | Alias for `$calculationTime` (auto-synced) |
| `$fromCache` | bool | Whether result was from cache |

```php
Event::on(
    AvailabilityService::class,
    AvailabilityService::EVENT_AFTER_AVAILABILITY_CHECK,
    function(AfterAvailabilityCheckEvent $event) {
        // Filter out slots that conflict with external events
        $externalEvents = ExternalCalendar::getEvents($event->date);
        $event->slots = array_filter($event->slots, function($slot) use ($externalEvents) {
            foreach ($externalEvents as $ext) {
                if ($slot['start'] === $ext['start']) return false;
            }
            return true;
        });
    }
);
```

## Registering Handlers

Register event handlers in your module's `init()` method and bootstrap in `config/app.php`:

```php
namespace modules;

use Craft;
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

## Internal Event Listeners

The plugin registers its own internal listeners on booking events for side-effect processing. These are registered in `Slots.php` during plugin initialization:


These listeners run automatically; no configuration is needed. Be aware of them when writing your own event handlers to avoid duplicate processing.

## Best Practices

1. **Keep handlers lightweight** — use Craft's queue for heavy operations
2. **Catch exceptions** — don't let event handler errors break the booking flow
3. **Validate in before events** — use `Before*` events for validation, `After*` for side effects

## Related Documentation

- [Developer Guide](DEVELOPER_GUIDE.md) — Service API reference
- [Craft CMS Events](https://craftcms.com/docs/5.x/extend/events.html) — Yii event system
