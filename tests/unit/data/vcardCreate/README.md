# Tests for data conversion of Roundcube internal data to a fresh VCard

This folder contains the test data sets for testing the data conversion process from roundcube's internal representation
to a _fresh_ VCard.

There are a few specifics to be tested during the creation:
- Unset attributes can either be missing from the save\_data array provided by roundcube, or they can be present as
  empty strings.
- Photo is special:
  - If set in the save data, the photo was changed/added by the user
  - If _not set_, the photo was left unchanged
  - If set to an empty string, the photo was deleted by the user
- Multi-value attributes are provided as arrays, that may contain empty members (i.e. empty strings) if not set and
  should not be added to the VCard.

## Short description of each data set

- AllAttr: Contains settings for all currently supported attributes. For the multi-value attributes, it also contains
  empty entries that are not supposed to be added to the VCard.
- InlinePhoto: Includes a photo set to binary data
- XAbLabelOnly: Contains two email addresses assigned a special label. These should be assigned using `X-ABLABEL` in the
  created VCard.
- DepartmentOnly: Contains a department setting with multiple levels, but no organization. Department must end up in the
  parts 1+ of the `ORG` property, the empty organization in this case must result in an empty part 0 of `ORG`.
