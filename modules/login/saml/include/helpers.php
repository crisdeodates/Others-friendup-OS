<?php

/*©lgpl*************************************************************************
*                                                                              *
* Friend Unifying Platform                                                     *
* ------------------------                                                     *
*                                                                              *
* Copyright 2014-2017 Friend Software Labs AS, all rights reserved.            *
* Hillevaagsveien 14, 4016 Stavanger, Norway                                   *
* Tel.: (+47) 40 72 96 56                                                      *
* Mail: info@friendos.com                                                      *
*                                                                              *
*****************************************************************************©*/

// Get varargs
function getArgs()
{
	$args = new stdClass();
	
	if( isset( $GLOBALS['request_path'] ) && $GLOBALS['request_path'] && strstr( $GLOBALS['request_path'], '/loginprompt/' ) )
	{
		if( $url = explode( '/loginprompt/', $GLOBALS['request_path'] ) )
		{
			if( isset( $url[1] ) && $url[1] )
			{
				$args->publickey = $url[1];
			}
		}
	}
	
	if( isset( $GLOBALS['request_variables'] ) && $GLOBALS['request_variables'] )
	{
		foreach( $GLOBALS['request_variables'] as $k => $v )
		{
			if( $k && $k != '(null)' )
			{
				$args->{$k} = $v;
			}
		}
	}
	
	return $args;
}

// Render the SAML login form
function renderSAMLLoginForm()
{
	$providers = [];
	$provider = false;
	$lp = '';		


    if( isset( $GLOBALS['login_modules']['saml']['Providers'] ) )
    {
	    foreach( $GLOBALS['login_modules']['saml']['Providers'] as $pk => $pv );
	    {
		    //do some checks here
	    }
	}
	
	if( file_exists(dirname(__FILE__) . '/../templates/login.html') )
		die( renderReplacements( file_get_contents(dirname(__FILE__) . '/../templates/login.html') ) );
	
	
	die( '<h1>Your FriendUP installation is incomplete!</h1>' );
}

// Set replacements on template
function renderReplacements( $template )
{
	$samlLog = $GLOBALS[ 'login_modules' ][ 'saml' ][ 'Login' ];
	$samlMod = $GLOBALS[ 'login_modules' ][ 'saml' ][ 'Module' ];

	// Get some keywords
	$welcome = $samlLog['logintitle_en'] !== null ? 
		$samlLog['logintitle_en'] : 'SAML Login';
		
	$friendlink = $samlLog['friend_login_text_en'] !== null ? 
		$samlLog['friend_login_text_en'] : 'Login using Friend account';
		
	$additional_iframe_styles = $samlLog['additional_iframe_styles'] !== null ? 
		$samlLog['additional_iframe_styles'] : '';
		
	$additionalfriendlinkstyles = $samlLog['additional_friendlink_styles'] !== null ? 
		$samlLog['additional_friendlink_styles'] : '';

	// Find endpoint
	$samlendpoint = $samlMod['samlendpoint'] !== null ? 
		$samlMod['samlendpoint'] : 'about:blank';
	$samlendpoint .= '?friendendpoint=' . urlencode( $GLOBALS['request_path'] );
	
	$finds = [
		'{scriptpath}',
		'{welcome}',
		'{friendlinktext}',
		'{additionaliframestyles}',
		'{samlendpoint}',
		'{additionalfriendlinkstyle}'
	];
	$replacements = [
		$GLOBALS['request_path'],
		$welcome,
		$friendlink,
		$additional_iframe_styles,
		$samlendpoint,
		$additionalfriendlinkstyles
	];
	
	return str_replace( $finds, $replacements, $template );
}

function SendSMS( $mobile, $message )
{
	global $Config;
	
	$host    = ( isset( $Config['SMS']['host']  )   ? $Config['SMS']['host']  :   '' );
	$token   = ( isset( $Config['SMS']['token'] )   ? $Config['SMS']['token'] :   '' );
	$from    = ( isset( $Config['SMS']['from']  )   ? $Config['SMS']['from']  :   '' );
	$version = ( isset( $Config['SMS']['version'] ) ? $Config['SMS']['version'] : '' );
	
	switch( $version )
	{
		case 1:	
			$header = array(
				'X-Version: 1',
				'Content-Type: application/json',
				'Authorization: bearer ' . $token,
				'Accept: application/json'
			);
			$json = new stdClass();
			$json->text = $message;
			$json->to = array( $mobile );
			break;
		default:
			$header = array(
				'Content-Type: application/json',
				'Authorization: ' . $token
			);
			$json = new stdClass();
			$json->content = $message;
			$json->to = array( $mobile );
			break;
	}
	
	$curl = curl_init();
	curl_setopt( $curl, CURLOPT_URL, $host );
	curl_setopt( $curl, CURLOPT_HTTPHEADER, $header );
	curl_setopt( $curl, CURLOPT_POST, 1 );
	curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $json ) );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	$output = curl_exec( $curl );
	$httpCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
	curl_close( $curl );
	return $output;
}

// Check if this user was authenticated with an auth token!
// If not, do register and send an SMS
function check2faAuth( $token, $mobile, $code = false )
{
	global $SqlDatabase;
	
	$cleanToken  = mysqli_real_escape_string( $SqlDatabase->_link, $token  );
	$cleanMobile = mysqli_real_escape_string( $SqlDatabase->_link, $mobile );
	if( $code )
	{
		$cleanCode = mysqli_real_escape_string( $SqlDatabase->_link, $code );
	}
	
	// TODO: By removing previous tokens on mobile number, we could prevent 
	//       multiple logins on same mobile number
	if( $code )
	{
		$q = 'SELECT * FROM FUserLogin WHERE UserID=-1 AND Login="' . $cleanToken . '|' . $cleanMobile . '" AND Information="' . $cleanCode . '"';
	}
	else
	{
		$q = 'SELECT * FROM FUserLogin WHERE UserID=-1 AND Login="' . $cleanToken . '|' . $cleanMobile . '"';
	}
	
	if( $row = $SqlDatabase->fetchObject( $q ) )
	{
		return 'ok<!--separate-->' . $token;
	}
	
	// Generate code (six decimals)
	$code = '';
	for( $a = 0; $a < 6; $a++ )
	{
		$code .= rand( 0, 9 ) . '';
	}
	
	// Send the verification code
	$response = SendSMS( $mobile, 'Your verification code: ' . $code );
	
	$o = new dbIO( 'FUserLogin' );
	$o->UserID = -1;
	$o->Login = $token . '|' . $mobile;
	$o->Information = $code;
	$o->Save();
	return 'fail<!--separate-->{"message":"-1","reason":"Token registered.","SMS-Response":"' . $response . '"}';
}

// Verify the Windows user identity for a specific RDP server
function verifyWindowsIdentity( $username, $password = '', $server )
{	
	$error = false; $data = false;
	
	// TODO: set login data static for the presentation, remove later, only test data.
	// TODO: verify user with free rdp ...
	// TODO: Get user data from free rdp login or use powershell via ssh as friend admin user ...
	// TODO: implement max security with certs for ssh / rdp access if possible, only allow local ...
	
	if( $username && $server )
	{
		if( function_exists( 'shell_exec' ) )
		{
			$connection = false;
			
			// TODO: Move this to a server config, together with what mode to use for 2factor ...
			// TODO: Look at hashing password or something ...
			
			$adminus = $server->username;
			$adminpw = $server->password;
			$hostname = $server->host;
			$port = ( $server->ssh_port ? $server->ssh_port : 22 );
			$rdp =  ( $server->rdp_port ? $server->rdp_port : 3389 );
			$username = trim( $username );
			$password = trim( $password );
			$dbdiskpath = ( $server->users_db_diskpath ? $server->users_db_diskpath : '' );
			
			if( $hostname && $username && $password )
			{
				// Check user creds using freerdp ...
				// TODO: Add more security to password check ...	
				$authenticated = false; $found = false;
				// sfreerdp needs special option added on install cmake -GNinja -DCHANNEL_URBDRC=OFF -DWITH_DSP_FFMPEG=OFF -DWITH_CUPS=OFF -DWITH_PULSE=OFF -DWITH_SAMPLE=ON .
				// TODO: Get error messages for not WHITE LABELLED!!!!!!
				
				if( $checkauth = exec_timeout( "sfreerdp /cert-ignore /cert:ignore +auth-only /u:$username /p:$password /v:$hostname /port:$rdp /log-level:ERROR 2>&1" ) )
				{
					if( strstr( $checkauth, 'sfreerdp: not found' ) )
					{
						$checkauth = exec_timeout( "xfreerdp /cert-ignore /cert:ignore +auth-only /u:$username /p:$password /v:$hostname /port:$rdp /log-level:ERROR 2>&1" );
					}
					$found = true;
				}
				
				if( !$found )
				{
					$checkauth = exec_timeout( "xfreerdp /cert-ignore /cert:ignore +auth-only /u:$username /p:$password /v:$hostname /port:$rdp /log-level:ERROR 2>&1" );
				}
				
				if( $checkauth )
				{
					if( strstr( $checkauth, 'sfreerdp: not found' ) || strstr( $checkauth, 'xfreerdp: not found' ) )
					{
						$error = '{"result":"-1","response":"Dependencies: sfreerdp or xfreerdp is required, contact support ..."}';
					}
					else if( $parts = explode( "\n", $checkauth ) )
					{
						foreach( $parts as $part )
						{
							if( $value = explode( 'Authentication only,', $part ) )
							{
								if( trim( $value[1] ) && trim( $value[1] ) == 'exit status 0' )
								{
									$authenticated = true;
								}
							}
						}
						
						if( !$authenticated )
						{
							$error = '{"result":"-1","response":"Unable to perform terminal login","code":"6","data":"","debug":"1"}';
						}
					}
				}
				else
				{
					$error = '{"result":"-1","response":"Account blocked until: 0","code":"6","debug":"2"}';
				}
				
				if( !$error )
				{
					//$path = ( SCRIPT_2FA_PATH . '/../../../cfg' );
					// Specific usecase ...
					if( $dbdiskpath && file_exists( $dbdiskpath /*$path . '/Friend-AD-Time-IT.csv'*/ ) )
					{
						if( $output = file_get_contents( $dbdiskpath /*$path . '/Friend-AD-Time-IT.csv'*/ ) )
						{
							$identity = new stdClass();
							
							if( $rows = explode( "\n", trim( $output ) ) )
							{							
								foreach( $rows as $line )
								{
									$line = explode( ';', $line );
									list( $mobnum, $name, $user, ) = $line;
									$mobnum = trim( $mobnum );
									$name = trim( $name );
									$user = trim( $user );
									
									// Our user!
									if( isset( $mobnum ) && isset( $name ) && isset( $user ) && strtolower( $user ) == strtolower( trim( $username ) ) )
									{
										if( !intval( $mobnum ) )
										{
											// TODO: Will this even work?
											if( $tmp_mobile = (int) filter_var( $mobnum, FILTER_SANITIZE_NUMBER_INT ) )
											{
												$mobnum = $tmp_mobile; // Set sanitized number
											}
										}
										else
										{
											$mobnum = intval( $mobnum );
										}
										
										$data = new stdClass();
										$data->id       = '0';
										$data->fullname = $name;
										$data->mobile   = $mobnum;
										
										theLogger( 'Found ' . print_r( $data, 1 ) );
										
										return [ 'ok', $data ];
									}
								}
							}
							//return [ 'fail', '{"result":"-1","response":"Account blocked until: 0","code":"6","debug":"0"}' ];
						}
					}

					// Failed to fin d user in list.
					return [ 'fail', '{"result":"-1","response":"Could not find user in index, or index does not exist","code":"7","debug":"0"}' ];
				}
			}
		}
		else
		{
			$error = '{"result":"-1","response":"Dependencies: php-ssh2 libssh2-php and or enabling shell_exec is required, contact support ..."}';
		}
	}
	else
	{
		$error = '{"result":"-1","response":"Account blocked until: 0","code":"6","debug":"4"}';
	}
	
	theLogger( 'Er returned with an error: ' . $error );
	
	return [ 'fail', $error ];
}

// Do the final execution of 2fa verification
function execute2fa( $data )
{
	global $Config;
	
	if( check2faAuth( $data->AuthToken, $data->MobileNumber, $data->Code ) )
	{
		$result = verifyWindowsIdentity( $data->Username, $data->Password, $Config[ 'Windows' ][ 'server' ] );
		die( 'ok<!--separate-->' . print_r( $result, 1 ) );
	}
	else
	{
		die( 'fail<!--separate-->{"result":"-1","response":"Could not verify token and code. Please retry again."}' );
	}
}

?>
