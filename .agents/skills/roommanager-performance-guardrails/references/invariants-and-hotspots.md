# RoomManager Invariants And Hotspots

Read this file before optimizing logic that affects room states or reservation flow.

## Operational Day

- The operational day is not the same as calendar midnight.
- Respect `HOTEL_OPERATIONAL_DAY_START`.
- Before the operational-day cutoff, "today" still belongs to the previous operational date.
- Daily observation and maintenance overrides are tied to the operational date, not the wall-clock date.

## Business Invariants

1. Real occupancy comes from `stays`, not from raw reservations.
   `active` and `pending_checkout` stays block occupancy-related actions.

2. Pending check-in comes from the room assignment.
   A room can show pending check-in only when a `reservation_room` overlaps the operational date and there is no stay already started in that same room for that reservation.

3. Releasing or cancelling the pending-checkout room closes that flow.
   After release or cancel checkout, the same reservation must not reappear as pending check-in for that room.

4. There are two different room-change flows.
   Pending reservation room change:
   origin becomes free and clean, destination stays reserved and pending check-in.

   Active-stay room change:
   origin becomes free and pending cleaning, destination becomes occupied immediately with the moved stay data.

5. Maintenance is an operational override, not a permanent room attribute.
   It can only be applied when the room is `free_clean` and base cleaning is `limpia`.
   It blocks the selected operational day and the next one.
   If it is re-applied on the next day, the block extends one more day.
   If marked `limpia`, the carried maintenance block must clear as well.
   A room in maintenance cannot be used for reservation, rent, check-in, or room-change destination.

6. Daily observation is per room, per operational day.

## Hotspots

- `app/Livewire/RoomManager.php`
  Main render path, pagination, room enrichment, pending check-in detection, maintenance validation, room changes, cleaning writes.

- `app/Models/Room.php`
  Status helpers are convenient but dangerous if they execute extra queries per room.

- `app/Services/RoomAvailabilityService.php`
  Overlap rules for stays, pending checkout, maintenance conflicts, and reservation conflicts.

- `resources/views/components/room-manager/*.blade.php`
  Blade loops must stay passive. If a view triggers more queries by calling helpers repeatedly, move that work into the component first.

## Safe Optimization Patterns

- Preload all date-scoped data for the paginated room ids in one pass.
- Build maps keyed by room id instead of calling per-room query helpers repeatedly.
- Prefer eager loading with narrow columns to lazy loading in loops.
- Keep write flows transactional and read flows side-effect free.
- Refactor duplicated overlap logic into one source of truth, then make the render path consume that result.

## Search Patterns

- `getOperationalStatus`
- `cleaningStatus`
- `isInMaintenance`
- `dailyObservation`
- `getPendingCheckInReservationForRoom`
- `submitChangeRoom`
- `updateCleaningStatus`
- `getStayForDate`
- `findConflictingReservationRoom`
