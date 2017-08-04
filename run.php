<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once 'env.php';

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

date_default_timezone_set('Asia/Shanghai');

new Class extends AbstractMaster {

	const S_FORMAT = "[%datetime%] > %message%\n";
	const S_FORMAT_VERBOSE = "[%datetime%] > %channel%.%level_name% - %message%\n";

	/**
	 * {@inheritdoc}
	 */
	protected function startAll() {
		if (!filter_var(getenv('RECV_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
			exit(1);
		}
		$this->startOne(Workers\Danmaku::class);
		if (filter_var(getenv('SEND_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
			$this->startOne(Workers\Authentication::class);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	protected function makeLogger($channel) {
		$logger = new Logger($channel);
		$handler = $this->makeLoggerHandler(getenv('LOG_PATH'), getenv('LOG_LEVEL'));
		$logger->pushHandler($handler);
		$logger->pushProcessor(new PsrLogMessageProcessor());
		return $logger;
	}

	private function makeLoggerHandler(string $path, $level) {
		$format = $level === 'DEBUG' ? self::S_FORMAT_VERBOSE : self::S_FORMAT;
		$formatter = new ColoredLineFormatter(null, $format, null, true, true);
		$handler = new StreamHandler($path, $level);
		return $handler->setFormatter($formatter);
	}

};
