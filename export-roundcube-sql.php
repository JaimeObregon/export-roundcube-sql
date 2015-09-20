<?php
/**
 * Exports Roundcube webmail configuration. See README.txt for details.
 * Copyright (C) 2014 ITEISA DESARROLLO Y SISTEMAS, S.L.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

define('VERSION', '0.2');
define('ROUNDCUBE_VERSION_MIN', '2013011000');
define('ROUNDCUBE_VERSION_MAX', '2015030800');
define('ROUNDCUBE_DATABASE', 'roundcubemail');
define('DATABASE_HOST', 'localhost');


/**
 * Convenience function to construct and format the SQL queries.
 */
function format($query, $array, $replacements = array()) {
  global $mysqli;

  $keys = $values = array();

  foreach ($array as $key => $value) {
    if ($key !== '__md5') {
      $keys[] = "`$key`";
      $escapedValue = sprintf('"%s"', $mysqli->real_escape_string($value));
      $values[] = in_array($key, array_keys($replacements)) ? $replacements[$key] : $escapedValue;
    }
  }

  return sprintf($query, implode(', ', $keys), implode(', ', $values));
}

/**
 * Parse command line argument count.
 */
if (count($argv) !== 4) {
  echo <<<EOT
export-roundcube-sql.php  Copyright (C) 2014 ITEISA DESARROLLO Y SISTEMAS, S.L.
This program comes with ABSOLUTELY NO WARRANTY. This is free software, and you
are welcome to redistribute it under certain conditions; See LICENSE.txt for details.

USAGE:
    php export-roundcube-sql.php db-user db-pass domain-to-export

EOT;
  die;
}


/**
 * Connect to the database.
 */
$mysqli = new mysqli(DATABASE_HOST, $argv[1], $argv[2], ROUNDCUBE_DATABASE);
if ($mysqli->connect_errno) {
    die("Error connecting to the database.\n");
}
$mysqli->query('SET NAMES UTF8');

/**
 * Check schema's version.
 */
$results = $mysqli->query('SELECT `value` FROM system WHERE `name` = "roundcube-version"');
$version = $results->fetch_assoc();
if ($version['value'] < ROUNDCUBE_VERSION_MIN OR $version['value'] > ROUNDCUBE_VERSION_MAX) {
  $message = sprintf("This (%s) is not the Roundcube version for which I was designed. See README.txt for details.", $version['value']);
  die("$message\n");
}

/**
 * Load the database registers into $data.
 */

$data = $queries = array();
$queries[] = 'SET NAMES UTF8;';

$results = $mysqli->query('SELECT * FROM users WHERE username LIKE "%' . $argv[3] . '"');
while ($row = $results->fetch_assoc()) {
  $data['users'][] = $row;
}

foreach ($data['users'] as $user) {

  $results = $mysqli->query(sprintf('SELECT * FROM identities WHERE user_id = "%s"', $user['user_id']));
  while ($row = $results->fetch_assoc()) {
    $data['identities'][$user['user_id']][] = $row;
  }

  $results = $mysqli->query(sprintf('SELECT * FROM contacts WHERE user_id = "%s"', $user['user_id']));
  while ($row = $results->fetch_assoc()) {
    $string = '';
    foreach (array('changed', 'del', 'name', 'email', 'firstname', 'surname', 'vcard', 'words') as $field) {
      $string .= $row[$field];
    }
    $row['__md5'] = md5($string);

    $data['contacts'][$user['user_id']][$row['contact_id']] = $row;
  }

  $results = $mysqli->query(sprintf('SELECT * FROM contactgroups WHERE user_id = "%s"', $user['user_id']));
  while ($row = $results->fetch_assoc()) {
    $data['contactgroups'][$user['user_id']][] = $row;
  }

  if (!empty($data['contactgroups'][$user['user_id']])) {
    foreach ($data['contactgroups'][$user['user_id']] as $contactgroup) {
      $template = 'SELECT * FROM contactgroupmembers WHERE contactgroup_id = "%s"';
      $results = $mysqli->query(sprintf($template, $contactgroup['contactgroup_id']));
      while ($row = $results->fetch_assoc()) {
        $data['contactgroupmembers'][$user['user_id']][$contactgroup['contactgroup_id']][] = $row;
      }
    }
  }

}

/**
 * Construct the SQL queries to avoid primary key conflicts when importing on the target database.
 */
foreach ($data['users'] as $user) {
  
  $query = 'INSERT INTO users (%s) VALUES (%s) ON DUPLICATE KEY UPDATE user_id=LAST_INSERT_ID(user_id);';
  $queries[] = format($query, $user, array('user_id' => 'NULL'));
  $queries[] = 'SET @lastUser := LAST_INSERT_ID();';

  if (!empty($data['identities'][$user['user_id']])) {
    foreach ($data['identities'][$user['user_id']] as $identity) {
      $query = 'INSERT INTO identities (%s) VALUES (%s);';
      $queries[] = format($query, $identity, array(
        'identity_id' => 'NULL',
        'user_id' => '@lastUser')
      );
    }
  }

  if (!empty($data['contacts'][$user['user_id']])) {
    foreach ($data['contacts'][$user['user_id']] as $contact) {
      $query = 'INSERT INTO contacts (%s) VALUES (%s);';
      $queries[] = format($query, $contact, array(
        'contact_id' => 'NULL',
        'user_id' => '@lastUser',
        '__md5' => false)
      );
    }
  }

  if (!empty($data['contactgroups'][$user['user_id']])) {
    foreach ($data['contactgroups'][$user['user_id']] as $contactgroup) {
      $query = 'INSERT INTO contactgroups (%s) VALUES (%s) ON DUPLICATE KEY UPDATE contactgroup_id=LAST_INSERT_ID(contactgroup_id);';
      $queries[] = format($query, $contactgroup, array(
        'contactgroup_id' => 'NULL',
        'user_id' => '@lastUser')
      );
      $queries[] = 'SET @lastGroup := LAST_INSERT_ID();';

      if (!empty($data['contactgroupmembers'][$user['user_id']][$contactgroup['contactgroup_id']])) {
        foreach ($data['contactgroupmembers'][$user['user_id']][$contactgroup['contactgroup_id']] as $contactgroupmember) {

          $query = 'INSERT INTO contactgroupmembers (%s) VALUES (%s);';
          $template = "(SELECT `contact_id` FROM `contacts` WHERE MD5(CONCAT(changed, del, name, email, firstname, surname, vcard, words)) = '%s')";
          $contact_id = sprintf($template, $data['contacts'][$user['user_id']][$contactgroupmember['contact_id']]['__md5']);
          $replacements = array(
            'contactgroupmember_id' => 'NULL',
            'contactgroup_id' => '@lastGroup',
            'contact_id' => $contact_id,
          ); 
          $queries[] = format($query, $contactgroupmember, $replacements);

        }
      }
    }
  }

}


/**
 * Send the SQL queries to stdout.
 */
echo implode("\n", $queries) . "\n";
