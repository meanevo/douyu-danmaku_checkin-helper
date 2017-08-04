<?php

use Dotenv\Dotenv;

$env = new Dotenv(__DIR__);
$env->load();

$env->required('LOG_LEVEL')->allowedValues([
	'DEBUG',
	'INFO',
	'NOTICE',
	'WARNING',
	'ERROR',
	'EMERGENCY',
	'ALERT',
]);
$env->required([
	'AUTH_ADDR',
	'DANMAKU_ADDR',
	'ROOMINFO_ADDR',
	'HEARTBEAT_INTERVAL',
	'DEVICE_ID',
])->notEmpty();
$env->required([
	'RECV_ENABLED',
	'SEND_ENABLED',
])->allowedValues(['true', 'false']);
$env->required([
	'RETRY_PROCESS_WAIT',
	'RETRY_CONN_INTERVAL',
	'RETRY_SEND_INTERVAL',
	'SEND_INTERVAL',
])->isInteger();
$env->required('ROOM_ID')->isInteger();
