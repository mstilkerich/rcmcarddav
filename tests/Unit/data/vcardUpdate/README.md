# Tests for data conversion of Roundcube internal data based on an existing VCard

This folder contains the test data sets for testing the data conversion process from roundcube's internal representation
to an _updated_ VCard.

In addition to the creation of fresh VCards, the updating of VCard must address that unsupported attributes are
preserved.

## Short description of each data set

- DeleteAllAttrEmptyString: All attributes from the original VCard are deleted giving an empty string in the attribute; 
- DeleteAllAttrNoValue: Like DeleteAllAttrEmptyString, but save\_data contains no key/value at all for deleted
  attributes.
- PhotoDeleted: A PHOTO property shall be deleted (save\_data contains a `photo` key with empty value)
- PhotoUnchanged: A PHOTO property shall be left unchanged (save\_data contains no `photo`)
- PhotoUpdated: A PHOTO property shall be updated with a new photo
- XABLabel: Two EMAIL properties have a custom label given with X-ABLabel in the original VCard. One of the addresses
  gets a new custom attribute, while the other one retains the original custom label.
- Group: Update a KIND=group VCard to have a new group name
- VCard4-DataUriPhotoUnchanged: Preserve inline photo including mime-type in v4 vcard
- VCard4-DataUriPhotoUpdated: Update an inline photo in a v4 vcard
- ZeroStrings: save data contains "0" values from some data fields, which are considered empty() by PHPs empty()
  function. These properties must be properly set to 0 in the updated VCard, not omitted.
