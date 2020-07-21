# Changelog for RCMCardDAV

## Version 4.0.0-alpha1 (to 3.0.3)

Note: The Changelog for this version is not complete

This is an alpha release because I did not perform any tests on it. Nevertheless, it has many bugs fixed and I encourage
you to upgrade and report issues as you find them. The last release 3.0.3 has many issues that have been fixed with in
v4. I push this release early mainly because of the security issue reported. I'll continue working on remaining issues I
want to fix (note: all of them are also present in 3.0.3) for v4 and I intend release a more tested version and a more
detailed changelog within the next weeks.

- __Security issue__: It was possible to read data from other user's addressbooks. Depending on the configuration, it
  might also have been possible to change data in their addressbooks. Thanks to @cnmicha for reporting this issue. This
  issue affects all previously released versions of RCMCardDAV using a database cache.
- Many bugs you reported and several more I discovered during refactoring have been fixed.
- The password scheme now defaults to `encrypted` (if you have not configured a password scheme, this will take effect
  automatically for newly stored password. If you don't want this, configure a password scheme in settings.php).
- The URL is not changeable after creation of an addressbook anymore. It used to work in specific, but not all cases. As
  the behavior is potentially broken and not easy to fix, it is removed for now.
- The two kinds of contact groups (VCard-based vs. CATEGORIES-based) are not transparently supported to the possible
  extent. The configuration switch is only meaningful concerning the type of group used when a __new__ group is created
  from RCMCardDAV.
- The CardDAV interaction is moved to a [library](https://github.com/mstilkerich/carddavclient). It is essentially a
  complete rewrite of the code communicating with the CardDAV servers and includes interoperability tests with many
  common servers, see [here](https://github.com/mstilkerich/carddavclient).
