/**
 * k6 Availability Endpoint Load Test
 *
 * Tests: POST /actions/slots/slot/get-available-slots
 * Ramps from 20 to 100 VUs over ~4 minutes.
 *
 * Prerequisites:
 *   1. Update SERVICE_IDS and EMPLOYEE_IDS below with seeded IDs
 *
 * Usage:
 *   k6 run plugins/slots/tests/stress/availability.js
 *   k6 run --env BASE_URL=https://my-site.ddev.site plugins/slots/tests/stress/availability.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { randomItem } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

// ─── Configuration ────────────────────────────────────────────────────────────
// Update these with IDs from `php craft slots/test/seed` output
const SERVICE_IDS = [1649, 1650, 1651, 1652, 1653, 1654, 1655, 1656, 1657, 1658, 1659, 1660, 1661, 1662, 1663];
const EMPLOYEE_IDS = [1664, 1665, 1666, 1667, 1668, 1669, 1670, 1671, 1672, 1673, 1674, 1675, 1676, 1677, 1678, 1679, 1680, 1681, 1682, 1683, 1684, 1685, 1686, 1687, 1688];

const BASE_URL = __ENV.BASE_URL || 'https://craft-plugin-dev.ddev.site';

// ─── k6 Options ───────────────────────────────────────────────────────────────
export const options = {
    stages: [
        { duration: '30s', target: 20 },   // Warm up
        { duration: '1m', target: 50 },    // Ramp to 50
        { duration: '1m', target: 100 },   // Ramp to 100
        { duration: '1m', target: 100 },   // Hold at 100
        { duration: '30s', target: 0 },    // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<500'],    // 95th percentile < 500ms
        http_req_failed: ['rate<0.01'],      // Error rate < 1%
    },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Generate a random date within +1 to +90 days from now
 */
function randomFutureDate() {
    const now = new Date();
    const offset = Math.floor(Math.random() * 90) + 1;
    now.setDate(now.getDate() + offset);
    return now.toISOString().slice(0, 10); // YYYY-MM-DD
}

// ─── Test ─────────────────────────────────────────────────────────────────────

export default function () {
    const date = randomFutureDate();
    const serviceId = randomItem(SERVICE_IDS);
    // 50% of requests include employeeId, 50% let the system pick
    const includeEmployee = Math.random() > 0.5;

    const payload = {
        date: date,
        serviceId: String(serviceId),
    };

    if (includeEmployee) {
        payload.employeeId = String(randomItem(EMPLOYEE_IDS));
    }

    const params = {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json',
        },
    };

    const res = http.post(
        `${BASE_URL}/actions/slots/slot/get-available-slots`,
        payload,
        params,
    );

    check(res, {
        'status is 200': (r) => r.status === 200,
        'response is JSON': (r) => {
            try {
                JSON.parse(r.body);
                return true;
            } catch {
                return false;
            }
        },
    });

    sleep(Math.random() * 0.5 + 0.1); // 100-600ms think time
}
