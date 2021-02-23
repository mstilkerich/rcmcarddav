# Database Collations - Sorting and Comparisons

RCMCardDAV supports several database management systems for storing its data. These provide different mechanisms when it
comes to sorting and comparing character data stored and indexed in the database. This document collects the
requirements of RCMCardDAV concerning sorting/comparison of character data _performed by the database_, and the
implementation details needed to address them for the supported database management systems.

Database management systems typically provide different ways of sorting character data, so called _collations_. There
may be default collations for the entire database, default collations on individual tables and collations assigned as
part of column definitions. It may also be possible to use a different collation in indexes or as part of individual
queries and string literal uses. The exact capabilities depend on the DBMS.

Generally, RCMCardDAV attempts to utilize the UTF-8 character set for storing character data in the DBMS, as well as
when exchanging character data with the DBMS.

## Requirements of RCMCardDAV

RCMCardDAV avoids relying on the DBMS for sorting data because of the differences in available collations and operators.
However, when result limiting is applied (paging through the addressbook), it can only be used with DB-side filtering
unless the limit is also performed inside RCMCardDAV, which means more and possibly a lot of data is uselessly
transferred from the database. Case-insensitive sorting is preferable for all current use cases, so the order option of
`Database::get()` is defined to sort ignoring case.

More important is the impact on comparisons, which firstly affect the retrieval of data (for example, what records are
presented to the user, and which are not), and `UNIQUE` constraints in the database that is affected by whether to
strings are considered equal or not (especially with respect to case sensitivity).

The overarching goal is that the behavior of RCMCardDAV as visible to the end user should be same for all supported
DBMS.

1. The Database class of RCMCardDAV provides an `ilike()` operation which is expected to select data in a
   case-insensitive way. `ILIKE` is not a standard SQL operator and needs to be implemented in a DBMS-specific way.
   This operation is also used by the corresponding selection criterion provided by `Database::get()`.
2. `UNIQUE` constraints including character data attributes:
  - Unique `uri` for contacts entry in the `carddav_contacts` table: These URIs are generally case sensitive. In a URL,
    the domain and scheme parts would in principal be considered case insensitive, but all servers observered so far
    return URIs for VCards that only contain the path component, which is case sensitive.
  - Unique `cuid` for contacts entry in the `carddav_contacts` table: In VCard3, the UID type is defined as 8-bit
    data string, in VCard4 it should take the form of an URI. Because the data is opaque and should be constant for an
    address object, the safe option is to use case-sensitive behavior when comparing UID values.
  - Unique `subtype` name for each user-defined subtype of a `type` in the `carddav_xsubtypes` table: We aim to preserve
    the data as entered by the user, so we consider two subtypes different if not spelled exactly the same way. Note
    that the typename is hard-coded data in roundcube (e.g. email, phone), where we know there will only be one kind of
    spelling; thus case is not relevant concerning the typename.
  - Unique `uri` for group entry in the `carddav_groups` table: Analog to contacts.
  - Unique `cuid` for group entry in the `carddav_groups` table: Analog to contacts.
  - Unique `filename` in the `carddav_migrations` table: Filenames stored here are defined by the plugin. We will never
    use the same filename with different lowercase/uppercase spelling, so it does not really matter.
  Bottom line: for all currently used UNIQUE indexes, case-sensitive behavior is either required or acceptable.

## DBMS-specific behavior and implementation

For sorting, `Database::get()` will ask the DBMS to order on the uppercased field values to achieve case insensitive
sorting.

### MySQL

We use `utf8mb4` character set for storing the data (enables storage of full unicode character set). Where
case-insensitive behavior is needed, we use the collation `utf8mb4_unicode_ci`, where case-sensitive behavior is needed,
we use the `utf8mb4_bin` collation (in MySQL 5.7, this is the only case sensitive collation for the `utf8mb4` character
set).

MySQL has no special `ILIKE` operator, but a collation can be specified with the pattern provided for the `LIKE`
operator.

MySQL `UNIQUE` indexes use the collation applicable to the indexed columns. There is currently no way to specify a
different collation for the index than the one assigned to an indexed column. Using expressions, it would be possible to
create a case-insensitive index for a column using a case-sensitive collation, but not vice versa.

### PostgreSQL

PostgreSQL by default uses case-sensitive collation behavior. It provides an ILIKE operator as a case-insensitive
variant of LIKE.

### SQLite 3

SQLite 3 per default uses binary collation behavior (i.e. case sensitive) on indexes and comparisons, but case
insensitive behavior in LIKE (only concerning ASCII characters, but this is all SQLite 3 supports concerning case
sensitivity.
