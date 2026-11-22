<?php

require __DIR__.'/strict.php';

class waException extends Exception {}
class mpsRateLimitException extends waException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Throttled', 429);
    }
}
class mpsEntityBatch
{
    public static array $rows = [];
    public static function save(string $table, array $fields, array $rows): void
    {
        check($table === 'mps_marketplace_product' && $fields === array_keys($rows[0]), 'Incorrect catalog write');
        self::$rows = $rows;
    }
}
function _wd(string $domain, string $message): string { return $message; }
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $call, int $code): void
{
    try {
        $call();
    } catch (waException $error) {
        check($error->getCode() === $code, 'Unexpected API error: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected an API error');
}

foreach (['Avito', 'AvitoApi', 'AvitoProduct', 'AvitoCategory', 'AvitoRequestQueue'] as $class) {
    require dirname(__DIR__).'/lib/classes/mps'.$class.'.class.php';
}

class AvitoCacheFixture
{
    public mixed $value = null;
    public function get(): mixed { return $this->value; }
    public function set(array $value): void { $this->value = $value; }
    public function delete(): void { $this->value = null; }
}

class AvitoApiFixture extends mpsAvitoApi
{
    public array $calls = [];
    public array $queuedAccounts = [];
    public array $listingResponse = [200, '{"resources":[],"meta":{"page":1,"per_page":99}}', 60];

    public function __construct(private readonly AvitoCacheFixture $cache)
    {
        parent::__construct((new mpsAvito())->authProfile(['token_default'=>'fixture-id', 'token'=>'fixture-secret']));
    }

    protected function tokenCache(): object { return $this->cache; }

    protected function queue(string $accountId, callable $send): array
    {
        $this->queuedAccounts[] = $accountId;
        return $send();
    }

    protected function request(string $path, ?string $token, ?array $form = null): array
    {
        $this->calls[] = $path;
        return match ($path) {
            '/token' => self::assertTokenRequest($token, $form),
            '/core/v1/accounts/self' => $token === 'fixture-bearer'
                ? [200, '{"id":42}', 60] : throw new RuntimeException('No bearer'),
            '/core/v1/items?page=1&per_page=99&status=active%2Cremoved%2Cold%2Cblocked%2Crejected',
            '/core/v1/items?page=2&per_page=99&status=active%2Cremoved%2Cold%2Cblocked%2Crejected' => $token === 'fixture-bearer'
                ? $this->listingResponse : throw new RuntimeException('No bearer'),
            default => throw new RuntimeException('Unexpected endpoint '.$path),
        };
    }

    private static function assertTokenRequest(?string $token, ?array $form): array
    {
        check($token === null && $form === [
            'grant_type'=>'client_credentials', 'client_id'=>'fixture-id', 'client_secret'=>'fixture-secret',
        ], 'Invalid OAuth parameters');
        return [200, '{"token_type":"Bearer","access_token":"fixture-bearer","expires_in":3600}', 60];
    }
}

$cache = new AvitoCacheFixture();
$api = new AvitoApiFixture($cache);
rejects(fn() => $api->listings('43', 1, 99), 403);
check($api->calls === ['/token', '/core/v1/accounts/self'] && !$api->queuedAccounts, 'Cross-account read attempted');
$api->listings('42', 1, 99);
check(count($api->calls) === 3 && $api->queuedAccounts === ['42'], 'Token reuse or quota scope failed');
$cache->value['expires_at'] = time()-1;
$api->listings('42', 1, 99);
check(count($api->calls) === 6 && $api->calls[3] === '/token', 'Expired token reused');

$api->listingResponse = [401, 'fixture internal details', 60];
rejects(fn() => $api->listings('42', 1, 99), 401);
check($cache->value === null, 'Revoked bearer remains cached');
$api->listingResponse = [429, '', 85];
try {
    $api->listings('42', 1, 99);
    throw new RuntimeException('429 was not propagated');
} catch (mpsRateLimitException $error) {
    check($error->retryAfter === 85, 'Incorrect retry interval');
}

class AvitoProductFixture extends mpsAvitoProduct
{
    public function __construct(private readonly mpsAvitoApi $provider)
    {
        parent::__construct(['company'=>'42'], ['company_uuid'=>'company-a']);
    }
    protected function api(): mpsAvitoApi { return $this->provider; }
}

$product = new AvitoProductFixture($api);
$listings = [];
$statuses = ['active'=>'active', 'removed'=>'archived', 'old'=>'archived', 'blocked'=>'withdrawn', 'rejected'=>'withdrawn'];
for ($id = 1; $id <= 99; ++$id) {
    $listings[] = [
        'id'=>$id, 'title'=>'Объявление '.$id, 'category'=>['id'=>20, 'name'=>'Товары'],
        'status'=>array_keys($statuses)[($id-1)%count($statuses)], 'price'=>$id === 1 ? null : 350,
        'url'=>'https://www.avito.ru/example',
    ];
}
$api->listingResponse = [200, json_encode(['resources'=>$listings, 'meta'=>['page'=>1, 'per_page'=>99]], JSON_THROW_ON_ERROR), 60];
check($product->sync([]) === ['offers_count'=>99, 'interval'=>2500, 'nextPageToken'=>'2'], 'Incorrect continuation');
foreach (mpsEntityBatch::$rows as $index=>$row) {
    check($row['state'] === $statuses[$row['status_code']] && $row['company_uuid'] === 'company-a'
        && $row['marketplace_product_id'] === (string)($index+1) && $row['marketplace_category_id'] === '20'
        && !array_key_exists('offer_id', $row) && !array_key_exists('sku_id', $row), 'Incorrect listing mapping');
}
check(mpsEntityBatch::$rows[0]['price'] === null && mpsEntityBatch::$rows[1]['currency'] === 'RUB', 'Incorrect nullable price');

$api->listingResponse = [200, '{"resources":[],"meta":{"page":2,"per_page":99}}', 60];
check($product->sync(['page_token'=>'2']) === ['offers_count'=>0, 'interval'=>2500, 'exit'=>1], 'Final page did not stop');
rejects(fn() => $product->sync(['page_token'=>'-1']), 400);
rejects(fn() => $product->sync([]), 502);
$api->listingResponse = [200, '{"resources":[{"id":1,"title":"a","status":"active"},{"id":1,"title":"b","status":"active"}],"meta":{"page":1,"per_page":99}}', 60];
rejects(fn() => $product->sync([]), 502);
check(count(mpsEntityBatch::$rows) === 99, 'Invalid page partially changed the catalog');

class AvitoCategoryFixture extends mpsAvitoCategory
{
    protected function categories(): array
    {
        return self::parseCatalog('<Categories><Category><id>20</id><name>Игрушки</name></Category>
            <Category><id>21</id><name>Книги</name></Category></Categories>');
    }
    public static function parse(string $xml): array { return self::parseCatalog($xml); }
}
$category = new AvitoCategoryFixture();
foreach (['ИГРУ', '20'] as $term) {
    $category->search = $term;
    $category->getTree();
    check(array_column($category->search_result, 'id') === ['20'], 'Category not found');
}
rejects(fn() => AvitoCategoryFixture::parse('<broken'), 502);
echo "Avito offline behavior checks passed.\n";

require __DIR__.'/../lib/classes/mpsAvitoOperationsApi.class.php';
require __DIR__.'/../lib/classes/mpsAvitoOrders.class.php';
$api = new class([]) extends mpsAvitoOperationsApi {
    public array $calls = [];
    public function payload(string $account,string $path,string $method='GET',?array $payload=null): array
    {
        $this->calls[] = [$account,$path,$method,$payload];
        return ['hasMore'=>false,'orders'=>[['id'=>'50000000000000000','marketplaceId'=>'70000000000000001',
            'createdAt'=>'2026-11-18T00:00:00Z','status'=>'ready_to_ship','delivery'=>['serviceType'=>'pvz'],
            'items'=>[['avitoId'=>'4131','id'=>'sku-a','count'=>2,'title'=>'Toy','prices'=>['price'=>420]]]]]];
    }
};
$orders = new class(['company'=>'42'],['company_uuid'=>'company','profile_uuid'=>'profile'],$api) extends mpsAvitoOrders {
    public array $saved = [];
    public function __construct(array $auth,array $company,private mpsAvitoOperationsApi $fixtureApi)
    {
        parent::__construct($auth,$company);
    }
    protected function api(): mpsAvitoOperationsApi { return $this->fixtureApi; }
    protected function save(array $rows,array $items,array $marketplaceIds): void { $this->saved = [$rows,$items,$marketplaceIds]; }
};
check($orders->sync([])===['offers_count'=>1,'interval'=>2500,'exit'=>1], 'Avito order paging failed');
check($orders->saved[0]['50000000000000000']['delivery_model']==='pvz', 'Avito delivery mode was lost');
check($orders->saved[1]['50000000000000000'][0]['price']==='420.0000', 'Avito item price was lost');
check($orders->saved[2]['50000000000000000']==='70000000000000001', 'Avito label order ID was lost');
$from = time()-2*86400;
$orders->sync(['scheduled_from'=>$from]);
parse_str(parse_url($api->calls[1][1],PHP_URL_QUERY),$search);
check($search['dateFrom']===(string)($from-5*86400), 'Avito scheduled orders lost their lookback');
rejects(fn () => $orders->sync(['page_token'=>'not-a-cursor']),400);
echo "Avito order paging, item identity and label order ID OK\n";
