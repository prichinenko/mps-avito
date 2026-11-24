<?php

/**
 * Registers the Avito integration and its Webasyst hooks.
 */
class mpsAvitoPlugin extends mpsPlugin
{
    /**
     * Return shipment models supported for order filtering.
     */
    public static function getOrderDeliveryModels(): array
    {
        return ['pvz','dbs','rdbs','postamat'];
    }

    /**
     * Return supported marketplace order statuses and their labels.
     */
    public static function getOrderStates(): array
    {
        return array_map(static fn ($label) => ['label'=>_wd('mps_avito',$label)], [
            'on_confirmation'=>'Awaiting confirmation','ready_to_ship'=>'Ready to ship','in_transit'=>'In transit',
            'canceled'=>'Cancelled','delivered'=>'Delivered','on_return'=>'On return','in_dispute'=>'Disputed','closed'=>'Closed',
        ]);
    }
}
