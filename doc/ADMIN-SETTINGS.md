# Global configuration of RCMCardDAV by the administrator

RCMCardDAV provides some configuration options for the administrator that define what functionalities are available for
the individual users. If all users of roundcube share a common addressbook service, it is also possible for the
administrator to pre-configure such addressbooks as presets so that they are automatically available for the users.

The plugin configuration settings for the administrator are located in the file `config.inc.php` in the plugin
directory. There is a template file `config.inc.php.dist`, that can initially be copied and customized. The admin
settings are optional, i.e. the plugin will be functional without.

## Logging configuration

The plugin uses roundcube's facilities for logging. It creates two log files, `carddav.log` and `carddav_http.log`. The
latter contains a log of the HTTP traffic. Because of its verbosity, it is put in a separate log file and can also be
configured with a separate log level. The log files are found in the `logs/` subdirectory of the roundcube installation,
if logging to files is configured in the roundcube configuration.

The settings relevant to logging are:

```php
$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::WARNING;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::ERROR;
```

They give the minimum log level required for log messages to be recorded in the log files.

Use the constants from \Psr\Log\LogLevel, any of the following are possible:
`DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, `CRITICAL`, `ALERT`, `EMERGENCY`

Per default, both log levels are set to `ERROR`.

## Limiting feature set available to users

The administrator can limit the feature set offered by the plugin for ordinary users using the following settings.

- `$prefs['_GLOBAL']['fixed']`: If true, users are not able to add custom addressbooks (Default: false)
   This option only affects custom addressbooks. Preset addressbooks (see below) are not affected. If the user already
   created custom addressbooks and the admin then changes this setting to true, the user will still be able to
   edit/delete their previously created addressbooks, but not add new ones anymore.

- `$prefs['_GLOBAL']['hide_preferences']`: If true, CardDAV settings are not shown in preferences pane (Default: false)
   In effect, the user can neither add/remove or change preferences of any addressbooks, including preconfigured ones.

## Password storing scheme

The plugin needs to store the user's CardDAV passwords in the roundcube database, except for the special case where the
CardDAV password is the same as the user's roundcube / IMAP password (see [placeholder substitution for
passwords](#password). There is different options on how the passwords can be stored, with different degrees of
convenience and privacy. For all variants, the administrators of the server generally needs to be trusted, as they would
with more or less effort always be able to determine the password.

The password scheme is configured in `$prefs['_GLOBAL']['pwstore_scheme']`. The available schemes are:

- `plain`: The password is stored as plaintext in the database.
- `base64`: The password is stored BASE64-encoded in the database. This has the advantage that an admin working on the
  database will not see the passwords in cleartext by accident.
- `des_key`: The password is encrypted with roundcube's installation encryption key (`$config['des_key']`) and cipher
  (`$config['cipher_method']`). If the key is changed, the passwords cannot be decrypted anymore and would need to be
  updated by each user for custom addressbooks.
- `encrypted` (DEFAULT):  The password is encrypted with the cipher method configured in roundcube
  (`$config['cipher_method']`), but using the user's roundcube password as encryption key. Similarly, if the user
  changes their roundcube/IMAP password, the CardDAV password cannot be decrypted anymore and needs to be entered in the
  settings again. Note: If a password-less login method (OAuth2, Kerberos) is used, this encryption scheme cannot be
  used as no password for the encryption is available.

When the password scheme setting is changed, only passwords stored/updated after the change of the setting will be
stored with the new scheme.

## Placeholder substitutions in Username, Password and URL

It is possible to user placeholders inside the addressbook fields. This is mainly useful in combination with
addressbooks preconfigured by the admin. The available substitutions depend on the field.

### Username and URL

In the username and URL fields, the following substitutions are available:

 - `%u`: Replaced by the full roundcube username
 - `%l`: Replaced by the local part of the roundcube username if it is an email address.
         (Example: Roundcube username `theuser@example.com` - `%l` is replaced with `theuser`)
 - `%d`: Replaced by the domain part of the roundcube username if it is an email address.
         (Example: Roundcube username `theuser@example.com` - `%d` is replaced with `example.com`)
 - `%V`: Replaced by the roundcube username, with `@` and `.` characters substituted by `_`.
         (Example: Roundcube username `user.name@example.com` - `%V` is replaced with `user_name_example_com`)

### Password

In the password field, the following special values are substituted:

 - `%p`: Replaced by the roundcube/IMAP password of the user.
 - `%b`: Marker to use bearer authentication. If no preset-specific OAUTH configuration is configured, the bearer token
   acquired by roundcube during login with OAUTH2 (available from roundcube 1.5) is used. The username is not used and
   can be empty in that case.

Substitution only works if the password is exactly the placeholder, i.e. this placeholder is not replaced if it is part
of a larger string. In this case, the placeholder is also stored as password in the database, so the user can avoid
having their password stored in the roundcube database at all.

## Preconfigured Addressbooks (Presets)

Preconfigured addressbook (a.k.a. _presets_) enable the administrator to configure addressbooks that are automatically
added to a user's account on their first login, and optionally re-discovered upon subsequent logins. Furthermore, it is
possible to have the settings of preconfigured addressbooks updated automatically.

A preconfigured addressbook configuration looks like this:

```php
$prefs['<Presetname>'] = [
    // required attributes
    'name'         =>  '<Addressbook Name>',
    'url'          =>  '<CardDAV Discovery URL>',

    // required attributes unless passwordless authentication is used (Kerberos)
    'username'     =>  '<CardDAV Username>',
    'password'     =>  '<CardDAV Password>',

    // optional attributes
    'active'       =>  <true or false>,
    'readonly'     =>  <true or false>,
    'refresh_time' => '<Refresh Time in Hours, Format HH[:MM[:SS]]>',
    'rediscover_mode' => '<One of: none|fulldiscovery',

    // attributes that are fixed (i.e., not editable by the user) and auto-updated for this preset
    'fixed'        =>  [ < 0 or more of the other attribute keys > ],

    // always require these attributes, even for addressbook view
    'require_always' => ['email'],

    // hide this preset from CardDAV preferences section so users can't even see it
    'hide' => <true or false>,
];

```

The following describes the configuration options for a preset addressbook.

`<Presetname>` needs to be a unique preset name. `<Presetname>` must not be `_GLOBAL`. The presetname is only used for
an internal mapping between addressbooks created from a preset and the preset itself. You should never change this
throughout its lifetime. If changed, the effect will be as if the existing preset was deleted and and a new one was
added, resulting in deletion of the existing addressbooks from the database and creation of new ones.

### Required parameters

 - `name`: User-visible name of the addressbook. If the server provides an additional display name for the addressbooks
           found for the preset, it will be appended in brackets to this name, except if carddav_name_only is true (see
           below).
 - `url`: URL where to find the CardDAV addressbook(s). This URL is taken to start a discovery process for addressbooks
          according to RFC 6764. If the URL points to an addressbook __outside__ the user's addressbook home (i.e. a
          shared or public addressbook), __only__ this addressbook is added. Otherwise, all discovered addressbooks are
          added. That means, even if the URL points to an individual addressbook that belongs to the user, all other
          addressbooks of the user are added as well.

All addressbooks found will be added. The URL is fixed after creation of an addressbook and cannot currently be updated
automatically. You can use the same placeholders as for the username field.

### Required parameters unless password-less authentication is used (e.g. Kerberos)
 - `username`: CardDAV username to access the addressbook.
 - `password`: CardDAV password to access the addressbook.

### Optional parameters
 - `active`: If this parameter is false, the addressbook is not used by roundcube unless the user changes this setting.
             Default: true
 - `carddav_name_only`: If this parameter is true, only the server provided displayname is used for addressbooks created
             from this preset, except if the server does not provide a display name.
             Default: false
 - `readonly`: If this parameter is true, the addressbook will only be accessible in read-only mode, i.e., the user will
             not be able to add, modify or delete contacts in the addressbook.
             Default: false
 - `refresh_time`: Time interval for that cached versions of the addressbook entries should be used, in hours. After
             this time interval has passed since the last pull from the server, it will be refreshed when the
             addressbook is accessed the next time. Format: `HH[:MM[:SS]]`
             Default: 01:00:00 (1 hour)
 - `use_categories`: If this parameter is true, new contact groups created by the user from within roundcube will be
             created as CATEGORIES-type groups.
             Default: false
 - `fixed`: Array of parameter keys that must not be changed by the user.
             Note that only fixed parameters will be automatically updated for existing addressbooks created from
             presets. Otherwise the user may already have changed the setting, and his change would be lost. You can add
             any of the above keys, but it the setting only affects parameters that can be changed via the settings pane
             (e.g., readonly cannot be changed by the user anyway). Still only parameters listed as fixed will
             automatically updated if the preset is changed.
             Default: empty, all settings modifiable by user
 - `require_always`: If set, this database field is required to be non-empty for ALL queries, even just for displaying
             members. This may be useful if you have shared, read-only addressbooks with a lot of contacts that do not
             have an email address. The following are supported: name, email, firstname, surname
 - `hide`: Whether this preset should be hidden from the CardDAV listing on the preferences page.
 - `rediscover_mode`: Whether this preset should be re-discovered upon every login. This allows to detect new and
             deleted addressbooks at the server side, but comes at the expense of extra communication upon every login.
             Therefore the admin can choose whether such re-discovery should happen or not on a per-preset basis. The
             update of `fixed` settings works independently of this setting.
             The following values are possible (default: `fulldiscovery`):
   - `none`: When addressbooks for the preset are present for the user logging in, no re-discovery will be performed.
             This was the behavior of RCMCardDAV <4.3.0. When the admin knows that the set of addressbooks at the
             server is fixed (the user cannot create/delete addressbooks at the server), this setting reduces network
             traffic and does not unneccessarily slow down the login of the user. Another situation where this settings
             makes sense is if the preset specifies a shared/public addressbook (see description of `url`).
   - `fulldiscovery`: A full rediscovery is attempted on every login. New addressbooks at the server are added, and
             addressbooks that do not exist at the server anymore are removed from roundcube.

### Examples

The following code snippet shows two examples for preset addressbooks.

```php
// Preset 1: Personal
$prefs['Personal'] = [
    // required attributes
    'name'         =>  'Personal',
    // will be substituted for the roundcube username
    'username'     =>  '%u',
    // will be substituted for the roundcube password
    'password'     =>  '%p',
    // %u will be substituted for the CardDAV username
    'url'          =>  'https://ical.example.org/caldav.php/%u/Personal',

    'active'       =>  true,
    'readonly'     =>  false,
    'refresh_time' => '02:00:00',

    'fixed'        =>  ['username'],
    'hide'         =>  false,
];

// Preset 2: Corporate
$prefs['Work'] = [
    'name'         =>  'Corporate',
    'username'     =>  'CorpUser',
    'password'     =>  'C0rpPasswo2d',
    'url'          =>  'corp.example.com',

    'fixed'        =>  ['name', 'username', 'password'],
    'hide'         =>  true,
];
```

### Creation and Updates

Preconfigured addressbooks are processed when the user logs into roundcube.

- An addressbook discovery is performed for each preset, using the information stored with the preset. Depending on the
  `rediscover_mode` setting, the discovery is performed on every login or only when no addressbooks for the preset are
  available for the user.
  - Newly found addressbooks are added, except for the special case of shared/public addressbooks (see documentation of
    the `url` preset parameter).
  - Addressbooks stored in RCMCardDAV that cannot be discovered anymore are deleted.
- For addressbooks already stored in RCMCardDAV, all fields that the admin listed in the `fixed` setting of the
  corresponding preset are updated. Other fields are not updated, since they may have been modified by the user.
- If the user has addressbooks created from a preset that no longer exists (identified by the Presetname), the
  addressbooks are deleted from the database.

### Using presets for the roundcube trusted senders/collected recipients addressbooks

Roundcube 1.5 and newer has two special internal addressbooks to automatically collect all addresses the user previously
sent mail to (roundcube config option: `collected_recipients`) and to collect addresses of trusted senders (roundcube
config option: `collected_senders`).

It is possible for a user to manually select CardDAV addressbooks for these two special purpose addressbooks using
the roundcube settings interface. When using preconfigured CardDAV addressbooks, the admin may want to also set these
special addressbooks by configuration, which is possible using the following configuration options:

```php
$prefs['_GLOBAL']['collected_recipients'] = [
    // Key of the preset
    'preset'  => '<Presetname>',
    // The placeholders that can be used in the url preset attribute can also be used inside these regular rexpressions
    'matchname' => '/collected recipients/i',
    'matchurl' => '#http://carddav.example.com/abooks/%u/CollectedRecipients#',
];
$prefs['_GLOBAL']['collected_senders'] = [
    // Configuration analog to collected recipients
];
```

Each of the above global RCMCardDAV settings will cause the roundcube setting of the same name to be overridden in case
a matching preset addressbook is found. The match works by specifying the key of a preset and further match settings to
filter the matching addressbook in case multiple addressbooks are discovered for the preset. The preset must not be
read-only as roundcube requires both special addressbooks to be writeable. For presets with several addressbooks, the
wanted addressbook can be identified by regular expression matches on the addressbook name and/or URL. The
%-placeholders the are possible in a preset URL also can be used inside these regular expressions.

In case the preset only contains one addressbook, the match settings can be omitted. The match settings must result in
exactly one addressbook. If no or multiple addressbooks match, the roundcube setting is not touched by RCMCardDAV.

Because RCMCardDAV overrides the setting configured in roundcube, including a possible setting by the user, the
possibilty to configure these addressbooks by the user must be disabled if the admin uses this mechanism. Otherwise
the user might be confused as settings made by the user in the roundcube settings will stay without effect. In addition,
it is possible in certain situations (see #391) that preferences made by the user would in fact override the settings
made by RCMCardDAV.

Configuration of these addressbooks by the user can be disabled using the following configuration options in the
roundcube (not RCMCardDAV) configuration:

```php
$config['dont_override'] = ['collected_recipients', 'collected_senders'];
```

When using the trusted senders addressbook, please also configure the roundcube options `show_images` and `mdn_requests`
to define for what purpose the trusted senders are used.

<!-- vim: set ts=4 sw=4 expandtab fenc=utf8 ff=unix tw=120: -->
