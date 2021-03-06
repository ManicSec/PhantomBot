<?php

namespace Core;
use Modules;

$path = str_replace('\\', '/', str_replace(array('Library/Core/PhantomCore.class.php', 'Library\Core\PhantomCore.class.php'), '', __FILE__));

class PhantomCore
{
	public $socket;
	public $address;
	public $portno;
	public $nick;
	public $nickCounter = 0;
	public $size = 512;
	public $prefix = '@';
	public $modules;
	public $modules_regex = array();
	public $modules_prefix = array();
	public $modules_alias = array();
	public $modules_hooks = array();
	public $listeners;
	public $listeners_regex = array();
	public $listeners_prefix = array();
	public $listeners_alias = array();
	public $listeners_hooks = array();
	public $config;
	public $path;
	public $shmop;
	
	public $ignores = array('nicks', 'hosts');
	
	public function __construct(&$shmop, $config = array())
	{
		global $path;
		
		$this->path = $path;
		$this->shmop = $shmop;
		$this->config = $config;
		
		if(!class_exists('Core\ModuleBase'))
		{
			new Core\ModuleBase();
		}
		
		if(!class_exists('Core\ListenerBase'))
		{
			new Core\ListenerBase();
		}
		
		$this->nick = Helpers\Str::trim($config['ident']['nickname']);
		
		$this->address = $config['server']['address'];
		$this->portno = $config['server']['portnum'];
		
		$ssl = Helpers\Str::beginsWith('+', $this->portno);
		if($ssl)
		{
			$this->address = 'ssl://' . $this->address;
			$this->portno = Helpers\Str::after('+', $this->portno);
		}
		
		if(isset($config['server']['prefix']))
		{
			$this->prefix = Helpers\Str::trim($config['server']['prefix']);
		}
	}
	
	public function connect()
	{
		$ctxOptions = array(
    		'ssl' => array(
        		'verify_peer' => false,
        		'verify_peer_name' => false,
        		'allow_self_signed'=> true
    		)
		);
		$ctx = stream_context_create($ctxOptions);
		$this->socket = stream_socket_client(
			$this->address.':'.$this->portno, $errNo, $errStr, 15,
			STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $ctx
		);
		
		stream_set_blocking($this->socket, true);
		
		if($this->socket == FALSE)
		{
			die("Error: [$errNo] $errStr");
		}
		else
		{
			if(isset($this->config['server']['password']) && strlen($this->config['server']['password']))
			{
				$this->send("PASS {$this->config['server']['password']}");
			}
			
			$this->send("NICK {$this->nick}");
			$ident = isset($this->config['ident']['username']) ? $this->config['ident']['username'] : $this->nick;
			$this->send('USER ' . $ident . ' * * :' . $this->config['ident']['realname']);
			
			$count = 0;
			$pinged = false;
			while(!$pinged)
			{
				$data = fgets($this->socket, $this->size);
				if(strlen($data)>1)
					echo '[RECV] ' . trim($data) . PHP_EOL;
				
				if(preg_match("/:Nickname is already in use.$/", Helpers\Str::trim($data)))
				{
					$this->nick = $this->nick . ($this->nickCounter++);
					$this->send('NICK ' . $this->nick);
				}
				
				if($count > 5)
				{
					break;
				}
				
				if(Helpers\Str::beginsWith('PING :', $data))
				{
					$ping = Helpers\Str::after('PING :', $data);
					$this->send('PONG :' . $ping);
					$pinged = true;
				}
				
				$count++;
			}
			
			$count = 0;
			$nickserv = (!isset($this->config['ident']['nickserv']['password'])||$this->config['ident']['nickserv']['password']==='')?true:false;
			while(!$nickserv)
			{
				$data = fgets($this->socket, $this->size);
				if(strlen($data)>1)
					echo '[RECV] ' . Helpers\Str::trim($data) . PHP_EOL;
				
				$code = explode(' ', $data);
				if($code[1] === '266')
				{
					$this->privmsg('NickServ', 'help');
				}
				
				if($code[1] !== '401')
				{
					$this->privmsg('NickServ', 'IDENTIFY ' . $this->config['ident']['nickserv']['password']);
					$nickserv = true;
				}
				
				if(preg_match("/^\:NickServ\!NickServ@.* NOTICE {$this->nick} :This nickname is registered./i", Helpers\Str::trim($data)))
				{
					$this->privmsg('NickServ', 'IDENTIFY ' . $this->config['ident']['nickserv']['password']);
					$nickserv = true;
				}
				
				if($count > 5)
				{
					break;
				}
			}
			
			$opered = false;
			while(!$opered)
			{
				$data = fgets($this->socket, $this->size);
				if(strlen($data)>1)
					echo '[RECV] ' . Helpers\Str::trim($data) . PHP_EOL;
				
				if($this->config['oline']['username'] !== '' && $this->config['oline']['password'] !== '')
				{
					$code = explode(' ', $data);
					if(@$code[1] === '266')
					{
						$this->send('OPER ' . $this->config['oline']['username'] . ' ' . $this->config['oline']['password']);
						$opered = true;
					}
				}
				else
				{
					break;
				}
			}
			
			$joined = false;
			while(!$joined)
			{
				$data = fgets($this->socket, $this->size);
				if(strlen($data)>1)
					echo '[RECV] ' . Helpers\Str::trim($data) . PHP_EOL;	

				foreach($this->config['server']['channels'] as $channel)
				{
					$password = '';
					if(count(explode(':', $channel)) > 1)
					{
						@list($channel, $password) = explode(':', $channel);
					}
					if(Helpers\Str::beginsWith('#', $channel))
					{
						$this->send('JOIN ' . $channel . ' ' . $password);
						usleep(250000);
					}
				}
				$joined = true;
			}
		}
	}
	
	public function loadmodules()
	{
		$allowed_hooks = array(
			'beforeCommand',
			//'beforePrefix',
		);
		
		foreach(glob($this->path . 'Library/Modules/*.php') as $module)
		{
			require_once($module);
			$module = strtolower(Helpers\Str::trim(str_replace('.php', '', basename($module))));
			$class = 'Modules\\' . ucfirst($module);
			$this->modules[$module] = new $class($this);
			echo "[INFO] Loaded: {$class}\n";
			
			/*if(!empty($this->modules[$module]->regex))
			{
				$this->modules_regex[$module] = $this->modules[$module]->regex;
			}*/
			
			/*if(!empty($this->modules[$module]->prefix))
			{
				$this->modules_prefix[$module] = $this->modules[$module]->prefix;
			}*/
			
			/*if(!empty($this->modules[$module]->alias) && is_array($this->modules[$module]->alias))
			{
				foreach($this->modules[$module]->alias as $alias)
				{
					$this->modules_alias[$alias] = $module;
				}
			}*/
			
			if(!empty($this->modules[$module]->hooks) && is_array($this->modules[$module]->hooks))
			{
				foreach ($this->modules[$module]->hooks as $hook)
				{
					if(isset($allowed_hooks[$hook]))
					{
						$this->modules_hooks[$hook][] = array('hook' => $hook, 'module' => $module);
					}
					else
					{
						echo "[INFO] {$module}'s hook is not supported.";
					}
				}
			}
		}
	}
	
	public function loadlisteners()
	{
		$allowed_hooks = array(
			'beforeCommand',
			//'beforePrefix',
		);
		
		foreach(glob($this->path . 'Library/Listeners/*.php') as $listener)
		{
			require_once($listener);
			$listener = strtolower(Helpers\Str::trim(str_replace('.php', '', basename($listener))));
			$class = 'Listeners\\' . ucfirst($listener);
			$this->listeners[$listener] = new $class($this);
			echo "[INFO] Loaded: {$class}\n";
			
			/*if(!empty($this->listeners[$listener]->regex))
			{
				$this->listeners_regex[$listener] = $this->listeners[$listener]->regex;
			}*/
			
			/*if(!empty($this->listeners[$listener]->prefix))
			{
				$this->listeners_prefix[$listener] = $this->listeners[$listener]->prefix;
			}*/
			
			/*if(!empty($this->listeners[$listener]->alias) && is_array($this->listeners[$listener]->alias))
			{
				foreach($this->listeners[$listener]->alias as $alias)
				{
					$this->listeners_alias[$alias] = $listener;
				}
			}*/
			
			if(!empty($this->listeners[$listener]->hooks) && is_array($this->listeners[$listener]->hooks))
			{
				foreach ($this->listeners[$listener]->hooks as $hook)
				{
					if(isset($allowed_hooks[$hook]))
					{
						$this->listeners_hooks[$hook][] = array('hook' => $hook, 'module' => $listener);
					}
					else
					{
						echo "[INFO] {$listener}'s hook is not supported.";
					}
				}
			}
		}
	}
	
	public function listen()
	{
		if(!$this->isConnected())
		{
			die('Reached end of socket.' . PHP_EOL);
		}
		
		stream_set_blocking($this->socket, false);
		
		$data = fgets($this->socket, $this->size);
		if(strlen($data)>1)
			echo '[RECV] ' . $data;
		
		return $data;
	}	
	
	public function isConnected()
	{
		return is_resource($this->socket) && !feof($this->socket);
	}	
	
	public function disconnect($message = 'Quit command issued.')
	{
		$this->send('QUIT :' . Helpers\Str::trim($message));
		fclose($this->socket);
	}	
	
	private function send($signal)
	{
		fputs($this->socket, Helpers\Str::trim($signal) . "\n");
		echo '[SEND] ';
		if(strstr(Helpers\Str::trim($signal), 'PASS'))
		{
			echo 'PASS ****';
		}
		elseif(strstr(Helpers\Str::trim($signal), 'OPER'))
		{
			echo 'OPER **** *****' . PHP_EOL;
		}
		elseif(strstr(Helpers\Str::trim($signal), 'IDENTIFY'))
		{
			echo 'NICKSERV IDENTIFY ****' . PHP_EOL;
		}
		else
		{
			echo Helpers\Str::trim($signal) . "\n";
		}
	}
	
	private function privmsg($target, $message)
	{
		$this->send('PRIVMSG ' . Helpers\Str::trim($target) . ' :' . Helpers\Str::trim($message));
	}
	
	public function command($input)
	{
		$input = explode(' ', Helpers\Str::trim($input));
		$command = isset($input[0]) ? Helpers\Str::after($this->prefix, $input[0]) : '';
		return $command;
	}
	
	public function input($data)
	{
		$input = explode(':', Helpers\Str::trim($data));
		$input = isset($input[0], $input[1]) ? Helpers\Str::trim(str_replace("{$input[0]}:{$input[1]}:", '', $data)) : '';
		return $input;
	}
	
	public function sender($data)
	{
		$e = explode(' ', Helpers\Str::trim($data));
		$sender = isset($e[0]) ? explode('!', Helpers\Str::after(':', $e[0])) : array('');
		return $sender[0];
	}
	
	public function channel($data)
	{
		$e = explode(' ', Helpers\Str::trim($data));
		$chan = isset($e[2]) ? $e[2] : '';
		if (!Helpers\Str::beginsWith('#', $chan)) $chan = $this->sender($data);
		return $chan;
	}
	
	public function host($data)
	{
		$e = explode(' ', Helpers\Str::trim($data));
		$e = explode('@', $e[0]);
		$host = isset($e[1]) ? $e[1] : '';
		return $host;
	}
	
	public function getLevel($user, $channel, $host = '')
	{
		$user = strtolower(Helpers\Str::trim($user));
		$host = strtolower(Helpers\Str::trim($host));
		
		if(!empty($host))
		{
			$admins = $this->config['admins'];			
			if(isset($admins[$user]))
			{
				if(strtolower($admins[$user]['host']) === $host)
				{
					return $admins[$user]['super'] ? 8 : 7;
				}
			}
		}
		
		if(!empty($channel))
		{
			$this->send('WHOIS ' . $user);
			$gotit = false;
			while(!$gotit)
			{
				$data = $this->listen();
				$channelflag = $this->expect($data, 'whoischannel', array('who' => $user, 'channel' => $channel));
				$end = $this->expect($data, 'endofwhois', array('who' => $user));
				if($channelflag !== false)
				{
					$gotit = true;
					switch ($channelflag[2])
					{
						case '~':
							return 6;
						break;
							
						case '&':
							return 5;
						break;
							
						case '@':
							return 4;
						break;
						
						case '%':
							return 3;
						break;
						
						case '+':
							return 2;
						break;
							
						default:
							return 1;
						break;
					}
				}
				elseif($end !== false)
				{
					$gotit = true;
					return 1;
				}
				else
				{
					$this->process($data);
				}
			}
		}
		return 1;
	}
	
	public function expect($data, $what, $with)
	{
		switch($what)
		{
			case 'nick':
				$who = preg_quote($with['who'], '/');
				if(preg_match("/{$who}![a-zA-Z0-9~]+@.+ NICK :(.+)/i", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'nickinuse':
				$who = preg_quote($with['who'], '/');
				if(preg_match("/:[a-zA-Z0-9\.]+ 433 {$this->nick} {$who} :Nickname is already in use./", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'nickserv':
				$who = preg_quote($with['who'], '/');
				if (preg_match("/:[a-zA-Z0-9\.]+ 307 {$this->nick} ({$who}) :is a registered nick/i", $data, $matches) || preg_match("/:[a-zA-Z0-9\.]+ 330 {$this->nick} ({$who}) .*?:is logged.*?/i", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'endofwhois':
				$who = preg_quote($with['who'], '/');
				if (preg_match("/:[a-zA-Z0-9\.]+ 318 {$this->nick} {$who} :End of \/WHOIS list./i", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'nosuchnickchannel':
				$who = preg_quote($with['who'], '/');
				if (preg_match("/:[a-zA-Z0-9\.]+ 401 {$this->nick} {$who} :No such nick\/channel/i", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'whoischannel':
				$who = preg_quote($with['who'], '/');
				$channel = preg_quote($with['channel'], '/');
				if(preg_match("/:[a-zA-Z0-9\.]+ 319 {$this->nick} ({$who}) :.*?([~&@%\+]*){$channel}[\s|$]/i", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'modechannel':
				$channel = preg_quote($with['channel'], '/');
				if(preg_match("/:[a-zA-Z0-9\.]+ 324 {$this->nick} {$channel} ([\+|-][a-zA-Z]+)/i", $data, $matches))
				{
					return $matches;
				}
				else
				{
					return false;
				}
			break;
			
			case 'topicchange':
				$channel = preg_quote($with['channel'], '/');
				return preg_match("/![a-zA-Z0-9~]+@.+ TOPIC {$channel}/i", $data);
			break;
			
			default:
				return false;
			break;
		}
	}
	
	public function process($data)
	{
		$input = $this->input($data);
		
		if(Helpers\Str::beginsWith('PING :', $data))
		{
			$ping = Helpers\Str::after('PING :', $data);
			$this->send('PONG :' . $ping);
			return;
		}
		
		/*
		if(preg_match("/:Nickname is already in use./", Helpers\Str::trim($data)))
		{
			$this->nick = $this->nick . (++$this->nickCounter);
			$this->send('NICK ' . $this->nick);
		}
		*/
		
		if(isset($this->config['server']['invites']) && $this->config['server']['invites'] === true)
		{
			if(preg_match("/.*INVITE " . $this->nick . " :(#[#a-zA-Z0-9]+)/", $data, $match))
			{
				$this->send('JOIN ' . $match[1]);
				return;
			}
		}

		/*foreach($this->modules_regex as $class => $regex)
		{
			if(preg_match($regex, $data, $matches))
			{
				$this->modules[$class]->match($this, $this->socket, Helpers\Str::trim($data), $matches);
			}
		}*/
		
		/*foreach($this->modules_prefix as $class => $prefix)
		{
			if(Helpers\Str::beginsWith($prefix, $input))
			{
				$command = explode(' ', Helpers\Str::after($this->prefix, $input));
				$command = $command[0];
				$pinput = explode(' ', $input);
				unset($pinput[0]);
				$pinput = implode(' ', $pinput);
				$okay = true;
				if(isset($this->modules_hooks['beforePrefix']))
				{
					foreach($this->modules_hooks['beforePrefix'] as $hook)
					{
						if($hook['hook'] == 'beforePrefix')
						{
							$okay = $this->modules[$hook['module']]->beforePrefix($this, $this->socket, Helpers\Str::trim($data), $input, $command, $pinput);
							if($okay == false)
							{
								break;
							}
						}
					}
				}
				if($okay)
				{
					$this->modules[$class]->prefix($this, $this->socket, Helpers\Str::trim($data), $input, $command, $pinput);
				}
			}
		}*/
		
		$data = Helpers\Str::trim($data);
		$sender = $this->sender($data);
		$senderhost = $this->host($data);
		$channel = $this->channel($data);
		
		if(Helpers\Str::beginsWith($this->prefix, $input))
		{
			// commands
			$command = strtolower($this->command($input));
			
			if(isset($this->ignores['nicks'][$sender]) || isset($this->ignores['hosts'][$senderhost]))
			{
				$this->privmsg($channel, "{$sender}, Ignorance is bliss!");
				return;
			}
				
			if(/*!*/(/*$command == 'module') && */(isset($this->modules[$command]) /*|| (isset($this->modules_alias[$command]) && isset($this->modules[$this->modules_alias[$command]])*/)))
			{
				/*
				 LEVELS:
				 1 - regular
				 2 - voiced
				 3 - halfop
				 4 - op
				 5 - protected
				 6 - owner
				 7 - admin
				 8 - super admin
				*/
				$okay = true;
				if(isset($this->modules_hooks['beforeCommand']))
				{
					foreach($this->modules_hooks['beforeCommand'] as $hook)
					{
						if($hook['hook'] == 'beforeCommand')
						{
							$okay = $this->modules[$hook['module']]->beforeCommand($this, $this->socket, $data, $input, $command, Helpers\Str::after($this->prefix . $this->command($input), $input));
							if($okay == false)
							{
								break;
							}
						}
					}
				}
				
				if($okay)
				{
					if(isset($this->modules[$command]))
					{
						$this->modules[$command]->process($this, $this->socket, $data, $input, $command, Helpers\Str::after($this->prefix . $this->command($input), $input));
					}
					/*elseif(isset($this->modules_alias[$command]) && isset($this->modules[$this->modules_alias[$command]]))
					{
						$this->modules[$this->modules_alias[$command]]->process($this, $this->socket, Helpers\Str::trim($data), $input, $command, Helpers\Str::after($this->prefix . $this->command($input), $input));
					}*/
				}
			}
		}
		else
		{	// listeners
			$arguments = explode(' ', $data);
			
            foreach($this->listeners as $listener)
            {
                if(is_array($listener->getKeywords()))
                {
                    foreach($listener->getKeywords() as $keyword)
                    {
                        //compare listeners keyword and 1st arguments of server response
                        if($keyword === $arguments[1])
                        {
                        	$reflector = new \ReflectionClass($listener);
                        	$listener = $reflector->newInstanceArgs();
							$listener->process($this, $this->socket, $data, $input);
						}
                    }
                }
            }
		} // else
	}
}
