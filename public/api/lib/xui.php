<?php
declare(strict_types=1);

final class XuiClient
{
    private string $host;
    private string $username;
    private string $password;
    private string $token;
    private int $timeout;
    private ?string $cookie = null;

    public function __construct(?array $settings = null)
    {
        $settings ??= JsonStore::read('settings.json', []);
        $this->host = rtrim((string) ($settings['xui_host'] ?? ''), '/');
        $this->username = (string) ($settings['xui_user'] ?? '');
        $this->password = (string) ($settings['xui_pass'] ?? '');
        $this->token = (string) ($settings['xui_token'] ?? '');
        $this->timeout = max(1, min(10, (int) ($settings['xui_timeout'] ?? 10)));

        if (!filter_var($this->host, FILTER_VALIDATE_URL)
            || !in_array(parse_url($this->host, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Invalid 3x-ui host configuration.');
        }
        if ($this->token === '' && ($this->username === '' || $this->password === '')) {
            throw new RuntimeException('3x-ui credentials are not configured.');
        }
    }

    private function logError(string $message, array $context = []): void
    {
        try {
            JsonStore::ensureDirectory();
            $line = json_encode([
                'timestamp' => date(DATE_ATOM),
                'message' => $message,
                'context' => $context
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents(
                JsonStore::directory() . DIRECTORY_SEPARATOR . 'xui_errors.log',
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            error_log($message);
        }
    }

    private function request(string $method, string $path, ?array $payload = null, bool $auth = true, bool $form = false): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required.');
        }

        $url = $this->host . '/' . ltrim($path, '/');
        $lastError = 'Unknown 3x-ui error';
        $lastStatus = 0;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $headers = ['Accept: application/json'];
            if ($payload !== null) {
                $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
            }
            if ($auth && $this->token !== '') {
                $headers[] = 'Authorization: Bearer ' . $this->token;
            }
            if ($this->cookie !== null) {
                $headers[] = 'Cookie: ' . $this->cookie;
            }

            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Cannot initialize cURL.');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ];
            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = $form
                    ? http_build_query($payload)
                    : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            curl_setopt_array($curl, $options);

            $raw = curl_exec($curl);
            $curlError = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            curl_close($curl);
            $lastStatus = $status;

            if ($raw === false) {
                $lastError = $curlError;
                if ($attempt < 2) {
                    usleep(250000 * ($attempt + 1));
                    continue;
                }
                break;
            }

            $headersRaw = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            if (preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/im', $headersRaw, $matches) && $matches[1] !== []) {
                $this->cookie = implode('; ', array_map('trim', $matches[1]));
            }

            if ($status >= 500 && $attempt < 2) {
                $lastError = 'HTTP ' . $status;
                usleep(250000 * ($attempt + 1));
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $lastError = 'HTTP ' . $status . ': ' . substr($body, 0, 500);
                break;
            }
            if (trim($body) === '') {
                return [];
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $lastError = 'Invalid JSON response: ' . $e->getMessage();
                break;
            }
            if (!is_array($decoded)) {
                $lastError = 'Unexpected response format.';
                break;
            }
            if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
                $lastError = (string) ($decoded['msg'] ?? $decoded['message'] ?? '3x-ui rejected request.');
                break;
            }
            if (isset($decoded['success']) && $decoded['success'] === true && isset($decoded['obj']) && !is_array($decoded['obj']) && $decoded['obj'] !== null) {
                return $decoded;
            }
            return $decoded;
        }

        $this->logError($lastError, ['url' => $url, 'method' => $method, 'http_status' => $lastStatus]);
        throw new RuntimeException('3x-ui request failed: ' . $lastError);
    }

    public function login(): bool
    {
        if ($this->token !== '') {
            return true;
        }
        $result = $this->request('POST', 'login', [
            'username' => $this->username,
            'password' => $this->password
        ], false, true);
        if (($result['success'] ?? true) === false) {
            throw new RuntimeException('3x-ui authentication failed.');
        }
        return true;
    }

    private function authenticate(): void
    {
        if ($this->token === '') {
            $this->login();
        }
    }

    public function listInbounds(): array
    {
        $this->authenticate();
        $result = $this->request('GET', 'panel/api/inbounds/list');
        $items = $result['obj'] ?? $result['data'] ?? [];
        return is_array($items) ? $items : [];
    }

    public function getClients(int $inboundId): array
    {
        if ($inboundId < 1) {
            throw new InvalidArgumentException('Invalid inbound ID.');
        }
        $this->authenticate();
        $result = $this->request('GET', 'panel/api/inbounds/get/' . $inboundId);
        $inbound = $result['obj'] ?? $result['data'] ?? null;
        if (!is_array($inbound)) {
            return [];
        }
        $settings = $inbound['settings'] ?? [];
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (!is_array($settings) || !is_array($settings['clients'] ?? null)) {
            return [];
        }
        return $settings['clients'];
    }

    public function getClientByEmail(string $email): ?array
    {
        foreach ($this->listInbounds() as $inbound) {
            $id = (int) ($inbound['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            foreach ($this->getClients($id) as $client) {
                if (isset($client['email']) && hash_equals((string) $client['email'], $email)) {
                    return ['inbound_id' => $id, 'client' => $client];
                }
            }
        }
        return null;
    }

    public function updateClient(int $inboundId, array $clientArray): array
    {
        $uuid = (string) ($clientArray['id'] ?? '');
        if ($inboundId < 1 || $uuid === '') {
            throw new InvalidArgumentException('Invalid client identifier.');
        }
        $this->authenticate();
        return $this->request('POST', 'panel/api/inbounds/updateClient/' . rawurlencode($uuid), [
            'id' => $inboundId,
            'settings' => json_encode(['clients' => [$clientArray]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        ]);
    }

    public function setClientEnabled(int $inboundId, string $uuid, bool $enabled): array
    {
        foreach ($this->getClients($inboundId) as $client) {
            if (isset($client['id']) && hash_equals($uuid, (string) $client['id'])) {
                if ((bool) ($client['enable'] ?? false) === $enabled) {
                    return ['unchanged' => true, 'enable' => $enabled];
                }
                $client['enable'] = $enabled;
                return $this->updateClient($inboundId, $client);
            }
        }
        throw new RuntimeException('3x-ui client not found.');
    }

    public function testConnection(): array
    {
        $this->login();
        $inbounds = $this->listInbounds();
        return ['connected' => true, 'host' => $this->host, 'inbounds_count' => count($inbounds)];
    }
}
