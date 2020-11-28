# Support for Instant Messaging Fields

The standardized way of storing instant messaging contact data inside a VCard is the IMPP property, which has been
specified as an extension to VCard 3 by [RFC 4770](https://tools.ietf.org/html/rfc4770) and is included in VCard 4.
According to the specification, the property looks like this:

```
IMPP;TYPE=personal,pref:im:alice@example.com
```

The `TYPE` parameter is used in the same way as for other multi-value properties like `EMAIL` to store the communication
purpose (e.g. home, work). The value of the property should be a URI that indicates the type of messaging service as
the scheme part of the URI (e.g. `im` in the example above). Unfortunately, the RFC does not define any of these
schemes, and so different clients use different ways of storing the IM type with the `IMPP` property, resulting in
interoperability problems.

As if that wasn't enough, there exist non-standard properties that may come from a pre RFC 4770 time, but are still
being used, by some addressbook applications exclusively. Examples for these properties are `X-MSN` and `X-ICQ`.

A check of different addressbook applications yielded the following:

- Apple's addressbook products use the `X-SERVICE-TYPE` parameter to indicate the type, preferred over the URI. TYPE and
  X-ABLabel are used to store the communication purpose, but _not_ the type of instant messaging service. The scheme
  part is not always useable. For example, for `ICQ` the scheme `aim` is used which exists as a service of its own. For
  other supported messaging services, a non standard `x-apple` scheme is used. Summarized, the instant messaging service
  should preferably taken from the `X-SERVICE-TYPE` parameter from VCards create by Apple products. iOS allows
  specification of custom messaging services, which will also end up in the `X-SERVICE-TYPE` paraameter. The Apple
  products set both the `IMPP` property plus the non-standard properties: `X-AIM`, `X-MSN`, `X-ICQ`, `X-YAHOO`,
  `X-JABBER`.
  ```
  item3.X-ICQ;type=pref:1234545
  item3.X-ABLabel:_$!<Other>!$_
  item6.IMPP;X-SERVICE-TYPE=ICQ:aim:1234545
  item6.X-ABLabel:_$!<Other>!$_
  item8.IMPP;X-SERVICE-TYPE=MikesIM:x-apple:mikeim@example.com
  item8.X-ABLabel:MikesIM
  ```
- Google Contacts uses only the `IMPP` property. It's usage is like that of Apple, funnily including usage of the
  `x-apple` URI scheme for some services. The service information can be taken from the `X-SERVICE-TYPE` parameter.
  ```
  item1.IMPP;X-SERVICE-TYPE=AIM:aim:aim@example.com
  item2.IMPP;X-SERVICE-TYPE=ICQ:aim:12345
  item1.X-ABLabel:Other
  item2.X-ABLabel:Other
  ```

- Evolution only uses the non-standard properties:
  ```
  X-AIM;X-EVOLUTION-UI-SLOT=1:hans+aim@example.com
  X-ICQ;X-EVOLUTION-UI-SLOT=2:123455
  X-GROUPWISE;X-EVOLUTION-UI-SLOT=3:hans+groupwise@example.com
  X-JABBER;X-EVOLUTION-UI-SLOT=4:jabber@example.com
  X-YAHOO:yahoo@example.com
  X-GADUGADU:gadugadu@example.com
  X-MSN:msn@example.com
  X-SKYPE:skype@example.com
  X-TWITTER:mtwitterich
  X-GOOGLE-TALK:gtalk@example.com
  ```

- Nextcloud only uses the `IMPP` property. However, as the value it simply stores what the user entered, which will
  normally not include a scheme part. It uses the standard `TYPE` parameter to encode the messaging service.
  ```
  IMPP;TYPE=SKYPE:skype@example.com
  IMPP;TYPE=XMPP:xmpp@example.com
  IMPP;TYPE=IRC:irc@example.com
  IMPP;TYPE=KIK:kik@example.com
  IMPP;TYPE=TELEGRAM:telegram@example.com
  IMPP;TYPE=SIP:sip@example.com
  IMPP;TYPE=QQ:qq@example.com
  IMPP;TYPE=WECHAT:wechat@example.com
  IMPP;TYPE=LINE:line@example.com
  IMPP;TYPE=KAKAOTALK:kakaotalk@example.com
  IMPP;TYPE=MATRIX:matrix@example.com
  IMPP;TYPE=ZOOM:zoom@example.com
  ```

- Owncloud behaves like Nextcloud, except it supports fewer messaging services.

- KAddressbook uses IMPP only and encodes the messaing service in the scheme part of the URI.
  ```
  IMPP:icq:12345
  IMPP:skype:skype@example.com
  IMPP:aim:aim@example.com
  IMPP:gg:gadugadu@example.com
  IMPP:googletalk:gtalk@example.com
  IMPP:groupwise:groupwise@example.com
  IMPP:msn:msn@example.com
  IMPP:twitter:mtwitterich
  ```

- Windows 10 People app does not support IM information

- eMClient uses scheme to encode the messaging service type
  ```
  IMPP:xmpp:jabber@example.com
  IMPP:skype:skype@example.com
  IMPP:icq:12345
  IMPP:msn:msn@example.com
  IMPP:aim:aim@example.com
  IMPP:google:gtalk@example.com
  IMPP:gadu:gadugadu@example.com
  IMPP:irc:irc@example.com
  IMPP:ymsgr:yahoo@example.com
  ```

## Import into roundcube

In roundcube, we cannot separately display a type label (like home, work) and the messaging service type. Therefore, we
can only support displaying the type of messaging service. From the above, the following import approach can be used to
provide maximum interoperability:

For IMPP:
  - If an X-SERVICE-TYPE parameter exists, it is preferred. If it does not contain a known service, we will create one
    from the contained value (to support custom service types specified by iOS addressbook app).
  - If the IMPP value includes a scheme part, we use that if it corresponds to a known service (we may see schemes like
    `x-apple` here that we should not accept for display).
  - Otherwise, we check the TYPE parameter for known instant messaging services. Again, we cannot accept unkown values
    here, as the type parameter may contain other values like `home` or `pref` that do not indicate the messaging
    service.
  - Eventually, fall back to `other`.


For the non-standard `X-*` properties:
  - Import these as well, but check for already known values from the `IMPP` property. Duplicates are skipped. This is
    to allow interoperability with apple products that add both properties for the same contact information.
  - Obviously, only hard-coded known properties can be supported here.

## Storing to CardDAV

Storing is more difficult than importing, since we should store VCards that are correctly interpreted by other clients
whose behavior widely differs. In addition, while considering issues of interoperability, we still want to stay
compliant with RFC 4770.

We adapt the following approach:
  - Add the IMPP attribute. We add the messenger service to the parameter `X-SERVICE-TYPE` and `TYPE`. Because we
    cannot have more than one subtype selected in roundcube, no other `TYPE` values are preserved anyway (this
    limitation is already present concerning the other multi-value properties as well).
  - In the value of the IMPP property, we store the messenger handle as an URI prefixed with a scheme as required by RFC
    4770. Since RFC 4770 defines no URI schemes, we choose schemes in the following order (prio decreasing):
    - As listed at [Wikipedia](https://en.wikipedia.org/wiki/List_of_URI_schemes)
    - As observed in use by other clients.
    - Lower case spelling of the service, if it contains no characters that must not be part of an URI scheme
    - Fallback to x-unknown

In addition, we add the old non-standard properties for those messenger services where such a property is known; this
is what Apple does and allows clients relying on the legacy properties to display the information.

Results observed / known caveats:
  - Generally: When a card is edited in a client that does not know a property, it will leave those properties
    untouched. With IM properties where the information is stored twice (one time in an `XMPP` property, and another
    time in a non-standard `X-*` property), it is likely that upon edit only one property will be adapted and the other
    will be retained with the old information.
  - iCloud addressbook shows all types correctly. When editing the card, however, all service types are emptied and
    would have to be edited by the user again.
  - Evolution shows the IM fields properly for that an `X-*` property exists. Services that are only available in an
    `IMPP` property are not shown.
  - KAddressbook shows all entries, but services that KAddressbook does not know are displayed without a label.
    KAddressbook interprets both the `IMPP` as well as the `X-*` headers with the result that the contact data is shown
    twice where both properties exist.
  - Google shows the data correctly (no duplicates, all service types shown).
  - Nextcloud shows all data properly, but as expected includes the URI scheme in the displayed messenger handle.
  - Owncloud behaves like nextcloud.
  - eMClient shows all entries properly, but lists services it does not know of as unkown instead of showing the label.
    GaduGadu, MSN and Google Talk are shown as unknown because eMClient uses different URI schems than rcmcarddav, but I
    preferred the schemes listed at Wikipedia.

