<?php

class mpsAvitoCategory
{
    public string $search = '';
    public array $search_result = [];

    public function getTree(): void
    {
        $term = trim($this->search);
        $this->search_result = [];
        foreach ($this->categories() as $id=>$name) {
            if ($term === '' || (string)$id === $term || mb_stripos($name, $term, 0, 'UTF-8') !== false) {
                $this->search_result[] = ['id'=>(string)$id, 'name'=>$name, 'label'=>$name, 'value'=>$name];
            }
            if (count($this->search_result) >= 50) {
                break;
            }
        }
    }

    protected function categories(): array
    {
        $cache = new waVarExportCache('avito/catalog-categories', 86400, 'mps');
        $categories = $cache->get();
        if (is_array($categories) && $categories) {
            return $categories;
        }

        $curl = curl_init('https://www.avito.st/s/openapi/catalog-categories.xml');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if (!is_string($body) || $status !== 200) {
            throw new waException(_wd('mps_avito', 'Could not load the Avito category catalog.'), 502);
        }
        $categories = self::parseCatalog($body);
        $cache->set($categories);
        return $categories;
    }

    protected static function parseCatalog(string $body): array
    {
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false || $xml->getName() !== 'Categories') {
            throw mpsAvitoApi::invalidResponse();
        }

        $categories = [];
        foreach ($xml->Category as $entry) {
            $id = (string)$entry->id;
            $name = trim((string)$entry->name);
            if (mpsAvitoApi::validId($id) && $name !== '') {
                $categories[$id] = $name;
            }
        }
        if (!$categories) {
            throw mpsAvitoApi::invalidResponse();
        }
        return $categories;
    }
}
