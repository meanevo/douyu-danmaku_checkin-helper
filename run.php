<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use MeanEVO\Swoolient\Helpers\LoggerFactory;
use MeanEVO\Swoolient\Workers\AbstractMaster;
use MeanEVO\Douyu\DanmakuIO\Workers\Authentication;
use MeanEVO\Douyu\DanmakuIO\Workers\Danmaku;

(new Dotenv(__DIR__, $GLOBALS['argv'][1] ?? null))->load();
date_default_timezone_set('Asia/Shanghai');

new Class extends AbstractMaster {

	public function __construct() {
		$this->setLogger(LoggerFactory::create('Master'));
		parent::__construct();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function startAll() {
		return [
			$this->startOne(Authentication::class),
			$this->startOne(Danmaku::class),
		];
	}

};
