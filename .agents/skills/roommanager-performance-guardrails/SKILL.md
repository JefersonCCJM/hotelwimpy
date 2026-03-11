---
name: roommanager-performance-guardrails
description: Optimize or refactor RoomManager, room statuses, reservations, stays, maintenance, and Livewire room-grid performance without breaking hotel business rules. Use when the user asks to refactorizar RoomManager, mejorar rendimiento, velocidad, escalabilidad, queries, estados de habitaciones, check-in, checkout, mantenimiento, observaciones diarias, or room changes safely.
---

# RoomManager Performance Guardrails

## Overview

Use this skill when changing `RoomManager` or related room-state code for speed, scalability, or clarity, but the business rules must remain stable.

Read [references/invariants-and-hotspots.md](references/invariants-and-hotspots.md) before editing if the task touches room states, operational day logic, check-in, checkout, maintenance, daily observations, or room changes.

## Workflow

1. Freeze the business rules first.
   Confirm which invariant must stay unchanged before touching queries, eager loading, caching, or status derivation.

2. Build a small baseline.
   Measure the target path first: page render, filter change, cleaning status update, room change, check-in, checkout, or reservation badge rendering.

3. Separate read-path from write-path.
   Optimize display work independently from mutation workflows. Do not mix render refactors with business-rule rewrites unless the task explicitly needs both.

4. Optimize the hot path.
   Prefer one query per dataset plus in-memory maps keyed by `room_id`, `reservation_id`, or `reservation_id-room_id`.
   Keep Blade passive. Avoid calling status methods that hit the database from loops when the same data can be preloaded once.
   Move overlap and status rules into a single service or helper when repeated across component, model, and view.

5. Protect mutations.
   For room changes, releases, check-in, checkout, cancellation, and maintenance writes, keep the operation transactional and idempotent where possible.

6. Verify before closing.
   Run focused tests for the touched flow and confirm the optimized path still respects the invariants.

## Optimization Rules

- Do not change hotel rules just to make the code faster.
- Compute the selected operational date once per request path and reuse it.
- Prefer preloading date-scoped room data for the visible page over per-room queries in model helpers.
- Avoid hidden N+1s from `getOperationalStatus()`, `cleaningStatus()`, `isInMaintenance()`, `dailyObservation()`, and pending check-in lookups.
- Prefer `reservation_rooms` for room-specific reservation assignments and `stays` for real occupancy.
- Do not add broad caching until invalidation rules are explicit for reservations, stays, maintenance, quick reservations, and cleaning changes.
- If an optimization changes a query shape, add or update a focused regression test for the affected rule.

## RoomManager Focus Areas

- Render path: `render()`, paginated room enrichment, filter changes, badges, action menus.
- State derivation: `Room`, `RoomAvailabilityService`, operational status, cleaning status, pending check-in resolution.
- Mutation path: `submitChangeRoom()`, `updateCleaningStatus()`, release/cancel checkout, check-in and checkout actions.

## Verification

- Search hotspots with:
  `rg "render\\(|getOperationalStatus|cleaningStatus|isInMaintenance|dailyObservation|getPendingCheckInReservationForRoom|submitChangeRoom|updateCleaningStatus" app/Livewire/RoomManager.php app/Models/Room.php app/Services/RoomAvailabilityService.php tests/Feature/Livewire`
- Run focused tests:
  `php artisan test tests/Feature/Livewire/RoomManagerRoomChangeTest.php tests/Feature/Livewire/RoomManagerOperationalStatusTest.php`
- If the task changes another flow, add the narrowest test that proves the invariant still holds.
