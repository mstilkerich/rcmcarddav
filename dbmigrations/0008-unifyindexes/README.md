This migration unifies the indexes among the different databases. So far, only MySQL has all the needed indexes because
it implicitly creates indexes for foreign key columns, which has to be done manually for PostgreSQL and SQLite.

However, migration 0005 also introduced a broken UNIQUE constraint on the addressbooks table exclusively for MySQL
(UNIQUE(user\_id, presetname)) - a user can get several addressbooks from a preset, hence this makes no sense.

Overview of indexes and unique constraint indexes enabled in different databases (primary key omitted):

| Table        |  Index                             | MySQL | PostgreSQL | SQLite |
|--------------|------------------------------------|:-----:|:----------:|:------:|
| Addressbooks | user\_id                           | ✔     | ✖          | ✖      |
| Addressbooks | Unique(user\_id,presetname)        | 🐞    | 👍         | 👍     |
| Contacts     | abook\_id                          | ✔     | ✔          | ✔      |
| Contacts     | Unique(uri, abook\_id)             | ✔     | ✔          | ✔      |
| Contacts     | Unique(cuid, abook\_id)            | ✔     | ✔          | ✔      |
| XSubTypes    | abook\_id                          | ✔     | ✖          | ✖      |
| XSubTypes    | Unique(typename,subtype,abook\_id) | ✔     | ✔          | ✔      |
| Groups       | abook\_id                          | ✔     | ✖          | ✖      |
| Groups       | Unique(uri, abook\_id)             | ✔     | ✔          | ✔      |
| Groups       | Unique(cuid, abook\_id)            | ✔     | ✔          | ✔      |
| Migrations   | Unique(filename)                   | ✔     | ✔          | ✔      |
| Group\_User  | contact\_id                        | ✔     | ✖          | ✖      |
| Group\_User  | group\_id                          | ✔     | ✖          | ✖      |

This migration drops the broken index of MySQL and adds the missing indexes for SQLite and PostgreSQL.
