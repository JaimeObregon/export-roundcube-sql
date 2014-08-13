<?php

/**
 * Script para facilitar la migración de datos de Roundcube de un dominio.
 * Véase ticket #1012872.
 *
 * Este script lee la base de datos ``roundcubemail``, extrae todos los registros
 * relacionados con buzones del dominio facilitado como primer parámetro, y devuelve
 * por ``stdout`` las sentencias SQL a ejecutar en la base de datos del servidor de
 * destino, convenientemente modificadas para que no haya conflictos con las claves
 * primarias de cada base de datos.
 *
 * @copyright     Copyright 2014, ITEISA DESARROLLO Y SISTEMAS, S.L (http://iteisa.com)
 */

/**
 * Función de apoyo para facilitar el formateo de las consultas.
 */
function format($query, $array, $replacements = array()) {
  global $mysqli;

  $keys = $values = array();

  foreach ($array as $key => $value) {
    if ($key !== 'md5') { // TODO FIXME
      $keys[] = "`$key`";
      $values[] = in_array($key, array_keys($replacements)) ? $replacements[$key] : sprintf('"%s"', $mysqli->real_escape_string($value));
    }
  }
  $keys = implode(', ', $keys);
  $values = implode(', ', $values);
  return sprintf($query, $keys, $values); 
}


if (count($argv) !== 4) {

echo <<<EOT
USO:
php export-roundcube-sql.php db-user db-pass domain-to-export

EJEMPLO:
php export-roundcube-sql.php admin `cat /etc/psa/.psa.shadow` dominio.com

EOT;

  die;
}

$mysqli = new mysqli('localhost', $argv[1], $argv[2], 'roundcubemail');
if ($mysqli->connect_errno) {
    die("Error al conectar a la base de datos.\n");
}

$results = $mysqli->query('SELECT `value` FROM system WHERE `name` = "roundcube-version"');
$version = $results->fetch_assoc();

if ($version['value'] !== '2013011000') {
  die("No es la versión de Roundcube para la que fui diseñado (2013011000)." . "\n");
}



$data = $queries = array();

$queries[] = 'SET NAMES UTF8';

$results = $mysqli->query('SELECT * FROM users WHERE username LIKE "%@' . $argv[3] . '"');
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
    $row['md5'] = md5(utf8_encode($string));

    $data['contacts'][$user['user_id']][$row['contact_id']] = $row;
  }

  $results = $mysqli->query(sprintf('SELECT * FROM contactgroups WHERE user_id = "%s"', $user['user_id']));
  while ($row = $results->fetch_assoc()) {
    $data['contactgroups'][$user['user_id']][] = $row;
  }

  if (!empty($data['contactgroups'][$user['user_id']])) {
    foreach ($data['contactgroups'][$user['user_id']] as $contactgroup) {
      $results = $mysqli->query(sprintf('SELECT * FROM contactgroupmembers WHERE contactgroup_id = "%s"', $contactgroup['contactgroup_id']));
      while ($row = $results->fetch_assoc()) {
        $data['contactgroupmembers'][$user['user_id']][$contactgroup['contactgroup_id']][] = $row;
      }
    }
  }

}

foreach ($data['users'] as $user) {
  
  $query = 'INSERT INTO users (%s) VALUES (%s);';
  $queries[] = format($query, $user, array('user_id' => 'NULL'));

  if (!empty($data['identities'][$user['user_id']])) {
    foreach ($data['identities'][$user['user_id']] as $identity) {
      $query = 'INSERT INTO identities (%s) VALUES (%s);';
      $queries[] = format($query, $identity, array('identity_id' => 'NULL', 'user_id' => '(SELECT MAX(user_id) FROM users)'));
    }
  }

  if (!empty($data['contacts'][$user['user_id']])) {
    foreach ($data['contacts'][$user['user_id']] as $contact) {
      $query = 'INSERT INTO contacts (%s) VALUES (%s);';
      $queries[] = format($query, $contact, array('contact_id' => 'NULL', 'user_id' => '(SELECT MAX(user_id) FROM users)', 'md5' => FALSE));
    }
  }

  if (!empty($data['contactgroups'][$user['user_id']])) {
    foreach ($data['contactgroups'][$user['user_id']] as $contactgroup) {
      $query = 'INSERT INTO contactgroups (%s) VALUES (%s);';
      $queries[] = format($query, $contactgroup, array('contactgroup_id' => 'NULL', 'user_id' => '(SELECT MAX(user_id) FROM users)'));

      if (!empty($data['contactgroupmembers'][$user['user_id']][$contactgroup['contactgroup_id']])) {
        foreach ($data['contactgroupmembers'][$user['user_id']][$contactgroup['contactgroup_id']] as $contactgroupmember) {

          $query = 'INSERT INTO contactgroupmembers (%s) VALUES (%s);';
          $replacements = array(
            'contactgroupmember_id' => 'NULL',
            'contactgroup_id' => '(SELECT MAX(contactgroup_id) FROM contactgroups)',
            'contact_id' => sprintf("(SELECT `contact_id` FROM `contacts` WHERE MD5(CONCAT(changed, del, name, email, firstname, surname, vcard, words)) = '%s')", $data['contacts'][$user['user_id']][$contactgroupmember['contact_id']]['md5'])
          ); 
          $queries[] = format($query, $contactgroupmember, $replacements);

        }
      }
    }
  }

}

// print_r($data);

echo implode("\n", $queries) . "\n";
