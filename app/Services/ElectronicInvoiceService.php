<?php

namespace App\Services;

// TODO: Adaptar cuando se implementen las reservas
// use App\Models\Reservation;
use App\Models\Customer;
use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceItem;
use App\Models\CompanyTaxSetting;
use App\Models\DianDocumentType;
use App\Models\DianOperationType;
use App\Models\DianPaymentMethod;
use App\Models\DianPaymentForm;
use App\Models\Service;
use App\Services\FactusNumberingRangeService;
use App\Exceptions\FactusApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ElectronicInvoiceService
{
    private const FACTUS_OBSERVATION_MAX_LENGTH = 250;

    public function __construct(
        private FactusApiService $apiService,
        private FactusNumberingRangeService $numberingRangeService
    ) {}

    // TODO: Adaptar este método cuando se implementen las reservas
    // La facturación electrónica se generará desde las reservas, no desde ventas
    // public function createFromReservation(Reservation $reservation): ElectronicInvoice
    // {
    //     ...
    // }

    /**
     * Create electronic invoice from form data.
     *
     * @param array<string, mixed> $data
     * @return ElectronicInvoice
     * @throws \Exception
     */
    public function createFromForm(array $data): ElectronicInvoice
    {
        DB::beginTransaction();
        
        try {
            $normalizedNotes = $this->normalizeObservation($data['notes'] ?? null);
            $customer = Customer::with('taxProfile')->findOrFail($data['customer_id']);
            
            // Agregar logs detallados para depurar el perfil fiscal
            Log::info('Customer data:', [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'requires_electronic_invoice' => $customer->requires_electronic_invoice,
                'has_tax_profile' => $customer->taxProfile ? true : false,
            ]);
            
            if ($customer->taxProfile) {
                Log::info('Tax profile data:', [
                    'identification_document_id' => $customer->taxProfile->identification_document_id,
                    'identification' => $customer->taxProfile->identification,
                    'legal_organization_id' => $customer->taxProfile->legal_organization_id,
                    'tribute_id' => $customer->taxProfile->tribute_id,
                    'municipality_id' => $customer->taxProfile->municipality_id,
                    'dv' => $customer->taxProfile->dv,
                ]);
            }
            
            if (!$customer->hasCompleteTaxProfileData()) {
                $missingFields = [];
                $taxProfile = $customer->taxProfile;
                
                if (!$taxProfile) {
                    $missingFields[] = 'tax_profile (no existe)';
                } else {
                    // Verificar cada campo requerido por el método hasCompleteTaxProfileData()
                    if (empty($taxProfile->identification_document_id)) $missingFields[] = 'identification_document_id';
                    if (empty($taxProfile->identification)) $missingFields[] = 'identification';
                    if (empty($taxProfile->municipality_id)) $missingFields[] = 'municipality_id';
                    
                    // Verificar DV si es requerido
                    if ($taxProfile->requiresDV() && $taxProfile->dv === null) $missingFields[] = 'dv';
                    
                    // Verificar company si es persona jurídica
                    if ($taxProfile->isJuridicalPerson() && empty($taxProfile->company)) $missingFields[] = 'company (requerido para persona jurídica)';
                    
                    // Agregar logs específicos para depuración
                    Log::info('Tax profile validation details:', [
                        'requiresDV' => $taxProfile->requiresDV(),
                        'hasDV' => $taxProfile->dv !== null,
                        'isJuridicalPerson' => $taxProfile->isJuridicalPerson(),
                        'hasCompany' => !empty($taxProfile->company),
                        'identification_document_id' => $taxProfile->identification_document_id,
                        'identification' => $taxProfile->identification,
                        'municipality_id' => $taxProfile->municipality_id,
                    ]);
                }
                
                Log::error('Customer missing tax profile fields:', [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'missing_fields' => $missingFields,
                    'tax_profile_data' => $taxProfile ? $taxProfile->toArray() : null,
                ]);
                
                throw new \Exception('El cliente no tiene perfil fiscal completo. Campos faltantes: ' . implode(', ', $missingFields));
            }

            $this->validateCustomerTaxProfileForFactus($customer);
            
            // Get document type code
            $documentType = DianDocumentType::findOrFail($data['document_type_id']);
            
            // Get numbering range directly by ID (not by document type)
            $numberingRange = \App\Models\FactusNumberingRange::findOrFail($data['numbering_range_id']);
            if (!$numberingRange || !$numberingRange->is_active || $numberingRange->is_expired) {
                throw new \Exception('El rango de numeración seleccionado no está activo o está vencido.');
            }
            
            Log::info('Using numbering range:', [
                'range_id' => $numberingRange->id,
                'document' => $numberingRange->document,
                'prefix' => $numberingRange->prefix,
                'is_active' => $numberingRange->is_active,
                'is_expired' => $numberingRange->is_expired,
            ]);
            
            // Crear la factura en la base de datos local primero
            $invoice = ElectronicInvoice::create([
                'customer_id' => $customer->id,
                'factus_numbering_range_id' => $numberingRange->factus_id,
                'document_type_id' => $data['document_type_id'],
                'operation_type_id' => $data['operation_type_id'],
                'payment_method_code' => $data['payment_method_code'],
                'payment_form_code' => $data['payment_form_code'],
                'reference_code' => $data['reference_code'] ?? $this->generateReferenceCode(),
                'document' => $this->generateDocumentNumber($numberingRange),
                'notes' => $normalizedNotes,
                'status' => 'pending',
                'gross_value' => $data['totals']['subtotal'],
                'tax_amount' => $data['totals']['tax'],
                'discount_amount' => 0,
                'total' => $data['totals']['total'],
            ]);
            
            // Crear los items con datos del formulario (no desde servicios)
            foreach ($data['items'] as $itemData) {
                // Validar que el nombre del item no esté vacío
                $itemName = trim($itemData['name']);
                if (empty($itemName)) {
                    throw new \Exception('El nombre del item es obligatorio y no puede estar vacío');
                }
                
                ElectronicInvoiceItem::create([
                    'electronic_invoice_id' => $invoice->id,
                    'name' => $itemName,
                    'quantity' => $itemData['quantity'],
                    'price' => $itemData['price'],
                    'tax_rate' => $itemData['tax_rate'],
                    'tax_amount' => $itemData['tax'],
                    'total' => $itemData['total'],
                    'discount_rate' => 0,
                    'is_excluded' => false,
                    // Valores por defecto para los campos requeridos (usando IDs correctos)
                    'tribute_id' => 18, // IVA (ID 18)
                    'standard_code_id' => 1, // Estándar por defecto
                    'unit_measure_id' => 70, // Unidad por defecto
                    'code_reference' => 'SRV-' . uniqid(), // Código de referencia generado
                ]);
            }
            
            // Commit de la transacción de creación de factura
            DB::commit();
            
            // Ahora intentar enviar a Factus API (fuera de la transacción)
            try {
                $this->sendToFactusWithRecovery($invoice);
            } catch (\Exception $e) {
                // Si falla el envío a Factus, la factura ya está guardada como 'pending'
                Log::warning('Error sending invoice to Factus, keeping as pending:', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                    'reference_code' => $invoice->reference_code,
                ]);
                
                // NO hacer rollback aquí - la factura ya está guardada y committed
                // Solo lanzar el error para el usuario, pero la factura permanece en BD
                throw $e;
            }
            
            return $invoice->fresh(['customer', 'items']);
            
        } catch (\Exception $e) {
            // Solo hacer rollback si el error ocurrió antes del commit
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            
            Log::error('Error creating electronic invoice from form', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Send invoice to Factus API.
     *
     * @param ElectronicInvoice $invoice
     * @return void
     * @throws \Exception
     */
    private function sendToFactus(ElectronicInvoice $invoice): void
    {
        $company = CompanyTaxSetting::first();
        if (!$company) {
            throw new \Exception('La configuración fiscal de la empresa no está completa.');
        }
        
        $payload = $this->buildPayload($invoice, $company);
        
        // Agregar log del payload para depuración
        Log::info('Payload enviado a Factus API:', [
            'payload' => $payload,
            'invoice_id' => $invoice->id,
            'document_number' => $invoice->document,
        ]);
        
        try {
            // Usar el endpoint de validación según la documentación
            $response = $this->apiService->post('/v1/bills/validate', $payload);
            
            // Agregar log de la respuesta
            Log::info('Respuesta de Factus API:', [
                'response' => $response,
                'invoice_id' => $invoice->id,
            ]);
            
            $status = $this->mapStatusFromResponse($response);
            
            $updateData = [
                'status' => $status,
                'payload_sent' => $payload,
                'response_dian' => $response,
            ];

            if (isset($response['data']['bill']['id']) && !empty($response['data']['bill']['id'])) {
                $updateData['factus_bill_id'] = (int) $response['data']['bill']['id'];
            }
            
            // Actualizar campos según la respuesta de la API
            if (isset($response['data']['bill']['cufe']) && !empty($response['data']['bill']['cufe'])) {
                $updateData['cufe'] = $response['data']['bill']['cufe'];
            }
            
            if (isset($response['data']['bill']['qr']) && !empty($response['data']['bill']['qr'])) {
                $updateData['qr'] = $response['data']['bill']['qr'];
            }
            
            if (isset($response['data']['bill']['number']) && !empty($response['data']['bill']['number'])) {
                $updateData['document'] = $response['data']['bill']['number'];
            }
            
            if (isset($response['data']['bill']['pdf_url']) && !empty($response['data']['bill']['pdf_url'])) {
                $updateData['pdf_url'] = $response['data']['bill']['pdf_url'];
            }
            
            if (isset($response['data']['bill']['xml_url']) && !empty($response['data']['bill']['xml_url'])) {
                $updateData['xml_url'] = $response['data']['bill']['xml_url'];
            }
            
            if (isset($response['data']['bill']['validated_at']) && !empty($response['data']['bill']['validated_at'])) {
                $updateData['validated_at'] = $response['data']['bill']['validated_at'];
            }
            
            $invoice->update($updateData);
            
            Log::info('Factura enviada exitosamente a Factus', [
                'invoice_id' => $invoice->id,
                'document' => $updateData['document'] ?? null,
                'status' => $status,
                'response' => $response
            ]);
            
        } catch (FactusApiException $e) {
            // Capturar error específico de Factus API con detalles
            $errorDetails = $e->getMessage();
            $statusCode = $e->getStatusCode();
            $errorData = $e->getResponseBody();
            
            Log::error('Error sending invoice to Factus API', [
                'invoice_id' => $invoice->id,
                'status_code' => $statusCode,
                'error_message' => $errorDetails,
                'error_data' => $errorData,
                'payload' => $payload,
            ]);
            
            // Construir mensaje de error más detallado
            $detailedError = "Error en Factus API ({$statusCode}): {$errorDetails}";
            if ($errorData && isset($errorData['data']['errors'])) {
                $detailedError .= ' | Errores: ' . json_encode($errorData['data']['errors']);
            }
            
            throw new \Exception('Error al enviar la factura a Factus: ' . $detailedError);
        } catch (\Exception $e) {
            // Manejar otros errores
            Log::error('Error sending invoice to Factus', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
            throw new \Exception('Error al enviar la factura a Factus: ' . $e->getMessage());
        }
    }

    private function sendToFactusWithRecovery(ElectronicInvoice $invoice): void
    {
        $company = CompanyTaxSetting::first();
        if (!$company) {
            throw new \Exception('La configuracion fiscal de la empresa no esta completa.');
        }

        $payload = $this->buildPayload($invoice, $company);

        Log::info('Payload enviado a Factus API:', [
            'payload' => $payload,
            'invoice_id' => $invoice->id,
            'document_number' => $invoice->document,
            'flow' => 'sendToFactusWithRecovery',
        ]);

        try {
            $response = $this->apiService->post('/v1/bills/validate', $payload);

            Log::info('Respuesta de Factus API:', [
                'response' => $response,
                'invoice_id' => $invoice->id,
                'flow' => 'sendToFactusWithRecovery',
            ]);

            $this->applySuccessfulFactusInvoiceResponse($invoice, $payload, $response);
        } catch (FactusApiException $exception) {
            $cleanupContext = null;

            Log::error('Error sending invoice to Factus API', [
                'invoice_id' => $invoice->id,
                'status_code' => $exception->getStatusCode(),
                'error_message' => $exception->getMessage(),
                'error_data' => $exception->getResponseBody(),
                'payload' => $payload,
                'flow' => 'sendToFactusWithRecovery',
            ]);

            if ($this->isPendingInvoiceConflict($exception)) {
                $cleanupContext = $this->cleanupBlockingPendingInvoicesForRange($invoice);

                if (!empty($cleanupContext['deleted_reference_codes'])) {
                    try {
                        $response = $this->apiService->post('/v1/bills/validate', $payload);

                        Log::info('Respuesta de Factus API tras reintento', [
                            'response' => $response,
                            'invoice_id' => $invoice->id,
                            'cleanup_context' => $cleanupContext,
                        ]);

                        $this->applySuccessfulFactusInvoiceResponse($invoice, $payload, $response);

                        return;
                    } catch (FactusApiException $retryException) {
                        $exception = $retryException;
                    }
                }
            }

            if ($exception->getStatusCode() === 422) {
                $cleanupContext = array_merge(
                    $cleanupContext ?? [],
                    $this->cleanupCurrentFactusReferenceAfterValidationError($invoice)
                );
            }

            $this->persistFailedFactusInvoiceAttempt($invoice, $payload, $exception, $cleanupContext);

            $detailedError = "Error en Factus API ({$exception->getStatusCode()}): {$exception->getMessage()}";
            $errorData = $exception->getResponseBody();
            if (is_array($errorData) && isset($errorData['data']['errors'])) {
                $detailedError .= ' | Errores: ' . json_encode($errorData['data']['errors']);
            }

            if (!empty($cleanupContext['deleted_reference_codes'])) {
                $detailedError .= ' | Limpieza automatica: ' . implode(', ', $cleanupContext['deleted_reference_codes']);
            } elseif (!empty($cleanupContext['current_reference_cleanup']['success'])) {
                $detailedError .= ' | Factus dejo un pendiente oculto y fue limpiado automaticamente.';
            }

            throw new \Exception('Error al enviar la factura a Factus: ' . $detailedError);
        } catch (\Exception $exception) {
            Log::error('Error sending invoice to Factus', [
                'invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
                'payload' => $payload,
                'flow' => 'sendToFactusWithRecovery',
            ]);

            throw new \Exception('Error al enviar la factura a Factus: ' . $exception->getMessage());
        }
    }

    private function applySuccessfulFactusInvoiceResponse(ElectronicInvoice $invoice, array $payload, array $response): void
    {
        $status = $this->mapStatusFromResponse($response);

        $updateData = [
            'status' => $status,
            'payload_sent' => $payload,
            'response_dian' => $response,
        ];

        if (isset($response['data']['bill']['id']) && !empty($response['data']['bill']['id'])) {
            $updateData['factus_bill_id'] = (int) $response['data']['bill']['id'];
        }

        if (isset($response['data']['bill']['cufe']) && !empty($response['data']['bill']['cufe'])) {
            $updateData['cufe'] = $response['data']['bill']['cufe'];
        }

        if (isset($response['data']['bill']['qr']) && !empty($response['data']['bill']['qr'])) {
            $updateData['qr'] = $response['data']['bill']['qr'];
        }

        if (isset($response['data']['bill']['number']) && !empty($response['data']['bill']['number'])) {
            $updateData['document'] = $response['data']['bill']['number'];
        }

        if (isset($response['data']['bill']['pdf_url']) && !empty($response['data']['bill']['pdf_url'])) {
            $updateData['pdf_url'] = $response['data']['bill']['pdf_url'];
        }

        if (isset($response['data']['bill']['xml_url']) && !empty($response['data']['bill']['xml_url'])) {
            $updateData['xml_url'] = $response['data']['bill']['xml_url'];
        }

        if (isset($response['data']['bill']['validated_at']) && !empty($response['data']['bill']['validated_at'])) {
            $updateData['validated_at'] = $response['data']['bill']['validated_at'];
        }

        $invoice->update($updateData);

        Log::info('Factura enviada exitosamente a Factus', [
            'invoice_id' => $invoice->id,
            'document' => $updateData['document'] ?? null,
            'status' => $status,
            'response' => $response,
            'flow' => 'sendToFactusWithRecovery',
        ]);
    }

    private function persistFailedFactusInvoiceAttempt(
        ElectronicInvoice $invoice,
        array $payload,
        FactusApiException $exception,
        ?array $cleanupContext = null
    ): void {
        $responseDian = $exception->getResponseBody();
        if (!is_array($responseDian)) {
            $responseDian = [
                'message' => $exception->getMessage(),
            ];
        }

        if ($cleanupContext !== null && $cleanupContext !== []) {
            $responseDian['_cleanup'] = $cleanupContext;
        }

        $invoice->update([
            'status' => $exception->getStatusCode() === 422 ? 'rejected' : 'pending',
            'payload_sent' => $payload,
            'response_dian' => $responseDian,
        ]);
    }

    private function isPendingInvoiceConflict(FactusApiException $exception): bool
    {
        return $exception->getStatusCode() === 409
            && str_contains(mb_strtolower($exception->getMessage()), 'factura pendiente por enviar a la dian');
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupBlockingPendingInvoicesForRange(ElectronicInvoice $invoice): array
    {
        $candidates = ElectronicInvoice::query()
            ->where('id', '!=', $invoice->id)
            ->where('factus_numbering_range_id', $invoice->factus_numbering_range_id)
            ->whereIn('status', ['pending', 'rejected'])
            ->whereNotNull('reference_code')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $attemptedReferenceCodes = [];
        $deletedReferenceCodes = [];

        foreach ($candidates as $candidate) {
            $attemptedReferenceCodes[] = $candidate->reference_code;

            try {
                $response = $this->apiService->deleteBillByReference($candidate->reference_code);

                $candidate->update([
                    'status' => 'cancelled',
                    'response_dian' => array_merge($candidate->response_dian ?? [], [
                        'auto_cleanup_at' => now()->toISOString(),
                        'auto_cleanup_response' => $response,
                    ]),
                ]);

                $deletedReferenceCodes[] = $candidate->reference_code;
            } catch (FactusApiException $cleanupException) {
                if ($cleanupException->getStatusCode() === 404) {
                    $candidate->update([
                        'status' => 'cancelled',
                        'response_dian' => array_merge($candidate->response_dian ?? [], [
                            'auto_cleanup_at' => now()->toISOString(),
                            'auto_cleanup_response' => $cleanupException->getResponseBody(),
                            'auto_cleanup_reason' => 'not_found_in_factus',
                        ]),
                    ]);
                }
            } catch (\Throwable $cleanupException) {
                Log::warning('No se pudo limpiar factura bloqueante en Factus', [
                    'invoice_id' => $candidate->id,
                    'reference_code' => $candidate->reference_code,
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }

        return [
            'attempted_reference_codes' => $attemptedReferenceCodes,
            'deleted_reference_codes' => $deletedReferenceCodes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupCurrentFactusReferenceAfterValidationError(ElectronicInvoice $invoice): array
    {
        if (empty($invoice->reference_code)) {
            return [];
        }

        try {
            $response = $this->apiService->deleteBillByReference($invoice->reference_code);

            return [
                'current_reference_cleanup' => [
                    'success' => true,
                    'reference_code' => $invoice->reference_code,
                    'response' => $response,
                ],
            ];
        } catch (FactusApiException $cleanupException) {
            return [
                'current_reference_cleanup' => [
                    'success' => false,
                    'reference_code' => $invoice->reference_code,
                    'status_code' => $cleanupException->getStatusCode(),
                    'response' => $cleanupException->getResponseBody(),
                    'message' => $cleanupException->getMessage(),
                ],
            ];
        } catch (\Throwable $cleanupException) {
            return [
                'current_reference_cleanup' => [
                    'success' => false,
                    'reference_code' => $invoice->reference_code,
                    'message' => $cleanupException->getMessage(),
                ],
            ];
        }
    }

    private function buildPayload(ElectronicInvoice $invoice, CompanyTaxSetting $company): array
    {
        $customer = $invoice->customer;
        $taxProfile = $customer->taxProfile;
        $identificationDocument = $taxProfile->identificationDocument;

        // Debug logging al principio del método
        Log::info('DV Debug - Inicio buildPayload:', [
            'customer_id' => $customer->id,
            'identification_document_id' => $identificationDocument->id,
            'document_code' => $identificationDocument->code,
            'requires_dv' => $identificationDocument->requires_dv,
            'tax_profile_dv' => $taxProfile->dv,
            'dv_type' => gettype($taxProfile->dv),
            'tax_profile_id' => $taxProfile->id,
        ]);

        // Obtener información de la empresa directamente desde la API de Factus
        try {
            $companyApiResponse = $this->apiService->get('/v1/company');
            $companyData = $companyApiResponse['data'];
            
            Log::info('Company data from Factus API:', [
                'nit' => $companyData['nit'] ?? 'N/A',
                'name' => !empty($companyData['company']) ? $companyData['company'] : trim(($companyData['names'] ?? '') . ' ' . ($companyData['surnames'] ?? '')),
                'address' => $companyData['address'] ?? 'N/A',
                'phone' => $companyData['phone'] ?? 'N/A',
                'email' => $companyData['email'] ?? 'N/A',
                'municipality_code' => $companyData['municipality']['code'] ?? 'N/A',
                'municipality_name' => $companyData['municipality']['name'] ?? 'N/A',
            ]);
            
            // Buscar el factus_id usando el code de la API
            $municipalityCode = $companyData['municipality']['code'] ?? null;
            $municipalityFactusId = null;
            
            if ($municipalityCode) {
                $municipality = \App\Models\DianMunicipality::where('code', $municipalityCode)->first();
                if ($municipality) {
                    $municipalityFactusId = $municipality->factus_id;
                    Log::info('Municipality found:', [
                        'api_code' => $municipalityCode,
                        'local_id' => $municipality->id,
                        'factus_id' => $municipalityFactusId,
                        'name' => $municipality->name,
                    ]);
                } else {
                    Log::warning('Municipality not found with code:', ['code' => $municipalityCode]);
                    // Usar un municipio por defecto que sabemos que funciona
                    $municipalityFactusId = 980;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error getting company data from Factus API', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('No se pudo obtener la información de la empresa desde Factus API: ' . $e->getMessage());
        }

        // Determine names and company according to document type
        $isJuridicalPerson = $identificationDocument->code === 'NIT';
        $customerNames = $isJuridicalPerson 
            ? ($taxProfile->company ?? $customer->name)
            : ($taxProfile->names ?? $customer->name);
        
        // Construir el establecimiento con datos de la API de Factus
        $establishment = [
            'name' => !empty($companyData['company']) 
                ? $companyData['company'] 
                : trim(($companyData['names'] ?? '') . ' ' . ($companyData['surnames'] ?? '')),
            'address' => $companyData['address'] ?? 'Sin dirección',
            'phone_number' => $companyData['phone'] ?? 'Sin teléfono',
            'email' => $companyData['email'],
            'municipality_id' => $municipalityFactusId, // Usar el factus_id encontrado por code
        ];

        // Construir el cliente según la documentación de la API
        $dvValue = null;
        if ($identificationDocument->code === 'NIT' && $taxProfile->dv !== null) {
            // Usar el DV almacenado directamente sin recalcular
            $dvValue = (string)$taxProfile->dv;
            
            Log::info('DV usando valor almacenado:', [
                'identification' => $taxProfile->identification,
                'stored_dv' => $taxProfile->dv,
                'sent_dv' => $dvValue,
            ]);
        }
        
        $customerData = [
            'identification_document_id' => $identificationDocument->id,
            'identification' => $taxProfile->identification,
            'dv' => $dvValue,
            'municipality_id' => $taxProfile->municipality->factus_id, // Usar el factus_id del municipio del cliente
        ];
        
        // Debug logging para DV
        Log::info('DV Debug:', [
            'customer_id' => $customer->id,
            'identification_document_id' => $identificationDocument->id,
            'document_code' => $identificationDocument->code,
            'requires_dv' => $identificationDocument->requires_dv,
            'tax_profile_dv' => $taxProfile->dv,
            'dv_type' => gettype($taxProfile->dv),
            'sent_dv' => $customerData['dv'],
            'sent_dv_type' => gettype($customerData['dv']),
        ]);
        
        // Debug logging completo del customer
        Log::info('Customer Data Completo:', [
            'customer_data' => $customerData,
            'customer_names' => $customerNames,
            'is_juridical_person' => $isJuridicalPerson,
        ]);
        
        // Add names or company according to document type
        if ($isJuridicalPerson) {
            // Para personas jurídicas, siempre enviar 'names' con el nombre de la empresa
            if (!empty($taxProfile->company)) {
                $customerData['names'] = $taxProfile->company;
            } elseif (!empty($customer->name)) {
                $customerData['names'] = $customer->name; // Usar nombre del cliente como razón social
            } else {
                $customerData['names'] = 'EMPRESA SIN NOMBRE'; // Valor por defecto
            }
            
            // Para personas jurídicas, también enviar company si está disponible
            if (!empty($taxProfile->company)) {
                $customerData['company'] = $taxProfile->company;
            } elseif (!empty($customer->name)) {
                $customerData['company'] = $customer->name;
            } else {
                $customerData['company'] = 'EMPRESA SIN NOMBRE'; // Valor por defecto
            }
        } else {
            // Para personas naturales, enviar names
            if (!empty($taxProfile->names)) {
                $customerData['names'] = $taxProfile->names;
            } elseif (!empty($customer->name)) {
                $customerData['names'] = $customer->name;
            } else {
                $customerData['names'] = 'CLIENTE SIN NOMBRE'; // Valor por defecto
            }
        }
        
        // Agregar información de contacto opcional (solo si existe) - del customer, no del taxProfile
        if (!empty($customer->address)) {
            $customerData['address'] = $customer->address;
        }
        if (!empty($customer->email)) {
            $customerData['email'] = $customer->email;
        }
        if (!empty($customer->phone)) {
            $customerData['phone'] = $customer->phone;
        }
        
        // Agregar organización legal y tributo
        if (!empty($taxProfile->legal_organization_id)) {
            $customerData['legal_organization_id'] = $taxProfile->legal_organization_id;
        }
        if (!empty($taxProfile->tribute_id)) {
            $customerData['tribute_id'] = $taxProfile->tribute_id;
        }
        
        // Construir los items del payload
        $items = $invoice->items->map(function($item) {
            // Validar que el nombre del item no esté vacío
            $itemName = trim($item->name);
            if (empty($itemName)) {
                Log::error('Item con nombre vacío:', [
                    'item_id' => $item->id,
                    'name' => $item->name,
                    'invoice_id' => $invoice->id,
                ]);
                throw new \Exception('El nombre del item es obligatorio y no puede estar vacío');
            }
            
            // Usar IDs que sabemos que funcionan según la documentación de Factus
            $itemData = [
                'code_reference' => $item->code_reference,
                'name' => $itemName,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->price,
                'unit_measure_id' => 70, // ID correcto según documentación
                'tax_rate' => number_format($item->tax_rate, 2),
                'tax_amount' => (float) $item->tax_amount,
                'discount_rate' => (float) $item->discount_rate,
                'is_excluded' => $item->is_excluded ? 1 : 0,
                'standard_code_id' => 1, // ID correcto según documentación
                'tribute_id' => 1, // ID correcto según documentación (IVA)
                'total' => (float) $item->total,
            ];

            // Agregar retenciones si existen
            if ($item->withholding_taxes && count($item->withholding_taxes) > 0) {
                $itemData['withholding_taxes'] = $item->withholding_taxes->map(function($withholding) {
                    return [
                        'code' => $withholding->code,
                        'withholding_tax_rate' => (float) $withholding->rate,
                    ];
                })->toArray();
            }

            return $itemData;
        })->toArray();

        // Log para depuración de IDs
        Log::info('IDs usados en el payload:', [
            'establishment_municipality_id' => $company->municipality->factus_id,
            'customer_municipality_id' => $taxProfile->municipality->factus_id,
            'items_unit_measure_id' => $invoice->items->first()->unit_measure_id,
            'items_standard_code_id' => $invoice->items->first()->standard_code_id,
            'items_tribute_id' => $invoice->items->first()->tribute_id,
        ]);

        // Construir el payload final según la documentación
        $payload = [
            'document' => $invoice->documentType->code,
            'numbering_range_id' => $invoice->factus_numbering_range_id,
            'reference_code' => $invoice->reference_code,
            'payment_method_code' => (string) $invoice->payment_method_code,
            'payment_form_code' => (string) $invoice->payment_form_code,
            'operation_type' => $invoice->operationType->code,
            'establishment' => $establishment,
            'customer' => $customerData,
            'items' => $items,
        ];

        $observation = $this->normalizeObservation($invoice->notes);
        if ($observation !== null) {
            $payload['observation'] = $observation;
        }

        // Agregar related_documents solo si el document_type lo requiere (ej. nota crédito)
        if ($invoice->documentType->code !== '01') {
            // Para documentos diferentes a factura, agregar documentos relacionados
            $payload['related_documents'] = [
                [
                    'code' => 'Factura electrónica de venta',
                    'issue_date' => $invoice->created_at->format('Y-m-d'),
                    'number' => $invoice->document,
                ]
            ];
        }

        // Agregar descuentos o recargos si existen
        if ($invoice->allowance_charges && count($invoice->allowance_charges) > 0) {
            $payload['allowance_charges'] = $invoice->allowance_charges->map(function($charge) {
                return [
                    'concept_type' => $charge->concept_type,
                    'is_surcharge' => $charge->is_surcharge,
                    'reason' => $charge->reason,
                    'base_amount' => (float) $charge->base_amount,
                    'amount' => (float) $charge->amount,
                ];
            })->toArray();
        }

        return $payload;
    }

    private function mapStatusFromResponse(array $response): string
    {
        if (isset($response['data']['bill']['status'])) {
            $status = strtolower($response['data']['bill']['status']);
            if (in_array($status, ['accepted', 'rejected', 'pending', 'error'])) {
                return $status;
            }
        }

        if (isset($response['data']['bill']['cufe']) && !empty($response['data']['bill']['cufe'])) {
            return 'accepted';
        }

        return 'pending';
    }

    /**
     * Generate a unique reference code for the invoice.
     */
    private function generateReferenceCode(): string
    {
        return 'INV-' . date('Ymd') . '-' . strtoupper(uniqid());
    }

    private function normalizeInvoiceNotes(null|string $notes): ?string
    {
        $normalized = trim((string) ($notes ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Generate document number using the numbering range.
     */
    private function generateDocumentNumber(\App\Models\FactusNumberingRange $range): string
    {
        return $range->prefix . $range->current;
    }

    /**
     * Elimina una factura no validada de Factus API y la marca como eliminada localmente
     * 
     * @param ElectronicInvoice $invoice
     * @return void
     * @throws \Exception
     */
    public function deleteInvoice(ElectronicInvoice $invoice): void
    {
        // Validaciones previas
        if ($invoice->isAccepted()) {
            throw new \Exception('No se puede eliminar una factura que ya ha sido validada por la DIAN. Las facturas aceptadas no se pueden eliminar.');
        }

        if ($invoice->status === 'deleted') {
            throw new \Exception('La factura ya ha sido eliminada previamente.');
        }

        if (!$invoice->canBeDeleted()) {
            throw new \Exception('Esta factura no se puede eliminar. Solo se pueden eliminar facturas con estado "Pendiente" o "Rechazada" que no hayan sido validadas por la DIAN.');
        }

        if (empty($invoice->reference_code)) {
            throw new \Exception('La factura no tiene código de referencia, no se puede eliminar de Factus API.');
        }

        try {
            Log::info('Intentando eliminar factura de Factus API:', [
                'invoice_id' => $invoice->id,
                'reference_code' => $invoice->reference_code,
                'document' => $invoice->document,
                'status' => $invoice->status,
            ]);

            // Eliminar de Factus API usando el reference_code
            $response = $this->apiService->deleteBillByReference($invoice->reference_code);
            
            Log::info('Factura eliminada exitosamente de Factus API:', [
                'invoice_id' => $invoice->id,
                'reference_code' => $invoice->reference_code,
                'response' => $response,
            ]);

            // Marcar como eliminada localmente
            $invoice->update([
                'status' => 'cancelled', // Usar 'cancelled' en lugar de 'deleted' por limitación de BD
                'response_dian' => array_merge($invoice->response_dian ?? [], [
                    'deleted_at' => now()->toISOString(),
                    'delete_response' => $response,
                    'delete_method' => 'reference_code_api',
                    'original_status' => 'deleted', // Guardar el estado original que se quería
                ])
            ]);

            Log::info('Factura marcada como eliminada en base de datos local:', [
                'invoice_id' => $invoice->id,
                'reference_code' => $invoice->reference_code,
            ]);

        } catch (FactusApiException $e) {
            Log::error('Error al eliminar factura de Factus API:', [
                'invoice_id' => $invoice->id,
                'reference_code' => $invoice->reference_code,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'response_body' => $e->getResponseBody(),
            ]);
            
            // Mensajes específicos según el código de estado
            $userMessage = match($e->getStatusCode()) {
                404 => 'La factura no se encuentra en Factus API. Esto puede ocurrir si la factura nunca fue enviada a Factus o ya fue eliminada previamente. La factura será marcada como eliminada localmente.',
                403 => 'No tiene permisos para eliminar esta factura en Factus API.',
                409 => 'La factura tiene un conflicto y no se puede eliminar en este momento.',
                422 => 'La factura tiene errores de validación y no se puede eliminar.',
                default => 'Error al eliminar la factura de Factus: ' . $e->getMessage(),
            };
            
            // Si es 404, marcar como eliminada localmente de todas formas
            if ($e->getStatusCode() === 404) {
                Log::info('Factura no encontrada en Factus API, marcando como eliminada localmente:', [
                    'invoice_id' => $invoice->id,
                    'reference_code' => $invoice->reference_code,
                ]);
                
                // Marcar como eliminada localmente
                $invoice->update([
                    'status' => 'cancelled', // Usar 'cancelled' en lugar de 'deleted' por limitación de BD
                    'response_dian' => array_merge($invoice->response_dian ?? [], [
                        'deleted_at' => now()->toISOString(),
                        'delete_response' => $e->getResponseBody(),
                        'delete_method' => 'local_only_not_in_factus',
                        'delete_reason' => 'not_found_in_factus_api',
                        'original_status' => 'deleted', // Guardar el estado original que se quería
                    ])
                ]);
                
                // No lanzar excepción, solo informar que se eliminó localmente
                return;
            }
            
            throw new \Exception($userMessage);
        } catch (\Exception $e) {
            Log::error('Error inesperado al eliminar factura:', [
                'invoice_id' => $invoice->id,
                'reference_code' => $invoice->reference_code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new \Exception('Error inesperado al eliminar la factura: ' . $e->getMessage());
        }
    }

    /**
     * Crea facturas pendientes en la base de datos desde códigos de referencia del log
     * 
     * @param array $referenceCodes Array de códigos de referencia
     * @return array Resultados de la creación
     */
    public function createPendingInvoicesFromReferences(array $referenceCodes): array
    {
        $results = [];
        
        foreach ($referenceCodes as $referenceCode) {
            try {
                // Verificar si ya existe
                $existing = ElectronicInvoice::where('reference_code', $referenceCode)->first();
                if ($existing) {
                    $results[$referenceCode] = [
                        'success' => false,
                        'message' => 'La factura ya existe en la base de datos',
                        'invoice_id' => $existing->id
                    ];
                    continue;
                }

                // Crear factura pendiente con datos mínimos
                $invoice = ElectronicInvoice::create([
                    'customer_id' => 4, // Cliente por defecto (JEFERSON ALVAREZ)
                    'factus_numbering_range_id' => 1274, // Rango por defecto
                    'document_type_id' => 1, // Factura electrónica
                    'operation_type_id' => 1, // Estándar
                    'payment_method_code' => '10', // Efectivo
                    'payment_form_code' => '1', // Contado
                    'reference_code' => $referenceCode,
                    'document' => 'TEMP-' . substr($referenceCode, -8), // Documento temporal
                    'status' => 'pending',
                    'gross_value' => 0,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total' => 0,
                    'notes' => 'Factura creada desde log para eliminación - Error API: 409/422',
                ]);

                $results[$referenceCode] = [
                    'success' => true,
                    'message' => 'Factura pendiente creada exitosamente',
                    'invoice_id' => $invoice->id
                ];
                
            } catch (\Exception $e) {
                $results[$referenceCode] = [
                    'success' => false,
                    'message' => 'Error al crear factura: ' . $e->getMessage(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Calcular dígito de verificación para NIT colombiano
     */
    private function validateCustomerTaxProfileForFactus(Customer $customer): void
    {
        $taxProfile = $customer->taxProfile;
        $identificationDocument = $taxProfile?->identificationDocument;

        if (!$taxProfile || !$identificationDocument) {
            throw new \Exception('El cliente no tiene perfil fiscal listo para Factus.');
        }

        if ($identificationDocument->code !== 'NIT') {
            return;
        }

        $expectedDv = $this->calculateDV($taxProfile->identification);
        $currentDv = $taxProfile->dv !== null ? (int) $taxProfile->dv : null;

        if ($currentDv === null) {
            throw new \Exception('El cliente tiene tipo de documento NIT pero no tiene DV configurado.');
        }

        if ($currentDv !== $expectedDv) {
            Log::warning('DV del cliente no coincide con el calculo local, se enviara el valor almacenado para validacion final de Factus.', [
                'customer_id' => $customer->id,
                'identification' => $taxProfile->identification,
                'stored_dv' => $currentDv,
                'calculated_dv' => $expectedDv,
            ]);
        }
    }

    private function calculateDV($nit): int
    {
        // Eliminar espacios y caracteres no numéricos
        $nit = preg_replace('/[^0-9]/', '', $nit);
        
        // Si el NIT está vacío, retornar 0
        if (empty($nit)) {
            return 0;
        }
        
        // Algoritmo para calcular DV de NIT en Colombia
        $weights = [41, 37, 33, 29, 25, 23, 19, 17, 13, 11, 7, 3, 1];
        $sum = 0;
        
        // Recorrer el NIT de derecha a izquierda
        $nitReversed = strrev($nit);
        $length = strlen($nitReversed);
        
        for ($i = 0; $i < $length && $i < 13; $i++) {
            $digit = intval($nitReversed[$i]);
            $sum += $digit * $weights[$i];
        }
        
        // Calcular el módulo
        $mod = $sum % 11;
        
        // Determinar el DV
        if ($mod < 2) {
            return $mod;
        } else {
            return 11 - $mod;
        }
    }

    private function normalizeObservation(?string $observation): ?string
    {
        $normalized = trim((string) ($observation ?? ''));

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, self::FACTUS_OBSERVATION_MAX_LENGTH);
    }
}
