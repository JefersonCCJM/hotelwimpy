<?php

namespace App\Services;

use App\Models\FactusNumberingRange;
use Illuminate\Support\Facades\Log;

class FactusNumberingRangeService
{
    private FactusApiService $factusApi;

    public function __construct(FactusApiService $factusApi)
    {
        $this->factusApi = $factusApi;
    }

    public function sync(): int
    {
        try {
            $response = $this->factusApi->get('/v1/numbering-ranges', [
                'filter' => ['is_active' => 1]
            ]);
            
            $data = $response['data']['data'] ?? $response['data'] ?? [];
            
            if (empty($data)) {
                Log::warning('Factus API devolvió datos vacíos para numbering-ranges');
                return 0;
            }

            $synced = 0;
            
            foreach ($data as $range) {
                $rawDocument = isset($range['document']) ? (string) $range['document'] : '';
                $documentName = isset($range['document_name']) && $range['document_name'] !== ''
                    ? (string) $range['document_name']
                    : $rawDocument;
                $documentCode = isset($range['document_code']) && $range['document_code'] !== ''
                    ? (string) $range['document_code']
                    : (is_numeric($rawDocument) ? $rawDocument : null);

                FactusNumberingRange::updateOrCreate(
                    ['factus_id' => $range['id']],
                    [
                        'document' => $documentName,
                        'document_code' => $documentCode,
                        'prefix' => $range['prefix'] ?? null,
                        'range_from' => $range['from'] ?? $range['range_from'] ?? 0,
                        'range_to' => $range['to'] ?? $range['range_to'] ?? 0,
                        'current' => $range['current'] ?? 0,
                        'resolution_number' => $range['resolution_number'] ?? null,
                        'technical_key' => $range['technical_key'] ?? null,
                        'start_date' => isset($range['start_date']) ? $range['start_date'] : null,
                        'end_date' => isset($range['end_date']) ? $range['end_date'] : null,
                        'is_expired' => $range['is_expired'] ?? false,
                        'is_active' => $range['is_active'] ?? false,
                    ]
                );
                $synced++;
            }

            Log::info("Numbering ranges sincronizados desde Factus", ['count' => $synced]);
            
            return $synced;
        } catch (\Exception $e) {
            Log::error('Error al sincronizar numbering ranges desde Factus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public static function getValidRangeForDocument(string $documentType): ?FactusNumberingRange
    {
        return FactusNumberingRange::where(function ($query) use ($documentType) {
                $query->where('document', $documentType)
                    ->orWhere('document_code', $documentType);
            })
            ->where('is_active', true)
            ->where('is_expired', false)
            ->first();
    }
}
