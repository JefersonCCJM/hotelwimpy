<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\ReservationRetroactiveRecalculationService;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RecalculateReservationsRetroactive extends Command
{
    protected $signature = 'reservations:recalculate-retroactive
        {--from= : Fecha inicial (YYYY-MM-DD) para filtrar reservas por rango}
        {--to= : Fecha final (YYYY-MM-DD) para filtrar reservas por rango}
        {--reservation-id=* : IDs de reserva especificos}
        {--chunk=200 : Tamano de lote}
        {--dry-run : Simula sin guardar cambios}
        {--force : Permite ejecucion en produccion}
        {--confirm : Confirmacion explicita para ejecutar cambios (sin --dry-run)}
        {--backup-sql= : Ruta .sql para backup previo con mysqldump (si se omite valor usa ruta automatica)}
        {--keep-total : No actualizar reservations.total_amount}
        {--without-sales-debt : Excluye deuda de ventas del balance}
        {--strict-paid-outside-range : Falla si hay noches pagadas fuera de rango}
        {--with-trashed : Incluye reservas eliminadas logicamente}';

    protected $description = 'Recalculo retroactivo masivo de stays, stay_nights y finanzas de reservas.';

    public function handle(ReservationRetroactiveRecalculationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (app()->environment('production') && !$this->option('force')) {
            $this->error('En produccion debes confirmar con --force.');
            $this->line('Sugerido primero: php artisan reservations:recalculate-retroactive --dry-run --force');
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

        $chunkSize = max(1, (int) $this->option('chunk'));
        $withTrashed = (bool) $this->option('with-trashed');
        $reservationIds = $this->parseReservationIds((array) $this->option('reservation-id'));

        $baseQuery = Reservation::query()->select('id')->orderBy('id');
        if ($withTrashed) {
            $baseQuery->withTrashed();
        }

        $baseQuery->whereHas('reservationRooms');

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

        $totalReservations = (clone $baseQuery)->count();
        if ($totalReservations <= 0) {
            $this->warn('No hay reservas para recalcular con los filtros enviados.');
            return Command::SUCCESS;
        }

        $this->line('Modo: ' . ($dryRun ? 'DRY-RUN (sin persistencia)' : 'EJECUCION REAL'));
        $this->line('Reservas objetivo: ' . $totalReservations);
        $this->line('Chunk: ' . $chunkSize);
        if ($from || $to) {
            $this->line(
                'Rango: '
                . ($from ? $from->toDateString() : '...') . ' -> '
                . ($to ? $to->toDateString() : '...')
            );
        }

        if (!$dryRun && !$this->confirmWriteExecution($totalReservations)) {
            $this->warn('Ejecucion cancelada.');
            return Command::FAILURE;
        }

        try {
            $backupPath = $this->maybeRunBackupSql($dryRun);
        } catch (Throwable $e) {
            $this->error('No fue posible crear backup SQL previo: ' . $e->getMessage());
            Log::error('Error creando backup SQL antes de recalculo retroactivo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        if (!empty($backupPath)) {
            $this->info('Backup SQL generado en: ' . $backupPath);
        }

        $summary = [
            'processed' => 0,
            'changed' => 0,
            'created_stays' => 0,
            'updated_stays' => 0,
            'created_nights' => 0,
            'updated_nights' => 0,
            'deleted_nights' => 0,
            'paid_flags_updated' => 0,
            'preserved_paid_nights' => 0,
            'updated_reservations' => 0,
            'errors' => 0,
        ];
        $errors = [];

        $options = [
            'dry_run' => $dryRun,
            'keep_total' => (bool) $this->option('keep-total'),
            'without_sales_debt' => (bool) $this->option('without-sales-debt'),
            'strict_paid_outside_range' => (bool) $this->option('strict-paid-outside-range'),
            'operational_date' => HotelTime::currentOperationalDate(),
        ];

        $progress = $this->output->createProgressBar($totalReservations);
        $progress->start();

        $baseQuery->chunkById($chunkSize, function ($rows) use (
            $service,
            $withTrashed,
            $options,
            &$summary,
            &$errors,
            $progress
        ): void {
            $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $reservationsQuery = Reservation::query()
                ->with(['reservationRooms', 'stays', 'sales'])
                ->whereIn('id', $ids);

            if ($withTrashed) {
                $reservationsQuery->withTrashed();
            }

            $reservations = $reservationsQuery->get()->keyBy('id');

            foreach ($ids as $id) {
                $reservation = $reservations->get($id);
                if (!$reservation) {
                    $summary['processed']++;
                    $summary['errors']++;
                    $errors[] = "#{$id}: no encontrada en carga de lote.";
                    $progress->advance();
                    continue;
                }

                try {
                    $result = $service->recalculateReservation($reservation, $options);

                    $summary['processed']++;
                    $summary['changed'] += !empty($result['changed']) ? 1 : 0;
                    $summary['created_stays'] += (int) ($result['created_stays'] ?? 0);
                    $summary['updated_stays'] += (int) ($result['updated_stays'] ?? 0);
                    $summary['created_nights'] += (int) ($result['created_nights'] ?? 0);
                    $summary['updated_nights'] += (int) ($result['updated_nights'] ?? 0);
                    $summary['deleted_nights'] += (int) ($result['deleted_nights'] ?? 0);
                    $summary['paid_flags_updated'] += (int) ($result['paid_flags_updated'] ?? 0);
                    $summary['preserved_paid_nights'] += (int) ($result['preserved_paid_nights'] ?? 0);
                    $summary['updated_reservations'] += !empty($result['updated_reservation_row']) ? 1 : 0;
                } catch (Throwable $e) {
                    $summary['processed']++;
                    $summary['errors']++;

                    $message = "#{$id}: {$e->getMessage()}";
                    $errors[] = $message;

                    Log::error('Error en recalc retroactivo de reserva', [
                        'reservation_id' => $id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } finally {
                    $progress->advance();
                }
            }
        });

        $progress->finish();
        $this->newLine(2);

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Procesadas', (string) $summary['processed']],
                ['Con cambios', (string) $summary['changed']],
                ['Stays creadas', (string) $summary['created_stays']],
                ['Stays actualizadas', (string) $summary['updated_stays']],
                ['Noches creadas', (string) $summary['created_nights']],
                ['Noches actualizadas', (string) $summary['updated_nights']],
                ['Noches eliminadas', (string) $summary['deleted_nights']],
                ['Flags is_paid actualizados', (string) $summary['paid_flags_updated']],
                ['Noches pagadas preservadas', (string) $summary['preserved_paid_nights']],
                ['Reservas actualizadas', (string) $summary['updated_reservations']],
                ['Errores', (string) $summary['errors']],
            ]
        );

        if (!empty($errors)) {
            $this->warn('Primeros errores encontrados (max 20):');
            foreach (array_slice($errors, 0, 20) as $errorLine) {
                $this->line(' - ' . $errorLine);
            }
        }

        if ($summary['errors'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private function parseReservationIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function parseDateOption(string $option): ?Carbon
    {
        $value = trim((string) $this->option($option));
        if ($value === '') {
            return null;
        }

        $date = Carbon::createFromFormat('Y-m-d', $value, HotelTime::timezone());
        if (!$date) {
            throw new \InvalidArgumentException("Formato invalido en --{$option}. Usa YYYY-MM-DD.");
        }

        return $date->startOfDay();
    }

    private function confirmWriteExecution(int $totalReservations): bool
    {
        if ((bool) $this->option('confirm')) {
            return true;
        }

        if (!$this->input->isInteractive()) {
            $this->error('Falta --confirm para ejecutar cambios en modo no interactivo.');
            return false;
        }

        $this->warn(
            "Vas a ejecutar cambios reales sobre {$totalReservations} reservas. "
            . 'Recomendado: usar --backup-sql antes de continuar.'
        );

        return $this->confirm('Confirma la ejecucion real del recalculo retroactivo', false);
    }

    private function maybeRunBackupSql(bool $dryRun): ?string
    {
        $backupOption = $this->option('backup-sql');
        if ($backupOption === null) {
            return null;
        }

        if ($dryRun) {
            $this->warn('Se omite --backup-sql porque estas en --dry-run.');
            return null;
        }

        $backupPath = $this->resolveBackupSqlPath($backupOption);
        $this->runMysqlDumpBackup($backupPath);

        return $backupPath;
    }

    private function resolveBackupSqlPath(mixed $optionValue): string
    {
        $value = trim((string) $optionValue);
        if ($value === '' || strtolower($value) === 'auto') {
            $timestamp = Carbon::now(HotelTime::timezone())->format('Ymd_His');
            $value = storage_path("app/backups/recalculate_reservations_{$timestamp}.sql");
        } elseif (!$this->isAbsolutePath($value)) {
            $value = base_path($value);
        }

        $directory = dirname($value);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio para backup: {$directory}");
        }

        return $value;
    }

    private function runMysqlDumpBackup(string $backupPath): void
    {
        $connectionName = (string) config('database.default', 'mysql');
        $connectionConfig = (array) config("database.connections.{$connectionName}", []);
        $driver = (string) ($connectionConfig['driver'] ?? '');

        if ($driver !== 'mysql') {
            throw new RuntimeException(
                "El backup automatico solo soporta MySQL (conexion actual: {$driver})."
            );
        }

        $database = (string) ($connectionConfig['database'] ?? '');
        $username = (string) ($connectionConfig['username'] ?? '');
        $password = (string) ($connectionConfig['password'] ?? '');
        $host = (string) ($connectionConfig['host'] ?? '127.0.0.1');
        $port = (string) ($connectionConfig['port'] ?? '3306');
        $socket = (string) ($connectionConfig['unix_socket'] ?? '');

        if ($database === '' || $username === '') {
            throw new RuntimeException('Credenciales incompletas en configuracion de base de datos.');
        }

        $tables = [
            'reservations',
            'reservation_rooms',
            'stays',
            'stay_nights',
            'payments',
            'reservation_sales',
        ];

        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            "--host={$host}",
            "--port={$port}",
            "--user={$username}",
        ];

        if ($socket !== '') {
            $command[] = "--socket={$socket}";
        }

        $command[] = $database;
        foreach ($tables as $table) {
            $command[] = $table;
        }

        $env = [];
        if ($password !== '') {
            $env['MYSQL_PWD'] = $password;
        }

        $process = new Process($command, base_path(), $env, null, 600);

        $handle = @fopen($backupPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo de backup: {$backupPath}");
        }

        $stderr = '';

        try {
            $process->run(function (string $type, string $buffer) use ($handle, &$stderr): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                    return;
                }

                $stderr .= $buffer;
            });
        } finally {
            fclose($handle);
        }

        if (!$process->isSuccessful()) {
            @unlink($backupPath);
            throw new RuntimeException(
                'mysqldump fallo: ' . trim($stderr !== '' ? $stderr : $process->getOutput())
            );
        }

        if (!is_file($backupPath) || (int) @filesize($backupPath) <= 0) {
            @unlink($backupPath);
            throw new RuntimeException('El backup generado esta vacio.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }
}
