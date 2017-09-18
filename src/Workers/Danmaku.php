<?php

namespace MeanEVO\Douyu\DanmakuIO\Workers;

use DateTime;
use GuzzleHttp\Client as HttpClient;
use Swoole\Client;
use Swoole\Timer;
use MeanEVO\Douyu\DanmakuIO\Protocols\Danmaku as DanmakuProtocol;

class Danmaku extends AbstractDouyu {

	const PROTOCOL_CLASS = DanmakuProtocol::class;
	const ROOMINFO_DSN = 'http://open.douyucdn.cn/api/RoomApi/room';

	/**
	 * {@inheritdoc}
	 */
	protected $scheme = SWOOLE_TCP;
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
		if (filter_var(getenv('SEND_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
			// Schedule a timer to send danmaku periodically
			$this->scheduleSendDanmaku(
				getenv('SEND_MESSAGE'),
				getenv('SEND_INTERVAL'),
				getenv('SEND_STARTFROM')
			);
		}
		// Retrieve servers list through Authentication worker at once
		$this->callWorkerFunction(
			[Authentication::class, 'getMessageServer'],
			null,
			'setDestination'
		);
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

	/**
	 * {@inheritdoc}
	 */
	public function connect() {
		if (filter_var(getenv('RECV_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
			return parent::connect();
		}
		// Send only, noop default connect behavior
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
			'roomid' => getenv('ROOM_ID'),
		]);
	}

	protected function sendJoin(int $groupId = -9999) {
		$this->logger->notice('Joining group {id}', [
			'id' => $groupId,
		]);
		$this->send('joingroup', [
			'rid' => getenv('ROOM_ID'),
			'gid' => $groupId,
		]);
	}

	public function sendDanmaku(string $expression) {
		$content = $this->parseElPayload($expression);
		$this->logger->notice('Sending: ' . $content);
		// Sending danmaku through auth-worker
		$this->callWorkerFunction(
			[Authentication::class, 'send'],
			['chatmessage', [ 'content' => $content ]]
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Response
	|--------------------------------------------------------------------------
	*/

	protected function onAuth($message) {
		// Retrieve group id through Authentication worker at once
		$this->callWorkerFunction(
			[Authentication::class, 'getMessageGroupId'],
			null,
			'sendJoin'
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
		$client = new HttpClient();
		$result = $client->request('GET', self::ROOMINFO_DSN . '/' . getenv('ROOM_ID'));
		$this->onRoomInfo(json_decode($result->getBody())->data);
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
		Timer::after($distance * 1000, function () use ($payload, $interval) {
			$this->sendDanmaku($payload);
			$this->registerOnlineTicker(
				$interval * 1000,
				[$this, 'sendDanmaku'],
				[$payload]
			);
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
