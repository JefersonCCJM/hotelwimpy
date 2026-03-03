# Análisis del Flujo de Estados en el Módulo de Habitaciones

**Proyecto:** Hotel Wimpy  
**Stack:** Laravel 12 + Livewire v3  
**Fecha:** 2025-01-27  
**Objetivo:** Explicar técnicamente cómo se determina y muestra el estado de las habitaciones desde la base de datos hasta la UI.

---

## 1. MODELO Room

### 1.1 Estructura de Datos en Base de Datos

**Tabla:** `rooms`

**Campo clave:** `status` (Enum `RoomStatus`)
- Tipo: Enum PHP (`App\Enums\RoomStatus`)
- Valores posibles en BD: `'libre'`, `'reservada'`, `'ocupada'`, `'mantenimiento'`, `'limpieza'`, `'sucia'`, `'pendiente_checkout'`, `'pendiente_aseo'`
- Cast en modelo: `'status' => RoomStatus::class` (línea 29)
- **Representa:** El estado físico/operativo de la habitación almacenado en la base de datos.

**Campo adicional relevante:** `last_cleaned_at` (datetime, nullable)
- Usado para determinar el estado de limpieza (derivado, no almacenado).

### 1.2 Casts y Relaciones

```php
protected $casts = [
    'status' => RoomStatus::class,  // Convierte string DB → Enum PHP
    'last_cleaned_at' => 'datetime',
    // ... otros casts
];

public function reservations() {
    return $this->hasMany(Reservation::class);
}
```

**Observación crítica:**
- `status` es el **único estado persistido** en la base de datos.
- Las relaciones `reservations` se usan para **calcular** estados derivados, pero no se guardan como parte del estado de la habitación.

### 1.3 Accessors y Métodos Derivados

#### A) `getDisplayStatusAttribute()` (Accessor)

**Ubicación:** `app/Models/Room.php:199-202`

```php
public function getDisplayStatusAttribute(): RoomStatus
{
    return $this->getDisplayStatus();
}
```

**Función:**
- Permite usar `$room->display_status` como si fuera un atributo del modelo.
- Internamente llama a `getDisplayStatus()` con la fecha actual (`today()`).

**IMPORTANTE:** Este accessor **NO lee de la base de datos**. Es un valor **calculado dinámicamente**.

#### B) `getDisplayStatus(?\Carbon\Carbon $date = null): RoomStatus`

**Ubicación:** `app/Models/Room.php:162-190`

**Lógica de prioridad (ORDEN CRÍTICO):**

1. **MANTENIMIENTO** (prioridad máxima)
   - Si `$this->status === RoomStatus::MANTENIMIENTO` → retorna `MANTENIMIENTO`
   - Bloquea todo lo demás.

2. **OCUPADA** (derivado de reservas)
   - Si `$this->isOccupied($date)` → retorna `OCUPADA`
   - **NO depende de `status` en BD**, depende de reservas activas.

3. **PENDIENTE_ASEO** (derivado de limpieza)
   - Si NO hay reserva activa Y `cleaningStatus()['code'] === 'pendiente'` → retorna `PENDIENTE_ASEO`
   - **NO se guarda en BD**, es un estado visual derivado.

4. **SUCIA** (persistido en BD)
   - Si `$this->status === RoomStatus::SUCIA` → retorna `SUCIA`
   - Este SÍ está guardado en BD.

5. **LIBRE** (default)
   - Si ninguna de las anteriores aplica → retorna `LIBRE`
   - **Puede diferir del `status` en BD**.

**Dependencias:**
- `isOccupied($date)`: Consulta `reservations` donde `check_in_date <= $date AND check_out_date > $date`
- `cleaningStatus()`: Consulta `last_cleaned_at` (si es NULL o >= 24h → pendiente)

#### C) `cleaningStatus(): array`

**Ubicación:** `app/Models/Room.php:118-147`

**Retorna:** Array con `['code' => string, 'label' => string, 'color' => string, 'icon' => string]`

**Lógica:**
- Si `last_cleaned_at` es NULL → `'code' => 'pendiente'`
- Si `last_cleaned_at->diffInHours(now()) >= 24` → `'code' => 'pendiente'`
- Si `last_cleaned_at < 24h` → `'code' => 'limpia'`

**IMPORTANTE:** Este método **NO depende de reservas ni de `status` en BD**. Solo depende de `last_cleaned_at`.

#### D) `isOccupied(?\Carbon\Carbon $date = null): bool`

**Ubicación:** `app/Models/Room.php:72-82`

**Lógica:**
```php
return $this->reservations()
    ->where('check_in_date', '<=', $date)
    ->where('check_out_date', '>', $date)
    ->exists();
```

**IMPORTANTE:** 
- **NO lee `status` de la BD**.
- **Solo consulta la tabla `reservations`**.
- Si hay una reserva activa en la fecha, retorna `true`, independientemente del `status` en BD.

---

## 2. ESTADOS DERIVADOS

### 2.1 ¿Qué es un Estado Derivado?

Un **estado derivado** es un valor calculado en tiempo de ejecución que **NO se almacena en la base de datos**, sino que se determina combinando múltiples fuentes de datos.

### 2.2 Estados Derivados en el Sistema

#### A) `display_status` (RoomStatus Enum)

**Fuente de verdad:** Método `Room::getDisplayStatus($date)`

**Criterios de cálculo:**
1. Consulta `rooms.status` (BD) → para MANTENIMIENTO y SUCIA
2. Consulta `reservations` (BD) → para OCUPADA
3. Consulta `rooms.last_cleaned_at` (BD) → para PENDIENTE_ASEO
4. Default → LIBRE

**Dependencia de fecha:**
- **SÍ depende de la fecha** pasada como parámetro.
- Si no se pasa fecha, usa `today()` por defecto.
- El mismo `status` en BD puede mostrar estados diferentes según la fecha consultada.

**Ejemplo práctico:**
- BD: `status = 'libre'`
- Fecha consultada: `2025-01-27`
- Si hay reserva activa en esa fecha → `display_status = OCUPADA` (aunque BD diga `libre`)
- Si no hay reserva pero `last_cleaned_at` es NULL → `display_status = PENDIENTE_ASEO` (aunque BD diga `libre`)

#### B) `cleaningStatus()` (Array)

**Fuente de verdad:** Método `Room::cleaningStatus()`

**Criterios:**
- Solo depende de `last_cleaned_at`.
- **NO depende de reservas ni de `status`**.

**No es un estado persistido:** Es un array calculado, no un Enum ni un campo en BD.

### 2.3 ¿Por Qué Existen Estados Derivados?

**Razón de negocio:**
- El `status` en BD representa el **estado físico/operativo** de la habitación (libre, sucia, mantenimiento).
- Pero el **estado visual** debe reflejar la **realidad operativa**:
  - Si hay un huésped (reserva activa) → debe mostrar "Ocupada", aunque BD diga "libre".
  - Si se liberó una habitación y necesita limpieza → debe mostrar "Pendiente por Aseo", aunque BD diga "libre".

**Problema arquitectónico:**
- Hay **dos fuentes de verdad**:
  1. `rooms.status` (BD) → estado físico
  2. `display_status` (calculado) → estado visual
- El frontend usa `display_status`, **NO** `status` directamente.

---

## 3. LIVEWIRE (RoomManager)

### 3.1 Query de Habitaciones

**Ubicación:** `app/Livewire/RoomManager.php:562-581`

```php
$query = Room::query();
// ... filtros por search y status ...

$rooms = $query->with([
    'reservations' => function($q) use ($startOfMonth, $endOfMonth) {
        $q->where('check_in_date', '<=', $endOfMonth)
          ->where('check_out_date', '>=', $startOfMonth)
          ->with('customer');
    }, 
    'reservations.sales', 
    'rates'
])->orderBy('room_number')->paginate(30);
```

**Observaciones:**
- Carga habitaciones con relaciones `reservations` eager-loaded.
- Las reservas se filtran por rango de fechas del mes seleccionado.
- **NO filtra por `status` en BD** (a menos que el usuario use el filtro `$this->status`).

### 3.2 Transformación de Datos

**Ubicación:** `app/Livewire/RoomManager.php:583-639`

**Método:** `$rooms->getCollection()->transform(function($room) use ($date) { ... })`

**Proceso de transformación:**

1. **Determina si es fecha futura:**
   ```php
   $isFuture = $date->isAfter(now()->endOfDay());
   ```

2. **Busca reserva activa o que termina hoy:**
   ```php
   if (!$isFuture) {
       // Busca reserva activa (check_out_date > $date)
       $reservation = $room->reservations->first(function($res) use ($date) {
           $checkIn = Carbon::parse($res->check_in_date)->startOfDay();
           $checkOut = Carbon::parse($res->check_out_date)->startOfDay();
           return $checkIn->lte($date) && $checkOut->gt($date);
       });
       
       // Si no hay activa, busca que termina hoy (para botón continuar)
       if (!$reservation) {
           $reservation = $room->reservations->first(function($res) use ($date) {
               $checkIn = Carbon::parse($res->check_in_date)->startOfDay();
               $checkOut = Carbon::parse($res->check_out_date)->startOfDay();
               return $checkIn->lte($date) && $checkOut->eq($date);
           });
       }
   }
   ```

3. **Asigna `current_reservation` al modelo:**
   ```php
   $room->current_reservation = $reservation;
   ```

4. **Calcula deudas y pagos** (si hay reserva):
   ```php
   if ($reservation) {
       // ... cálculos de deuda ...
       $room->total_debt = $stay_debt + $sales_debt;
   }
   ```

5. **ASIGNA `display_status` (CRÍTICO):**
   ```php
   $room->display_status = $room->getDisplayStatus($date);
   ```

**IMPORTANTE:**
- En este punto, `$room->display_status` **sobrescribe** el accessor `getDisplayStatusAttribute()`.
- Se pasa la **fecha seleccionada** (`$date`), no `today()`.
- Esto permite ver estados históricos o futuros.

6. **Asigna precios activos:**
   ```php
   $room->active_prices = $room->getPricesForDate($date);
   ```

### 3.3 ¿Qué Atributos se Pasan a la Vista?

**Retorno del método `render()`:**
```php
return view('livewire.room-manager', [
    'rooms' => $rooms,  // Collection transformada con display_status asignado
    'statuses' => RoomStatus::cases(),
    'daysInMonth' => $daysInMonth,
    'currentDate' => $date
]);
```

**Atributos disponibles en Blade:**
- `$room->display_status` → RoomStatus Enum (calculado, NO de BD)
- `$room->status` → RoomStatus Enum (de BD, pero NO se usa directamente en la UI)
- `$room->current_reservation` → Reservation|null (asignado en transform)
- `$room->total_debt` → float (calculado en transform)
- `$room->active_prices` → array (calculado en transform)

---

## 4. BLADE (room-manager.blade.php)

### 4.1 Uso de `display_status` en la Vista

#### A) Visualización del Estado (Líneas 144-147)

```blade
<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $room->display_status->color() }}">
    <span class="w-1.5 h-1.5 rounded-full mr-2" style="background-color: currentColor"></span>
    {{ $room->display_status->label() }}
</span>
```

**Observación:**
- Usa `$room->display_status` (Enum) → llama a métodos `color()` y `label()`.
- **NO usa `$room->status`** (el valor de BD).

#### B) Condición para Botón "Arrendar" (Línea 205)

```blade
@if($room->display_status === \App\Enums\RoomStatus::LIBRE && (!Carbon\Carbon::parse($date)->isPast() || Carbon\Carbon::parse($date)->isToday()))
    <button wire:click="openQuickRent({{ $room->id }})">...</button>
@endif
```

**Observación:**
- Compara `$room->display_status` con `RoomStatus::LIBRE` (Enum).
- **NO compara con `$room->status`**.

#### C) Condición para Botón "Liberar" (Línea 221)

```blade
@if($room->display_status !== \App\Enums\RoomStatus::LIBRE)
    <button @click="confirmRelease(...)">...</button>
@endif
```

**Observación:**
- Muestra el botón si `display_status !== LIBRE`.
- **NO verifica `$room->status`**.

### 4.2 Uso de `cleaningStatus()` en la Vista

**Ubicación:** Líneas 151-156

```blade
@php($cleaning = $room->cleaningStatus())
<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $cleaning['color'] }}">
    <i class="fas {{ $cleaning['icon'] }} mr-1.5"></i>
    {{ $cleaning['label'] }}
</span>
```

**Observación:**
- Muestra el estado de limpieza como una **columna separada** del estado operativo.
- Usa el array retornado por `cleaningStatus()`, no un Enum.

### 4.3 ¿Por Qué el Frontend No Refleja Directamente la BD?

**Respuesta técnica:**

1. **El frontend usa `display_status` (calculado), NO `status` (BD):**
   - `display_status` se calcula en `RoomManager::render()` llamando a `$room->getDisplayStatus($date)`.
   - Este método combina `status` (BD) + `reservations` (BD) + `last_cleaned_at` (BD).
   - El resultado puede ser diferente al `status` almacenado en BD.

2. **El cálculo depende de la fecha seleccionada:**
   - Si el usuario selecciona una fecha diferente a `today()`, el `display_status` puede cambiar.
   - El `status` en BD no cambia, pero el `display_status` sí.

3. **Ejemplo concreto:**
   - BD: `rooms.status = 'libre'`
   - BD: Existe `reservation` con `check_in_date = '2025-01-27'` y `check_out_date = '2025-01-30'`
   - Usuario selecciona fecha: `2025-01-28`
   - `getDisplayStatus('2025-01-28')` → retorna `OCUPADA` (porque hay reserva activa)
   - UI muestra: "Ocupada" (aunque BD diga `'libre'`)

---

## 5. FLUJO COMPLETO (BD → Modelo → Livewire → Vista)

### 5.1 Diagrama de Flujo

```
┌─────────────────────────────────────────────────────────────────┐
│ BASE DE DATOS                                                    │
├─────────────────────────────────────────────────────────────────┤
│ rooms.status = 'libre' (Enum string)                             │
│ rooms.last_cleaned_at = '2025-01-25 10:00:00'                   │
│ reservations (tabla relacionada)                                 │
│   - check_in_date = '2025-01-27'                                │
│   - check_out_date = '2025-01-30'                               │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ MODELO Room                                                      │
├─────────────────────────────────────────────────────────────────┤
│ 1. Eloquent carga:                                              │
│    $room = Room::with('reservations')->find(1)                  │
│                                                                  │
│ 2. Cast automático:                                             │
│    $room->status → RoomStatus::LIBRE (Enum)                     │
│                                                                  │
│ 3. Accessor (si se accede a display_status sin asignar):        │
│    $room->display_status → getDisplayStatusAttribute()          │
│    → getDisplayStatus(today())                                  │
│    → RoomStatus::OCUPADA (calculado)                            │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ LIVEWIRE RoomManager::render()                                  │
├─────────────────────────────────────────────────────────────────┤
│ 1. Query con eager loading:                                     │
│    $rooms = Room::with('reservations')->paginate(30)            │
│                                                                  │
│ 2. Transformación (línea 583):                                  │
│    $rooms->transform(function($room) use ($date) {              │
│        // Busca reserva activa en $date                         │
│        $reservation = ...                                       │
│        $room->current_reservation = $reservation;               │
│                                                                  │
│        // CALCULA display_status con fecha seleccionada         │
│        $room->display_status = $room->getDisplayStatus($date); │
│        // ↑ Esto SOBRESCRIBE el accessor                        │
│        // ↑ Retorna RoomStatus::OCUPADA (si hay reserva)        │
│                                                                  │
│        return $room;                                            │
│    })                                                           │
│                                                                  │
│ 3. Retorna a vista:                                             │
│    return view('livewire.room-manager', ['rooms' => $rooms])    │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ BLADE room-manager.blade.php                                    │
├─────────────────────────────────────────────────────────────────┤
│ 1. Muestra estado (línea 144):                                  │
│    {{ $room->display_status->label() }}                         │
│    → "Ocupada" (NO "Libre" de BD)                              │
│                                                                  │
│ 2. Condiciones (líneas 205, 221):                               │
│    @if($room->display_status === RoomStatus::LIBRE)             │
│    @if($room->display_status !== RoomStatus::LIBRE)              │
│    → Usa display_status (calculado), NO status (BD)            │
└─────────────────────────────────────────────────────────────────┘
```

### 5.2 Fuentes de Verdad

#### Fuente de Verdad #1: `rooms.status` (BD)
- **Qué representa:** Estado físico/operativo persistido.
- **Cuándo se actualiza:** Manualmente o por lógica de negocio (ej: al liberar habitación).
- **Dónde se usa:** En `getDisplayStatus()` para MANTENIMIENTO y SUCIA.

#### Fuente de Verdad #2: `reservations` (BD)
- **Qué representa:** Reservas activas que determinan ocupación.
- **Cuándo se actualiza:** Al crear/modificar/eliminar reservas.
- **Dónde se usa:** En `isOccupied()` y `getDisplayStatus()` para OCUPADA.

#### Fuente de Verdad #3: `rooms.last_cleaned_at` (BD)
- **Qué representa:** Última vez que se limpió la habitación.
- **Cuándo se actualiza:** Desde el Panel de Aseo al marcar como limpia.
- **Dónde se usa:** En `cleaningStatus()` y `getDisplayStatus()` para PENDIENTE_ASEO.

#### Fuente de Verdad #4: `display_status` (CALCULADO)
- **Qué representa:** Estado visual que combina las 3 fuentes anteriores.
- **Cuándo se calcula:** En `RoomManager::render()` durante la transformación.
- **Dónde se usa:** En la vista Blade para mostrar el estado y controlar botones.

### 5.3 ¿Dónde se Pierde la Sincronización?

**Punto crítico de desincronización:**

1. **En `RoomManager::render()` (línea 636):**
   ```php
   $room->display_status = $room->getDisplayStatus($date);
   ```
   - Este valor se calcula **cada vez que se renderiza**.
   - Si la BD cambia pero Livewire no se refresca, el `display_status` puede estar desactualizado.

2. **Dependencia de fecha:**
   - Si el usuario cambia la fecha (`$this->date`), el `display_status` cambia.
   - El `status` en BD no cambia, pero el `display_status` sí.

3. **Cache de relaciones:**
   - Si `reservations` se carga con eager loading y luego se modifica en BD, Livewire puede mostrar datos cacheados.
   - Solución actual: `$room->unsetRelation('reservations')` en `releaseRoom()`.

4. **Eventos Livewire:**
   - Si `CleaningPanel` actualiza `last_cleaned_at`, `RoomManager` debe recibir el evento `room-status-updated` para refrescar.
   - Si el evento no se dispara o no se escucha, hay desincronización.

---

## 6. RESUMEN EJECUTIVO

### 6.1 Estado Real vs Estado Visible

| Concepto | Ubicación | Valor Ejemplo | ¿Se Guarda en BD? |
|----------|-----------|---------------|-------------------|
| **Estado Real (BD)** | `rooms.status` | `RoomStatus::LIBRE` | ✅ SÍ |
| **Estado Visible (UI)** | `$room->display_status` | `RoomStatus::OCUPADA` | ❌ NO (calculado) |

### 6.2 Flujo de Cálculo de `display_status`

```
BD (rooms.status = 'libre')
    ↓
getDisplayStatus($date)
    ↓
¿Mantenimiento? → NO
    ↓
¿Ocupada? → SÍ (hay reserva activa)
    ↓
display_status = OCUPADA
    ↓
UI muestra: "Ocupada"
```

### 6.3 Por Qué el Frontend No Refleja Directamente la BD

**Respuesta directa:**
- El frontend usa `display_status` (calculado), no `status` (BD).
- `display_status` combina múltiples fuentes (status + reservations + last_cleaned_at).
- El resultado puede diferir del `status` en BD por diseño arquitectónico.

**Razón de negocio:**
- El `status` en BD es el estado físico/operativo.
- El `display_status` es el estado visual que refleja la realidad operativa (ocupación, limpieza).

### 6.4 Puntos de Atención para Refactorización

1. **Múltiples fuentes de verdad:**
   - `rooms.status` (BD)
   - `reservations` (BD)
   - `last_cleaned_at` (BD)
   - `display_status` (calculado)

2. **Cálculo en tiempo de render:**
   - `display_status` se calcula en `RoomManager::render()`, no se cachea.

3. **Dependencia de fecha:**
   - El mismo `status` en BD puede mostrar diferentes `display_status` según la fecha seleccionada.

4. **Sincronización entre componentes:**
   - `CleaningPanel` y `RoomManager` deben sincronizarse vía eventos Livewire.

5. **Cache de relaciones:**
   - Las relaciones eager-loaded pueden quedar desactualizadas si se modifican en BD.

---

## 7. CONCLUSIÓN

El módulo de habitaciones **NO refleja directamente el `status` de la BD** en el frontend porque:

1. **Arquitectura intencional:** El sistema separa el estado físico (`status` en BD) del estado visual (`display_status` calculado).

2. **Lógica de negocio:** El estado visual debe reflejar la realidad operativa (ocupación por reservas, necesidad de limpieza), no solo el estado físico.

3. **Múltiples fuentes:** El `display_status` combina 3 fuentes de verdad (status, reservations, last_cleaned_at) para determinar el estado correcto.

4. **Dependencia temporal:** El `display_status` depende de la fecha seleccionada, permitiendo ver estados históricos o futuros.

**El frontend está diseñado para mostrar `display_status` (calculado), no `status` (BD), por razones arquitectónicas y de negocio.**

---

**Fin del análisis.**

