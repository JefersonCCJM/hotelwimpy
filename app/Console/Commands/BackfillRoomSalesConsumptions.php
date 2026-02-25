<?php

namespace App\Console\Commands;

use App\Models\ReservationSale;
use App\Models\Sale;
use App\Services\RoomConsumptionLinkService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BackfillRoomSalesConsumptions extends Command
{
    protected $signature = 'sales:backfill-room-consumptions
        {--sale-id=* : Specific sale IDs to sync}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--chunk=200 : Chunk size}
        {--dry-run : Simulate without persisting}
        {--force : Allow real execution in production}';

    protected $description = 'Backfill room-linked sales into reservation_sales for existing records.';

    public function handle(RoomConsumptionLinkService $linkService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (
            app()->environment('production')
            && !$dryRun
            && !(bool) $this->option('force')
        ) {
            $this->error('In production use --dry-run first or confirm with --force.');
            return self::FAILURE;
        }

        if (
            !Schema::hasColumn('reservation_sales', 'sale_id')
            || !Schema::hasColumn('reservation_sales', 'sale_item_id')
        ) {
            $this->error('Missing sale link columns in reservation_sales. Run migrations first.');
            return self::FAILURE;
        }

        try {
            $from = $this->parseDateOption('from');
            $to = $this->parseDateOption('to');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($from && $to && $from->gt($to)) {
            $this->error('Invalid range: --from cannot be greater than --to.');
            return self::FAILURE;
        }

        $saleIds = $this->parseSaleIds((array) $this->option('sale-id'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $query = Sale::query()
            ->whereNotNull('room_id')
            ->whereHas('items')
            ->with('items')
            ->orderBy('id');

        if (!empty($saleIds)) {
            $query->whereIn('id', $saleIds);
        } else {
            if ($from) {
                $query->whereDate('sale_date', '>=', $from->toDateString());
            }

            if ($to) {
                $query->whereDate('sale_date', '<=', $to->toDateString());
            }
        }

        $total = (clone $query)->count();
        if ($total <= 0) {
            $this->warn('No room-linked sales found with the provided filters.');
            return self::SUCCESS;
        }

        $this->line('Mode: ' . ($dryRun ? 'DRY-RUN' : 'LIVE'));
        $this->line('Target sales: ' . $total);
        $this->line('Chunk: ' . $chunkSize);

        $summary = [
            'processed' => 0,
            'synced' => 0,
            'already_synced' => 0,
            'missing_reservation' => 0,
            'errors' => 0,
        ];

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $query->chunkById($chunkSize, function ($sales) use (
            $dryRun,
            $linkService,
            &$summary,
            $progress
        ): void {
            foreach ($sales as $sale) {
                $summary['processed']++;

                try {
                    $itemIds = $sale->items
                        ->pluck('id')
                        ->map(static fn ($id): int => (int) $id)
                        ->filter(static fn (int $id): bool => $id > 0)
                        ->values()
                        ->all();

                    if (empty($itemIds)) {
                        $summary['already_synced']++;
                        $progress->advance();
                        continue;
                    }

                    $linkedCount = ReservationSale::query()
                        ->whereIn('sale_item_id', $itemIds)
                        ->distinct('sale_item_id')
                        ->count('sale_item_id');

                    if ($linkedCount >= count($itemIds)) {
                        $summary['already_synced']++;
                        $progress->advance();
                        continue;
                    }

                    $saleDate = $sale->sale_date instanceof Carbon
                        ? $sale->sale_date->copy()
                        : Carbon::parse((string) $sale->sale_date);

                    $reservation = $linkService->resolveReservationForRoomOnDate(
                        (int) $sale->room_id,
                        $saleDate
                    );

                    if (!$reservation) {
                        $summary['missing_reservation']++;
                        $progress->advance();
                        continue;
                    }

                    if (!$dryRun) {
                        DB::transaction(function () use ($linkService, $sale, $reservation): void {
                            $linkService->syncSaleItemsToReservation($sale, $reservation);
                        });
                    }

                    $summary['synced']++;
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    Log::error('Error backfilling room-linked sale', [
                        'sale_id' => $sale->id ?? null,
                        'room_id' => $sale->room_id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $progress->advance();
                }
            }
        });

        $progress->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', (string) $summary['processed']],
                ['Synced', (string) $summary['synced']],
                ['Already synced', (string) $summary['already_synced']],
                ['Missing reservation', (string) $summary['missing_reservation']],
                ['Errors', (string) $summary['errors']],
            ]
        );

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private function parseSaleIds(array $values): array
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

        $date = Carbon::createFromFormat('Y-m-d', $value);
        if (!$date) {
            throw new \InvalidArgumentException("Invalid --{$option} format. Use YYYY-MM-DD.");
        }

        return $date->startOfDay();
    }
}
