<?php

namespace MeanEVO\Douyu\DanmakuIO\Protocols;

class Authentication extends AbstractDouyu {

	const VER = 20150929;
	const AVER = 2017073111;

	/**
	 * {@inheritdoc}
	 */
	public function encode($type, $arguments = []) {
		switch ($type) {
			case 'loginreq':
				$arguments += $this->getExtraArgsForLogin();
				break;
			case 'qrl':
				break;
			case 'chatmessage':
				$arguments += $this->getExtraArgsForChat();
				break;
		}
		return parent::encode($type, $arguments);
	}

	protected function getExtraArgsForLogin() {
		return [
			'ct' => 0,
		] + $this->getDeviceValidation();
	}

	protected function getExtraArgsForChat() {
		return [
			'receiver' => 0,
			'col' => 0,
		];
	}

	protected function getDeviceValidation() {
		static $devid;
		$devid = $devid ?: str_replace('-', '', $this->makeUuid4());
		$rt = time();
		// vk algorithm: https://github.com/spacemeowx2/DouyuHTML5Player/blob/master/src/douyu/api.ts#L228
		$vk = md5($rt . "r5*^5;}2#\${XF[h+;'./.Q'1;,-]f'p[" . $devid);
		$ver = self::VER;
		$aver = self::AVER;
		return get_defined_vars();
	}

	protected function makeUuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			// 16 bits for "time_mid"
			mt_rand(0, 0xffff),
			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,
			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,
			// 48 bits for "node"
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff)
		);
	}

}
