<?php

/**
 * We can use request using CURL.
 *
 * Method: POST
 * Params: array()
 *   xml file required.
 *   attachment file optional.
 *   charge_payment optional default TRUE.
 *   send_email optional default TRUE.
 *
 * Return json result.
 */

$target_url = 'http://api.jaleatech.com/XML2ZohoScript.php?appAuthToken=89759375937693758292';

// This needs to be the full path to the file you want to send.
$xml_file_name_with_full_path = realpath('./JobFinal-testing-payment-example-zero.xml');
$attachment_file_name_with_full_path = realpath('./test.xlsx');

// If php 5.6+.
if (function_exists('curl_file_create')) {
  $xml_file = curl_file_create($xml_file_name_with_full_path);
  $attachment_file = curl_file_create($attachment_file_name_with_full_path);
}
else {
  $xml_file = '@' . realpath($xml_file_name_with_full_path);
  $attachment_file = '@' . realpath($attachment_file_name_with_full_path);
}

$parameters = array(
  'xml' => $xml_file,
  'attachment' => $attachment_file,
  'charge_payment' => TRUE,
  'send_email' => TRUE,
);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
$result = curl_exec($ch);
curl_close($ch);

echo json_encode(array(
  'result' => TRUE,
));
