<?php

namespace Workers;

use Swoole\Client;
use Protocols\Authentication as AuthenticationProtocol;

class Authentication extends AbstractDouyu {

	protected $addr = 'AUTH_ADDR';
	protected $protocol = AuthenticationProtocol::class;

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
			case 'initcl':
				$this->onInitDanmaku();
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
	protected function auth() {
		$this->send('loginreq', [
			'username' => getenv('AUTH_UID'),
			'password' => '',
			'roomid' => getenv('ROOM_ID'),
			'biz' => 1,
			'ltkid' => getenv('AUTH_LTKID'),
			'stk' => getenv('AUTH_STK'),
		]);
	}

	public function qrl() {
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
			// $this->qrl();
		} else {
			$this->logger->error('Login failed');
			// TODO: login failed logic
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
			$this->logger->debug('Retrieved danmaku servers: ' . implode(', ', $servers));
			$this->process->write(Danmaku::class, 'setAddress', $servers);
		}
	}

	protected function onMessageGroup($message) {
		if (!$groupId = $message['gid']) {
			return;
		}
		$this->logger->debug('Retrieved danmaku group: ' . $groupId);
		$this->process->write(Danmaku::class, 'setGroupId', $groupId);
	}

	protected function onInitDanmaku() {
		$this->process->write(Danmaku::class, 'reconnect');
	}

}
