<?php

return array(

	'server'	=> array(
		'address' 	=> '127.0.0.1',
		'portnum' 	=> '6667',	// '+6697'
		'password'	=> '',
		'prefix'	=> '@',
		'invites'	=> true,
		'channels'	=> array(
			'#bot'
		)
	),
	
	'ident'	=> array(
		'nickname'	=> 'Phantom',
		'username'	=> 'root',
		'realname'	=> 'Phantom Bot',
		'nickserv'	=> array(
			'password'	=> ''
		)
	),
	
	'oline'	=> array(
		'username'	=> '',
		'password'	=> ''
	),
	
	'admins'	=> array(
		'nickname'	=> array(
			'host'	=> '0.0.0.0',
			'super'	=> true
		)
	)
	
);
