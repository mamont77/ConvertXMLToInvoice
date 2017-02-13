<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/Helpers/Common.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;
use Helpers\Common;

$config = new Config();
$tools = new Common();

$config->load('./config/config.php');

if (@$_GET['appAuthToken'] != $config->get('app_authtoken')) {
  print json_encode(array('error' => 'app_authtoken is invalid!'));
  exit;
}

$zoho = new ZohoBooksApi(
  $config->get('zoho.authtoken'),
  $config->get('zoho.organizationID')
);

print $tools->getNextInvoiceNumber($zoho);
