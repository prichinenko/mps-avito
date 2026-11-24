<?php

/**
 * Wraps the marketplace API operations for Avito.
 */
class mpsAvito
{
    /**
     * Check the credentials required by an existing marketplace profile.
     */
    public function authProfile(array $profile): array
    {
        return [
            'client_id'=>$profile['token_default'] ?? '',
            'client_secret'=>$profile['token'] ?? '',
        ];
    }
}
