<?php

/**
 * Wraps the operational API requests for Avito.
 */
class mpsAvitoOperationsApi extends mpsAvitoApi
{
    /**
     * Resolve payload for this operation.
     */
    public function payload(string $account, string $path, string $method = 'GET', ?array $payload = null): array
    {
        return $this->decode($this->accountRequest($account,$path,$method,$payload));
    }
}
