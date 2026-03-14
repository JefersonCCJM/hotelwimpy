<?php

namespace App\Console\Commands;

use App\Exceptions\FactusApiException;
use App\Models\ElectronicCreditNote;
use App\Services\FactusApiService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ManagePendingFactusCreditNotes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'factus:credit-notes-pending
        {reference-codes?* : Codigos de referencia especificos a procesar}
        {--cleanup : Elimina las referencias en Factus y marca las notas locales como canceladas}
        {--remote : Lista notas credito directamente desde Factus}
        {--all : Procesa todas las notas locales que coincidan con los estados indicados}
        {--status=pending,rejected : Estados locales a incluir}
        {--limit=50 : Limite de resultados al listar}
        {--page=1 : Pagina a consultar en Factus al usar --remote}
        {--per-page=50 : Cantidad de resultados por pagina en Factus al usar --remote}
        {--force : Ejecuta sin confirmacion interactiva}';

    /**
     * @var string
     */
    protected $description = 'Lista y limpia notas credito pendientes o rechazadas en Factus';

    public function handle(): int
    {
        $statuses = $this->parseStatuses();

        if ($statuses === []) {
            $this->error('Debes indicar al menos un estado valido en --status.');

            return self::FAILURE;
        }

        $baseQuery = $this->buildBaseQuery($statuses);
        $totalMatches = (clone $baseQuery)->count();

        if (!$this->option('cleanup')) {
            if ($this->option('remote')) {
                /** @var FactusApiService $factusApi */
                $factusApi = app(FactusApiService::class);

                return $this->renderRemoteCreditNotesTable($factusApi);
            }

            $this->renderCreditNotesTable((clone $baseQuery)->limit($this->resolveLimit())->get(), $totalMatches);

            return self::SUCCESS;
        }

        if ($this->option('remote') && empty($this->argument('reference-codes'))) {
            $this->error('Para limpiar referencias remotas usa los codigos explicitamente. Ejemplo: php artisan factus:credit-notes-pending REF-123 --cleanup --force');

            return self::FAILURE;
        }

        $referenceCodes = $this->resolveReferenceCodesForCleanup($baseQuery);

        if ($referenceCodes->isEmpty()) {
            $this->error('No hay referencias para limpiar. Usa referencias explicitas o agrega --all.');

            return self::FAILURE;
        }

        $this->info('Se limpiaran ' . $referenceCodes->count() . ' referencia(s) en Factus.');
        foreach ($referenceCodes as $referenceCode) {
            $this->line(' - ' . $referenceCode);
        }

        if (!$this->option('force') && !$this->confirm('Deseas continuar con la limpieza en Factus?')) {
            $this->warn('Operacion cancelada.');

            return self::SUCCESS;
        }

        $results = [];
        /** @var FactusApiService $factusApi */
        $factusApi = app(FactusApiService::class);

        foreach ($referenceCodes as $referenceCode) {
            $results[] = $this->cleanupReference($factusApi, $referenceCode);
        }

        $this->table(
            ['Referencia', 'Resultado', 'Notas locales', 'Mensaje'],
            $results
        );

        $successCount = collect($results)
            ->whereIn('Resultado', ['eliminada', 'no_encontrada'])
            ->count();
        $errorCount = count($results) - $successCount;

        $this->newLine();
        $this->info("Resumen: {$successCount} procesadas, {$errorCount} con error.");

        return $errorCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    private function parseStatuses(): array
    {
        return collect(explode(',', (string) $this->option('status')))
            ->map(static fn (string $status): string => trim($status))
            ->filter(static fn (string $status): bool => $status !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $statuses
     */
    private function buildBaseQuery(array $statuses): Builder
    {
        return ElectronicCreditNote::query()
            ->with(['customer:id,name', 'electronicInvoice:id,document'])
            ->whereIn('status', $statuses)
            ->orderByDesc('id');
    }

    private function resolveLimit(): int
    {
        return max(1, (int) $this->option('limit'));
    }

    /**
     * @param Collection<int, ElectronicCreditNote> $creditNotes
     */
    private function renderCreditNotesTable(Collection $creditNotes, int $totalMatches): void
    {
        if ($creditNotes->isEmpty()) {
            $this->info('No se encontraron notas credito pendientes o rechazadas con esos filtros.');

            return;
        }

        $rows = $creditNotes->map(static function (ElectronicCreditNote $creditNote): array {
            return [
                'ID' => (string) $creditNote->id,
                'Referencia' => (string) $creditNote->reference_code,
                'Documento' => (string) ($creditNote->document ?: '-'),
                'Estado' => $creditNote->getStatusLabel(),
                'Factura' => (string) ($creditNote->electronicInvoice?->document ?: '-'),
                'Cliente' => (string) ($creditNote->customer?->name ?: '-'),
                'Total' => number_format((float) $creditNote->total, 2, '.', ','),
                'Creada' => $creditNote->created_at?->format('Y-m-d H:i') ?: '-',
            ];
        })->all();

        $this->table(
            ['ID', 'Referencia', 'Documento', 'Estado', 'Factura', 'Cliente', 'Total', 'Creada'],
            $rows
        );

        $this->line("Mostrando {$creditNotes->count()} de {$totalMatches} coincidencias.");
        $this->newLine();
        $this->line('Para limpiar todas las referencias listadas:');
        $this->line('php artisan factus:credit-notes-pending --cleanup --all --force');
        $this->newLine();
        $this->line('Para limpiar referencias especificas:');
        $this->line('php artisan factus:credit-notes-pending NC-XXXX NC-YYYY --cleanup --force');
    }

    private function renderRemoteCreditNotesTable(FactusApiService $factusApi): int
    {
        $page = max(1, (int) $this->option('page'));
        $perPage = max(1, min(100, (int) $this->option('per-page')));

        $filters = [];
        $referenceCodes = collect($this->argument('reference-codes'))
            ->map(static fn (string $referenceCode): string => trim($referenceCode))
            ->filter(static fn (string $referenceCode): bool => $referenceCode !== '')
            ->values();

        if ($referenceCodes->count() === 1) {
            $filters['reference_code'] = $referenceCodes->first();
        }

        $response = $factusApi->getCreditNotes($filters, $page, $perPage);
        $items = collect($response['data']['data'] ?? $response['data'] ?? []);

        if ($referenceCodes->count() > 1) {
            $items = $items->filter(static function ($item) use ($referenceCodes): bool {
                return is_array($item)
                    && $referenceCodes->contains((string) data_get($item, 'reference_code'));
            })->values();
        }

        if ($items->isEmpty()) {
            $this->info('No se encontraron notas credito remotas en Factus con esos filtros.');

            return self::SUCCESS;
        }

        $rows = $items->map(function ($item): array {
            $rawStatus = data_get($item, 'status');

            return [
                'Referencia' => (string) (data_get($item, 'reference_code') ?? '-'),
                'Numero' => (string) (data_get($item, 'number') ?? '-'),
                'Estado raw' => is_scalar($rawStatus) ? (string) $rawStatus : '-',
                'Estado' => $this->guessRemoteStatusLabel(is_array($item) ? $item : []),
                'Cliente' => (string) (
                    data_get($item, 'customer.graphic_representation_name')
                    ?? data_get($item, 'names')
                    ?? data_get($item, 'customer.names')
                    ?? '-'
                ),
                'Total' => (string) (data_get($item, 'total') ?? '-'),
            ];
        })->all();

        $this->table(['Referencia', 'Numero', 'Estado raw', 'Estado', 'Cliente', 'Total'], $rows);
        $this->newLine();
        $this->line('Si identificas una referencia bloqueante en Factus, puedes limpiarla asi:');
        $this->line('php artisan factus:credit-notes-pending REF-XXXX --cleanup --force');

        return self::SUCCESS;
    }

    private function resolveReferenceCodesForCleanup(Builder $baseQuery): Collection
    {
        $explicitReferenceCodes = collect($this->argument('reference-codes'))
            ->map(static fn (string $referenceCode): string => trim($referenceCode))
            ->filter(static fn (string $referenceCode): bool => $referenceCode !== '');

        if ($explicitReferenceCodes->isNotEmpty()) {
            return $explicitReferenceCodes->unique()->values();
        }

        if (!$this->option('all')) {
            return collect();
        }

        return (clone $baseQuery)
            ->whereNotNull('reference_code')
            ->pluck('reference_code')
            ->filter(static fn (?string $referenceCode): bool => is_string($referenceCode) && $referenceCode !== '')
            ->unique()
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function cleanupReference(FactusApiService $factusApi, string $referenceCode): array
    {
        $localCreditNotes = ElectronicCreditNote::query()
            ->where('reference_code', $referenceCode)
            ->get();

        try {
            $response = $factusApi->deleteCreditNoteByReference($referenceCode);

            $this->markLocalCreditNotesAsCancelled(
                $localCreditNotes,
                [
                    'manual_cleanup_at' => now()->toISOString(),
                    'manual_cleanup_response' => $response,
                ]
            );

            return [
                'Referencia' => $referenceCode,
                'Resultado' => 'eliminada',
                'Notas locales' => $this->formatLocalIds($localCreditNotes),
                'Mensaje' => (string) ($response['message'] ?? 'Documento eliminado en Factus'),
            ];
        } catch (FactusApiException $exception) {
            if ($exception->getStatusCode() === 404) {
                $this->markLocalCreditNotesAsCancelled(
                    $localCreditNotes,
                    [
                        'manual_cleanup_at' => now()->toISOString(),
                        'manual_cleanup_response' => $exception->getResponseBody(),
                        'manual_cleanup_reason' => 'not_found_in_factus',
                    ]
                );

                return [
                    'Referencia' => $referenceCode,
                    'Resultado' => 'no_encontrada',
                    'Notas locales' => $this->formatLocalIds($localCreditNotes),
                    'Mensaje' => 'La referencia no existe en Factus. Se marco cancelada localmente.',
                ];
            }

            return [
                'Referencia' => $referenceCode,
                'Resultado' => 'error',
                'Notas locales' => $this->formatLocalIds($localCreditNotes),
                'Mensaje' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            return [
                'Referencia' => $referenceCode,
                'Resultado' => 'error',
                'Notas locales' => $this->formatLocalIds($localCreditNotes),
                'Mensaje' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param Collection<int, ElectronicCreditNote> $creditNotes
     * @param array<string, mixed> $cleanupContext
     */
    private function markLocalCreditNotesAsCancelled(Collection $creditNotes, array $cleanupContext): void
    {
        foreach ($creditNotes as $creditNote) {
            $responseDian = is_array($creditNote->response_dian) ? $creditNote->response_dian : [];

            $creditNote->update([
                'status' => 'cancelled',
                'response_dian' => array_merge($responseDian, $cleanupContext),
            ]);
        }
    }

    /**
     * @param Collection<int, ElectronicCreditNote> $creditNotes
     */
    private function formatLocalIds(Collection $creditNotes): string
    {
        if ($creditNotes->isEmpty()) {
            return '-';
        }

        return $creditNotes->pluck('id')
            ->map(static fn (int $id): string => (string) $id)
            ->implode(', ');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function guessRemoteStatusLabel(array $item): string
    {
        $status = data_get($item, 'status');

        if ((string) $status === '1' || !empty(data_get($item, 'cude'))) {
            return 'Aceptada';
        }

        if ((string) $status === '0') {
            return 'Pendiente';
        }

        if (is_string($status) && $status !== '') {
            return ucfirst(strtolower($status));
        }

        return 'Pendiente/Desconocido';
    }
}
