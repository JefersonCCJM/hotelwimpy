<?php

namespace App\Services;

use App\Exceptions\FactusApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FactusApiService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $tokenEndpoint;

    public function __construct()
    {
        $this->baseUrl = config('factus.api_url');
        $this->clientId = config('factus.client_id');
        $this->clientSecret = config('factus.client_secret');
        $this->username = config('factus.username');
        $this->password = config('factus.password');
        $this->tokenEndpoint = '/oauth/token';

        // Validar que las credenciales estén configuradas
        if (empty($this->baseUrl) || empty($this->clientId) || empty($this->clientSecret) || empty($this->username) || empty($this->password)) {
            throw new FactusApiException('Las credenciales de Factus no están configuradas correctamente. Verifica las variables de entorno.', 500);
        }
    }

    public function getAuthToken(): string
    {
        $tokenData = Cache::get('factus_token_data');
        
        if ($tokenData && isset($tokenData['access_token'])) {
            $expiresAt = $tokenData['expires_at'] ?? null;
            
            if ($expiresAt && now()->lt($expiresAt)) {
                return $tokenData['access_token'];
            }
            
            if (isset($tokenData['refresh_token'])) {
                try {
                    return $this->refreshAccessToken($tokenData['refresh_token']);
                } catch (FactusApiException $e) {
                    Log::warning('Error al renovar token con refresh_token, obteniendo nuevo token', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $this->requestNewAccessToken();
    }

    private function requestNewAccessToken(): string
    {
        $tokenUrl = "{$this->baseUrl}{$this->tokenEndpoint}";
        
        $httpClient = Http::withHeaders([
            'Accept' => 'application/json',
        ]);
        
        if (!config('factus.verify_ssl', true)) {
            $httpClient = $httpClient->withoutVerifying();
        }
        
        $response = $httpClient->asForm()->post($tokenUrl, [
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if (!$response->successful()) {
            $errorBody = $response->json();
            throw new FactusApiException(
                "Error de autenticación con Factus: " . ($errorBody['message'] ?? $response->body()),
                $response->status(),
                $errorBody
            );
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? 600;
        $tokenType = $data['token_type'] ?? 'Bearer';

        if (!$accessToken) {
            throw new FactusApiException('No se recibió access_token de Factus', 500, $data);
        }

        $expiresAt = now()->addSeconds($expiresIn - 60);

        $tokenData = [
            'access_token' => $accessToken,
            'token_type' => $tokenType,
            'expires_at' => $expiresAt,
            'expires_in' => $expiresIn,
        ];

        if ($refreshToken) {
            $tokenData['refresh_token'] = $refreshToken;
        }

        Cache::put('factus_token_data', $tokenData, now()->addSeconds($expiresIn));

        return $accessToken;
    }

    private function refreshAccessToken(string $refreshToken): string
    {
        $httpClient = Http::withHeaders([
            'Accept' => 'application/json',
        ]);
        
        if (!config('factus.verify_ssl', true)) {
            $httpClient = $httpClient->withoutVerifying();
        }
        
        $response = $httpClient->asForm()->post("{$this->baseUrl}{$this->tokenEndpoint}", [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            throw new FactusApiException(
                "Error al renovar token con Factus: " . $response->body(),
                $response->status(),
                $response->json()
            );
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $newRefreshToken = $data['refresh_token'] ?? $refreshToken;
        $expiresIn = $data['expires_in'] ?? 600;
        $tokenType = $data['token_type'] ?? 'Bearer';

        if (!$accessToken) {
            throw new FactusApiException('No se recibió access_token al renovar token', 500, $data);
        }

        $expiresAt = now()->addSeconds($expiresIn - 60);

        $tokenData = [
            'access_token' => $accessToken,
            'token_type' => $tokenType,
            'refresh_token' => $newRefreshToken,
            'expires_at' => $expiresAt,
            'expires_in' => $expiresIn,
        ];

        Cache::put('factus_token_data', $tokenData, now()->addSeconds($expiresIn));

        return $accessToken;
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('get', $endpoint, $params);
    }

    public function post(string $endpoint, array $data): array
    {
        return $this->request('post', $endpoint, $data);
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $token = $this->getAuthToken();

        $httpClient = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
        
        if (!config('factus.verify_ssl', true)) {
            $httpClient = $httpClient->withoutVerifying();
        }

        $url = "{$this->baseUrl}{$endpoint}";
        $response = $method === 'get' 
            ? $httpClient->get($url, $data)
            : $httpClient->post($url, $data);

        if ($response->status() === 401) {
            Cache::forget('factus_token_data');
            $token = $this->getAuthToken();
            
            $httpClient = $httpClient->withHeaders(['Authorization' => "Bearer {$token}"]);
            $response = $method === 'get' 
                ? $httpClient->get($url, $data)
                : $httpClient->post($url, $data);
        }

        if (!$response->successful()) {
            $errorData = $response->json();
            throw new FactusApiException(
                "Error en Factus API ({$method} {$endpoint}): " . ($errorData['message'] ?? $response->body()),
                $response->status(),
                $errorData
            );
        }

        return $response->json();
    }

    /**
     * Obtiene el listado de facturas desde Factus con filtros y paginación
     * 
     * @param array $filters Filtros: identification, names, number, prefix, reference_code, status
     * @param int $page Número de página
     * @param int $perPage Resultados por página (máximo 100 según Factus)
     * @return array Respuesta con facturas y paginación
     * @throws \Exception Si falla la petición
     */
    public function getBills(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $params = [
            'page' => $page,
            'per_page' => min($perPage, 100), // Limitar a 100 según Factus
        ];

        // Agregar filtros
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $params["filter[{$key}]"] = $value;
            }
        }

        $response = $this->get('/v1/bills', $params);

        return $response;
    }

    /**
     * Obtiene el estado de una factura específica desde Factus
     * 
     * @param string $number Número de factura (ej: SETP990000203)
     * @return array|null Datos de la factura o null si no se encuentra
     * @throws \Exception Si falla la petición
     */
    public function getBillByNumber(string $number): ?array
    {
        $response = $this->getBills(['number' => $number], 1, 1);
        
        // La respuesta puede tener estructura: ['data' => ['data' => [...]]] o ['data' => [...]]
        $data = $response['data']['data'] ?? $response['data'] ?? null;
        
        if (!is_array($data) || empty($data)) {
            return null;
        }

        // Buscar la factura exacta por número
        foreach ($data as $bill) {
            if (isset($bill['number']) && $bill['number'] === $number) {
                return $bill;
            }
        }

        return null;
    }

    /**
     * Descarga el PDF de una factura desde Factus
     * 
     * @param string $number Número de factura (ej: SETP990000203)
     * @return array Respuesta con file_name y pdf_base_64_encoded
     * @throws \Exception Si falla la petición
     */
    public function downloadPdf(string $number): array
    {
        $endpoint = "/v1/bills/download-pdf/{$number}";
        $response = $this->get($endpoint);

        if (!isset($response['data'])) {
            throw new \Exception('Respuesta inválida de Factus API al descargar PDF');
        }

        return $response['data'];
    }

    /**
     * Descarga el PDF de una nota crédito desde Factus
     *
     * @param string $number Número de la nota crédito (ej: NC76)
     * @return array Respuesta con file_name y pdf_base_64_encoded
     * @throws \Exception Si falla la petición
     */
    public function downloadCreditNotePdf(string $number): array
    {
        $endpoint = "/v1/credit-notes/download-pdf/{$number}";
        $response = $this->get($endpoint);

        if (!isset($response['data'])) {
            throw new \Exception('Respuesta inválida de Factus API al descargar PDF de nota crédito');
        }

        return $response['data'];
    }

    /**
     * Elimina una factura no validada usando el código de referencia
     * 
     * @param string $referenceCode Código de referencia de la factura
     * @return array Respuesta de la API
     * @throws \Exception Si falla la petición
     */
    public function deleteBillByReference(string $referenceCode): array
    {
        if (empty($referenceCode)) {
            throw new \InvalidArgumentException('El código de referencia no puede estar vacío.');
        }

        $token = $this->getAuthToken();

        $httpClient = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        
        if (!config('factus.verify_ssl', true)) {
            $httpClient = $httpClient->withoutVerifying();
        }

        $url = "{$this->baseUrl}/v1/bills/destroy/reference/{$referenceCode}";
        
        Log::info('Eliminando factura de Factus API:', [
            'reference_code' => $referenceCode,
            'url' => $url,
        ]);

        $response = $httpClient->delete($url);

        if (!$response->successful()) {
            $errorData = $response->json();
            Log::error('Error al eliminar factura en Factus API:', [
                'reference_code' => $referenceCode,
                'status_code' => $response->status(),
                'response' => $errorData,
            ]);
            
            throw new FactusApiException(
                "Error al eliminar factura en Factus: " . ($errorData['message'] ?? $response->body()),
                $response->status(),
                $errorData
            );
        }

        $responseData = $response->json();
        
        Log::info('Factura eliminada exitosamente de Factus API:', [
            'reference_code' => $referenceCode,
            'response' => $responseData,
        ]);

        return $responseData;
    }

    /**
     * Elimina múltiples facturas pendientes por sus códigos de referencia
     * 
     * @param array $referenceCodes Array de códigos de referencia
     * @return array Resultados de la eliminación
     */
    public function deletePendingBills(array $referenceCodes): array
    {
        $results = [];
        
        foreach ($referenceCodes as $referenceCode) {
            try {
                $response = $this->deleteBillByReference($referenceCode);
                $results[$referenceCode] = [
                    'success' => true,
                    'message' => $response['message'] ?? 'Eliminada exitosamente',
                    'response' => $response
                ];
            } catch (\Exception $e) {
                $results[$referenceCode] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
}
