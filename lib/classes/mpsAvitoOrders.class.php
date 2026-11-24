<?php

/**
 * Loads order snapshots and related data for Avito.
 */
class mpsAvitoOrders
{
    /**
     * Initialize the provider client with the supplied credentials and connection scope.
     */
    public function __construct(private readonly array $auth, private readonly array $company)
    {
    }

    /**
     * Resolve the API adapter for this operation.
     */
    protected function api(): mpsAvitoOperationsApi
    {
        return new mpsAvitoOperationsApi((new mpsProfile($this->company['profile_uuid']))->api_auth);
    }

    /**
     * Advance the source snapshot synchronization by one step.
     */
    public function sync(array $params): array
    {
        $cursor = $params['page_token'] ?? '';
        if (!is_string($cursor) || ($cursor!=='' && !preg_match('/^[0-9]{10}:[1-9][0-9]{0,5}$/D',$cursor))) {
            throw new waException(_wd('mps','Invalid synchronization token'),400);
        }
        $from = max(time()-30*86400,(int)($params['scheduled_from'] ?? 0)-5*86400);
        [$since,$page] = $cursor === '' ? [$from,1] : array_map('intval',explode(':',$cursor));
        if ($since>time() || $since<time()-32*86400) {
            throw new waException(_wd('mps','Invalid synchronization token'),400);
        }
        $query = http_build_query(['dateFrom'=>$since,'page'=>$page,'limit'=>20]);
        $reply = $this->api()->payload((string)$this->auth['company'],'/order-management/1/orders?'.$query);
        if (!is_array($reply['orders'] ?? null) || !array_is_list($reply['orders']) || count($reply['orders'])>20
            || !is_bool($reply['hasMore'] ?? null) || ($reply['hasMore'] && !$reply['orders'])) {
            throw mpsAvitoApi::invalidResponse();
        }
        $rows = $items = $marketplaceIds = [];
        foreach ($reply['orders'] as $order) {
            $id = $order['id'] ?? null;
            $status = $order['status'] ?? null;
            if (!mpsAvitoApi::validId($id) || isset($rows[$id]) || !is_string($status) || !preg_match('/^[a-z_]{1,100}$/D',$status)
                || !is_string($order['createdAt'] ?? null) || !is_array($order['items'] ?? null)) {
                throw mpsAvitoApi::invalidResponse();
            }
            $date = (new DateTimeImmutable($order['createdAt']))->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $delivery = $order['delivery']['serviceType'] ?? null;
            $rows[$id] = ['company_uuid'=>$this->company['company_uuid'],'profile_uuid'=>$this->company['profile_uuid'],
                'marketplace_order_id'=>(string)$id,'marketplace_status'=>$status,'currency'=>'RUB',
                'delivery_model'=>in_array($delivery,['pvz','dbs','rdbs','postamat'],true) ? $delivery : null,
                'marketplace_create_date'=>$date->format('Y-m-d'),'marketplace_create_datetime'=>$date->format('Y-m-d H:i:s'),
                'marketplace_sync_datetime'=>date('Y-m-d H:i:s')];
            if (mpsAvitoApi::validId($order['marketplaceId'] ?? null)) {
                $marketplaceIds[$id] = (string)$order['marketplaceId'];
            }
            $items[$id] = [];
            foreach ($order['items'] as $index=>$item) {
                if (!mpsAvitoApi::validId($item['avitoId'] ?? null) || !is_int($item['count'] ?? null) || $item['count']<=0) {
                    throw mpsAvitoApi::invalidResponse();
                }
                $price = $item['prices']['price'] ?? null;
                $items[$id][] = ['source_key'=>$item['avitoId'].':'.$index,'marketplace_product_id'=>(string)$item['avitoId'],
                    'offer_id'=>mb_substr((string)($item['id'] ?? ''),0,255),'quantity'=>$item['count'],
                    'name'=>mb_substr((string)($item['title'] ?? ''),0,512),'currency'=>'RUB',
                    'price'=>is_numeric($price) && (float)$price>=0 ? number_format((float)$price,4,'.','') : null];
            }
        }
        if ($rows) {
            $this->save($rows,$items,$marketplaceIds);
        }
        return ['offers_count'=>count($rows),'interval'=>mpsAvitoRequestQueue::INTERVAL_MS]
            + ($reply['hasMore'] ? ['nextPageToken'=>$since.':'.($page+1)] : ['exit'=>1]);
    }

    /**
     * Resolve save for this operation.
     */
    protected function save(array $rows, array $items, array $marketplaceIds): void
    {
        $model = new waModel();
        $model->exec('START TRANSACTION');
        try {
            mpsEntityBatch::save('mps_order',array_keys(reset($rows)),array_values($rows));
            $stored = $model->query('SELECT uuid,marketplace_order_id FROM mps_order
                WHERE company_uuid=s:company AND marketplace_order_id IN (s:orders)',
                ['company'=>$this->company['company_uuid'],'orders'=>array_keys($rows)])->fetchAll('marketplace_order_id');
            $parameters = [];
            foreach ($stored as $id=>$row) {
                mpsOrderItems::replace($row['uuid'],$items[$id]);
                if (isset($marketplaceIds[$id])) {
                    $parameters[] = ['mps_order_uuid'=>$row['uuid'],'name'=>'avito_marketplace_id','value'=>$marketplaceIds[$id]];
                }
            }
            if ($parameters) {
                mpsEntityBatch::save('mps_order_params',array_keys($parameters[0]),$parameters);
            }
            $model->exec('COMMIT');
        } catch (Throwable $error) {
            $model->exec('ROLLBACK');
            throw $error;
        }
    }
}
