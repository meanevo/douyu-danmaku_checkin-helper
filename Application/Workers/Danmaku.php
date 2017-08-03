<?php

namespace Workers;

use DateTime;
use Swoole\Client;
use Swoole\Http\Client as HttpClient;
use Protocols\Danmaku as DanmakuProtocol;

class Danmaku extends AbstractDouyu {

	protected $addr = 'DANMAKU_ADDR';
	protected $protocol = DanmakuProtocol::class;
	private $groupId = -9999;
	private $gifts = [
		124 => '电竞三丑',
		191 => '100鱼丸',
		192 => '赞',
		193 => '弱鸡',
		194 => '666',
		195 => '飞机',
		196 => '火箭',
		268 => '发财',
		338 => '草莓蛋糕',
		339 => '新手之剑',
		340 => '被剪掉的网线',
		342 => '全场MVP',
		343 => '冠军杯',
		380 => '好人卡',
		479 => '帐篷',
		519 => '呵呵',
		520 => '稳',
		529 => '猫耳',
		530 => '天秀',
		712 => '棒棒哒',
		714 => '怂',
		713 => '辣眼睛',
		750 => '办卡',
		824 => '粉丝荧光棒',
		825 => '嘉年华火箭',
		826 => '嘉年华蛋糕',
		918 => '双马尾',
		924 => '办卡',
	];

	/**
	 * {@inheritdoc}
	 */
	public function onWorkerStart() {
		// Retrieve room info
		$this->getRoomInfo(getenv('ROOM_ID'));
		// Delay auto-connect as fallback due to passive ${addr} setting
		$this->handleAddrFallback(function () {
			$this->connect();
		});
	}

	/**
	 * {@inheritdoc}
	 */
	public function onMessage(Client $client, $message) {
		switch ($type = $message['type']) {
			case 'loginres':
				$this->onAuth($message);
				break;
			case 'chatmsg':
				$this->onDanmaku($message);
				break;
			case 'dgb':
				$this->onGift($message);
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
			'roomid' => getenv('ROOM_ID'),
		]);
	}

	protected function join() {
		$this->logger->notice('Joining {server} with group {groupId}', [
			'server' => $this->addr,
			'groupId' => $this->groupId,
		]);
		$this->send('joingroup', [
			'rid' => getenv('ROOM_ID'),
			'gid' => $this->groupId,
		]);
	}

	public function sendDanmaku(string $text) {
		// Scheduled-sending takes 2nd argument as text payload
		$text = $this->parseElPayload(@func_get_arg(1) ?? $text);
		$this->logger->notice('Sending: ' . $text);
		// Sending danmaku through auth-worker
		$this->process->write(Authentication::class, 'send', 'chatmessage', [
			'content' => $text,
		]);
	}

	/*
	|--------------------------------------------------------------------------
	| Response
	|--------------------------------------------------------------------------
	*/
	protected function onAuth($message) {
		$this->join();
		// Schedule a timer to send danmaku periodically
		$this->scheduleSendDanmaku(
			getenv('SEND_MESSAGE'),
			getenv('SEND_INTERVAL'),
			getenv('SEND_STARTFROM')
		);
	}

	protected function onDanmaku($message) {
		$this->logger->info('{user}: {text}', [
			'user' => $this->getUserString($message),
			'text' => $message['txt'],
		]);
	}

	protected function onGift($message) {
		$giftId = $message['gfid'];
		$this->logger->debug('{user} sent {gift}x{hits}', [
			'user' => $this->getUserString($message),
			'gift' => $this->gifts[$giftId] ?? "unrecognized_gift(${giftId})",
			'hits' => $message['hits'] ?? 1,
		]);
	}

	protected function onRoomInfo($message) {
		$this->logger->info('{live}: {owner} - {name}', [
			'owner' => $message->owner_name,
			'name' => $message->room_name,
			'live' => $message->room_status == 1 ? 'Broadcasting' : 'Offline',
		]);
		foreach ($message->gift as $gift) {
			$this->gifts[$gift->id] = $gift->name;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/
	public function getRoomInfo(int $roomId) {
		extract(parse_url(getenv('ROOMINFO_ADDR')));
		$port = $scheme === 'https' ? 443 : 80;
		$client = new HttpClient($host, $port, $scheme === 'https');
		$client->get("${path}/${roomId}", function ($client) {
			// Room info retrieved
			$this->onRoomInfo(json_decode($client->body)->data);
			$client->close();
		});
	}

	private function handleAddrFallback(callable $callback) {
		$timeout = getenv('RETRY_CONN_INTERVAL');
		swoole_timer_after($timeout * 1000, function () use ($timeout, $callback) {
			if (!$this->client->isConnected()) {
				// Fallback to public API
				$this->logger->warn('{reason}{timeout}, fallback to public API', [
					'reason' => 'Connect call does not arrive',
					'timeout' => " in ${timeout}s",
				]);
				$callback();
			}
		});
	}

	public function setGroupId(int $groupId) {
		$this->groupId = $groupId;
	}

	protected function scheduleSendDanmaku(
		string $payload,
		int $interval,
		string $start = null
	) {
		$payload = getenv('SEND_MESSAGE');
		$interval = getenv('SEND_INTERVAL');
		$runAt = $this->getNextTimestampWithInterval($interval, getenv('SEND_FROM'));
		if (!$runAt) {
			$this->logger->warn('Schedule sending disabled');
			return;
		}
		$distance = $runAt - time();
		// Schedule first auto-sending
		swoole_timer_after($distance * 1000, function () use ($payload, $interval) {
			$this->sendDanmaku(-1, $payload);
			// Schedule the second time and after
			swoole_timer_tick($interval * 1000, [$this, 'sendDanmaku'], $payload);
		});
		$this->logger->notice('Scheduled first sending on {src} (in {dist}s)', [
			'src' => (new DateTime)->setTimestamp($runAt)->format('Y-m-d H:i:s'),
			'dist' => $distance,
		]);
	}

	private function getNextTimestampWithInterval($interval, $startfrom = 'now') {
		if ($interval <= 0 || $interval > 86400) {
			return false;
		}
		$timestamp = (new DateTime($startfrom))->getTimestamp();
		// Calculate next running timestamp distance
		while ($timestamp <= time()) {
			$timestamp += $interval;
		}
		return $timestamp;
	}

	private function parseElPayload($el) {
		return preg_replace_callback('/\${(.*?)}/', function ($matches) {
			return eval("return {$matches[1]};");
		}, $el);
	}

	private function getUserString($message) {
		return sprintf('%s(Lv.%d)', $message['nn'], $message['level'] ?: 0);
	}

}
