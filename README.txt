Description
===========
Exports Roundcube webmail configuration (users, identities, contacts, groups...) for a given domain, so it can be easily migrated to a target Roundcube system.

This script reads all the registers (i.e, users, contacts, groups...) from a given domain in a Roundcube database, and outputs the required SQL queries to reproduce them in a different Roundcube database without facing primary key conflicts, thus easing the migration of user data from one installation to another.

It has been tested with Roundcube version 2013011000 (as seen with ``SELECT `value` FROM system WHERE `name` = "roundcube-version"``).


Usage
=====
Usage:
	$php export-roundcube-sql.php db-user db-pass domain-to-export

Example (in a Parallels Plesk setup):

	$php export-roundcube-sql.php admin `cat /etc/psa/.psa.shadow` mydomain.com > dump.sql

Then run dump.sql on your target database.


Additional info
===============
* This script only performs **database reads**. It won't actually run any query on the target setup, nor delete the migrated records from the original database.

* The output queries are not [idempotent](http://en.wikipedia.org/wiki/Idempotence): running them more than once will lead to unexpected result. So **do a backup your target database first** and restore it before retrying if something goes wrong in the first place.

* The script will cowardly refuse to run if Roundcube database version is other than "2013011000". Feel free to tamper this restriction if you are running a different version with the same SQL schema than 2013011000.

* It would probably be a good idea to empty the cache tables in the target database after completing the migration.

* This script is thought to be used in-house, so it does little to no data sanitization. Run it only if you trust your inputs.


Author
======
First released on August 2014, by Jaime Gómez Obregón (@JaimeObregon), from ITEISA (@ITEISA).

Has this been useful to you? Spread the love by dropping a tweet :-) 
