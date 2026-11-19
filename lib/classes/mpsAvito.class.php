<?php

class mpsAvito
{
    public function authProfile(array $profile): array
    {
        return [
            'client_id'=>$profile['token_default'] ?? '',
            'client_secret'=>$profile['token'] ?? '',
        ];
    }
}
