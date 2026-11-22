<?php

class mpsAvitoShipment extends mpsAvitoApi
{
    public static function shipmentActions(array $order): array
    {
        return $order['delivery_model']==='pvz' && in_array($order['marketplace_status'],['ready_to_ship','in_transit'],true)
            ? ['label'] : [];
    }

    public function shipment(string $action, array $order, array $company, array $input): array
    {
        $id = (new waModel())->query("SELECT value FROM mps_order_params WHERE mps_order_uuid=s:uuid AND name='avito_marketplace_id'",
            ['uuid'=>$order['uuid']])->fetchField('value');
        if (!self::validId($id)) {
            throw new waException(_wd('mps','Load order items from the marketplace first'),409);
        }
        $auth = (new mpsProfile($company['profile_uuid']))->api_auth;
        return (new self($auth))->label($company['marketplace_company'],(string)$id,$order['uuid']);
    }

    protected function label(string $account, string $id, string $uuid): array
    {
        $base = '/order-management/1/orders/labels';
        $cache = new waVarExportCache('avito/labels/'.$uuid,900,'mps');
        $task = $cache->isCached() ? $cache->get() : null;
        if (!is_string($task) || $task==='') {
            $result = $this->decode($this->accountRequest($account,$base,'POST',['orderIDs'=>[$id]]));
            $task = $result['taskID'] ?? null;
            if (!is_string($task) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$task)) {
                throw self::invalidResponse();
            }
            $cache->set($task);
            return ['pending'=>true];
        }
        [$status,$body,$retry] = $this->accountRequest($account,$base.'/'.rawurlencode($task).'/download');
        if ($status===404) {
            return ['pending'=>true];
        }
        if ($status===429) {
            throw new mpsRateLimitException($retry);
        }
        if ($status!==200) {
            throw new waException(sprintf(_wd('mps_avito','Avito API request failed (HTTP %d).'),$status),502);
        }
        return ['content'=>$body,'type'=>'pdf'];
    }
}
