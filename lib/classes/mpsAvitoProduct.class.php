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

    protected function api(): mpsAvitoApi
    {
        $profile = new mpsProfile($this->company['profile_uuid']);
        return new mpsAvitoApi($profile->api_auth);
    }
}
