<?php

namespace Protocols;

interface ProtocolInterface {

	/**
	 * Decode package and emit onMessage($message) callback, $message is the result that decode returned.
	 *
	 * @param string $buffer
	 * @return mixed
	 */
	public function decode($buffer);

	/**
	 * Encode package brefore sending to client.
	 *
	 * @param mixed $data
	 * @param array|null $extra
	 * @return string
	 */
	public function encode($buffer, $extra = []);

}
