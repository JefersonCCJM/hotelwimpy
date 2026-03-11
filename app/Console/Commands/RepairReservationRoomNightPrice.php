<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\ReservationRoomPricingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class RepairReservationRoomNightPrice extends Command
{
    protected $signature = 'reservations:repair-room-night-price
        {reservation_id : ID de la reserva}
        {room_id : ID de la habitacion dentro de la reserva}
        {price_per_night : Precio correcto por noche}
        {--from-date= : Fecha desde la cual aplicar el precio (YYYY-MM-DD). Por defecto usa check_in_date}
        {--force : Permite ejecucion en produccion}
        {--confirm : Confirmacion explicita para ejecutar cambios reales}';

    protected $description = 'Repara el precio por noche de una habitacion contractual desde una fecha dada, sin tocar noches anteriores validas.';

    public function handle(ReservationRoomPricingService $pricingService): int
    {
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('En produccion debes confirmar con --force.');
            return Command::FAILURE;
        }

        if (!$this->option('confirm')) {
            $this->error('Debes incluir --confirm para ejecutar la reparacion.');
            return Command::FAILURE;
        }

        $reservationId = (int) $this->argument('reservation_id');
        $roomId = (int) $this->argument('room_id');
        $pricePerNight = round((float) $this->argument('price_per_night'), 2);

        if ($reservationId <= 0 || $roomId <= 0) {
            $this->error('reservation_id y room_id deben ser enteros positivos.');
            return Command::FAILURE;
        }

        if ($pricePerNight <= 0) {
            $this->error('El precio por noche debe ser mayor a 0.');
            return Command::FAILURE;
        }

        $reservation = Reservation::with(['reservationRooms'])->find($reservationId);
        if (!$reservation) {
            $this->error('Reserva no encontrada.');
            return Command::FAILURE;
        }

        $reservationRoom = $reservation->reservationRooms->firstWhere('room_id', $roomId);
        if (!$reservationRoom) {
            $this->error('La reserva no tiene esa habitacion asociada.');
            return Command::FAILURE;
        }

        $fromDateOption = trim((string) $this->option('from-date'));
        try {
            $fromDate = $fromDateOption !== ''
                ? Carbon::createFromFormat('Y-m-d', $fromDateOption)->startOfDay()
                : Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay();
        } catch (Throwable $e) {
            $this->error('La opcion --from-date debe tener formato YYYY-MM-DD.');
            return Command::FAILURE;
        }

        try {
            $result = $pricingService->repairRoomNightPriceFromDate(
                $reservation,
                $reservationRoom,
                $fromDate,
                $pricePerNight
            );
        } catch (Throwable $e) {
            $this->error('No fue posible reparar la reserva: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Reserva', (string) $result['reservation_id']],
                ['Habitacion', (string) $result['room_id']],
                ['Desde', (string) $result['from_date']],
                ['Noches aplicadas', (string) $result['applied_nights']],
                ['Nuevo price_per_night', number_format((float) $result['price_per_night'], 2, ',', '.')],
                ['Nuevo subtotal cuarto', number_format((float) $result['subtotal'], 2, ',', '.')],
                ['Nuevo total reserva', number_format((float) $result['total_amount'], 2, ',', '.')],
                ['Nuevo balance_due', number_format((float) $result['balance_due'], 2, ',', '.')],
                ['Noches con pago re-sincronizado', (string) $result['paid_flags_updated']],
            ]
        );

        $this->info('Reparacion completada.');

        return Command::SUCCESS;
    }
}
