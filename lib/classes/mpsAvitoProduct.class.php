<?php

class mpsAvitoProduct
{
    private const PAGE_SIZE = 99;

    public function __construct(private readonly array $auth, private readonly array $company)
    {
    }

    public function sync(array $params): array
    {
        $cursor = $params['page_token'] ?? '';
        $page = $cursor === '' ? 1 : filter_var($cursor, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>100000000]]);
        if ($page === false) {
            throw new waException(_wd('mps', 'Invalid synchronization token'), 400);
        }
        $response = $this->api()->listings((string)($this->auth['company'] ?? ''), $page, self::PAGE_SIZE);
        $listings = $response['resources'] ?? null;
        $meta = $response['meta'] ?? null;
        if (!is_array($listings) || !array_is_list($listings) || count($listings) > self::PAGE_SIZE
            || !is_array($meta) || ($meta['page'] ?? null) !== $page || ($meta['per_page'] ?? null) !== self::PAGE_SIZE) {
            throw mpsAvitoApi::invalidResponse();
        }

        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach ($listings as $listing) {
            $id = $listing['id'] ?? null;
            $title = $listing['title'] ?? null;
            $status = $listing['status'] ?? null;
            $category = $listing['category'] ?? [];
            $price = $listing['price'] ?? null;
            $url = $listing['url'] ?? null;
            if (!mpsAvitoApi::validId($id) || isset($rows[$id]) || !is_string($title) || !is_string($status)
                || !is_array($category) || ($price !== null && (!is_int($price) || $price < 0))) {
                throw mpsAvitoApi::invalidResponse();
            }
            $rows[$id] = [
                'company_uuid'=>$this->company['company_uuid'],
                'marketplace_product_id'=>(string)$id,
                'name'=>mb_substr($title, 0, 512, "UTF-8"),
                'marketplace_category_id'=>mpsAvitoApi::validId($category['id'] ?? null) ? (string)$category['id'] : null,
                'marketplace_category_name'=>is_string($category['name'] ?? null) ? mb_substr($category['name'], 0, 600, "UTF-8") : null,
                'price'=>$price,
                'currency'=>$price === null ? null : 'RUB',
                'url'=>is_string($url) && preg_match('~^https?://(?:[a-z0-9-]+\.)*avito\.ru/~i', $url) ? $url : null,
                'status_code'=>mb_substr($status, 0, 128, "UTF-8"),
                'state'=>match ($status) {
                    'active'=>'active',
                    'old', 'removed'=>'archived',
                    'blocked', 'rejected'=>'withdrawn',
                    default=>'other',
                },
                'sync_datetime'=>$now,
            ];
        }

        if ($rows) {
            mpsEntityBatch::save('mps_marketplace_product', array_keys(reset($rows)), array_values($rows));
        }
        $result = ['offers_count'=>count($rows), 'interval'=>mpsAvitoRequestQueue::INTERVAL_MS];
        return $result + (count($listings) === self::PAGE_SIZE ? ['nextPageToken'=>(string)($page+1)] : ['exit'=>1]);
    }

    public function exportPrice(array $params): array
    {
        $export = $this->exportBatch($params,1);
        $id = array_key_first($export->skus_export);
        $price = $export->getPricesBySkuId($id,['price']);
        if (($price['currency_code'] ?? null)!=='RUB' || (int)round((float)($price['price'] ?? 0))<1) {
            throw new waException(_wd('mps_avito','Set a positive price in rubles for this SKU'),400);
        }
        $offer = $this->listingId($export->skus_export[$id]);
        $reply = $this->operations()->payload((string)$this->auth['company'],
            '/core/v1/items/'.$offer.'/update_price','POST',['price'=>(int)round((float)$price['price'])]);
        if (($reply['result']['success'] ?? null)!==true) {
            throw mpsAvitoApi::invalidResponse();
        }
        return ['offers_count'=>1,'interval'=>mpsAvitoRequestQueue::INTERVAL_MS];
    }

    public function exportStock(array $params): array
    {
        $export = $this->exportBatch($params,100,true);
        $stocks = [];
        foreach ($export->skus_export as $id=>$sku) {
            $idAvito = $this->listingId($sku);
            $data = $export->skus[$id];
            $quantity = $export->getStock($data,['product_name'=>$export->products[$data['product_id']]['name']]);
            $stocks[$idAvito] = ['item_id'=>$idAvito,'quantity'=>min(999999,(int)floor($quantity))];
        }
        $reply = $this->operations()->payload((string)$this->auth['company'],
            '/stock-management/1/stocks','PUT',['stocks'=>array_values($stocks)]);
        $seen = [];
        foreach (($reply['stocks'] ?? []) as $result) {
            $id = $result['item_id'] ?? null;
            if (!mpsAvitoApi::validId($id) || !isset($stocks[$id]) || isset($seen[$id]) || ($result['success'] ?? null)!==true) {
                throw mpsAvitoApi::invalidResponse();
            }
            $seen[$id] = true;
        }
        if (count($seen)!==count($stocks)) {
            throw mpsAvitoApi::invalidResponse();
        }
        return ['offers_count'=>count($stocks),'interval'=>mpsAvitoRequestQueue::INTERVAL_MS];
    }

    private function exportBatch(array $params, int $limit, bool $stock = false): mpsExport
    {
        $export = mpsMarketplaceExport::prepare($this->company,$params,$limit,$stock);
        $links = mpsMarketplaceLinks::requireExactExport($this->company['company_uuid'],$export->skus_export,false);
        foreach ($export->skus_export as $skuId=>&$sku) {
            $sku['marketplace_product_id'] = $links[$skuId]['id'];
        }
        unset($sku);
        return $export;
    }

    private function listingId(array $sku): int
    {
        $id = filter_var($sku['marketplace_product_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id === false) {
            throw new waException(_wd('mps','Resolve the Shop-Script link before exporting'),409);
        }
        return $id;
    }

    protected function operations(): mpsAvitoOperationsApi
    {
        return new mpsAvitoOperationsApi((new mpsProfile($this->company['profile_uuid']))->api_auth);
    }

    protected function api(): mpsAvitoApi
    {
        $profile = new mpsProfile($this->company['profile_uuid']);
        return new mpsAvitoApi($profile->api_auth);
    }
}
