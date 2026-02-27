<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\StayNight;
use App\Services\ReservationRetroactiveRecalculationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RepairPaidNightStatus extends Command
{
    protected $signature = 'reservations:repair-paid-night-status
        {--from= : Fecha inicial (YYYY-MM-DD) para filtrar reservas}
        {--to= : Fecha final (YYYY-MM-DD) para filtrar reservas}
        {--reservation-id=* : IDs de reserva especificos}
        {--chunk=200 : Tamano de lote}
        {--dry-run : Simula sin persistir cambios}
        {--force : Permite ejecucion en produccion}
        {--confirm : Confirmacion explicita para ejecutar cambios}
        {--with-trashed : Incluye reservas eliminadas logicamente}';

    protected $description = 'Repara reservas con noches pendientes cuando el hospedaje ya esta totalmente pagado.';

    public function handle(ReservationRetroactiveRecalculationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $withTrashed = (bool) $this->option('with-trashed');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (app()->environment('production') && !$this->option('force')) {
            $this->error('En produccion debes confirmar con --force.');
            $this->line('Sugerido primero: php artisan reservations:repair-paid-night-status --dry-run --force');
            return Command::FAILURE;
        }

        try {
            $from = $this->parseDateOption('from');
            $to = $this->parseDateOption('to');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($from && $to && $from->gt($to)) {
            $this->error('El rango es invalido: --from no puede ser mayor a --to.');
            return Command::FAILURE;
        }

        $reservationIds = $this->parseReservationIds((array) $this->option('reservation-id'));

        $baseQuery = Reservation::query()->select('id')->orderBy('id');
        if ($withTrashed) {
            $baseQuery->withTrashed();
        }

        $baseQuery->whereHas('reservationRooms');
        $baseQuery->whereExists(function ($query): void {
            $query->select(DB::raw(1))
                ->from('stay_nights as sn')
                ->whereColumn('sn.reservation_id', 'reservations.id')
                ->where('sn.is_paid', false);
        });

        if (!empty($reservationIds)) {
            $baseQuery->whereIn('id', $reservationIds);
        } elseif ($from || $to) {
            $baseQuery->whereHas('reservationRooms', function ($query) use ($from, $to): void {
                if ($from) {
                    $query->whereDate('check_out_date', '>=', $from->toDateString());
                }
                if ($to) {
                    $query->whereDate('check_in_date', '<=', $to->toDateString());
                }
            });
        }

        $totalCandidates = (clone $baseQuery)->count();
        if ($totalCandidates <= 0) {
            $this->warn('No hay reservas candidatas con noches pendientes.');
            return Command::SUCCESS;
        }

        $this->line('Modo: ' . ($dryRun ? 'DRY-RUN (sin persistencia)' : 'EJECUCION REAL'));
        $this->line('Candidatas iniciales: ' . $totalCandidates);
        $this->line('Chunk: ' . $chunkSize);

        $affectedReservationIds = [];

        (clone $baseQuery)->chunkById($chunkSize, function ($rows) use (&$affectedReservationIds, $withTrashed): void {
            $ids = collect($rows)->pluck('id')->map(static fn ($id) => (int) $id)->filter()->values()->all();
            if (empty($ids)) {
                return;
            }

            $query = Reservation::query()->with('reservationRooms')->whereIn('id', $ids);
            if ($withTrashed) {
                $query->withTrashed();
            }

            $reservations = $query->get();
            foreach ($reservations as $reservation) {
                if ($this->isReservationAffected($reservation)) {
                    $affectedReservationIds[] = (int) $reservation->id;
                }
            }
        });

        $affectedReservationIds = array_values(array_unique($affectedReservationIds));
        sort($affectedReservationIds);

        if (empty($affectedReservationIds)) {
            $this->info('No se detectaron reservas afectadas (saldo cubierto + noches pendientes).');
            return Command::SUCCESS;
        }

        $this->info('Reservas afectadas detectadas: ' . count($affectedReservationIds));
        $sample = array_slice($affectedReservationIds, 0, 20);
        $this->line('Muestra IDs: ' . implode(', ', $sample) . (count($affectedReservationIds) > 20 ? ' ...' : ''));

        if ($dryRun) {
            return Command::SUCCESS;
        }

        if (!$this->option('confirm')) {
            $this->error('Para ejecutar cambios reales debes incluir --confirm.');
            return Command::FAILURE;
        }

        $processed = 0;
        $changed = 0;
        $errors = 0;
        $paidFlagsUpdated = 0;

        foreach (array_chunk($affectedReservationIds, $chunkSize) as $idChunk) {
            $query = Reservation::query()->whereIn('id', $idChunk);
            if ($withTrashed) {
                $query->withTrashed();
            }

            $reservations = $query->get();
            foreach ($reservations as $reservation) {
                $processed++;
                try {
                    $result = $service->recalculateReservation($reservation, [
                        'dry_run' => false,
                        'keep_total' => true,
                        'without_sales_debt' => false,
                        'strict_paid_outside_range' => false,
                    ]);

                    if ((bool) ($result['changed'] ?? false)) {
                        $changed++;
                    }

                    $paidFlagsUpdated += (int) ($result['paid_flags_updated'] ?? 0);
                } catch (Throwable $e) {
                    $errors++;
                    $this->error('Error en reserva #' . (int) $reservation->id . ': ' . $e->getMessage());
                }
            }
        }

        $this->newLine();
        $this->info('Proceso finalizado.');
        $this->line('Procesadas: ' . $processed);
        $this->line('Con cambios: ' . $changed);
        $this->line('Noches actualizadas (paid flags): ' . $paidFlagsUpdated);
        $this->line('Errores: ' . $errors);

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function isReservationAffected(Reservation $reservation): bool
    {
        $contractualTotal = $this->resolveContractualLodgingTotal($reservation);
        if ($contractualTotal <= 0) {
            return false;
        }

        $paymentsNet = round(max(0, (float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount')), 2);

        if ($paymentsNet + 0.01 < $contractualTotal) {
            return false;
        }

        foreach ($reservation->reservationRooms as $reservationRoom) {
            if (empty($reservationRoom->check_in_date) || empty($reservationRoom->check_out_date)) {
                continue;
            }

            $roomId = (int) ($reservationRoom->room_id ?? 0);
            if ($roomId <= 0) {
                continue;
            }

            $checkIn = Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay()->toDateString();
            $checkOut = Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay()->toDateString();

            $hasPendingNight = StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->where('room_id', $roomId)
                ->whereDate('date', '>=', $checkIn)
                ->whereDate('date', '<', $checkOut)
                ->where('is_paid', false)
                ->exists();

            if ($hasPendingNight) {
                return true;
            }
        }

        return false;
    }

    private function resolveContractualLodgingTotal(Reservation $reservation): float
    {
        $reservation->loadMissing('reservationRooms');

        $rooms = $reservation->reservationRooms ?? collect();
        if ($rooms->isNotEmpty()) {
            $roomsWithSubtotal = $rooms->filter(static fn ($room) => (float) ($room->subtotal ?? 0) > 0);
            if ($roomsWithSubtotal->count() === $rooms->count()) {
                return round((float) $roomsWithSubtotal->sum(static fn ($room) => (float) ($room->subtotal ?? 0)), 2);
            }
        }

        $reservationTotal = round((float) ($reservation->total_amount ?? 0), 2);
        if ($reservationTotal > 0) {
            return $reservationTotal;
        }

        return round((float) StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->sum('price'), 2);
    }

    /**
     * @return array<int,int>
     */
    private function parseReservationIds(array $rawIds): array
    {
        return collect($rawIds)
            ->flatMap(function ($value): array {
                if ($value === null || $value === '') {
                    return [];
                }
                return preg_split('/[,;\s]+/', (string) $value) ?: [];
            })
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function parseDateOption(string $option): ?Carbon
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (Throwable $e) {
            throw new \InvalidArgumentException(
                sprintf('La opcion --%s no tiene un formato de fecha valido (YYYY-MM-DD).', $option)
            );
        }
    }
}
