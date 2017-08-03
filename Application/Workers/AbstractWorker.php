<?php

namespace Workers;

use Psr\Log\LoggerAwareTrait;

abstract class AbstractWorker {

	use LoggerAwareTrait;

	protected $process;

	public function __construct($process) {
		$this->process = $process;
		$this->listenPipe();
	}

	/**
	 * Post worker initialisation.
	 *
	 * @return null
	 */
	public function onWorkerStart() {
		$this->logger->debug('Worker online');
	}

	private function listenPipe() {
		// Decode message from pipe
		swoole_event_add($this->process->pipe, function ($pipe) {
			$message = $this->process->read();
			$callable = [$this, $message[0]];
			if (!is_callable($callable)) {
				return $this->onPipe($message[0]);
			}
			// Call function named ${message} if exists
			call_user_func($callable, ...array_slice($message, 1));
		});
	}

	/**
	 * Listen on process pipe message received.
	 *
	 * @param string $message The received message
	 * @return null
	 */
	protected function onPipe($message) {
		$this->logger->warn('Pipe-{number} received: {message}', [
			'number' => $this->process->pipe,
			'message' => $message,
		]);
	}

}
