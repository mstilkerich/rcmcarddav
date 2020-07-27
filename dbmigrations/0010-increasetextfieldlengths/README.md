This migration further enlarges / unified the allowed sizes of some text fields.

A little bit of research on the text data types of the different databases:

## SQLite 3

SQLite 3 uses dynamic typing for columns, it is only possible to select a _type affinity_ for a column. For character
data, the proper affinity is TEXT. Internally, everything is TEXT with no length constraint.

__Bottom line__: Use TEXT for text columns, anything else is confusing and might indicate properties that are ignored by
the database.

## MySQL InnoDB

Note that lengths are given in bytes, and a character may occupy up to 4 bytes.

| Datatype     | Storage for L bytes  |  Max length in bytes |
|--------------|----------------------|---------------------:|
| TINYTEXT     | L + 1                | 255                  |
| TEXT         | L + 2                | 65535                |
| MEDIUMTEXT   | L + 3                | 16777215             |
| LONGTEXT     | L + 4                | 4294967295           |
| VARCHAR(M)   | L + 1, L + 2         | L < 256, L < 65536   |

- TEXT columns do not support default values
- TEXT columns are not stored in-row
- Indexes on text columns require specification of a prefix length

__Bottom line__: Stick to VARCHAR() where possible or needed, particularly where indexes and default values are
required.

## PostgreSQL

- TEXT can have arbitrary length
- VARCHAR() can be up to 65535 length
- No performance differences between the two
- Storage requirement: L+1 for L < 127, L+4 otherwise

__Bottom line__: Use TEXT unless a length limit is intended
