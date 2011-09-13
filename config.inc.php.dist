<?
$use_config = false; // Set to true to enable this config file

if ($use_config){
	// Enable CardDAV backend
	$prefs['use_carddav'] = true;
	// Use roundcubemail username
	$prefs['username'] = $_SESSION['username'];
	// Use roundcubemail password
	$prefs['password'] = $rcmail->decrypt($_SESSION['password']);
	// URL to backend, use %u as substitute for the username specified above
	//                 use %p as substitute for the password specified above
	$prefs['url'] = "https://ical.example.org/caldav.php/%u/";
	// Lax Resource Checking, see Settings page for help
	$prefs['lax_resource_checking'] = false;

	/* ------------------------------------------------------------ */
	/* --------- Normally no need to edit below this line --------- */
	/* ------------------------------------------------------------ */

	$prefs['url'] = str_replace("%u", $prefs['username'], $prefs['url']);
	$prefs['url'] = str_replace("%p", $prefs['password'], $prefs['url']);
	$read_only = true;
}
