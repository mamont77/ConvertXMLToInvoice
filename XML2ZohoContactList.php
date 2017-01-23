<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;

$config = new Config;
$config->load('./config/config.php');

function build_table($array) {
  // Start table.
  $html = '<table>';
  // Header row.
  $html .= '<tr>';
  foreach ($array[0] as $key => $value) {
    $html .= '<th>' . $key . '</th>';
  }
  $html .= '</tr>';

  // Data rows.
  foreach ($array as $key => $value) {
    $html .= '<tr>';
    foreach ($value as $key2 => $value2) {
      $html .= '<td>' . $value2 . '</td>';
    }
    $html .= '</tr>';
  }

  // Finish table.
  $html .= '</table>';

  return $html;
}


$zoho = new ZohoBooksApi(
  $config->get('zoho.authtoken'),
  $config->get('zoho.organizationID')
);

$contacts = $zoho->ContactsListAll();

print build_table($contacts);

print "<style type='text/css'>
table {
    font-family: arial, sans-serif;
    border-collapse: collapse;
    width: 100%;
}

td, th {
    border: 1px solid #dddddd;
    text-align: left;
    padding: 8px;
}
</style>";
