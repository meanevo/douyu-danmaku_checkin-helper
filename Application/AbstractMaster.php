<?php

use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

abstract class AbstractMaster {

	use LoggerAwareTrait;

	const DEF_PROC_RETRY_WAIT = 3;

	protected $workers;

	public function __construct() {
		$this->setLogger($this->makeLogger('MASTER'));
		@cli_set_process_title(getenv('APP_NAME') . ':MASTER');
		try {
			$this->startAll();
			$this->daemon();
		} catch (\Exception $e) {
			$this->logger->emergency($e);
		}
	}

	/**
	 * Initialize logger for master, worker process.
	 *
	 * @param string $name The logger channel
	 * @return Psr\Log\LoggerInterface
	 */
	protected function makeLogger($name) {
		echo 'No logger defined' . PHP_EOL;
		return new NullLogger();
	}

	/**
	 * Start worker by fully qualified class name.
	 *
	 * @param string $fqn The worker class's fully qualified name
	 * @return Process
	 */
	public function startOne($fqn) {
		$process = new Process($fqn);
		$process->start();
		$this->forwardPipe($process);
		$this->postWorkerStart($process);
		$this->workers[$process->pid] = $process;
	}

	/**
	 * Start all workers.
	 * Normally by calling startOne($fqn) several times.
	 *
	 * @return null
	 */
	abstract protected function startAll();

	/**
	 * Post work after worker process initialized.
	 *
	 * @param Process $process The initialized worker process
	 * @return null
	 */
	protected function postWorkerStart($process) {
		$process->write('setLogger', $this->makeLogger($process->name));
		$process->write('onWorkerStart');
	}

	private function forwardPipe($process) {
		// Set a message listener for pipe
		swoole_event_add($process->pipe, function ($pipe) use ($process) {
			// Strip out worker name, then forward message to the worker involved
			$message = $process->read();
			if ($worker = $this->getWorkerByName($message[0])) {
				$this->logger->debug('Forwarding message {1} to {0}', $message);
				$worker->write(...array_slice($message, 1));
			} else {
				$this->logger->warn('{0} is not a valid message destination', $message);
			}
		});
	}

	private function postWorkerStop($process) {
		// Remove exited worker handler
		swoole_event_del($process->pipe);
		unset($this->workers[$process->pid]);
	}

	private function daemon() {
		// Daemon child workers, restart process if needed
		Process::signal(SIGCHLD, function ($signo) {
			while ($info = Process::wait(false)) {
				// $info = ['code' => 0, 'pid' => 15001, 'signal' => 15]
				extract($info);
				$worker = $this->workers[$pid];
				$this->postWorkerStop($worker);
				$reason = $code === 0 ? $signal === 0 ? null
					: "signal ${signal}" : "code ${code}";
				if (!$reason) {
					// Worker initiated exit aka graceful shutdown
					$this->logger->notice('Worker-{name}({pid}) exited gracefully', [
						'name' => $worker->name,
						'pid' => $pid,
					]);
					break;
				}
				// Schedule process restarting
				$timeout = getenv('RETRY_PROCESS_WAIT') ?: self::DEF_PROC_RETRY_WAIT;
				$this->logger->critical('Worker-{name}({pid}) exited by {reason}{retry}', [
					'name' => $worker->name,
					'pid' => $pid,
					'reason' => $reason,
					'retry' => ", restarting in ${timeout} seconds",
				]);
				swoole_timer_after($timeout * 1000, [$this, 'startOne'], $worker->fqn);
			}
		});
	}

	private function getWorkerByName(string $name) {
		foreach ($this->workers as $pid => $worker) {
			if ($name !== $worker->fqn) {
				continue;
			}
			return $worker;
		}
	}

}
