This migration removes the NOT NULL constraints on the group table's attributes
vcard, etag, uri and cuid. These are not meaningful for groups that are derived
from the CATEGORIES property of VCards.
