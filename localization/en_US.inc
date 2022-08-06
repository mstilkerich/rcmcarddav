<?php
$labels = Array(
	'never' => 'never',

	'saveok' => 'Settings saved successfully',
	'savefail' => 'Failed to save settings ($errormsg)',

	// Account/addressbook list pane - Header / Toolbar
	'CardDAV_rclbl' => 'CardDAV',

	// Account/addressbook list pane
	'changeactive' => '(de)activate addressbook',
	'activatingabook' => 'Activating addressbook...',
	'deactivatingabook' => 'Deactivating addressbook...',
	'activateabook_success' => 'Addressbook successfully activated',
	'deactivateabook_success' => 'Addressbook successfully deactivated',

	// Details pane Toolbar
	'AccAdd_btnlbl' => 'Add',
	'AccAdd_btntit' => 'Add CardDAV account',

	'AccRm_btnlbl' => 'Delete',
	'AccRm_btntit' => 'Delete CardDAV account',

	'AbSync_btnlbl' => 'Resync',
	'AbSync_btntit' => 'Resynchronize addressbook',
	'AbSync_menulbl_aria' => "Addressbook resynchronization options",
	'AbClrCache_btnlbl' => 'Reset sync (clear cache)',
	'AbSync_msg_ok' => 'CardDAV addressbook $name synchronized (Took $duration seconds)',
	'AbSync_msg_fail' => 'Sync of CardDAV addressbook $name failed: $errormsg',
	'AbSync_msg_inprogress' => 'Synchronizing addressbook...',
	'AbClrCache_msg_ok' => 'Sync state of CardDAV addressbook $name has been reset',
	'AbClrCache_msg_fail' => 'Error resetting sync state of CardDAV addressbook $name: $errormsg',
	'AbClrCache_msg_inprogress' => 'Resetting sync state...',

	// Account details page
	'AccProps_rclbl_aria' => 'Account properties',

	'basicinfo' => 'Basic information',
	'frompreset' => 'Administrator preset',
	'accountname' => 'Name of the account',
	'discoveryurl' => 'Discovery URL',
	'cd_username' => 'Username',
	'cd_password' => 'Password',

	'discoveryinfo' => 'Addressbook discovery',
	'rediscover_time' => 'Rediscovery interval (hours)',
	'lastdiscovered_time' => 'Time of last addressbook discovery',

	// Addressbook details page
	'AbProps_rclbl_aria' => 'Addressbook properties',
	// basicinfo
	'cd_name' => "Name of the addressbook",
	'cd_url' => 'URL',

	'syncinfo' => 'Synchronization',
	'cd_refresh_time' => "Update interval (hours)",
	'cd_lastupdate_time'=>'Time of last update',

	'miscsettings' => 'Miscellaneous settings',
	'newgroupstype' => 'Type of new contact groups',
	'grouptype_vcard' => 'Separate KIND=group (RFC6350) VCards for new groups (best compatibility with Apple applications)',
	'grouptype_categories' => 'Store groups as CATEGORIES inside contact VCards (best compatibility with non-Apple applications)',

	// Add new account page
	'newaccount' => 'Add new CardDAV account',
);
?>
