<?php

namespace MeanEVO\Douyu\DanmakuIO\Workers;

use Swoole\Client;
use MeanEVO\Swoolient\Workers\AbstractClient;

abstract class AbstractDouyu extends AbstractClient {

	const HEARTBEAT_INTERVAL = 45;

	/**
	 * {@inheritdoc}
	 */
	public function onConnect(Client $client) {
		parent::onConnect($client);
		$this->sendAuth();
		// Schedule a timer to send heartbeat periodically
		$this->scheduleHeartbeat(self::HEARTBEAT_INTERVAL);
	}

	/**
	 * {@inheritdoc}
	 */
	public function onMessage(Client $client, $message) {
		switch ($type = $message['type']) {
			case 'keeplive':
				$this->logger->debug('Kept alive');
				break;
			case 'error':
				// TODO: notification
				$code = intval($message['code']);
				$this->logger->alert('Server sent error: ' . $code);
				switch ($code) {
					case 51:
						// Connection terminated
					case 57:
						// Restrict simultaneous online
						break;
					case 207:
						$this->logger->critical('STK invalid');
						$this->process->exitAll();
						break;
					case 4002:
						$this->logger->critical('Username does not exist');
						$this->process->exitAll();
						break;
					case 4202:
						$this->logger->critical('LTK mismatch');
						$this->process->exitAll();
						break;
					case 4205:
						$this->logger->critical('LTK invalid');
						$this->process->exitAll();
						break;
					case 4207:
						$this->logger->critical('STK mismatch');
						$this->process->exitAll();
						break;
				}
				break;
			default:
				$this->logger->debug('Dropping message {type}', $message);
				break;
		}
	}

	abstract protected function sendAuth();

	protected function scheduleHeartbeat(int $interval) {
		$this->registerOnlineTicker($interval * 1000, function () {
			$this->send('keeplive');
		});
	}

}
