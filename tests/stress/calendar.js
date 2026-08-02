/**
 * k6 Calendar Endpoint Load Test
 *
 * Tests: GET /actions/slots/slot/get-availability-calendar
 * Ramps from 20 to 100 VUs over ~4 minutes.
 * Calendar is heavier than slot lookups, so threshold is p95 < 2000ms.
 *
 * Prerequisites:
 *   1. Update SERVICE_IDS below with seeded IDs
 *
 * Usage:
 *   k6 run plugins/slots/tests/stress/calendar.js
 *   k6 run --env BASE_URL=https://my-site.ddev.site plugins/slots/tests/stress/calendar.js
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
        http_req_duration: ['p(95)<2000'],   // 95th percentile < 2000ms (calendar is heavier)
        http_req_failed: ['rate<0.01'],      // Error rate < 1%
    },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Generate a random date range (7-30 days) starting within the next 90 days
 */
function randomDateRange() {
    const now = new Date();
    const startOffset = Math.floor(Math.random() * 60) + 1; // 1-60 days out
    const rangeLength = Math.floor(Math.random() * 24) + 7;  // 7-30 day span

    const start = new Date(now);
    start.setDate(start.getDate() + startOffset);

    const end = new Date(start);
    end.setDate(end.getDate() + rangeLength);

    return {
        startDate: start.toISOString().slice(0, 10),
        endDate: end.toISOString().slice(0, 10),
    };
}

// ─── Test ─────────────────────────────────────────────────────────────────────

export default function () {
    const { startDate, endDate } = randomDateRange();
    const serviceId = randomItem(SERVICE_IDS);
    // 30% of requests include employeeId filter
    const includeEmployee = Math.random() < 0.3;

    let url = `${BASE_URL}/actions/slots/slot/get-availability-calendar`
        + `?startDate=${startDate}&endDate=${endDate}&serviceId=${serviceId}`;

    if (includeEmployee) {
        url += `&employeeId=${randomItem(EMPLOYEE_IDS)}`;
    }

    const params = {
        headers: {
            'Accept': 'application/json',
        },
    };

    const res = http.get(url, params);

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
        'has calendar data': (r) => {
            try {
                const body = JSON.parse(r.body);
                // Calendar returns an object or array with date keys
                return typeof body === 'object' && body !== null;
            } catch {
                return false;
            }
        },
    });

    sleep(Math.random() * 1.0 + 0.5); // 500-1500ms think time (heavier endpoint)
}
