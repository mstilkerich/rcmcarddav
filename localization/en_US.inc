<?php
$labels = Array(
	// Account/addressbook list pane - Header / Toolbar
	'CardDAV_rclbl' => 'CardDAV',
	'CardDAV_rctit' => 'Manage CardDAV addressbooks',

	// Account/addressbook list pane
	'AbToggleActive_cb_tit' => '(de)activate addressbook',
	'AbToggleActive_msg_ok' => 'Addressbook successfully activated',
	'AbToggleActive_msg_ok_de' => 'Addressbook successfully deactivated',
	'AbToggleActive_msg_fail' => 'Addressbook activation failed',
	'AbToggleActive_msg_fail_de' => 'Addressbook deactivation failed',

	// Details pane Toolbar
	'AccAdd_btnlbl' => 'Add',
	'AccAdd_btntit' => 'Add CardDAV account',
	'AccAdd_msg_ok' => 'New CardDAV account added successfully',

	'AccRm_btnlbl' => 'Delete',
	'AccRm_btntit' => 'Delete CardDAV account',
	'AccRm_msg_ok' => 'CardDAV account successfully deleted',
	'AccRm_msg_fail' => 'Removal of CardDAV account failed: $errormsg',

	'AccRedisc_btnlbl' => 'Rediscover',
	'AccRedisc_btntit' => 'Rediscover addressbooks for the account',
	'AccRedisc_msg_ok' => 'Account rediscovery successful ($new new, $rm removed addressbooks)',
	'AccRedisc_msg_fail' => 'Account rediscovery failed: $errormsg',

	'AbSync_btnlbl' => 'Resync',
	'AbSync_btntit' => 'Resynchronize addressbook',
	'AbSync_menulbl_aria' => "Addressbook resynchronization options",
	'AbClrCache_btnlbl' => 'Reset sync (clear cached addressbook data)',
	'AbSync_msg_ok' => 'CardDAV addressbook $name synchronized (Took $duration seconds)',
	'AbSync_msg_fail' => 'Sync of CardDAV addressbook $name failed: $errormsg',
	'AbSync_msg_inprogress' => 'Synchronizing addressbook...',
	'AbClrCache_msg_ok' => 'Sync state of CardDAV addressbook $name has been reset',
	'AbClrCache_msg_fail' => 'Error resetting sync state of CardDAV addressbook $name: $errormsg',
	'AbClrCache_msg_inprogress' => 'Resetting sync state...',

	// Larry skin only - actions button at bottom of addressbooks list
	'AccActions_btntit' => 'CardDAV actions...',
	'AccActions_menulbl_aria' => 'CardDAV actions menu',

	// Account details page
	'AccProps_rclbl' => 'Account properties',

	'AccAbSave_msg_ok' => 'Settings saved successfully',
	'AccAbSave_msg_fail' => 'Failed to save settings ($errormsg)',
	'DateTime_never_lbl' => 'never',

	'AccAbProps_basicinfo_seclbl' => 'Basic information',
	'AccProps_frompreset_lbl' => 'Administrator preset',
	'AccProps_accountname_lbl' => 'Name of the account',
	'AccProps_discoveryurl_lbl' => 'Discovery URL',
	'AccProps_username_lbl' => 'Username',
	'AccProps_password_lbl' => 'Password',

	'AccProps_discoveryinfo_seclbl' => 'Addressbook discovery',
	'AccProps_rediscover_time_lbl' => 'Rediscovery interval (hours)',
	'AccProps_lastdiscovered_time_lbl' => 'Time of last addressbook discovery',

	// Addressbook details page
	'AbProps_rclbl' => 'Addressbook properties',
	// basicinfo
	'AbProps_abname_lbl' => "Name of the addressbook",
	'AbProps_url_lbl' => 'URL',

	'AbProps_syncinfo_seclbl' => 'Synchronization',
	'AbProps_refresh_time_lbl' => "Update interval (hours)",
	'AbProps_lastupdate_time_lbl'=>'Time of last update',

	'AccAbProps_miscsettings_seclbl' => 'Miscellaneous settings',
	'AccAbProps_abookinitsettings_seclbl' => 'Initial settings for addressbooks in this account',
	'AbProps_newgroupstype_lbl' => 'Type of new contact groups',
	'AbProps_grouptype_vcard_lbl' => 'Separate KIND=group (RFC6350) VCards for new groups (best compatibility with Apple applications)',
	'AbProps_grouptype_categories_lbl' => 'Store groups as CATEGORIES inside contact VCards (best compatibility with non-Apple applications)',

	// Add new account page
	'AccProps_newaccount_lbl' => 'Add new CardDAV account',

	// Labels used in messages emitted by backend
	'Be_etagmismatch_msg' => 'Update failed because address object changed at the server side',
);
?>
