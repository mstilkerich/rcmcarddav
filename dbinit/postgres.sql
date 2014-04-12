CREATE SEQUENCE carddav_addressbooks_seq
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

-- table to store the configured address books

CREATE TABLE carddav_addressbooks (
	id integer DEFAULT nextval('carddav_addressbooks_seq'::text) PRIMARY KEY,
	name VARCHAR(64) NOT NULL,
	username VARCHAR(64) NOT NULL,
	password VARCHAR(255) NOT NULL,
	url VARCHAR(255) NOT NULL,
	active SMALLINT NOT NULL DEFAULT 1,
	user_id integer NOT NULL REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
	last_updated TIMESTAMP NOT NULL DEFAULT '-infinity', -- time stamp of the last update of the local database
	refresh_time INTERVAL NOT NULL DEFAULT '01:00:00', -- time span after that the local database will be refreshed, default 1h
	sync_token VARCHAR(255) NOT NULL DEFAULT '', -- sync-token the server sent us for the last sync
	preemptive_auth SMALLINT NOT NULL DEFAULT 0, -- should we send Authorization headers preemptively?

	presetname VARCHAR(64) -- presetname
);

CREATE SEQUENCE carddav_contacts_seq
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

CREATE TABLE carddav_contacts (
	id integer DEFAULT nextval('carddav_contacts_seq'::text) PRIMARY KEY,
	abook_id integer NOT NULL REFERENCES carddav_addressbooks (id) ON DELETE CASCADE ON UPDATE CASCADE,
	name VARCHAR(255)     NOT NULL, -- display name
	email VARCHAR(255), -- ", " separated list of mail addresses
	firstname VARCHAR(255),
	surname VARCHAR(255),
	organization VARCHAR(255),
	showas VARCHAR(32) NOT NULL DEFAULT '', -- special display type (e.g., as a company)
	vcard text NOT NULL,        -- complete vcard
	etag VARCHAR(255) NOT NULL, -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(255) NOT NULL,  -- path of the card on the server
	cuid VARCHAR(255) NOT NULL,  -- unique identifier of the card within the collection

	UNIQUE(uri,abook_id),
	UNIQUE(cuid,abook_id)
);

CREATE INDEX carddav_contacts_abook_id_idx ON carddav_contacts(abook_id);

CREATE SEQUENCE carddav_xsubtypes_seq
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

CREATE TABLE carddav_xsubtypes (
	id integer DEFAULT nextval('carddav_xsubtypes_seq'::text) PRIMARY KEY,
	typename VARCHAR(128) NOT NULL,  -- name of the type
	subtype  VARCHAR(128) NOT NULL,  -- name of the subtype
	abook_id integer NOT NULL REFERENCES carddav_addressbooks (id) ON DELETE CASCADE ON UPDATE CASCADE,
	UNIQUE (typename,subtype,abook_id) 
);

CREATE SEQUENCE carddav_groups_seq
    INCREMENT BY 1
    NO MAXVALUE
		MINVALUE 1
    CACHE 1;

CREATE TABLE carddav_groups (
	id integer DEFAULT nextval('carddav_groups_seq'::text) PRIMARY KEY,
	abook_id integer NOT NULL REFERENCES carddav_addressbooks (id) ON DELETE CASCADE ON UPDATE CASCADE,
	name VARCHAR(255) NOT NULL, -- display name
	vcard TEXT NOT NULL,        -- complete vcard
	etag VARCHAR(255) NOT NULL, -- entity tag, can be used to check if card changed on server
	uri  VARCHAR(255) NOT NULL, -- path of the card on the server
	cuid VARCHAR(255) NOT NULL, -- unique identifier of the card within the collection
	
	UNIQUE(uri,abook_id),
	UNIQUE(cuid,abook_id)
);

CREATE TABLE IF NOT EXISTS carddav_group_user (
	group_id   integer NOT NULL,
	contact_id integer NOT NULL,

	PRIMARY KEY(group_id,contact_id),
	FOREIGN KEY(group_id) REFERENCES carddav_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
	FOREIGN KEY(contact_id) REFERENCES carddav_contacts(id) ON DELETE CASCADE ON UPDATE CASCADE
);
