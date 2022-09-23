<?php
$labels = Array(
	// Account/addressbook list pane - Header / Toolbar
	'CardDAV_rclbl' => 'CardDAV',
	'CardDAV_rctit' => 'CardDAV-Adressbücher verwalten',

	// Account/addressbook list pane
	'AbToggleActive_cb_tit' => 'Adressbuch (de)aktivieren',
	'AbToggleActive_msg_ok' => 'Adressbuch erfolgreich aktiviert',
	'AbToggleActive_msg_ok_de' => 'Adressbuch erfolgreich deaktiviert',
	'AbToggleActive_msg_fail' => 'Adressbuch-Aktivierung fehlgeschlagen',
	'AbToggleActive_msg_fail_de' => 'Adressbuch-Deaktivierung fehlgeschlagen',

	// Details pane Toolbar
	'AccAdd_btnlbl' => 'Anlegen',
	'AccAdd_btntit' => 'CardDAV-Konto anlegen',
	'AccAdd_msg_ok' => 'Neues CardDAV-Konto erfolgreich angelegt',

	'AccRm_btnlbl' => 'Entfernen',
	'AccRm_btntit' => 'CardDAV-Konto entfernen',
	'AccRm_msg_ok' => 'CardDAV-Konto erfolgreich entfernt',
	'AccRm_msg_fail' => 'Entfernen des CardDAV-Kontos fehlgeschlagen: $errormsg',

	'AccRedisc_btnlbl' => 'Entdecken',
	'AccRedisc_btntit' => 'Adressbücher des Kontos neu entdecken',
	'AccRedisc_msg_ok' => 'Adressbücher des Kontos erfolgreich entdeckt ($new neue, $rm entfernte Adressbücher)',
	'AccRedisc_msg_fail' => 'Wiederentdeckung der Adressbücher fehlgeschlagen: $errormsg',

	'AbSync_btnlbl' => 'Resynchronisieren',
	'AbSync_btntit' => 'Adressbuch resynchronisieren',
	'AbSync_menulbl_aria' => "Aktionen zur Synchronisation des Adressbuchs",
	'AbClrCache_btnlbl' => 'Synchronisation zurücksetzen (zwischengespeicherte Adressbuch-Daten löschen)',
	'AbSync_msg_ok' => 'CardDAV-Adressbuch $name synchronisiert (Dauer: $duration Sekunden)',
	'AbSync_msg_fail' => 'Synchronisation des CardDAV-Adressbuchs $name fehlgeschlagen: $errormsg',
	'AbSync_msg_inprogress' => 'Synchronisiere Adressbuch...',
	'AbClrCache_msg_ok' => 'Synchronisation des CardDAV-Adressbuchs $name wurde zurückgesetzt',
	'AbClrCache_msg_fail' => 'Fehler beim Zurücksetzen der Synchronisation des CardDAV-Adressbuchs $name: $errormsg',
	'AbClrCache_msg_inprogress' => 'Setze Synchronisation zurück...',

	// Larry skin only - actions button at bottom of addressbooks list
	'AccActions_btntit' => 'CardDAV-Aktionen...',
	'AccActions_menulbl_aria' => 'CardDAV-Aktionsmenü',

	// Account details page
	'AccProps_rclbl' => 'Konto-Eigenschaften',

	'AccAbSave_msg_ok' => 'Einstellungen erfolgreich gespeichert',
	'AccAbSave_msg_fail' => 'Speichern der Einstellungen fehlgeschlagen ($errormsg)',
	'DateTime_never_lbl' => 'nie',

	'AccAbProps_basicinfo_seclbl' => 'Grundlegende Eigenschaften',
	'AccProps_frompreset_lbl' => 'Administrator-Vorlage',
	'AccProps_accountname_lbl' => 'Kontobezeichnung',
	'AccProps_discoveryurl_lbl' => 'Entdeckungs-URL',
	'AccProps_username_lbl'=> "Benutzername",
	'AccProps_password_lbl'=> "Passwort",

	'AccProps_discoveryinfo_seclbl' => 'Adressbuch-Entdeckung',
	'AccProps_rediscover_time_lbl' => 'Wiederentdeckungs-Intervall (Stunden)',
	'AccProps_lastdiscovered_time_lbl' => 'Zuletzt wiederentdeckt',

	// Addressbook details page
	'AbProps_rclbl' => 'Adressbuch-Eigenschaften',
	// basicinfo
	'AbProps_abname_lbl' => "Bezeichnung des Adressbuchs",
	'AbProps_url_lbl'=> "URL",

	'AbProps_syncinfo_seclbl' => 'Synchronisation',
	'AbProps_refresh_time_lbl'=> "Aktualisierungsintervall (Stunden)",
	'AbProps_lastupdate_time_lbl'=>'Zuletzt aktualisiert',

	'AccAbProps_miscsettings_seclbl' => 'Verschiedene Einstellungen',
	'AccAbProps_abookinitsettings_seclbl' => 'Start-Einstellungen für Adressbücher dieses Kontos',
	'AbProps_newgroupstype_lbl' => 'Typ für neu angelegte Kontaktgruppen',
	'AbProps_grouptype_vcard_lbl' => 'Gesonderte KIND=group (RFC6350) VCards für neue Gruppen (beste Kompatibilität mit Apple-Anwendungen)',
	'AbProps_grouptype_categories_lbl' => 'Gruppen in der CATEGORIES-Eigenschaft innerhalb der Kontakt-VCards speichern (beste Kompatibilität mit nicht-Apple Anwendungen)',

	// Add new account page
	'AccProps_newaccount_lbl' => 'Neues CardDAV-Konto hinzufügen',

	// Labels used in messages emitted by backend
	'Be_etagmismatch_msg' => 'Update fehlgeschlagen, da Adressobjekt serverseitig modifiziert wurde',
);
?>
