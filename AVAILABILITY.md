# Availability & Schedule System

This document explains how the Slots plugin calculates availability and manages schedules for employees and services.

## Table of Contents

- [Overview](#overview)
- [Subtractive Availability Model](#subtractive-availability-model)
- [Schedule System](#schedule-system)
- [Capacity Management](#capacity-management)
- [Schedule Priority](#schedule-priority)
- [Availability Calculation Flow](#availability-calculation-flow)
- [Examples](#examples)
- [Best Practices](#best-practices)

## Overview

The Slots plugin uses a **subtractive availability model** to calculate available time slots. This means we start with working hours and subtract unavailable time (bookings, buffers, breaks, etc.).

The system supports two types of schedules:
1. **Employee Schedules** - Define when employees are available to work
2. **Service Schedules** - Define when services are available (for employee-less bookings)

Both schedule types use the same structure: weekly patterns with optional date ranges and sortOrder-based matching.

## Subtractive Availability Model

The availability calculation follows this formula:

```
Available Slots = Working Hours - (Bookings + Buffers + Breaks + Blackouts)
```

### Key Concepts

- **Working Hours**: Base availability from employee or service schedules
- **Bookings**: Confirmed reservations that block time
- **Buffers**: Time before/after bookings (configured per service)
- **Breaks**: Lunch breaks or rest periods within working hours
- **Blackouts**: System-wide blackout dates that block all availability
- **Soft locks**: Slots temporarily held by another customer mid-booking

## Schedule System

### Schedule Structure

Each schedule (Employee or Service) contains:

- **Title**: Descriptive name for the schedule (e.g., "Summer Schedule", "Regular Hours")
- **Sort Order**: Determines which schedule applies when multiple match (lower sortOrder wins). Set by dragging schedules in the Control Panel
- **Working Hours / Availability Schedule**: Weekly pattern (Monday-Sunday) with:
  - `enabled`: Whether the day is available
  - `start`: Start time (e.g., "09:00")
  - `end`: End time (e.g., "17:00")
  - `breakStart`: Optional break start time (e.g., "12:00")
  - `breakEnd`: Optional break end time (e.g., "13:00")
- **Start Date**: Optional - when the schedule begins (null = unlimited)
- **End Date**: Optional - when the schedule ends (null = unlimited)
- **Enabled**: Whether the schedule is active
- **Capacity**: (Service Schedules only) Maximum number of people that can book the same slot (null = unlimited, 1 = 1-on-1, > 1 = capacity-based)

### Day of Week Format

The system uses a **1-7 format** where:
- `1` = Monday
- `2` = Tuesday
- `3` = Wednesday
- `4` = Thursday
- `5` = Friday
- `6` = Saturday
- `7` = Sunday

**Note**: This differs from PHP's `date('w')` which uses 0-6 (Sunday=0). The system automatically converts between formats.

### Employee Schedules

Employee schedules define when employees are available to perform services.

**Example**:
```json
{
  "title": "Regular Hours",
  "workingHours": {
    "1": { "enabled": true, "start": "09:00", "end": "17:00", "breakStart": "12:00", "breakEnd": "13:00" },
    "2": { "enabled": true, "start": "09:00", "end": "17:00", "breakStart": "12:00", "breakEnd": "13:00" },
    "3": { "enabled": true, "start": "09:00", "end": "17:00", "breakStart": "12:00", "breakEnd": "13:00" },
    "4": { "enabled": true, "start": "09:00", "end": "17:00", "breakStart": "12:00", "breakEnd": "13:00" },
    "5": { "enabled": true, "start": "09:00", "end": "17:00", "breakStart": "12:00", "breakEnd": "13:00" },
    "6": { "enabled": false },
    "7": { "enabled": false }
  },
  "startDate": null,
  "endDate": null,
  "enabled": true
}
```

This schedule defines:
- Monday-Friday: 9 AM - 5 PM with 12 PM - 1 PM lunch break
- Saturday-Sunday: Not working
- No date restrictions (unlimited)

### Service Schedules

Service schedules define when a service is available **without requiring an employee**. This is useful for:
- Automated services
- Self-service bookings
- Group classes where multiple people book the same slot

**Example**:
```json
{
  "title": "Online Course Availability",
  "workingHours": {
    "1": { "enabled": true, "start": "08:00", "end": "20:00" },
    "2": { "enabled": true, "start": "08:00", "end": "20:00" },
    "3": { "enabled": true, "start": "08:00", "end": "20:00" },
    "4": { "enabled": true, "start": "08:00", "end": "20:00" },
    "5": { "enabled": true, "start": "08:00", "end": "20:00" },
    "6": { "enabled": true, "start": "09:00", "end": "18:00" },
    "7": { "enabled": true, "start": "09:00", "end": "18:00" }
  },
  "startDate": "2026-01-01",
  "endDate": "2026-12-31",
  "capacity": null,
  "enabled": true
}
```

## Capacity Management

Capacity management allows you to control how many people can book the same time slot. This feature is available **only for Service Schedules** (employee-less bookings).

### Per-Day Capacity

Schedules support **per-day capacity**, allowing different capacities for each day of the week. This is configured in the Schedule edit page under the "Capacity" column in the working hours table.

**Example**: A bike tour might have:
- Monday-Friday: Capacity of 10 people
- Saturday-Sunday: Capacity of 20 people

| Day | Start | End | Capacity |
|-----|-------|-----|----------|
| Monday | 09:00 | 17:00 | 10 |
| Tuesday | 09:00 | 17:00 | 10 |
| Wednesday | 09:00 | 17:00 | 10 |
| Thursday | 09:00 | 17:00 | 10 |
| Friday | 09:00 | 17:00 | 10 |
| Saturday | 09:00 | 17:00 | 20 |
| Sunday | 09:00 | 17:00 | 20 |

**Leave capacity empty** for unlimited bookings on that day.

### How Capacity Works

Capacity **only applies to employee-less bookings** (service schedules). For employee-based bookings, capacity is always 1 per employee — the service schedule capacity field is ignored.

| Capacity Value | Wizard Display | Quantity Selector | Example Use Case |
|---------------|---------------|-------------------|-----------------|
| `null` (empty) | "Open" | Yes (up to 99) | Online courses, unlimited events |
| `1` | — | Hidden (auto 1) | 1-on-1 consultations, automated services |
| `> 1` (e.g. 20) | "15 available" | Yes (up to remaining) | Tours, group classes |

Fully booked slots (remaining capacity = 0) are filtered out automatically.

### Capacity in Availability Calculation

Capacity affects availability calculation in the following ways:

1. **Slot Generation**: For employee-based bookings, capacity is always 1 (one person per employee per slot). For service-level bookings, capacity comes from the active service schedule.

2. **Available Spots**: Each slot includes:
   - `maxCapacity`: The maximum capacity for this slot (null, 1, or > 1)
   - `bookedQuantity`: Number of spots already booked
   - `availableCapacity`: Remaining available spots (null = unlimited)

3. **Slot Filtering**: Slots with `availableCapacity = 0` are filtered out (fully booked).

4. **Quantity Selection**: The booking wizard:
   - For `capacity = 1`: Hides quantity selector, defaults to 1
   - For `capacity = null`: Shows "Open" with quantity selector (unlimited)
   - For `capacity > 1`: Shows available spots and quantity selector (up to remaining capacity)

### Technical Details

**Database**: Capacity is stored in the schedule's `workingHours` JSON structure:

```php
// workingHours structure with per-day capacity
[
    1 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'capacity' => 10], // Monday
    2 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'capacity' => 10], // Tuesday
    // ...
    6 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'capacity' => 20], // Saturday
    7 => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'capacity' => 20], // Sunday
]
```

**Capacity Resolution**:
- If `Schedule.workingHours[dayOfWeek].capacity` is set, that value is used
- If capacity is not set (null/empty), bookings are **unlimited**

**API**: Slots returned from `getAvailableSlots()` include:
```php
[
    'time' => '10:00',
    'maxCapacity' => 20,
    'bookedQuantity' => 5,
    'availableCapacity' => 15,
    'capacity' => 20, // Legacy field (same as maxCapacity)
]
```

**Booking**: When creating a booking, the `quantity` field specifies how many spots are being booked. The system validates that `quantity <= availableCapacity`.

## Schedule Priority

When multiple schedules match a date, the system selects **one active schedule** based on priority.

### Priority Rules

When multiple schedules match a date, the system uses **automatic date specificity prioritization**:

1. **Date specificity tier** (automatic, no configuration needed):
   - **Tier 1**: Schedules with BOTH `startDate` AND `endDate` defined (most specific, wins)
   - **Tier 2**: Schedules with only `startDate` OR only `endDate` defined
   - **Tier 3**: Schedules with NEITHER date defined (forever/default schedules)

2. **Tiebreaker**: Within the same tier, lower `sortOrder` values win

3. **Enabled check**: Only enabled schedules are considered

This means date-specific schedules (like holiday hours) automatically take priority over "forever" schedules without any manual configuration.

### Example: Date-Specific Schedule Priority

An employee has two schedules assigned:

1. **"Regular Hours"** (sortOrder: 1, Dates: unlimited)
   - Standard hours: 9 AM - 5 PM
   - Tier 3 (no dates defined)

2. **"Christmas Schedule"** (sortOrder: 2, Dates: Dec 24 - Jan 2)
   - Holiday hours: 10 AM - 4 PM
   - Tier 1 (both dates defined)

**Date: December 25, 2026** (Thursday)
- ✅ "Regular Hours" matches (unlimited dates) - Tier 3
- ✅ "Christmas Schedule" matches (within date range) - Tier 1
- **Selected**: "Christmas Schedule" (Tier 1 beats Tier 3, regardless of sortOrder)

**Date: March 15, 2026** (Sunday)
- ✅ "Regular Hours" matches (unlimited dates) - Tier 3
- ❌ "Christmas Schedule" doesn't match (outside date range)
- **Selected**: "Regular Hours" (only matching schedule)

> **Note**: The Christmas schedule wins on Dec 25 even though its sortOrder (2) is higher than Regular Hours (1). Date specificity always takes priority over sortOrder.

## Availability Calculation Flow

The availability calculation follows these steps:

### Step 1: Get Working Hours

The system retrieves working hours based on:
- **Date**: The specific date being checked
- **Day of Week**: Calculated from the date (1=Monday, 7=Sunday)
- **Employee ID**: Optional filter for specific employee
- **Service ID**: Optional filter for employees who can perform the service
- **Location ID**: Optional filter for location

**Process**:
1. Find all employees matching the criteria
2. For each employee, get their active schedule for the date (using priority matching)
3. Extract working hours for that day of week from the schedule
4. Split working hours by breaks (if any)

**Result**: Array of time windows (e.g., `[['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']]`)

### Step 2: Check Service-Level Availability

If no employee schedules match, the system checks if the service has its own availability schedule.

**Process**:
1. Check if service has any enabled schedules
2. Get active schedule for the date (using priority matching)
3. Extract availability for that day of week
4. Use service schedule directly (no employee filtering)

**Result**: Service-level availability slots or empty array

### Step 3: Merge Time Windows

If an employee has multiple time windows (due to breaks), merge overlapping windows.

**Example**:
- Window 1: 09:00 - 12:00
- Window 2: 13:00 - 17:00
- Result: Two separate windows (not merged due to break)

### Step 4: Subtract Bookings

For each employee, subtract their existing bookings from working hours.

**Process**:
1. Get all confirmed bookings for the employee on that date
2. For each booking, expand the blocked time by:
   - **Buffer Before**: Time before booking (service setting)
   - **Buffer After**: Time after booking (service setting)
3. Subtract blocked windows from available windows

**Example**:
- Working Hours: 09:00 - 17:00
- Existing Booking: 10:00 - 11:00
- Buffer Before: 15 minutes
- Buffer After: 15 minutes
- Blocked Time: 09:45 - 11:15
- Available: 09:00 - 09:45, 11:15 - 17:00

### Step 4b: External Calendar Blocking (Planned)


### Step 5: Subtract Blackout Dates

Employees or services with active blackout dates on the requested date are skipped entirely.

**Process**:
1. Load all blackout dates covering the requested date
2. Check each employee against the blackout list
3. Employees with a matching blackout date produce no slots for that date

For service-level schedules (employee-less), the system checks if the service itself has a blackout date.

### Step 6: Apply End-of-Day Buffers

If the service has buffers, ensure no slots start or end too close to working hour boundaries.

**Example**:
- Service Duration: 60 minutes
- Buffer Before: 15 minutes
- Buffer After: 15 minutes
- Working Hours End: 17:00
- Last Possible Slot: 15:45 (15:45 + 60 min + 15 min buffer = 17:00)

### Step 7: Generate Time Slots

Create individual time slots from available windows.

**Process**:
1. For each available time window
2. Generate slots at intervals determined by the **slot interval** (see [Time Slot Interval](#time-slot-interval) below)
3. Ensure each slot fits within the window (including buffers)

**Example**:
- Available Window: 09:00 - 12:00
- Service Duration: 30 minutes
- Slot Interval: 15 minutes (different from duration)
- Generated Slots: 09:00, 09:15, 09:30, 09:45, 10:00, 10:15, 10:30, 10:45, 11:00, 11:15, 11:30, 11:45

**Note**: The slot interval determines how often slots appear in the calendar, while the service duration determines how long each booking lasts. These can be different values.

#### Time Slot Interval

The slot interval (how often slots appear) follows this fallback chain:

```
Service timeSlotLength → Global defaultTimeSlotLength → Service duration
```

This means a 60-minute service with a 15-minute slot interval shows slots at 09:00, 09:15, 09:30, etc. See [Configuration Guide](CONFIGURATION.md#time-slot-interval) for setup details.

### Step 8: Filter Past Slots and Advance Booking

Remove slots that are in the past or too close to the current time.

- Slots before the current server time are removed
- If `minimumAdvanceBookingHours` is configured, slots within that window are also removed (e.g., with a 24-hour minimum, a slot tomorrow at 9 AM is unavailable if it's currently past 9 AM today)

### Step 9: Filter Soft-Locked Slots

Remove slots that are currently being booked by another user (unless it's the same user's soft lock).

**Purpose**: Prevents race conditions when multiple users try to book the same slot simultaneously.

### Step 10: Filter by Quantity

If the booking requires multiple resources (quantity > 1), ensure enough employees are available at that time.

**Example**:
- Requested Quantity: 3
- Available Slots at 10:00: 5 employees
- Result: Slot is available

- Requested Quantity: 3
- Available Slots at 11:00: 2 employees
- Result: Slot is not available

### Step 11: Apply Timezone Conversion

If a user timezone is specified, convert slots to that timezone.

**Note**: All times are stored in the location's timezone (default: Europe/Zurich), but displayed in the user's timezone.

### Step 12: Deduplicate Slots

If no specific employee was requested, remove duplicate time slots (keep one slot per unique time).

**Purpose**: When booking "any available employee", show each time only once.

## Examples

### Example 1: Basic Employee Schedule

**Setup**:
- Employee: John
- Schedule: Monday-Friday, 9 AM - 5 PM, 12 PM - 1 PM break
- Service: 60-minute consultation
- Date: Monday, January 19, 2026

**Calculation**:
1. Get working hours: 09:00-12:00, 13:00-17:00 (split by break)
2. No bookings (assume empty)
3. Generate slots:
   - 09:00, 10:00, 11:00, 13:00, 14:00, 15:00, 16:00

**Result**: 7 available slots

### Example 2: Schedule with Booking

**Setup**:
- Employee: John
- Schedule: Monday-Friday, 9 AM - 5 PM
- Service: 60-minute consultation, 15-minute buffer before/after
- Date: Monday, January 19, 2026
- Existing Booking: 10:00 - 11:00

**Calculation**:
1. Get working hours: 09:00-17:00
2. Blocked time: 09:45 - 11:15 (booking + buffers)
3. Available windows: 09:00-09:45, 11:15-17:00
4. Generate slots:
   - 09:00 (fits in 09:00-09:45)
   - 11:15, 12:15, 13:15, 14:15, 15:15, 16:15 (fit in 11:15-17:00)

**Result**: 7 available slots (09:00, 11:15, 12:15, 13:15, 14:15, 15:15, 16:15)

### Example 3: sortOrder Tiebreaker (Same Tier)

**Setup**:
- Employee: Sarah
- "Morning Shift" (sortOrder: 1, first in CP list): Jan-Jun 2026, Monday-Friday, 7 AM - 3 PM
- "Standard Shift" (sortOrder: 2, second in CP list): Jan-Dec 2026, Monday-Friday, 9 AM - 5 PM
- Date: March 16, 2026 (Monday)

**Calculation**:
1. Both schedules match the date and both have start+end dates → both are **Tier 1**
2. Same tier → sortOrder decides: "Morning Shift" (sortOrder 1) wins over "Standard Shift" (sortOrder 2)
3. Working hours: 07:00-15:00

**Result**: Slots based on morning shift (7 AM - 3 PM)

> **Note**: Both schedules are Tier 1 because they both define start and end dates. The sortOrder tiebreaker only matters within the same tier. If "Standard Shift" had no date range (Tier 3), "Morning Shift" (Tier 1) would win regardless of sortOrder.

### Example 4: Service-Level Availability

**Setup**:
- Service: Online Course (no employee required)
- Service Schedule: Monday-Sunday, 8 AM - 8 PM
- Date: Saturday, January 17, 2026

**Calculation**:
1. No employee schedules (service doesn't require employee)
2. Check service schedule: Available 08:00-20:00 on Saturdays
3. Generate slots based on service schedule

**Result**: Slots from 8 AM to 8 PM (based on service schedule)

## Best Practices

### Schedule Management

1. **Use Descriptive Titles**: Name schedules clearly (e.g., "Holiday 2025", "Summer Extended Hours")

2. **Arrange Schedule Order**:
   - Drag schedules in the Control Panel to set priority
   - Place override/seasonal schedules at the top (lower sortOrder wins)
   - Place regular/fallback schedules at the bottom

3. **Always Set Date Ranges**: Even for "regular" schedules, consider setting start/end dates to prevent conflicts

4. **Test Schedule Priority**: When multiple schedules have overlapping date ranges, verify the priority system selects the correct one (date-specific schedules win over unlimited ones).

### Working Hours

1. **Define Breaks Properly**: Use break start/end times to accurately represent lunch breaks and rest periods

2. **Consistent Time Format**: Always use 24-hour format (HH:MM) for times

3. **Validate Time Ranges**: Ensure `end` time is after `start` time, and break times are within working hours

### Performance

1. **Limit Active Schedules**: Having many schedules can slow down availability checks. Keep only necessary schedules enabled

2. **Use Date Ranges**: Schedules with unlimited dates are checked for every date. Use date ranges to limit the search space

3. **Batch Query Optimization**: The availability system uses batch queries internally. Schedule lookups for multiple employees are resolved in a single query via `getActiveSchedulesForDateBatch()`, and capacity enrichment pre-loads all employees, schedules, and reservations before processing slots. This reduces per-slot database queries from ~5 to near zero.

4. **Session Handling**: AJAX controllers (`SlotController`, `BookingDataController`) close the PHP session immediately after initialization. This prevents session file lock contention when multiple availability requests run in parallel.

5. **Logging**: Per-employee schedule resolution and per-slot generation logs are at `DEBUG` level. Set your Craft log level to `debug` in `config/general.php` if you need to trace availability calculations. Summary-level logs (e.g., "Returning 27 available slots for 2026-02-18") remain at `INFO`.

### Employee vs Service Schedules

1. **Use Employee Schedules When**: 
   - Service requires a specific person
   - Different employees have different availability
   - You want to track which employee performs the service

2. **Use Service Schedules When**:
   - Service doesn't require a specific employee
   - Multiple people can book the same time slot
   - Service is automated or self-service

3. **Don't Mix Both**: If a service has a schedule, it's used instead of employee schedules. The system checks employee schedules first, then falls back to service schedules only if no employee schedules match.

## Technical Details

### Database Structure

The schedule system uses **Schedule elements** that can be shared across employees and services via many-to-many relationships:

- **Schedule Elements**: Stored in `slots_schedules` table (linked to Craft's `elements` table)
  - `workingHours`: JSON containing weekly schedule pattern
  - `startDate` / `endDate`: Optional validity period

- **Employee-Schedule Assignments**: Stored in `slots_employee_schedule_assignments` pivot table
  - Links employees to Schedule elements
  - `sortOrder`: Determines priority (lower = higher priority)

- **Service-Schedule Assignments**: Stored in `slots_service_schedule_assignments` pivot table
  - Links services to Schedule elements (for employee-less bookings)
  - `sortOrder`: Determines priority

### Service Classes

- **`AvailabilityService`**: Main service for calculating **time-slot** availability
- **`ScheduleAssignmentService`**: Manages many-to-many relationships between schedules and employees/services
- **`ScheduleResolverService`**: Resolves which schedule is active for a given date
- **`CapacityService`**: Handles capacity checking for slots
- **`SlotGeneratorService`**: Generates bookable time slots from time windows, applying duration and interval rules
- **`TimeWindowService`**: Pure time window arithmetic — merging, subtracting, and splitting time ranges
- **`TimezoneService`**: Handles timezone conversions between location-local times, UTC, and user-facing display

### Methods

- **`AvailabilityService::getAvailableSlots()`**: Main entry point for availability calculation. Full signature:
  - `string $date` — The date to check (Y-m-d format)
  - `?int $employeeId = null` — Filter by specific employee
  - `?int $locationId = null` — Filter by location
  - `?int $serviceId = null` — Filter by service
  - `int $requestedQuantity = 1` — Number of spots needed
  - `?string $userTimezone = null` — Convert slots to this timezone
  - `?string $softLockToken = null` — Exclude this user's soft locks from filtering
  - `?string $targetTime = null` — Target time to prioritize in results (used for rescheduling)
  - `?int $excludeReservationId = null` — Reservation ID to exclude from booking subtraction (used for rescheduling)
- **`ScheduleAssignmentService::getActiveScheduleForDate()`**: Gets the active schedule for an employee on a date
- **`ScheduleAssignmentService::getActiveSchedulesForDateBatch()`**: Batch-fetches active schedules for multiple employees in a single query (used internally for performance)
- **`ScheduleAssignmentService::getActiveScheduleForServiceOnDate()`**: Gets the active schedule for a service on a date (memoized within a request)
- **`Schedule::getWorkingSlotsForDay()`**: Returns working time slots for a day of week

## Troubleshooting

### No Slots Available

**Check**:
1. Employee/service has at least one enabled schedule
2. Schedule's date range includes the requested date
3. Day of week is enabled in the schedule
4. Working hours are valid (start < end)
5. No conflicting bookings blocking all time

### Wrong Schedule Selected

**Check**:
1. Schedule order in Control Panel is correct (schedules at the top have lower sortOrder and win)
2. Date ranges don't overlap unexpectedly
3. Only one schedule is enabled for the date range

### Breaks Not Working

**Check**:
1. Break times are within working hours
2. Break start < break end
3. Service duration fits in available windows (accounting for breaks)

### Service Schedule Not Used

**Check**:
1. Service has at least one enabled schedule
2. No employee schedules match (service schedules are fallback only)
3. Service schedule's date range includes the requested date

## Summary

The Slots availability system uses a subtractive model that starts with schedules (employee or service) and subtracts unavailable time (bookings, buffers, breaks). Schedules support priority-based selection, date ranges, and weekly patterns with breaks. The system automatically handles complex scenarios like multiple overlapping schedules, breaks, buffers, and timezone conversion.
