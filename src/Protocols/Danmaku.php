<?php

namespace MeanEVO\Douyu\DanmakuIO\Protocols;

class Danmaku extends AbstractDouyu {

	const PASSWORD = 1234567890123456;

	/**
	 * {@inheritdoc}
	 */
	public function encode($type, $arguments = []) {
		switch ($type) {
			case 'loginreq':
				$arguments += $this->getExtraArgsForLogin();
				break;
		}
		return parent::encode($type, $arguments);
	}

	protected function getExtraArgsForLogin() {
		return [
			'password' => self::PASSWORD,
		];
	}

}
