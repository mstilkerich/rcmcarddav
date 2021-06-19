# Tests for export of VCards

This folder contains the test data sets for testing the VCard export as received from the CardDAV server with minimal
adaptations considered to improve interoperability with the importing application. Currently, these adaptations only
concern the PHOTO property, the rest of the VCard is expected as retrieved from the server.

There are a few specifics to be tested during the export:

- A PHOTO property referenced by URI must be inlined into the VCard
  - In case of error fetching the PHOTO, the original PHOTO property is retained.
- A PHOTO property with an `X-ABCROP-RECTANGLE` shall be stored cropped in the exported card.
- The remaining properties must be untouched, including:
  - Properties not known to rcmcarddav
  - Multiple occurences of TYPE parameter on a property (rcmcarddav only supports one)

## Short description of each data set

- PhotoInlined: Contains a PHOTO referenced by URI; it must be stored inline in the exported card.
- PhotoInlinedCropped: Contains a PHOTO referenced by URI with `X-ABCROP-RECTANGLE`; it must be stored inline and
  cropped in the exported card.
- UnknownProperties: Contains properties not known to rcmcarddav, and multiple TYPE parameters, including `X-ABLABEL`
  for some properties. These must all be preserved in the exported card.
- InvalidUriPhoto: Contains a PHOTO referenced by an invalid URI; the property must be left untouched.
