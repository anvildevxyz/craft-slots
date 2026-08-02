# Upstream sync

Slots is a fork of [Booked](https://github.com/anvildevxyz/craft-booked) with **full history
preserved**, so `git cherry-pick` works natively against upstream commits.

```
git fetch upstream
git log --oneline upstream/main ^main -- src/services/AvailabilityService.php
git cherry-pick <sha>
```

## The sync zone

The availability engine is the part of Booked worth *not* diverging from. It is mature, it is where the
hard bugs live, and upstream keeps fixing it — recent examples include capacity holes and cross-service
group-booking leakage. Roughly 3,000 lines:

| File | Lines |
|---|---|
| `src/services/AvailabilityService.php` | 1365 |
| `src/services/CapacityService.php` | 380 |
| `src/services/SoftLockService.php` | 321 |
| `src/services/ScheduleAssignmentService.php` | 294 |
| `src/services/ScheduleResolverService.php` | 282 |
| `src/services/SlotGeneratorService.php` | 150 |
| `src/services/TimeWindowService.php` | 100 |
| `src/services/TimezoneService.php` | 78 |

## Rules

The zone cannot be kept byte-identical — multi-day stays and event bookings are implemented *inside*
these files and must come out. So the discipline is **function-level, not file-level**:

1. When removing a dropped feature from a zone file, **delete branches surgically**. Do not restructure,
   rename, reorder, or reformat surrounding code.
2. **No opportunistic cleanup** in these files. Ever. A tidied signature costs a future cherry-pick.
3. Every altered function gets a row in the divergence log below, with the reason.
4. New Slots behaviour belongs in a *new* service, not in a zone file.

The payoff is direct: a clean cherry-pick takes a minute, a manual port of an availability fix takes an
afternoon and risks reintroducing a bug upstream already fixed.

## Divergence log

### Uniform rebrand delta (all files)

The fork was rebranded twice: first to Booked Lite (P1), then to **Slots** once that name was rejected.
Both passes were purely mechanical, and the net effect against upstream is a single rename:

- `anvildev\booked` → `anvildev\slots` (namespace + `use` lines)
- `Booked::` → `Slots::`
- `booked_` → `slots_` (table and cache-key prefix)
- `Craft::t('booked'` / `Yii::t('booked'` → `'slots'`
- `data-booked-*` → `data-slots-*` and the `booked-` CSS prefix → `slots-` (front-end contract; renamed
  in the second pass, while there were still no users to break)

No logic, control flow, signatures, or formatting changed. In the four sync-zone files this touched,
the whole delta is 44 lines:

| File | Lines changed |
|---|---|
| `AvailabilityService.php` | 40 (20 `use`/call sites) |
| `CapacityService.php` | 32 |
| `SlotGeneratorService.php` | 8 |
| `SoftLockService.php` | 8 |

Practical effect on cherry-picks: a conflict is only possible when an upstream commit **adds or removes
a `use` statement**, or touches one of the handful of `Slots::getInstance()` call sites. Changes
confined to function bodies apply cleanly. Resolve by keeping the `slots` spelling.

### Per-function divergences

| File | Function | Change | Reason |
|---|---|---|---|
| `ScheduleResolverService.php` | `hasAnyScheduleCoverage()` (docblock only) | Comment reworded from "Used to determine if waitlist should be offered" to "…whether any schedule covers the slot". | Waitlist is gone. **Comment only — no code changed**, so cherry-picks are unaffected. |

### Deliberate non-divergences

Cases where a feature was removed but the sync zone was **left alone on purpose**:

| Zone API | Decision | Why |
|---|---|---|
| `AvailabilityService::getAvailableSlots()` & friends — `int $extrasDuration = 0` | **Kept the parameter.** Service extras are gone, so nothing ever passes a non-zero value; callers simply stopped supplying it. | Removing it would have rewritten 12 call sites across the 1,365-line `AvailabilityService`, permanently breaking cherry-picks on the file that changes most upstream. An inert defaulted parameter is a far cheaper price than that. |
| `SoftLockService` — `isDateRangeLocked()`, the `endDate` lock column and the range branch in `createLock()` | **Kept as-is.** Multi-day stays are gone, so no caller supplies an `endDate` and the range branches never execute. | Same trade: the behaviour is unreachable, and rewriting it would diverge a zone file for no functional gain. The `slots_soft_locks.endDate` column stays nullable and unused. |

If upstream ever removes `$extrasDuration` itself, take their version — this fork has no opinion on it.
