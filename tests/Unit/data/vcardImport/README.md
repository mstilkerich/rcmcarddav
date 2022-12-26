# Tests for data conversion of VCards to Roundcube internal data

This folder contains the test data sets for testing the data conversion process from a VCard as received from the
CardDAV server into roundcube's internal representation.

There are a few specifics to be tested during the import:

- In roundcube we can only display one label/subtype for a property (e.g. home email address). In VCard, however,
  multiple such subtypes can be specified. Therefore it is necessary for RCMCardDAV to select one if several are given.
  - `X-ABLabel` custom labels have the highest priority
  - Standard labels assigned via the type parameter have a builtin precedence (e.g. _home_ is preferred over _internet_)
  - If no label can be determined, it defaults to _other_
- A photo can be specified either inline or referenced as external URI
- For photos, cropping via `X-ABCROP-RECTANGLE` extension is supported
- Special consideration should be given to `ADR`, `ORG` and `N` attributes as they are composed of multiple parts that
  map to individual roundcube attributes.
- If no `FN` attribute is contained or it is empty, RCMCardDAV composes a displayname from the `ORG` or `N` property,
  depending on a setting of X-ABShowAs,  falling back to `EMAIL` and `TEL` if no name is available.

## Short description of each data set

- AllAttr: This is a simple VCard that contains all supported attributes
- EmptyAttrs: This test is intended to test empty property values, mostly with the reaction that they should not be
  imported.
- EmptyFN: Contains an empty display name (`FN`) property. RCMCardDav should compose a displayname from the `N`
  property.
- EmptyFNCompany: Like EmptyFN, but with empty `N` property and a set `ORG` property.
- EmptyFNEmail: Like EmptyFN, but with empty `N` and `ORG` properties, but available `EMAIL` and `TEL`. Displayname must
  be composed from the mail address.
- EmptyFNPhone: Like EmptyFN, but with empty `N` and `ORG` properties, but available `TEL`. Displayname must be set from
  the phone number.
- EmptyFNBlank: Like EmptyFN, but with no properties usable for a name at all.
- CompanySetFN: A card with an `X-ABSHOWAS` property set to COMPANY and a `ORG` value that differs from the `FN` value.
  The `FN` must be kept.
- LabelPreference: Uses several standard labels in different order on an `EMAIL` property. RCMCardDAV should select the
  label with the highest preference.
- InlinePhoto: Contains a `PHOTO` property stored as base64 encoded data inside the VCard.
- UriPhoto: Contains a `PHOTO` property where the picture file is referenced by URI and stored externally. RCMCardDAV
  should retrieve the picture from the given URL and provide it to roundcube.
- UriPhotoCrop: Like UriPhoto, but additionally contains an `X-ABCROP-RECTANGLE` parameter that requests that only a
  crop of the entire image should be displayed to the user.
- InvalidUriPhoto: Like UriPhoto, but the referenced URI will return error when attempting to fetch
- XAbLabel: Contains a custom label assigned via `X-ABLABEL` property to an EMAIL property. Furthermore, it also
  contains a second `EMAIL` property that is also part of a group, but that group has no `X-ABLABEL` property. A
  standard or default label must be selected instead.
- XAbLabelAppleBuiltin: Uses the special Apple syntax in an `X-ABLABEL` that Apple uses for their builtin extra labels.
- XAbLabelOnly: Contains an `EMAIL` property that only has a subtype assigned via `X-ABLABEL`, no `TYPE` param is
  attached.
- Group: A KIND=group VCard
- IM-eMClient: A VCard containing instant messaging attributes produced by eMClient
- IM-Evolution: A VCard containing instant messaging attributes produced by evolution
- IM-GoogleContacts: A VCard containing instant messaging attributes produced by Google contacts
- IM-iOS: A VCard containing instant messaging attributes produced by iOS addressbook
- IM-KAddressbook: A VCard containing instant messaging attributes produced by KAddressbook
- IM-Nextcloud: A VCard containing instant messaging attributes produced by nextcloud
- IM-Owncloud: A VCard containing instant messaging attributes produced by owncloud
- VCard4-DataUriPhoto: A v4 VCard containing a PHOTO in data URI format. 
- ZeroStrings: Tests "0" strings in various places of the VCard, which are considered "empty" by php's empty function.
  This must not cause these properties to be discarded during the import like properties with no value.

