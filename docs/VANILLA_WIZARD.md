# Slots Vanilla Wizard — Developer Guide

The booking wizard is a **framework-free** component: a headless core (state
machine + API client, zero runtime dependencies) plus a vanilla renderer. This
guide covers the three ways to use it — drop-in Twig, template customization,
and fully headless — plus the public JS API and the `data-slots-*` contract.

> Status: 1.3.0. `{% include 'slots/frontend/wizard' %}` renders the vanilla
> wizard.

---

## 1. Drop-in (no code)

```twig
{% include 'slots/frontend/wizard' %}
```

Optional include params: `serviceId` (preselect), `labels` (override any label).
The include registers the asset bundle, renders the markup, and emits a JSON
config block the bundle auto-initializes from — **no inline script**, so it runs
under a strict `script-src 'self' 'nonce-…'` with no `unsafe-eval`.

Theme with CSS custom properties (no framework, no build):

```css
.slots-wizard {
  --slots-primary: #0b5cff;
  --slots-radius: 6px;
  --slots-accent: #0b5cff;
}
```

---

## 2. Customize the markup (Twig, no build step)

Copy the template into your project's `templates/` and edit the markup. Behavior
is driven entirely by `data-slots-*` attributes — the stable contract between
your HTML and the bundle. Keep those; change everything else (classes, layout,
copy). Card lists are `<template>` elements cloned per item.

Key hooks:

| Attribute | Purpose |
|---|---|
| `[data-slots-wizard][data-slots-auto]` | Root; auto-initialized on load |
| `<script type="application/json" data-slots-config>` | Config (CSRF, site, labels, flow) |
| `[data-slots-step="service\|location\|employee\|datetime\|info\|review\|success"]` | Step regions (one shown at a time) |
| `[data-slots-step-heading]` | Focus target on step change |
| `[data-slots-template="service-card\|location-card\|employee-card"]` | `<template>` cloned per item (inside its step) |
| `[data-slots-list="services\|locations\|employees"]` | Card container |
| `[data-slots-action="next\|back\|submit\|select-service\|select-location\|select-employee\|select-slot"]` | Delegated actions |
| `[data-slots-field="…"]` | Card/summary field slots and info inputs |
| `[data-slots-calendar]` / `[data-slots-slots]` | Calendar mount / slot listbox |
| `[data-slots-progress]`, `[data-slots-live]`, `[data-slots-error]`, `[data-slots-loading]` | Chrome |
| `[data-slots-honeypot]`, `[data-slots-captcha-token]` | Anti-spam fields sent with submit |

---

## 3. Headless / bring-your-own-frontend

Drive the core directly — no renderer, no DOM. The core is published as an
ESM/UMD build (`dist/slots-wizard-core.*`).

```js
import { create } from '@anvildev/slots-wizard/core';

const wizard = create({
  // omit `mount` for headless
  flow: 'booking',                 // 'booking' | 'manage'
  serviceId: 12,                   // optional preselect
  locale: 'de',
  api: { baseUrl: '/slots/api/v1', csrf: { name, value }, site: 'default' },
  config: { requirePhone: false },
  labels: { /* same keys as Twig */ },
});

wizard.on('state:change', ({ from, to, stepId }) => { /* … */ });
wizard.on('booking:confirmed', ({ reservation }) => { /* … */ });

await wizard.start();
await wizard.selectService(12);
wizard.goNext();
await wizard.selectSlot({ date: '2026-08-01', time: '10:00' }); // acquires the hold
wizard.goNext();
wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
wizard.goNext();
const result = await wizard.submit();  // { confirmed } | { paying, redirectUrl } | { expired } | { error }
```

### Public methods (semver'd)

`start()`, `getState()`, `goNext()` / `goBack()`, `selectService(id)`,
`selectLocation(id)` / `selectEmployee(id)`,
`selectSlot({date,time,quantity})`,
`setCustomer({name,email,phone,notes})`, `submit({fields})`,
`releaseLock()`, `reset()`, `destroy()`.

Availability loaders (for custom calendars): `loadCalendar({year,month})`,
`loadSlots({date})`.


### Events

`state:change`, `step:change`, `data:loaded`, `service:selected`,
`slot:selected`, `lock:acquired`, `lock:expiring`, `lock:extended`,
`lock:expired`, `lock:released`, `payment:redirect`, `booking:confirmed`,
`payment:required`, `deeplink:loaded`, `manage:loaded`, `announce`, `error`.

Expected domain failures (validation, taken slot, expired lock) surface as
states/events — the promise methods don't throw for them. `announce` events
carry i18n'd strings for an `aria-live` region.

---

## 4. Lifecycle states

```
idle → loading → browsing ⇄ holdingLock → submitting → paying → confirmed
                     │            │            │           │
                     └──────── error ◄─────────┴──── expired ◄──┘
```

`holdingLock` means a soft-lock is held with a live countdown; the core
auto-extends it once when the user commits, then expires cleanly.

---

## 5. Migrating from the legacy wizard

- The include path and documented config variables are unchanged — existing
  drop-in usage keeps working, now rendering the vanilla wizard.
- Forked the old legacy wizard? The structure maps to **Twig template overrides
  + JS events**: markup you edited in its directive attributes becomes `data-slots-*`
  markup; logic you patched becomes an event listener on the core. There is no
  framework to fork — behavior lives in the core, markup in your Twig.
- The REST endpoints the wizard calls are now the versioned, documented
  `/slots/api/v1/…` surface; pin to it for custom frontends.
