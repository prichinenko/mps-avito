<?php

class mpsAvitoPlugin extends mpsPlugin
{
    public static function getOrderDeliveryModels(): array
    {
        return ['pvz','dbs','rdbs','postamat'];
    }

    public static function getOrderStates(): array
    {
        return array_map(static fn ($label) => ['label'=>_wd('mps_avito',$label)], [
            'on_confirmation'=>'Awaiting confirmation','ready_to_ship'=>'Ready to ship','in_transit'=>'In transit',
            'canceled'=>'Cancelled','delivered'=>'Delivered','on_return'=>'On return','in_dispute'=>'Disputed','closed'=>'Closed',
        ]);
    }
}
