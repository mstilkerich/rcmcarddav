<?php
$labels = Array(
	// Account/addressbook list pane - Header / Toolbar
	'CardDAV_rclbl' => 'CardDAV',
	'CardDAV_rctit' => 'Zarządzaj książkami adresowymi CardDAV',

	// Account/addressbook list pane
	'AbToggleActive_cb_tit' => '(dez)aktywuj książkę adresową',
	'AbToggleActive_msg_ok' => 'Książka adresowa została pomyślnie aktywowana',
	'AbToggleActive_msg_ok_de' => 'Książka adresowa została pomyślnie dezaktywowana',
	'AbToggleActive_msg_fail' => 'Aktywacja książki adresowej nie powiodła się',
	'AbToggleActive_msg_fail_de' => 'Deaktywacja książki adresowej nie powiodła się',

	// Details pane Toolbar
	'AccAdd_btnlbl' => 'Dodaj',
	'AccAdd_btntit' => 'Dodaj konto CardDAV',
	'AccAdd_msg_ok' => 'Nowe konto CardDAV zostało pomyślnie dodane',

	'AccRm_btnlbl' => 'Usuń',
	'AccRm_btntit' => 'Usuń konto CardDAV',
	'AccRm_msg_ok' => 'Konto CardDAV zostało pomyślnie usunięte',
	'AccRm_msg_fail' => 'Nie udało się usunąć konta CardDAV: $errormsg',

	'AccRedisc_btnlbl' => 'Wykryj na nowo',
	'AccRedisc_btntit' => 'Wykryj na nowo książki adresowe dla tego konta',
	'AccRedisc_msg_ok' => 'Ponowne wykrycie konta zakończone powodzeniem ($new nowych, $rm usuniętych książek adresowych)',
	'AccRedisc_msg_fail' => 'Nie udało się ponownie wykryć konta: $errormsg',

	'AbSync_btnlbl' => 'Ponowna synchronizacja',
	'AbSync_btntit' => 'Ponownie zsynchronizuj książkę adresową',
	'AbSync_menulbl_aria' => 'Opcje ponownej synchronizacji książki adresowej',
	'AbClrCache_btnlbl' => 'Resetuj synchronizację (usuń dane książki adresowej z pamięci podręcznej)',
	'AbSync_msg_ok' => 'Książka adresowa CardDAV $name zsynchronizowana (zajęło to $duration sekund)',
	'AbSync_msg_fail' => 'Synchronizacja książki adresowej CardDAV $name nie powiodła się: $errormsg',
	'AbSync_msg_inprogress' => 'Synchronizowanie książki adresowej...',
	'AbClrCache_msg_ok' => 'Stan synchronizacji książki adresowej CardDAV $name został zresetowany',
	'AbClrCache_msg_fail' => 'Błąd podczas resetowania stanu synchronizacji książki adresowej CardDAV $name: $errormsg',
	'AbClrCache_msg_inprogress' => 'Resetowanie stanu synchronizacji...',

	// Larry skin only - actions button at bottom of addressbooks list
	'AccActions_btntit' => 'Akcje CardDAV...',
	'AccActions_menulbl_aria' => 'Menu akcji CardDAV',

	// Account details page
	'AccProps_rclbl' => 'Właściwości konta',

	'AccAbSave_msg_ok' => 'Ustawienia zostały pomyślnie zapisane',
	'AccAbSave_msg_fail' => 'Nie udało się zapisać ustawień ($errormsg)',
	'DateTime_never_lbl' => 'nigdy',
	'AccAbProps_timestr_placeholder_lbl' => 'Godziny[:Minuty[:Sekundy]]',

	'AccAbProps_basicinfo_seclbl' => 'Podstawowe informacje',
	'AccProps_frompreset_lbl' => 'Ustawienie wstępne administratora',
	'AccProps_accountname_lbl' => 'Nazwa konta',
	'AccProps_discoveryurl_lbl' => 'Adres URL wykrywania',
	'AccProps_discoveryurl_placeholder_lbl' => 'Często wystarcza sama domena',
	'AccProps_username_lbl' => 'Nazwa użytkownika',
	'AccProps_password_lbl' => 'Hasło',

	'AccProps_discoveryinfo_seclbl' => 'Wyszukiwanie książki adresowej',
	'AccProps_rediscover_time_lbl' => 'Interwał ponownego wykrywania (godziny)',
	'AccProps_lastdiscovered_time_lbl' => 'Czas ostatniego wykrywania książki adresowej',

	'AdvancedOpt_seclbl' => 'Opcje zaawansowane',
	'AccProps_preemptive_basic_auth_lbl' => 'Wysyłaj dane uwierzytelniania HTTP Basic do serwera przy każdym żądaniu',
	'AccProps_ssl_noverify_lbl' => 'Wyłącz weryfikację certyfikatu SSL',

	// Addressbook details page
	'AbProps_rclbl' => 'Właściwości książki adresowej',
	// basicinfo
	'AbProps_abname_lbl' => 'Nazwa książki adresowej',
	'AbProps_active_lbl' => 'Aktywuj nowe książki adresowe',
	'AbProps_url_lbl' => 'URL',
	'AbProps_srvname_lbl' => 'Nazwa po stronie serwera',
	'AbProps_srvdesc_lbl' => 'Opis po stronie serwera',

	'AbProps_syncinfo_seclbl' => 'Synchronizacja',
	'AbProps_refresh_time_lbl' => 'Interwał aktualizacji (godziny)',
	'AbProps_lastupdate_time_lbl' => 'Czas ostatniej aktualizacji',

	'AccAbProps_miscsettings_seclbl' => 'Pozostałe ustawienia',
	'AccAbProps_abookinitsettings_seclbl' => 'Wstępne ustawienia książek adresowych dla tego konta',
	'AbProps_newgroupstype_lbl' => 'Typ nowych grup kontaktów',
	'AbProps_grouptype_vcard_lbl' => 'Oddzielne wizytówki vCard z parametrem KIND=group (RFC6350) dla nowych grup (najlepsza kompatybilność z aplikacjami Apple)',
	'AbProps_grouptype_categories_lbl' => 'Zapisuj grupy jako CATEGORIES w wizytówkach kontaktów (zapewnia to najlepszą zgodność z aplikacjami spoza ekosystemu Apple)',
	'AbProps_reqemail_lbl' => 'Ukryj kontakty bez adresu e-mail',

	// Add new account page
	'AccProps_newaccount_lbl' => 'Dodaj nowe konto CardDAV',

	// Labels used in messages emitted by backend
	'Be_etagmismatch_msg' => 'Aktualizacja nie powiodła się, ponieważ obiekt adresu uległ zmianie po stronie serwera',
);
?>
