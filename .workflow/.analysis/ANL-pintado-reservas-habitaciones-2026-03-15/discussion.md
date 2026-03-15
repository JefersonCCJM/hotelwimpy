# Investigacion: pintado de reservas en habitaciones

Fecha: 2026-03-15
Tema: Como se reflejan visualmente las reservas creadas desde el modulo de reservas en las habitaciones.

## Decision Log

> **Decision**: Separar el analisis en dos superficies visuales.
> - **Context**: "Habitaciones" puede referirse tanto al `RoomManager` como al calendario del modulo de reservas.
> - **Options considered**: Revisar solo `RoomManager`; revisar solo `calendar-grid`; revisar ambos.
> - **Chosen**: Revisar ambos flujos. - **Reason**: ambos pintan reservas sobre habitaciones, pero con reglas distintas.
> - **Impact**: evita mezclar "estado operativo de habitacion" con "bloque pintado en calendario".

## Key Findings

### 1. La creacion desde el modulo de reservas escribe primero en `reservations` y luego en `reservation_rooms`

- `App\Livewire\Reservations\ReservationCreate::createReservation()` arma el payload con `check_in_date`, `check_out_date` y `room_ids`, llama a `ReservationService::createReservation()`, despacha `reservationCreated` y redirige al calendario de reservas.
- `App\Services\ReservationService::createReservation()` crea la reserva principal y luego crea un registro `reservation_rooms` por cada habitacion seleccionada.
- En la practica, el calendario y RoomManager dependen de `reservation_rooms` para saber en que habitacion cae la reserva y en que rango.

### 2. El calendario del modulo de reservas pinta bloques directamente por habitacion y por dia

- `ReservationController::index()` carga `rooms` con `reservationRooms.reservation` y `stays`.
- `resources/views/components/reservations/calendar-grid.blade.php` recorre cada habitacion y cada dia del mes.
- Si existe una `stay` que cruza ese dia, la celda se pinta como:
  - `checked_in` si hay estadia activa.
  - `pending_checkout` si es el dia de checkout y aun no se ha hecho checkout real.
  - `occupied` en snapshots/historico.
- Si no hay `stay` pero si existe `reservation_room` cuyo rango incluye el dia, la celda se pinta como `reserved`.
- Los colores salen de la misma vista:
  - `reserved` -> indigo.
  - `checked_in` -> verde.
  - `pending_checkout` -> amarillo.
  - `occupied` o walk-in -> rojo.

### 3. `RoomManager` no pinta bloques por dia; calcula un estado de habitacion y un badge de reserva pendiente

- `RoomManager::render()` obtiene habitaciones y delega la hidratacion a `RoomManagerGridHydrationService`.
- El servicio calcula:
  - `display_status`
  - `current_stay`
  - `current_reservation`
  - `future_reservation`
  - `pending_checkin_reservation`
  - `is_quick_reserved`
- En `room-row.blade.php`, si la habitacion esta libre (`free_clean`) pero tiene una reserva pendiente tipo `RES-`, se muestra visualmente como `quick_reserved` y aparece badge azul con cliente/codigo.
- Eso significa que en RoomManager una reserva futura o pendiente de check-in no cambia la fila a "ocupada"; se marca como "Reservada" solo si la habitacion sigue libre operativamente.

### 4. El refresco no depende del evento `reservationCreated` en RoomManager

- `ReservationCreate` y `ReservationEdit` despachan `reservationCreated` / `reservationUpdated`.
- `RoomManager` no escucha esos eventos.
- `RoomManager` se actualiza por render normal y por `wire:poll.visible.3s`.
- Conclusión: el modulo de reservas escribe datos; el pintado aparece cuando la superficie visual vuelve a consultar y recalcular.

## Corrected Assumptions

- Antes: podia parecer que el pintado dependia del campo `reservations.room_id`.
- Ahora: la fuente real de asignacion habitacion-fechas es `reservation_rooms`.

- Antes: podia parecer que RoomManager y el calendario usan la misma regla visual.
- Ahora: el calendario pinta bloques diarios; RoomManager pinta estado operativo + badge/flag de reserva pendiente.

## Conclusion

Hay dos mecanismos distintos:

1. Calendario de reservas:
- Usa `reservation_rooms` + `stays`.
- Pinta bloques por dia y por habitacion.

2. RoomManager:
- Usa `stays` para ocupacion real.
- Usa `reservation_rooms` para reserva futura/pendiente.
- Convierte eso en `display_status`, `is_quick_reserved` y badge visual.

Si queremos cambiar "como se pinta", primero hay que decidir en cual de los dos: calendario de reservas o RoomManager.
