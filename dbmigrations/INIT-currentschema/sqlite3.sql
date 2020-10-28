-- table to store the configured address books
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_addressbooks (
	id           integer NOT NULL PRIMARY KEY,
	name         TEXT NOT NULL,
	username     TEXT NOT NULL,
	password     TEXT NOT NULL,
	url          TEXT NOT NULL,
	active       TINYINT UNSIGNED NOT NULL DEFAULT 1,
	user_id      integer NOT NULL,
	last_updated BIGINT NOT NULL DEFAULT 0,  -- time stamp (seconds since epoch) of the last update of the local database
	refresh_time INT NOT NULL DEFAULT 3600, -- time span (seconds) after that the local database will be refreshed, default 1h
	sync_token   TEXT NOT NULL DEFAULT '', -- sync-token the server sent us for the last sync

	presetname   TEXT,                  -- presetname

	use_categories TINYINT NOT NULL DEFAULT 0,

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(user_id) REFERENCES TABLE_PREFIXusers(user_id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_contacts (
	id           integer NOT NULL PRIMARY KEY,
	abook_id     integer NOT NULL,
	name         VARCHAR(255) NOT NULL, -- display name
	email        VARCHAR(255),          -- ", " separated list of mail addresses
	firstname    VARCHAR(255),
	surname      VARCHAR(255),
	organization VARCHAR(255),
	showas       VARCHAR(32) NOT NULL DEFAULT '', -- special display type (e.g., as a company)
	vcard        TEXT NOT NULL,         -- complete vcard
	etag         VARCHAR(255) NOT NULL, -- entity tag, can be used to check if card changed on server
	uri          VARCHAR(255) NOT NULL, -- path of the card on the server
	cuid         VARCHAR(255) NOT NULL, -- unique identifier of the card within the collection

	UNIQUE(uri,abook_id),
	UNIQUE(cuid,abook_id),

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX TABLE_PREFIXcarddav_contacts_abook_id_idx ON TABLE_PREFIXcarddav_contacts(abook_id);

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_xsubtypes (
	id       integer NOT NULL PRIMARY KEY,
	typename VARCHAR(128) NOT NULL,  -- name of the type
	subtype  VARCHAR(128) NOT NULL,  -- name of the subtype
	abook_id integer NOT NULL,

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE,
	UNIQUE(typename,subtype,abook_id)
);
CREATE INDEX TABLE_PREFIXcarddav_xsubtypes_abook_id_idx ON TABLE_PREFIXcarddav_xsubtypes(abook_id);

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_groups (
	id       integer NOT NULL PRIMARY KEY,
	abook_id integer NOT NULL,
	name VARCHAR(255) NOT NULL, -- display name
	vcard TEXT,        -- complete vcard
	etag VARCHAR(255), -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(255), -- path of the card on the server
	cuid VARCHAR(255), -- unique identifier of the card within the collection

	UNIQUE(uri,abook_id),
	UNIQUE(cuid,abook_id),

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(abook_id) REFERENCES TABLE_PREFIXcarddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX TABLE_PREFIXcarddav_groups_abook_id_idx ON TABLE_PREFIXcarddav_groups(abook_id);

CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_group_user (
	group_id   integer NOT NULL,
	contact_id integer NOT NULL,

	PRIMARY KEY(group_id,contact_id),

	-- not enforced by sqlite < 3.6.19
	FOREIGN KEY(group_id) REFERENCES TABLE_PREFIXcarddav_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
	FOREIGN KEY(contact_id) REFERENCES TABLE_PREFIXcarddav_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX TABLE_PREFIXcarddav_group_user_contact_id_idx ON TABLE_PREFIXcarddav_group_user(contact_id);
CREATE INDEX TABLE_PREFIXcarddav_group_user_group_id_idx ON TABLE_PREFIXcarddav_group_user(group_id);

-- table to store the finished migrations
CREATE TABLE IF NOT EXISTS TABLE_PREFIXcarddav_migrations (
	id integer NOT NULL PRIMARY KEY,
	filename TEXT NOT NULL,
	processed_at TIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

	UNIQUE(filename)
);

