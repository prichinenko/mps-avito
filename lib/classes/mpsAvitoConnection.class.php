<?php

class mpsAvitoConnection extends mpsAvitoApi
{
    public function check(array $scope): array
    {
        $api = !empty($scope['company_uuid']) ? new self((new mpsProfile($scope['profile_uuid']))->api_auth) : $this;
        $id = $api->credentials()['account'];
        if (!empty($scope['company_uuid']) && $id !== (string)$scope['marketplace_company']) {
            throw new waException(_wd('mps_avito','The Avito account ID does not match these credentials.'),403);
        }
        return ['accounts'=>[['id'=>$id,'name'=>$scope['name'] ?? 'Avito','can_add'=>true]],'verified'=>['account']];
    }
}
