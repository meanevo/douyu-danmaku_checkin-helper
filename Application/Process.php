<?php

use Swoole\Serialize;

class Process extends \Swoole\Process {

	/**
	 * The process payload's fully qualified name.
	 * @var string
	 */
	public $fqn;

	/**
	 * The process payloads' short name.
	 * @var string
	 */
	public $name;

	public function __construct(string $fqn, $redirect = false, $pipe = 2) {
		$this->fqn = $fqn;
		$class = new \ReflectionClass($fqn);
		$this->name = $class->getShortName();
		@cli_set_process_title(getenv('APP_NAME') . ':' . strtoupper($this->name));
		// $arguments = array_slice(func_get_args(), 3);
		// return parent::__construct(function ($process) use ($class, $arguments) {
		// 	new $class($process, ...$arguments);
		// }, $redirect, $pipe);
		return parent::__construct([$class, 'newInstance'], $redirect, $pipe);
	}

	/**
	 * {@inheritdoc}
	 */
	public function read($size = 2048) {
		$message = parent::read($size);
		// TODO: get rid of serialisation
		return Serialize::unpack($message) ?: $message;
	}

	/**
	 * {@inheritdoc}
	 */
	public function write($data) {
		// Stream may got merged in underlayer => usleep(10) or $pipe => 2;
		return parent::write(Serialize::pack(func_get_args(), true));
	}

}
