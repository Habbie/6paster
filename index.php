<?php
/**
6core.net paster
Christiaan Ottow (chris@6core.net) 2011

A tiny pastebin clone. I created this because I don't perse like all my pastes ending up
on a public server somewhere. This paster can be very quickly deployed on your own system
allowing you to keep control over your pastes. It is coded with security in mind, forces
the use of HTTPS, and imposes rate limits on posters.

*/

ob_start();

define('CONFIG', '../config.php');
define('TPLDIR','../tpl/');

$base = dirname($_SERVER['SCRIPT_NAME']);
if( $base != '/' )
{
	$base .= '/';
}

define('BASEURL', $base );

require(CONFIG);

function do_cleanup()
{
	global $dbh;
	
	$stmt = $dbh->prepare("DELETE FROM `posts` WHERE `expires` < NOW()");
	$stmt->execute();
}

function check_setup()
{
	global $config;

	// check register_globals
	if( ini_get('register_globals') )
	{
		die('register_globals is enabled. I can\'t work like this.');
	}

	// check gpc_quotes
	if( get_magic_quotes_gpc() )
	{
		die('magci_quotes_gpc is enabled. I can\'t work like this.');
	}

	// check SSL
	if( !array_key_exists('HTTPS', $_SERVER) || $_SERVER['HTTPS'] != "on")
	{
		die('I really like encryption. Please use SSL.');
	}
	
	// sane config?
	if( $config['limit_hour'] > $config['limit_day'] )
	{
		die('You should allow less posts per hour than per day, silly');
	}

	// htaccess installed?
	if( !file_exists('.htaccess'))
	{
		die('You should install the included .htaccess file in the same dir as index.php');
	}

	return true;
}

function show_post( $ident )
{
	global $dbh;

	$stmt = $dbh->prepare("SELECT `text` FROM `posts` WHERE `ident` = ?");
	if( !$stmt )
	{
		die( 'mysql error' );
	}

	$stmt->bind_param('s', $ident );
	$stmt->execute();
	$stmt->store_result();
	$stmt->bind_result( $content );

	if( $stmt->num_rows == 1 )
	{
		$stmt->fetch();
		header("Content-Type: text/plain; charset=utf-8");
		require(TPLDIR.'post.php');
		return;
	}

	// not found
	header("HTTP/1.0 404 Not Found", true, 404);
	require(TPLDIR.'404.php');
}

function show_form() 
{
	global $config;

	require(TPLDIR.'header.php');
	require(TPLDIR.'form.php');
	require( TPLDIR.'footer.php');
}

function do_post()
{
	global $dbh;
	global $config;


	require(TPLDIR.'header.php');

	if( empty( $_POST['content'] ) || empty( $_POST['ttl'] ))
	{
		return;
	}

	if( strlen( $_POST['content']) > $config['post_max_chars'] )
	{
		$errmsg = "Your post exceeds the max limit of ".$config['post_max_chars'];
		require( TPLDIR.'error.php');
		return;
	}

	$ttl = intval( $_POST['ttl'] );
	
	if( $ttl < $config['ttl_min'] )
	{
		$ttl = $config['ttl_min'];
	} else if( $ttl > $config['ttl_max'] ) {
		$ttl = $config['ttl_max'];
	}

	if( limit_exceeded() )
	{
		$errmsg = "You have reached your throttle limit, try again later.";
		require( TPLDIR.'error.php');
		return;
	}

	// it's OK now, let's post it
	$ident = generate_ident();
	$stmt = $dbh->prepare("INSERT INTO `posts` SET `ident`= ?, `ip`=?, `date`=NOW(), `text`=?, `expires` = TIMESTAMPADD( SECOND, ?, NOW())");
	$stmt->bind_param('sssi', $ident, $_SERVER['REMOTE_ADDR'], $_POST['content'], $ttl );
	$stmt->execute();

	header("Location: ".BASEURL."p/".$ident);

	require( TPLDIR.'footer.php');
}

function generate_ident()
{
	global $dbh;

	$exists = true;
	while( $exists )
	{
		$set = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$ident = '';
		for( $i=0; $i<16; $i++)
		{
			$ident .= $set[rand(0, strlen($set))];
		}
		$stmt = $dbh->prepare("SELECT EXISTS ( SELECT * FROM `posts` WHERE `ident` = ? )");
		$stmt->bind_param('s', $ident );
		$stmt->execute();
		$stmt->bind_result( $_exists );
		$exists = ( $_exists == 1 ? true : false );
	}
	return $ident;
}

function limit_exceeded()
{
	global $dbh;
	global $config;

	// check day limit
	return( 
		_limit_exceeded( 'DAY', $config['limit_day']) || 
		_limit_exceeded('HOUR', $config['limit_hour'] ) 
	);

}

function _limit_exceeded( $type, $limit )
{
	global $dbh;

	if( !in_array( $type, array('DAY', 'HOUR')))
		return true;

	$stmt = $dbh->prepare("SELECT COUNT(*) FROM `posts` WHERE `ip`= ? AND TIMESTAMPDIFF( $type, NOW(), `date` ) <= 1");
	if( !$stmt )
	{
		die("Couldn't perform throttle check");
	}
	$stmt->bind_param("s", $_SERVER['REMOTE_ADDR'] );
	$stmt->execute();
	$stmt->bind_result( $count );
	$stmt->fetch();

	return( $count > $limit );
}

check_setup();

$dbh = mysqli_connect( 
	$config['mysql_host'],
	$config['mysql_user'],
	$config['mysql_pass'],
	$config['mysql_db']
);

if( !$dbh )
{
	die("Couldn't connect to database");
}

$ident = false;

do_cleanup();

if( array_key_exists( 'p', $_GET ) && ctype_alnum( $_GET['p'] ) )
{
	$ident = $_GET['p'];
}

if( $ident )
{
	show_post( $ident );
} else if( array_key_exists( 'content', $_POST ) ) {
	do_post();
} else {
	show_form();
}



