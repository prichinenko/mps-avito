<?php

/**
 * Coordinates authenticated Avito requests and provider rate limits.
 */
class mpsAvitoRequestQueue
{
    public const INTERVAL_MS = 2500;

    /**
     * Perform the prepared provider API request.
     */
    public function request(string $accountId, callable $send): array
    {
        $db = new waModel();
        $lock = 'mps.avito.items.'.$accountId;
        $locked = $db->query('SELECT GET_LOCK(s:name, 0) AS acquired', ['name'=>$lock])->fetchField('acquired');
        if ((int)$locked !== 1) {
            throw new mpsRateLimitException(3, true);
        }
        try {
            $remaining = (int)$db->query('SELECT CEIL(ready_at_ms - UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000) AS remaining
                FROM mps_avito_request_limit WHERE account_id=s:account', ['account'=>$accountId])->fetchField('remaining');
            if ($remaining > 0) {
                throw new mpsRateLimitException((int)ceil($remaining / 1000), true);
            }
            $this->reserve($db, $accountId, self::INTERVAL_MS);
            $response = $send();
            if ($response[0] === 429) {
                $this->reserve($db, $accountId, $response[2] * 1000);
            }
            return $response;
        } finally {
            $db->query('SELECT RELEASE_LOCK(s:name)', ['name'=>$lock]);
        }
    }

    /**
     * Reserve for the current scope.
     */
    private function reserve(waModel $db, string $accountId, int $milliseconds): void
    {
        $db->exec('INSERT INTO mps_avito_request_limit (account_id, ready_at_ms)
            VALUES (s:account, CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000 AS UNSIGNED)+i:delay)
            ON DUPLICATE KEY UPDATE ready_at_ms=GREATEST(ready_at_ms,VALUES(ready_at_ms))',
            ['account'=>$accountId, 'delay'=>$milliseconds]);
    }
}
