<?php 

namespace Core;

abstract class ModuleBase
{
	protected $size = 512;
	
	protected function send($socket, $signal)
	{
		fputs($socket, Helpers\Str::trim($signal) . PHP_EOL);
		usleep(100000);
		echo '[SEND] ' . $signal . PHP_EOL;
	}
	
	protected function privmsg($socket, $target, $message)
	{
		$this->send($socket, 'PRIVMSG ' . Helpers\Str::trim($target) . ' :' . Helpers\Str::trim($message));
		usleep(100000);
	}
	
	protected function notice($socket, $target, $message)
	{
		$this->send($socket, 'NOTICE ' . Helpers\Str::trim($target) . ' :' . Helpers\Str::trim($message));
		usleep(100000);
	}
	
	protected function getArgs()
	{
		$data = fgets($socket, $this->size);
		print_r($data);
		return $data;
	}
}
