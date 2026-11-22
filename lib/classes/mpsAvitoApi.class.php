<?php

class mpsAvitoApi
{
    public function __construct(private readonly array $auth)
    {
    }

    public function listings(string $accountId, int $page, int $size): array
    {
        if (!self::validId($accountId)) {
            throw new waException(_wd('mps_avito', 'Enter the Avito account ID in the company settings.'), 400);
        }
        $query = http_build_query([
            'page'=>$page, 'per_page'=>$size,
            'status'=>'active,removed,old,blocked,rejected',
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->decode($this->accountRequest($accountId,'/core/v1/items?'.$query));
    }

    protected function credentials(): array
    {
        if (!is_string($this->auth['client_id'] ?? null) || trim($this->auth['client_id']) === ''
            || !is_string($this->auth['client_secret'] ?? null) || trim($this->auth['client_secret']) === '') {
            throw new waException(_wd('mps_avito', 'Enter the Avito Client ID and Client Secret in the site settings.'), 400);
        }

        $cache = $this->tokenCache();
        $credentials = $cache->get();
        if (!is_array($credentials) || ($credentials['expires_at'] ?? 0) <= time()) {
            $token = $this->decode($this->request('/token', null, [
                'grant_type'=>'client_credentials',
                'client_id'=>trim($this->auth['client_id']),
                'client_secret'=>trim($this->auth['client_secret']),
            ]));
            $value = $token['access_token'] ?? null;
            $ttl = filter_var($token['expires_in'] ?? null, FILTER_VALIDATE_INT);
            if (!is_string($value) || !preg_match('/^[!-~]+$/D', $value) || !$ttl || $ttl <= 0
                || !is_string($token['token_type'] ?? null) || strcasecmp($token['token_type'], 'Bearer') !== 0) {
                throw self::invalidResponse();
            }
            $self = $this->decode($this->request('/core/v1/accounts/self', $value));
            $owner = $self['id'] ?? null;
            if (!self::validId($owner)) {
                throw self::invalidResponse();
            }
            $credentials = ['token'=>$value, 'account'=>(string)$owner, 'expires_at'=>time()+max(0,min($ttl,86400)-60)];
            $cache->set($credentials);
        }
        return $credentials;
    }

    protected function accountRequest(string $id, string $path, string $method = 'GET', ?array $payload = null): array
    {
        $credentials = $this->credentials();
        if ($credentials['account'] !== $id) {
            throw new waException(_wd('mps_avito','The Avito account ID does not match these credentials.'),403);
        }
        $body = $payload === null ? null : json_encode($payload,JSON_THROW_ON_ERROR);
        $reply = $this->queue($id, fn () => $method==='GET'
            ? $this->request($path,$credentials['token'])
            : $this->transport($path,$credentials['token'],$method,$body,'application/json'));
        if ($reply[0]===401) {
            $this->tokenCache()->delete();
        }
        return $reply;
    }

    protected function tokenCache(): object
    {
        $key = hash('sha256', json_encode([$this->auth['client_id'], $this->auth['client_secret']], JSON_THROW_ON_ERROR));
        return new waVarExportCache('avito/oauth/'.$key, 86400, 'mps');
    }

    protected function queue(string $accountId, callable $send): array
    {
        return (new mpsAvitoRequestQueue())->request($accountId, $send);
    }

    protected function request(string $path, ?string $token, ?array $form = null): array
    {
        return $this->transport($path,$token,$form===null ? 'GET' : 'POST',
            $form===null ? null : http_build_query($form,'','&',PHP_QUERY_RFC3986),'application/x-www-form-urlencoded');
    }

    protected function transport(string $path, ?string $token, string $method, ?string $body, string $type): array
    {
        $curl = curl_init('https://api.avito.ru'.$path);
        $headers = ['Accept: application/json'];
        $retryAfter = 60;
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: '.$type;
            curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HEADERFUNCTION=>static function ($curl, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $value = trim(substr($line, 12));
                    $delay = ctype_digit($value) ? (int)$value : (int)strtotime($value) - time();
                    $retryAfter = max(1, min(86400, $delay > 0 ? $delay : 60));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if ($body === false) {
            throw new waException(_wd('mps_avito', 'Could not connect to the Avito API.'), 502);
        }
        return [$status, $body, $retryAfter];
    }

    protected function decode(array $response): array
    {
        [$status, $body, $retryAfter] = $response;
        if ($status === 429) {
            throw new mpsRateLimitException($retryAfter);
        }
        if ($status === 401 || $status === 403) {
            throw new waException(_wd('mps_avito', 'Avito rejected the credentials or denied API access.'), $status);
        }
        if ($status !== 200) {
            throw new waException(sprintf(_wd('mps_avito', 'Avito API request failed (HTTP %d).'), $status), 502);
        }
        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($data)) {
            throw self::invalidResponse();
        }
        return $data;
    }

    public static function validId(mixed $id): bool
    {
        return (is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,31}$/D', (string)$id);
    }

    public static function invalidResponse(): waException
    {
        return new waException(_wd('mps_avito', 'Invalid Avito API response.'), 502);
    }
}
