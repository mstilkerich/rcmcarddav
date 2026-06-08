<?php
$labels = Array(
        'CardDAV_rclbl'=> "CardDAV",
        'CardDAV_rctit' => 'Управлять адресными книгами CardDAV',

        // Account/addressbook list pane
        'AbToggleActive_cb_tit' => '(Де)активировать адресную книгу',
        'AbToggleActive_msg_ok' => 'Адресная книга успешно активирована',
        'AbToggleActive_msg_ok_de' => 'Адресная книга успешно деактивирована',
        'AbToggleActive_msg_fail' => 'Не удалось активировать адресную книгу',
        'AbToggleActive_msg_fail_de' => 'Не удалось деактивировать адресную книгу',

        // Details pane Toolbar
        'AccAdd_btnlbl' => 'Добавить',
        'AccAdd_btntit' => 'Добавить профиль CardDAV',
        'AccAdd_msg_ok' => 'Новый профиль CardDAV был успешно добавлен',

        'AccRm_btnlbl' => 'Удалить',
        'AccRm_btntit' => 'Удалить профиль CardDAV',
        'AccRm_msg_ok' => 'Профиль CardDAV был успешно удалён',
        'AccRm_msg_fail' => 'Ошибка при удалении профиля CardDAV: $errormsg',

        'AccRedisc_btnlbl' => 'Найти книги',
        'AccRedisc_btntit' => 'Найти адресные книги для данного профиля',
        'AccRedisc_msg_ok' => 'Поиск адресных книг завершился успешно ($new новых, $rm удалённых книг)',
        'AccRedisc_msg_fail' => 'Ошибка при поиске адресных книг: $errormsg',

        'AbSync_btnlbl' => 'Синхронизировать',
        'AbSync_btntit' => 'Синхронизировать адресные книги',
        'AbSync_menulbl_aria' => "Опции синхронизации адресных книг",
        'AbClrCache_btnlbl' => 'Сброисть синхронизацию (отчистить кэш адресных книг)',
        'AbSync_msg_ok' => 'Адресная книга $name синхронизирована (Заняло $duration секунд)',
        'AbSync_msg_fail' => 'При синхронизации адресной книги $name возникла ошибка: $errormsg',
        'AbSync_msg_inprogress' => 'Синхронизация адресной книги...',
        'AbClrCache_msg_ok' => 'Состояние синхронизации для адресной книги $name было сброшено',
        'AbClrCache_msg_fail' => 'При сбросе состояния синхронизации адресной книги $name возникла ошибка: $errormsg',
        'AbClrCache_msg_inprogress' => 'Сброс состояния синхронизации...',

        // Larry skin only - actions button at bottom of addressbooks list
        'AccActions_btntit' => 'Действия с CardDAV...',
        'AccActions_menulbl_aria' => 'Меню взаимодействия с CardDAV',

        // Account details page
        'AccProps_rclbl' => 'Свойства профиля',

        'AccAbSave_msg_ok' => 'Настройки успешно сохранены',
        'AccAbSave_msg_fail' => 'Неудалось сохранить настройки ($errormsg)',
        'DateTime_never_lbl' => 'Никогда',
        'AccAbProps_timestr_placeholder_lbl' => 'Часы[:Минуты[:Секунды]]',

        'AccAbProps_basicinfo_seclbl' => 'Основная информация',
        'AccProps_frompreset_lbl' => 'Шаблон',
        'AccProps_accountname_lbl' => 'Имя профиля',
        'AccProps_discoveryurl_lbl' => 'URL для обнаружения адресных книг',
        'AccProps_discoveryurl_placeholder_lbl' => 'Обычно достаточно указать домен',
        'AccProps_username_lbl' => 'Имя пользователя',
        'AccProps_password_lbl' => 'Пароль',

        'AccProps_discoveryinfo_seclbl' => 'Обнаружение адресных книг',
        'AccProps_rediscover_time_lbl' => 'Интервал между проверками (в часах)',
        'AccProps_lastdiscovered_time_lbl' => 'Время последнего обнаружения адресных книг',

        'AdvancedOpt_seclbl' => 'Расширенные опции',
        'AccProps_preemptive_basic_auth_lbl' => 'Отправлять базовую HTTP аунтефикацию с каждым запросом к серверу',
        'AccProps_ssl_noverify_lbl' => 'Отключить проверку сертификатов',

        // Addressbook details page
        'AbProps_rclbl' => 'Свойства адресной книги',
        // basicinfo
        'AbProps_abname_lbl' => "Имя адресной книги",
        'AbProps_active_lbl' => "Активировать новые адресные книги",
        'AbProps_url_lbl' => 'URL',
        'AbProps_srvname_lbl' => 'Имя на сервере',
        'AbProps_srvdesc_lbl' => 'Описание на сервере',

        'AbProps_syncinfo_seclbl' => 'Синхронизация',
        'AbProps_refresh_time_lbl' => "Интервал обновлений (в часах)",
        'AbProps_lastupdate_time_lbl'=>'Время последнего обновления',

        'AccAbProps_miscsettings_seclbl' => 'Дополнительные настройки',
        'AccAbProps_abookinitsettings_seclbl' => 'Изначальные настройки для адресных книг в данном профиле',
        'AbProps_newgroupstype_lbl' => 'Типы новых контактов',
        'AbProps_grouptype_vcard_lbl' => 'Отдельные KIND=group (RFC6350) VCards для групп (лучше совместимость с приложениями Apple)',
        'AbProps_grouptype_categories_lbl' => 'Хранить группы как CATEGORIES в контакте VCards (лучше совместимость с приложениями не от Apple)',
        'AbProps_reqemail_lbl' => 'Скрывать контакты без почты',

        // Add new account page
        'AccProps_newaccount_lbl' => 'Добавить новый профиль CardDAV',

        // Labels used in messages emitted by backend
        'Be_etagmismatch_msg' => 'Ошибка при обновлении - адрес объекта поменялся на сервере',
);
?>
