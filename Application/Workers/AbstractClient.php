<?php

namespace Workers;

use Swoole\Client;
use Protocols\ProtocolInterface;

abstract class AbstractClient extends AbstractWorker {

	const DEF_CONN_RETRY_INTERVAL = 30;
	const DEF_SEND_RETRY_INTERVAL = 30;
	const CONN_ARGS = [
		'package_max_length' => 2048000,	// Maximum protocol context length
		'socket_buffer_size' => 1024 * 1024 * 2,	// 2MB buffer
	];
	const RECOVER_ERRNO = [
		50,	// Network is down
		51,	// Network is unreachable
		52,	// Network dropped connection on reset
		54,	// connection reset by peer
		55,	// No buffer space available
		60,	// Connection timed out
		61, 111,// Connection refused
		64,	// Host is down
		65,	// No route to host
	];
	const RESEND_ERRNO = [
		54,	// connection reset by peer
		// 56,	// Socket is already connected
		57,	// Socket is not connected
		// 58,	// Cannot send after socket shutdown
		60,		// Connection timed out
		61, 111,// Connection refused
		64,	// Host is down
		65,	// No route to host
	];

	protected $addr;
	protected $protocol;
	protected $client;

	public function __construct($process) {
		parent::__construct($process);
		$this->addr = filter_var($this->addr, FILTER_VALIDATE_URL) ?
			: filter_var(getenv($this->addr), FILTER_VALIDATE_URL);
		// Initialize protocol if exists
		if (is_subclass_of($this->protocol, ProtocolInterface::class)) {
			$this->protocol = new $this->protocol();
		} else {
			unset($this->protocol);
		}
		$scheme = parse_url($this->addr, PHP_URL_SCHEME);
		$scheme = $scheme === 'udp' ? SWOOLE_SOCK_UDP : (
			$scheme === 'socket' ? SWOOLE_UNIX_DGRAM : SWOOLE_SOCK_TCP
		);
		// Initialize client
		$this->client = new Client($scheme, SWOOLE_SOCK_ASYNC);
		$this->client->set($this->protocol->arguments ?? [] + self::CONN_ARGS);
		$this->client->on("connect", [$this, 'onConnect']);
		$this->client->on("receive", [$this, 'onReceive']);
		$this->client->on("error", [$this, 'onError']);
		$this->client->on("close", [$this, 'onClose']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStart() {
		$this->connect();
	}

	/**
	 * Listen on client connected.
	 *
	 * @param Swoole\Client $client The connected client
	 * @return null
	 */
	public function onConnect(Client $client) {
		$this->logger->debug('Connection established with {dst} via {src}', [
			'dst' => $this->addr,
			'src' => vsprintf('%2$s:%1$d', $this->client->getSockName()),
		]);
	}

	/**
	 * Listen on buffer received.
	 *
	 * @param Swoole\Client $client The connected client
	 * @param string $buffer The received buffer
	 * @return null
	 */
	final public function onReceive(Client $client, $buffer) {
		// Decode buffer
		if (isset($this->protocol)) {
			$buffer = call_user_func([$this->protocol, 'decode'], $buffer);
		}
		$this->onMessage($client, $buffer);
	}

	/**
	 * Listen on message(decoded buffer) received.
	 *
	 * @param Swoole\Client $client The connected client
	 * @param mixed $message The received message
	 * @return null
	 */
	abstract protected function onMessage(Client $client, $message);


	/**
	 * Listen on client error.
	 *
	 * @param Swoole\Client $client The connected client
	 * @return null
	 */
	public function onError(Client $client) {
		if (in_array($client->errCode, self::RECOVER_ERRNO)) {
			// Schedule client reconnecting
			$interval = getenv('RETRY_CONN_INTERVAL') ?: self::DEF_CONN_RETRY_INTERVAL;
			swoole_timer_after($interval * 1000, [$this, 'connect']);
			$this->logger->error(socket_strerror($client->errCode) . '{retry}', [
				'retry' => ", reconnecting in ${interval} seconds",
			]);
		} else {
			$this->logger->error(socket_strerror($client->errCode));
			$this->process->exit($client->errCode);
		}
	}

	/**
	 * Listen on client close.
	 *
	 * @param Swoole\Client $client The connected client
	 * @return null
	 */
	public function onClose(Client $client) {
		if ($client->errCode === -1) {
			// Handled close
			return;
		}
		$this->process->exit($client->errCode ?: 90);
	}

	/**
	 * Send message(pending encode) to endpoint.
	 *
	 * @param mixed ...$message The messages to send(encode)
	 * @param bool $handleResending Whether resending message on failure
	 * @return bool|int
	 */
	protected function send($message) {
		// Encode message if protocol encoder exists
		if (isset($this->protocol)) {
			// Pass multiple arguments as array to encoder
			$message = call_user_func([$this->protocol, 'encode'], ...func_get_args());
		}
		return $this->sendBuffer($message, (bool)func_get_arg(func_num_args() - 1));
	}

	/**
	 * Send/Schedule buffer(encoded message) to endpoint.
	 *
	 * @param string $buffer The buffer to send
	 * @param bool $resending Whether resending message on failure
	 * @return bool|int
	 */
	final public function sendBuffer(string $buffer, bool $resending = true) {
		if ($result = @$this->client->send($buffer)) {
			$this->logger->debug('Buffer sent, length {length}', [
				'length' => strlen($buffer),
				'buffer' => $buffer,
			]);
			return $result;
		}
		if ($resending && in_array($this->client->errCode, self::RESEND_ERRNO)) {
			// Schedule buffer resending
			$interval = getenv('RETRY_SEND_INTERVAL') ?: self::DEF_SEND_RETRY_INTERVAL;
			// TODO: Out of memory(128M) after approx. 550 scheduled resendings
			swoole_timer_after($interval * 1000, [$this, 'sendBuffer'], $buffer);
		}
		$this->logger->error('Send failed: {reason}{retry}', [
			'reason' => socket_strerror($this->client->errCode),
			'retry' => $interval ?? 0 ? ", resending in ${interval} seconds" : null,
		]);
		return false;
	}

	/**
	 * Set the connection destination address.
	 *
	 * @param array|string $addr
	 */
	public function setAddress($addr) {
		if (is_array($addr)) {
			$addr = $addr[mt_rand(0, count($addr) - 1)];
		}
		// Try parsing ${addr} then addr defined in .env
		if ($newAddr = filter_var($addr, FILTER_VALIDATE_URL)) {
			$this->addr = $newAddr;
		}
	}

	/**
	 * Connect to endpoint.
	 *
	 * @return bool
	 */
	public function connect() {
		extract(parse_url($this->addr));
		return $this->client->connect($host ?? $path, $port ?? 0);
	}

	/**
	 * Reconnect to endpoint
	 *
	 * @return bool
	 */
	public function reconnect() {
		if ($this->client->isConnected()) {
			$this->client->errCode = -1;
			$this->client->close();
		}
		$this->connect();
	}

}
