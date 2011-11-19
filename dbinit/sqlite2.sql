-- table to store the configured address books
CREATE TABLE carddav_addressbooks (
	id           integer NOT NULL PRIMARY KEY,
	name         VARCHAR(64) NOT NULL,
	username     VARCHAR(64) NOT NULL,
	password     VARCHAR(64) NOT NULL,
	url          VARCHAR(255) NOT NULL,
	active       TINYINT UNSIGNED NOT NULL DEFAULT 1,
	user_id      integer NOT NULL,
	last_updated DATETIME DEFAULT 0,  -- time stamp of the last update of the local database
	refresh_time TIME DEFAULT '1:00', -- time span after that the local database will be refreshed
	sortorder    VARCHAR(64) NOT NULL,
	displayorder VARCHAR(64) NOT NULL,

	readonly     TINYINT UNSIGNED NOT NULL DEFAULT 0, -- read only addressbook, no add/modify/delete of contacts
	presetname   VARCHAR(64),                         -- presetname, '' if no preset

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
	UNIQUE(user_id,presetname)
);

CREATE TABLE carddav_contacts (
	id           integer NOT NULL PRIMARY KEY,
	abook_id     integer NOT NULL,
	name         VARCHAR(255) NOT NULL, -- display name
	sortname     VARCHAR(255) NOT NULL, -- sort name
	email        VARCHAR(255),          -- ", " separated list of mail addresses
	firstname    VARCHAR(255),
	surname      VARCHAR(255),
	organization VARCHAR(255),
	showas       VARCHAR(32) NOT NULL DEFAULT '', -- special display type (e.g., as a company)
	vcard        TEXT,         -- complete vcard
	words        TEXT,         -- search keywords
	etag         VARCHAR(255), -- entity tag, can be used to check if card changed on server
	cuid         VARCHAR(255), -- unique identifier of the card within the collection

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(abook_id) REFERENCES carddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX carddav_contacts_abook_id_idx ON carddav_contacts(abook_id);

CREATE TABLE carddav_xsubtypes (
	id       integer NOT NULL PRIMARY KEY,
	typename VARCHAR(128) NOT NULL,  -- name of the type
	subtype  VARCHAR(128) NOT NULL,  -- name of the subtype
	abook_id integer NOT NULL,

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(abook_id) REFERENCES carddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE,
	UNIQUE(typename,subtype,abook_id)
);

