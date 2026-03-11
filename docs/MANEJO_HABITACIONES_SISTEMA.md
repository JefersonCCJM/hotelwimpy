# Manejo de Habitaciones: Dependencias y Flujo Completo

## 1) Alcance

Este documento consolida como funciona actualmente el manejo de habitaciones en el proyecto:

- De que depende el estado de una habitacion.
- Que modelos/tablas participan.
- Que servicios/controladores/livewire mandan en cada etapa.
- Cual es el flujo operativo real (crear reserva, check-in, cobro, liberar, continuar estancia, etc).
- Que partes son legacy o de riesgo.

## 2) Resumen ejecutivo (que manda hoy)

Para operacion diaria, el nucleo real del sistema es:

1. `app/Livewire/RoomManager.php` (pantalla operativa principal).
2. `app/Services/RoomAvailabilityService.php` (SSOT de disponibilidad/ocupacion por fecha).
3. `app/Models/Room.php` (estado operacional + limpieza).
4. `app/Models/Stay.php` y `app/Models/StayNight.php` (ocupacion real y noches cobrables).
5. `app/Models/ReservationRoom.php` (contrato de fechas por habitacion dentro de cada reserva).

Para creacion/edicion de reservas, el flujo recomendado hoy es:

1. `app/Livewire/Reservations/ReservationCreate.php`
2. `app/Livewire/Reservations/ReservationEdit.php`
3. `app/Services/ReservationService.php`

## 3) Fuentes de verdad (SSOT) por dominio

### 3.1 Ocupacion real de habitacion

- SSOT: `Stay` + `RoomAvailabilityService::getStayForDate()`.
- Archivo clave: `app/Services/RoomAvailabilityService.php`.
- Regla base: una stay ocupa fecha `D` si intersecta el dia operacional.

### 3.2 Fechas contratadas / bloqueo futuro

- SSOT: `reservation_rooms` (`check_in_date`, `check_out_date`, `nights`, `price_per_night`, `subtotal`).
- Modelo: `app/Models/ReservationRoom.php`.

### 3.3 Estado operacional de habitacion

- SSOT: `Room::getOperationalStatus($date)` en `app/Models/Room.php`.
- Estados operacionales: `occupied`, `pending_checkout`, `pending_cleaning`, `free_clean`.
- Diferencia pasado vs hoy/futuro: usa logica historica inmutable para fechas pasadas y logica reactiva para presente/futuro.

### 3.4 Estado visual (display) de habitacion

- SSOT: `RoomAvailabilityService::getDisplayStatusOn($date)`.
- Enum: `app/Enums/RoomDisplayStatus.php`.
- Prioridad implementada:
  1. mantenimiento
  2. ocupada
  3. pendiente_checkout
  4. sucia
  5. reservada
  6. libre

### 3.5 Limpieza

- SSOT: `Room::cleaningStatus($date)` + `rooms.last_cleaned_at`.
- Archivo: `app/Models/Room.php`.
- `last_cleaned_at` se usa para decidir si queda pendiente de aseo.

### 3.6 Tiempo operativo

- SSOT temporal: `app/Support/HotelTime.php` + `config/hotel.php`.
- Dependencias:
  - `check_in_time`
  - `check_out_time`
  - `operational_day_start_time`
  - `cleaning_buffer_minutes`
  - timezone hotel

### 3.7 Cobro de alojamiento por noches

- SSOT financiero-operativo: `stay_nights`.
- Modelo: `app/Models/StayNight.php`.
- Metodos de sincronizacion clave:
  - `ReservationController::ensureStayNightsCoverageForReservation(...)`
  - `ReservationController::rebuildStayNightPaidStateFromPayments(...)`
  - `ReservationController::syncReservationFinancials(...)`

### 3.8 Auditoria de liberacion

- SSOT historico de egresos: `room_release_history`.
- Modelo: `app/Models/RoomReleaseHistory.php`.
- En `RoomManager::releaseRoom(...)` se persiste snapshot final de cuenta/cliente/huespedes/consumos.

## 4) Mapa de componentes (codigo)

### 4.1 UI / Livewire

- `app/Livewire/RoomManager.php`
  - Operacion diaria de habitaciones.
  - Liberacion, continuar estancia, reserva rapida, historial de liberaciones.
- `app/Livewire/Reservations/ReservationCreate.php`
  - Crear reservas con validacion de disponibilidad/capacidad.
- `app/Livewire/Reservations/ReservationEdit.php`
  - Editar reservas; si ya existe estancia operativa, bloquea cambios de fechas/habitaciones.
- `app/Livewire/CreateRoom.php`
  - Alta de habitaciones y tarifas por ocupacion.

### 4.2 Servicios

- `app/Services/RoomAvailabilityService.php`
  - Disponibilidad por fecha/rango y conflictos.
- `app/Services/ReservationService.php`
  - Creacion/actualizacion de reserva + `reservation_rooms` + abono inicial.
- `app/Services/RoomDailyStatusService.php`
  - Servicio legacy para `room_daily_statuses_data` (ver riesgos).

### 4.3 Controladores

- `app/Http/Controllers/ReservationController.php`
  - Check-in, pagos, anulaciones, sincronizacion financiera/noches.
- `app/Http/Controllers/RoomController.php`
  - CRUD de habitaciones + endpoints legacy de estado/liberacion/continuar.

### 4.4 Modelos principales

- Habitaciones: `Room`, `RoomRate`, `RoomMaintenanceBlock`, `RoomQuickReservation`, `RoomReleaseHistory`.
- Reservas: `Reservation`, `ReservationRoom`.
- Operacion real: `Stay`, `StayNight`.
- Pagos: `Payment`.

## 5) De que depende el estado de una habitacion

Para una fecha dada, el estado final depende de:

1. Si hay mantenimiento activo (`room_maintenance_blocks` + status `active`).
2. Si hay una `stay` que intersecta la fecha.
3. Si es dia de checkout pendiente (checkout programado hoy sin checkout ejecutado).
4. Si requiere limpieza (`last_cleaned_at` vs checkout real).
5. Si tiene reserva futura (`reservation_rooms`).
6. Fecha operativa y reglas de `HotelTime`.

En otras palabras:

- Ocupacion = `stays` (no `rooms.status`).
- Reserva futura = `reservation_rooms`.
- Aseo = `last_cleaned_at`.
- Visual final = prioridad en `RoomAvailabilityService`.

## 6) Reglas de disponibilidad (reservar una habitacion)

`RoomAvailabilityService::isRoomAvailableForDates(...)` valida:

1. Conflicto con stays (`findConflictingStay`).
2. Conflicto con `reservation_rooms` sin stay asociada (`findConflictingReservationRoom`).

Notas importantes:

- El rango usa comparaciones de interseccion de fechas.
- Se puede excluir una reserva (`excludeReservationId`) en edicion.
- En `findConflictingStay`, cuando `check_out_at` es `NULL`, hay regla temporal tratandola como 1 noche para conflicto. Esto debe conocerse porque impacta casos borde.

## 7) Flujo operativo end-to-end

### 7.1 Creacion de habitacion

- Componente: `CreateRoom`.
- Crea:
  - `rooms` con `last_cleaned_at = now()` (nueva = limpia).
  - `room_rates` por rango de ocupacion (min/max guests).
- Solo administrador.

### 7.2 Creacion de reserva (antes de llegada)

- UI: `ReservationCreate`.
- Servicio: `ReservationService::createReservation`.
- Crea:
  - `reservations` (codigo, totales, estados de pago, etc).
  - `reservation_rooms` (fechas, noches, precio/noche, subtotal por habitacion).
  - `payments` (si hay abono inicial).
- Valida disponibilidad por `RoomAvailabilityService`.

### 7.3 Edicion de reserva

- UI: `ReservationEdit`.
- Si hay estancia operativa (`hasOperationalStay = true`):
  - No permite cambiar habitaciones/fechas.
  - Solo ajusta datos financieros/comerciales permitidos.
- Servicio: `ReservationService::updateReservation(...)`.

### 7.4 Check-in (llegada real)

- Endpoint: `ReservationController::checkIn`.
- Efectos:
  - Crea `stays` activas por cada habitacion de la reserva.
  - Genera cobertura de `stay_nights`.
  - Sincroniza noches pagadas con pagos ya existentes.
  - Recalcula financieros (`deposit_amount`, `balance_due`, `payment_status_id`).

### 7.5 Pagos y anulaciones

- `registerPayment`: registra pago y lo asigna a noches en orden; resync financiero.
- `cancelPayment`: crea movimiento reverso negativo y reconstruye estado de noches pagadas.
- SSOT de balance: pagos netos vs total de alojamiento (preferencia por `stay_nights`/`reservation_rooms` antes que legacy).

### 7.6 Continuar estancia

- Operativo en `RoomManager::continueStay`.
- Reglas:
  - Solo dia operativo actual.
  - Solo si estado operacional es `pending_checkout`.
  - Extiende `reservation_rooms.check_out_date` +1 dia.
  - No cierra la stay; la mantiene activa.
  - Genera noche cobrable adicional.
  - Marca `last_cleaned_at = null` (pendiente aseo).

### 7.7 Liberar habitacion (checkout)

- Operativo en `RoomManager::releaseRoom`.
- Reglas:
  - Solo hoy operativo.
  - No fechas historicas.
  - Exige balance en cero para liberar.
- Efectos:
  - Cierra stay activa (`check_out_at`).
  - Sincroniza noches/pagos.
  - Persiste `room_release_history` con snapshot final.
  - Ajusta limpieza segun estado objetivo (`libre` / `pendiente_aseo`).

### 7.8 Reserva rapida (RoomManager)

- `submitQuickReservation` crea reserva real via `ReservationService`.
- Adicionalmente crea marcador visual diario en `room_quick_reservations`.
- `cancelQuickReserve` elimina marcador y puede cancelar reserva asociada del dia.

## 8) Rutas HTTP relacionadas

En `routes/web.php`:

- Habitaciones:
  - `Route::resource('rooms', RoomController::class)`
  - endpoints de `rooms.release`, `rooms.continue`, `rooms.update-status`, `rooms.rates.*`
- Reservas:
  - `reservations.index/create/store/edit/update/destroy`
  - `reservations.check-in`
  - `reservations.register-payment`
  - `reservations.cancel-payment`
  - `reservations.release-data`

## 9) Tablas clave (resumen)

1. `rooms`
2. `room_rates`
3. `room_maintenance_blocks`
4. `reservations` (soft deletes)
5. `reservation_rooms`
6. `stays`
7. `stay_nights`
8. `payments` + `payments_methods`
9. `room_release_history`
10. `room_quick_reservations`

## 10) Estados y enums relevantes

- Display: `RoomDisplayStatus`:
  - `libre`, `reservada`, `ocupada`, `mantenimiento`, `sucia`, `pendiente_checkout`.
- Legacy/status general: `RoomStatus`:
  - incluye `limpieza`, `pendiente_aseo` y mapeos legacy via `RoomStatusCast`.

## 11) Dependencias de permisos y rol

Ejemplos observados en codigo:

- Crear habitacion: solo `Administrador` (`CreateRoom` / `RoomController`).
- Reservas: middleware de permisos `view/create/edit/delete_reservations`.
- Operaciones diarias en RoomManager: validaciones por rol/permisos para reservar rapido, cancelar, etc.

## 12) Riesgos / deuda tecnica detectada (importante)

1. `RoomController` conserva logica legacy mezclada con arquitectura nueva (stays + stay_nights).
   - Tiene operaciones de `release/continue` basadas en fechas de reserva historica y/o campo `status`.
   - Puede no reflejar completamente la logica operativa actual de `RoomManager`.

2. `RoomDailyStatusService` parece desacoplado de esquema vigente.
   - Usa campos tipo `check_in_at/check_out_at` en `Reservation`/`ReservationRoom` que no son el camino principal actual.
   - Debe tratarse como legacy hasta validar uso real.

3. `Reservation` model conserva metodos/scopes legacy por `check_in_date/check_out_date` en tabla principal.
   - Hoy la granularidad por habitacion vive en `reservation_rooms`.

4. Coexistencia de flujo nuevo y viejo:
   - Nuevo recomendado: Livewire (`ReservationCreate/Edit`, `RoomManager`) + servicios.
   - Viejo: ciertos endpoints/controladores con su propia logica.

## 13) Guia practica para futuros cambios

Si se va a tocar manejo de habitaciones, priorizar este orden:

1. `RoomAvailabilityService` (disponibilidad/estado visual).
2. `Room::getOperationalStatus` y `cleaningStatus` (estado operativo + aseo).
3. `ReservationService` (crear/editar contrato reserva-habitacion).
4. `ReservationController` para check-in/pagos/sync noches.
5. `RoomManager` para operaciones del dia (release/continue/quick reserve).

Evitar agregar nueva logica de negocio en `RoomController` sin alinear antes con el flujo principal.

## 14) Archivos de referencia rapida

- `app/Livewire/RoomManager.php`
- `app/Services/RoomAvailabilityService.php`
- `app/Models/Room.php`
- `app/Models/Stay.php`
- `app/Models/StayNight.php`
- `app/Livewire/Reservations/ReservationCreate.php`
- `app/Livewire/Reservations/ReservationEdit.php`
- `app/Services/ReservationService.php`
- `app/Http/Controllers/ReservationController.php`
- `app/Http/Controllers/RoomController.php`
- `app/Support/HotelTime.php`
- `routes/web.php`
- `config/hotel.php`
