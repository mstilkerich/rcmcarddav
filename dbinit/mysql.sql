-- table to store the configured address books
CREATE TABLE IF NOT EXISTS carddav_addressbooks (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 	name VARCHAR(64) NOT NULL,
 	username VARCHAR(64) NOT NULL,
 	password VARCHAR(64) NOT NULL,
 	url VARCHAR(255) NOT NULL,
 	active TINYINT UNSIGNED NOT NULL DEFAULT 1,
 	user_id INT(10) UNSIGNED NOT NULL REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
 	last_updated TIMESTAMP DEFAULT 0,
);

CREATE TABLE IF NOT EXISTS carddav_contacts (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	abook_id INT UNSIGNED NOT NULL REFERENCES carddav_addressbooks(id) ON DELETE CASCADE ON UPDATE CASCADE,
	name VARCHAR(255),  -- display name, or <FirstName> <LastName> if not set
	email VARCHAR(255), -- ", " separated list of mail addresses
	firstname VARCHAR(255),
	surname VARCHAR(255),
	vcard LONGTEXT,     -- complete vcard
	words text,         -- search keywords
	etag VARCHAR(255),  -- entity tag, can be used to check if card changed on server
	cuid VARCHAR(255)   -- unique identifier of the card within the collection
);
