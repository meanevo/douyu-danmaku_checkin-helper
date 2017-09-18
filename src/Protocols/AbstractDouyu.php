<?php

namespace MeanEVO\Douyu\DanmakuIO\Protocols;

use MeanEVO\Swoolient\Protocols\ProtocolInterface;

abstract class AbstractDouyu implements ProtocolInterface {

	const LEN_FLAG = 'V';	// Pack length to 4 bytes, little endian
	const LEN_OFFSET = 4;	// Read length from package header
	const PAYLOAD_OFFSET = 4;	// Header (4 + 2 + 2) + Payload
	const PAYLOAD_EOF = '\0';
	const T_FLAG = 'v';		// Pack message type to 2 bytes, little endian
	const T_MESSAGE_SEND = 0x02b1;
	const T_MESSAGE_RECV = 0x02b2;

	public $arguments = [
		// 'open_eof_check' => true,
		// 'package_eof' => self::PAYLOAD_EOF,
		'open_length_check' => true,
		'package_length_type' => self::LEN_FLAG,
		'package_length_offset' => self::LEN_OFFSET,
		'package_body_offset' => self::PAYLOAD_OFFSET,
	];

	/**
	 * {@inheritdoc}
	 */
	public function decode($buffer) {
		$payload = substr($buffer, self::PAYLOAD_OFFSET * 2 + 4, -2);
		// TODO: unpack byte 8, 9 to verify T_MESSAGE_RECV
		$arguments = [];
		foreach (explode('/', $payload) as $argument) {
			@list($key, $value) = explode('@=', $argument);
			$arguments[$key] = $value ?: null;
		}
		return $arguments;
	}


	/**
	 * {@inheritdoc}
	 */
	public function encode($_) {
		list($type, $arguments) = func_get_args();
		$query = "type@={$type}/";
		switch ($type) {
			case 'keeplive':
				$arguments += $this->getExtraArgsForHeartbeat();
				break;
		}
		// Concat arguments
		foreach ($arguments as $key => $value) {
			$query .= "${key}@=${value}/";
		}
		// Encode message
		$payload = $query . pack('C', self::PAYLOAD_EOF);
		$length = pack(self::LEN_FLAG, strlen($payload) + 8);	// Length (4 bytes)
		$header = $length	// Length duplicate (4 bytes)
			. pack(self::T_FLAG, self::T_MESSAGE_SEND)	// Type (2 bytes)
			. pack('C', null)	// Encryption (1 byte)
			. pack('C', null);	// Reserved (1 byte)
		return $length . $header . $payload;
	}

	protected function getExtraArgsForHeartbeat() {
		return [
			'tick' => time(),
		];
	}

}
