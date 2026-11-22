<?php

class mpsAvitoOperationsApi extends mpsAvitoApi
{
    public function payload(string $account, string $path, string $method = 'GET', ?array $payload = null): array
    {
        return $this->decode($this->accountRequest($account,$path,$method,$payload));
    }
}
