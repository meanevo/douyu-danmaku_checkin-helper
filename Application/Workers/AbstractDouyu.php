<?php

namespace Workers;

use Swoole\Client;

abstract class AbstractDouyu extends AbstractClient {

	/**
	 * {@inheritdoc}
	 */
	public function onConnect(Client $client) {
		parent::onConnect($client);
		$this->auth();
		// Schedule a timer to send heartbeat periodically
		$this->scheduleHeartbeat();
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
				$this->logger->alert('Server sent error: {code}', $message);
				break;
			default:
				$this->logger->debug('Dropping message {type}', $message);
				break;
		}
	}

	protected function scheduleHeartbeat() {
		swoole_timer_tick(getenv('HEARTBEAT_INTERVAL') * 1000, function () {
			$this->send('keeplive');
		});
	}

	abstract protected function auth();

}
