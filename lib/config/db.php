<?php

return [
    'mps_avito_request_limit' => [
        ':options' => ['engine'=>'InnoDB', 'charset'=>'utf8mb4'],
        'account_id' => ['varchar', 32, 'null'=>0],
        'ready_at_ms' => ['bigint', 'unsigned'=>1, 'null'=>0],
        ':keys' => ['PRIMARY'=>['account_id']],
    ],
];
