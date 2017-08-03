<?php

namespace Protocols;

class Authentication extends AbstractDouyu {

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
				$arguments += $this->getExtraArgsForSend();
				break;
		}
		return parent::encode($type, $arguments);
	}

	protected function getExtraArgsForLogin() {
		return [
			'ct' => 0,
		] + $this->getDeviceValidation();
	}

	protected function getExtraArgsForSend() {
		return [
			'receiver' => 0,
			'col' => 0,
		];
	}

	protected function getDeviceValidation() {
		$devid = getenv('DEVICE_ID');
		$rt = time();
		// vk algorithm: https://github.com/spacemeowx2/DouyuHTML5Player/blob/master/src/douyu/api.ts#L228
		$vk = md5($rt . "r5*^5;}2#\${XF[h+;'./.Q'1;,-]f'p[" . $devid);
		$ver = 20150929;
		$aver = 2017073111;
		return get_defined_vars();
	}

}
