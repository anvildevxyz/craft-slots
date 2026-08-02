/**
 * k6 Race Condition Test
 *
 * Tests: POST /actions/slots/booking/create-booking
 * 50 VUs all try to book the SAME slot concurrently.
 * Expected: exactly 1 success, 49 failures (slot already taken).
 *
 * Prerequisites:
 *   1. Update SERVICE_ID and EMPLOYEE_ID below with seeded IDs
 *   4. Pick a DATE and TIME that is a valid, open slot for the chosen employee/service
 *
 * Usage:
 *   k6 run plugins/slots/tests/stress/race-condition.js
 *
 * After running:
 *   ddev exec php craft slots/test/verify-no-doubles
 */

import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

// ─── Configuration ────────────────────────────────────────────────────────────
// Update these with a valid slot from seeded data.
// Pick a future date where the employee is available.
const SERVICE_ID = 1659;   // [TEST] Service 11 (must be in employee's serviceIds)
const EMPLOYEE_ID = 1664;  // [TEST] Employee 1 (serviceIds: [1659, 1662, 1663])
const DATE = '2026-04-22';  // Future date with no existing bookings for this employee
const TIME = '10:00';       // Must be within employee's schedule

const BASE_URL = __ENV.BASE_URL || 'https://craft-plugin-dev.ddev.site';

// ─── Custom Metrics ───────────────────────────────────────────────────────────
const bookingSuccesses = new Counter('booking_successes');
const bookingFailures = new Counter('booking_failures');

// ─── k6 Options ───────────────────────────────────────────────────────────────
export const options = {
    scenarios: {
        race: {
            executor: 'shared-iterations',
            vus: 50,
            iterations: 50,
            maxDuration: '30s',
        },
    },
};

// ─── Test ─────────────────────────────────────────────────────────────────────

export default function () {
    const vuId = __VU;
    const iterationId = __ITER;

    const payload = {
        customerName: `Race Test VU${vuId}-${iterationId}`,
        customerEmail: `race-vu${vuId}-iter${iterationId}@stress-test.local`,
        date: DATE,
        time: TIME,
        serviceId: String(SERVICE_ID),
        employeeId: String(EMPLOYEE_ID),
    };

    const params = {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json',
        },
    };

    const res = http.post(
        `${BASE_URL}/actions/slots/booking/create-booking`,
        payload,
        params,
    );

    const isSuccess = check(res, {
        'status is 200': (r) => r.status === 200,
    });

    // Parse response to determine booking outcome
    // Server returns {"success": true, ...} or {"success": false, "message": "..."}
    let booked = false;
    try {
        const body = JSON.parse(res.body);
        booked = body.success === true;
    } catch {
        // Non-JSON response (redirect) = not booked
    }

    if (booked) {
        bookingSuccesses.add(1);
    } else {
        bookingFailures.add(1);
    }
}

export function handleSummary(data) {
    const successes = data.metrics.booking_successes ? data.metrics.booking_successes.values.count : 0;
    const failures = data.metrics.booking_failures ? data.metrics.booking_failures.values.count : 0;

    const result = successes === 1
        ? '\n✓ PASS: Exactly 1 booking succeeded (mutex working correctly)\n'
        : `\n✗ FAIL: Expected 1 success, got ${successes} (${failures} failures)\n`;

    return {
        stdout: result + JSON.stringify(data, null, 2),
    };
}
