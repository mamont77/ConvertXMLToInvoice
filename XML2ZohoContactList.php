<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;

$config = new Config;
$config->load('./config/config.php');

$zoho = new ZohoBooksApi(
  $config->get('zoho.authtoken'),
  $config->get('zoho.organizationID')
);

$contacts = $zoho->ContactsListAll();

print json_encode($contacts);
