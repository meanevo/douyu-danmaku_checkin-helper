<?php

namespace MeanEVO\Douyu\DanmakuIO\Workers;

use GuzzleHttp\Client as HttpClient;
use Swoole\Client;
use MeanEVO\Douyu\DanmakuIO\Protocols\Authentication as AuthenticationProtocol;

class Authentication extends AbstractDouyu {

	const PROTOCOL_CLASS = AuthenticationProtocol::class;

	/**
	 * {@inheritdoc}
	 */
	public function setDestination($_) {
		// Get auth server list at once
		$dsn = $this->getAuthServer();
		return parent::setDestination($dsn);
	}

	/**
	 * {@inheritdoc}
	 */
	public function onMessage(Client $client, $message) {
		switch ($type = $message['type']) {
			case 'loginres':
				$this->onAuth($message);
				break;
			case 'msgrepeaterlist':
				$this->onMessageServer($message);
				break;
			case 'setmsggroup':
				$this->onMessageGroup($message);
				break;
			default:
				parent::onMessage($client, $message);
				break;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Request
	|--------------------------------------------------------------------------
	*/

	/**
	 * {@inheritdoc}
	 */
	protected function sendAuth() {
		$this->send('loginreq', [
			'username' => getenv('AUTH_USERNAME'),
			'password' => '',
			'roomid' => getenv('ROOM_ID'),
			'biz' => 1,
			'ltkid' => getenv('AUTH_LTKID'),
			'stk' => getenv('AUTH_STK'),
		]);
	}

	public function sendQrl() {
		$this->send('qrl', [
			'rid' => getenv('ROOM_ID'),
		]);
	}

	/*
	|--------------------------------------------------------------------------
	| Response
	|--------------------------------------------------------------------------
	*/

	protected function onAuth($message) {
		if ($nickname = $message['nickname']) {
			$this->logger->notice('Logged in as ' . $nickname);
			// $this->sendQrl();
		} else {
			$this->logger->error('Login failed');
			$this->process->exitAll();
		}
	}

	protected function onMessageServer($message) {
		if (!$list = $message['list']) {
			return;
		}
		if (preg_match_all('/ip@AA=(.+?)@ASport@AA=(\d+)@AS/', $list, $matches)) {
			$servers = [];
			foreach ($matches[1] as $key => $host) {
				$servers[] = "tcp://${host}:{$matches[2][$key]}";
			}
			$this->logger->debug('Retrieved danmaku servers: {dsn}', [
				'dsn' => implode(', ', $servers)
			]);
			$this->servers = $servers;
		}
	}

	protected function onMessageGroup($message) {
		if (!$groupId = $message['gid']) {
			return;
		}
		$this->logger->debug('Retrieved danmaku group: {id}', [
			'id' => $groupId,
		]);
		$this->groupId = $groupId;
	}

	/*
	|--------------------------------------------------------------------------
	| Response
	|--------------------------------------------------------------------------
	*/

	public function getMessageServer() {
		if ($servers = $this->servers ?? false) {
			$this->callWorkerFunction([Danmaku::class, 'setDestination'], [$servers]);
			$this->callWorkerFunction([Danmaku::class, 'connect']);
		}
	}

	public function getMessageGroupId() {
		if ($groupId = $this->groupId ?? false) {
			$this->callWorkerFunction([Danmaku::class, 'sendJoin'], [$groupId]);
		}
	}

	protected function getAuthServer() {
		$client = new HttpClient();
		$result = $client->request('GET', 'https://www.douyu.com/' . getenv('ROOM_ID'));
		if (preg_match('/\$ROOM\.args = (.*?);/', $result->getBody(), $matches)) {
			$args = $matches[1];
			if ($payload = urldecode(json_decode($args)->server_config)) {
				$servers = [];
				foreach (json_decode($payload, true) as $server) {
					$servers[] = sprintf('tcp://%s:%d', $server['ip'], $server['port']);
				}
				return $servers;
			}
		}
	}

}
