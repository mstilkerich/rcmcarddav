CREATE SEQUENCE carddav_addressbook_ids
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

-- table to store the configured address books

CREATE TABLE IF NOT EXISTS carddav_addressbooks (
	id integer DEFAULT nextval('carddav_addressbook_ids'::text) PRIMARY KEY,
	name VARCHAR(64) NOT NULL,
	username VARCHAR(64) NOT NULL,
	password VARCHAR(64) NOT NULL,
	url VARCHAR(255) NOT NULL,
	active SMALLINT NOT NULL DEFAULT 1,
	user_id integer NOT NULL REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
	last_updated TIMESTAMP DEFAULT '-infinity',     -- time stamp of the last update of the local database
	refresh_time INTERVAL DEFAULT '1:00', -- time span after that the local database will be refreshed, default 1h
	sortorder VARCHAR(64) NOT NULL,
	displayorder VARCHAR(64) NOT NULL
);

CREATE SEQUENCE carddav_contact_ids
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

CREATE TABLE IF NOT EXISTS carddav_contacts (
	id integer DEFAULT nextval('carddav_contact_ids'::text) PRIMARY KEY,
	abook_id integer NOT NULL REFERENCES carddav_addressbooks (id) ON DELETE CASCADE ON UPDATE CASCADE,
	name VARCHAR(255)     NOT NULL, -- display name
	sortname VARCHAR(255) NOT NULL, -- sort name
	email VARCHAR(255), -- ", " separated list of mail addresses
	firstname VARCHAR(255),
	surname VARCHAR(255),
	organization VARCHAR(255),
	showas VARCHAR(32) NOT NULL DEFAULT '', -- special display type (e.g., as a company)
	vcard text,         -- complete vcard
	words text,         -- search keywords
	etag VARCHAR(255),  -- entity tag, can be used to check if card changed on server
	cuid VARCHAR(255)   -- unique identifier of the card within the collection
);

CREATE INDEX carddav_contacts_abook_id_idx ON carddav_contacts(abook_id);

CREATE SEQUENCE carddav_xsubtype_ids
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

CREATE TABLE IF NOT EXISTS carddav_xsubtypes (
	id integer DEFAULT nextval('carddav_xsubtype_ids'::text) PRIMARY KEY,
	typename VARCHAR(128) NOT NULL,  -- name of the type
	subtype  VARCHAR(128) NOT NULL,  -- name of the subtype
	abook_id integer NOT NULL REFERENCES carddav_addressbooks (id) ON DELETE CASCADE ON UPDATE CASCADE,
	UNIQUE (typename,subtype,abook_id) 
);

