<?php

namespace Protocols;

class Danmaku extends AbstractDouyu {

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
			'password' => 1234567890123456,
		];
	}

}
