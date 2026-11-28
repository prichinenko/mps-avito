<?php

/** Declares the plugin identity, capabilities and Webasyst hooks. */

return [
    'shipment_check'=>['models'=>['pvz','dbs','rdbs','postamat'], 'statuses'=>['ready_to_ship']],
    'name' => 'Авито',
    'vendor' => 1046725,
    'version' => '1.0',
    'img' => 'img/logo.png',
    'mps_type' => 'marketplace',
];
