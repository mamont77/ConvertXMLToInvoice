<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /form.php');
  exit;
}

require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/Helpers/Common.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;
use Helpers\Common;

$config = new Config();
$tools = new Common();

$config->load('./config/config.php');

// Prepare something.
$work_statements = __DIR__ . '/' . $config->get('paths.work_statements') . '/';
$archives_dir = __DIR__ . '/' . $config->get('paths.archives') . '/';
$allowed_xml_extensions = $config->get('allowed_extensions.xml');
$allowed_attachment_extensions = $config->get('allowed_extensions.attachment');
$xml_file_name = '';
$xml_file_path = '';
$attachment_file_name = '';
$attachment_file_path = '';

if (!file_exists($archives_dir)) {
  mkdir($archives_dir, 0777, TRUE);
}
if (!file_exists($work_statements)) {
  mkdir($work_statements, 0777, TRUE);
}

// Check and move XML file.
if (isset($_FILES['form-xml']) && !empty($_FILES['form-xml']['name'])) {
  $xml_file_name = $_FILES['form-xml']['name'];
  $extension = pathinfo($_FILES['form-xml']['name'], PATHINFO_EXTENSION);
  if (!in_array($extension, $allowed_xml_extensions)) {
    $tools->logger('Wrong extension for XML file. Allowed', $allowed_xml_extensions, 'error');
  }
  $xml_file_path = $archives_dir . (string) time() . '-' . $xml_file_name;
  if (move_uploaded_file($_FILES['form-xml']['tmp_name'], $xml_file_path)) {
    $tools->logger('XML file has been uploaded and renamed to', $xml_file_path);
  }
  else {
    $tools->logger('Error during XML file uploading', $xml_file_name, 'error');
  }
}

// Parse basic info from XML.
$xml_data = simplexml_load_file($xml_file_path);

// If the XML has an attachment's info we use it.
if ($xml_data->Job->Production->WorkStatement) {
  $attachment_file_path = (string) $xml_data->Job->Production->WorkStatement;
  $attachment_file_name = basename($attachment_file_path);
  if (file_exists(__DIR__ . $attachment_file_path)) {
    $new_attachment_file_path = $archives_dir . (string) time() . '-' . $attachment_file_name;
    rename(__DIR__ . $attachment_file_path, $new_attachment_file_path);
    $attachment_file_path = $new_attachment_file_path;
    unset($new_attachment_file_path);
    $tools->logger('Attachment file has been moved to', $attachment_file_path);
  }
}

// Check and move attachment file.
// If the form contain the file,
// we use it instead of native attachment from XML (from WorkStatements).
if (isset($_FILES['form-attachment']) && !empty($_FILES['form-attachment']['name'])) {
  $attachment_file_name = $_FILES['form-attachment']['name'];
  $extension = pathinfo($_FILES['form-attachment']['name'], PATHINFO_EXTENSION);
  if (!in_array($extension, $allowed_attachment_extensions)) {
    $tools->logger('Wrong extension for attachment file. Allowed', $allowed_attachment_extensions, 'error');
  }
  // Zoho has limit 5MB per file for attachments, so checking.
  if ($_FILES['form-xlsx']['size'] > 5242880) {
    $tools->logger(
      'You can upload a maximum 5MB', 'The current size is ' . $tools->formatBytes($_FILES['form-xlsx']['size']),
      'error'
    );
  }
  $attachment_file_path = $archives_dir . (string) time() . '-' . $attachment_file_name;
  if (move_uploaded_file($_FILES['form-attachment']['tmp_name'], $attachment_file_path)) {
    $tools->logger('Attachment file has been uploaded and renamed to', $attachment_file_path);
  }
  else {
    $tools->logger('Error during attachment file uploading', $attachment_file_name, 'error');
  }
}


$contact_id = (string) $xml_data->Job->Client->contactID;
if ($contact_id == '') {
  $tools->logger('Client not found in XML by contactID', $contact_id, 'error');
}

$authtoken = ($_POST['form-authtoken']) ? trim($_POST['form-authtoken']) : $config->get('zoho.authtoken');

$zoho = new ZohoBooksApi($authtoken, $config->get('zoho.organizationID'));

// STEP 1: ConvertXMLToInvoice.
try {
  $contact = $zoho->ContactsGet($contact_id);
  $tools->logger('Contact was found in Zoho with ID', $contact_id);
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
  $tools->logger('Contact not found in Zoho by contactID', $contact_id, 'error');
}

// Preparing an Invoice.
if (!isset($xml_data->Job->Production->InvoiceItems->Product)) {
  $tools->logger('Products not found in XML', '', 'error');
}
$invoice_items = array();
foreach ($xml_data->Job->Production->InvoiceItems->Product as $key => $item) {
  $item = (array) $item;
  $rate_mapping = array(
    'PricePerInd',
    'PricePerRender',
    'PricePerTick',
    'PricePerSheet',
    'PricePerPose',
  );
  $quantity_mapping = array(
    'RenderCount',
    'IndCount',
    'TickCount',
    'SheetCount',
    'PoseCount',
  );

  $current_rate_type = '';
  foreach ($rate_mapping as $rate) {
    if (isset($item[$rate])) {
      $current_rate_type = $item[$rate];
      continue;
    }
  }
  $current_quantity = 0;
  foreach ($quantity_mapping as $quantity) {
    if (isset($item[$quantity])) {
      $current_quantity = $item[$quantity];
      continue;
    }
  }

  $name = trim((string) $item['ID'], '""');
  $description = (string) $item['Description'];
  $quantity = (string) $current_quantity;
  $rate = (string) $current_rate_type;

  if ($name == '' || $description == '' || $quantity == '' || $rate == '') {
    $tools->logger('Something is missing in XML. Check', '[sku, qty, price, description]', 'error');
  }
  $invoice_items[] = array(
    'name' => $name,
    'description' => $description,
    'quantity' => $quantity,
    'rate' => $rate,
  );
}

$invoce_data = array(
  'customer_id' => $contact_id,
  // Fixme: Out of scope.
  // Allowed Values:
  // paypal, authorize_net, payflow_pro, stripe, 2checkout and braintree.
  'payment_options' => array(
    'payment_gateways' => array(
      0 => array(
        'gateway_name' => 'paypal',
        'additional_field1' => 'standard',
      ),
      1 => array(
        'gateway_name' => 'stripe',
        'additional_field1' => '',
      ),
    ),
  ),
  'line_items' => $invoice_items,
);

if ($xml_data->Job->Production->Responses->CommentsToTheClient) {
  $invoce_data['notes'] = (string) $xml_data->Job->Production->Responses->CommentsToTheClient;
}

$tools->logger('Creating invoice: Try send the data to Zoho', $invoce_data);

try {
  $invoice = $zoho->InvoicesCreate($invoce_data);
  // $invoice['invoice_id'] = '159812000000849219'; // For testing.
  $invoice_id = $invoice['invoice_id'];
  $invoice_number = $invoice['invoice_number'];
  $invoice_total = $invoice['currency_symbol'] . $invoice['total'];
  $tools->logger('Invoice has been created with ID / NUMBER', $invoice_id . ' / ' . $invoice_number);
  $tools->logger('Invoice total', $invoice_total);
  echo '<a href="https://books.zoho.com/app#/invoices/'
       . $invoice_id
       . '" target="_blank">Open in Zoho!</a>'
       . ' Or upload <a href="/">another</a> XML.<br />';
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
}

if ($attachment_file_path != '') {
  // Attach a file.
  $mime_type = mime_content_type($attachment_file_path);
  $parameters = new CURLFile($attachment_file_path, $mime_type, $attachment_file_name);
  $tools->logger('Append the attachment to the invoice', $invoice_number);
  try {
    $result = $zoho->makeApiRequest(
      'invoices/' . $invoice_id . '/attachment',
      'POST',
      $parameters);
    $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
  } catch (Exception $e) {
    $tools->logger(
      'Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
  }

  // Update "can_send_in_mail" param.
  // Seems we don't need this. Commmented for now.
  /*
  $parameters = array(
    'can_send_in_mail' => TRUE,
  );
  try {
    $result = $zoho->makeApiRequest(
      'invoices/' . $invoice_id . '/attachment',
      'PUT',
      $parameters
    );
    $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
  } catch (Exception $e) {
    $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
  }
  */

}

// STEP 2: Handle Payment.
/**
 * For testing/debugging.
 */
//$contact_id = '159812000000572643';
//$invoice['total'] = '500';
//
//$credit_notes = $zoho->CreditNotesList(array('customer_id' => $contact_id, 'status' => 'open'));
//
//if (is_array($credit_notes)) {
//  if (!empty($credit_notes)) {
//    $credit_note_id = (string) $credit_notes[0]['creditnote_id'];
//    $credit_note = $zoho->CreditNotesGet($credit_note_id);
//    $total = $credit_note['total'];
//
//    if ($total >= $invoice['total']) {
//      try {
//        $parameters = array(
//          'apply_creditnotes' => array(
//            'creditnote_id' => $credit_note_id,
//            'amount_applied' => $invoice['total'],
//          ),
//        );
//        $result = $zoho->makeApiRequest(
//          'invoices/' . $invoice_id . '/credits',
//          'POST',
//          $parameters
//        );
//        $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
//      } catch (Exception $e) {
//        $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
//      }
//    }
//
//  }
//  else {
//    // Assume a client doesn't have any credits.
//  }
//}

// STEP 3: Send Email.
/**
 * $invoice_id for testing/debugging.
 */
//$invoice_id = '159812000000857049';
//FIXME: Is it work? Is it need in $parameters?
$parameters = array(
//  'send_customer_statement' => FALSE,
//  'send_attachment' => FALSE,
//  'send_from_org_email_id' => FALSE,
);

// Find primary and secondary emails.
try {
  $result = $zoho->makeApiRequest(
    'contacts/' . $contact_id . '/contactpersons',
    'GET'
  );
  $result = $result['zohoResponse'];
  $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
  if (is_array($result) && !empty($result)) {
    foreach ($result as $contact) {
      // Add emails to $parameters.
      if ($contact['is_primary_contact'] === TRUE) {
        $parameters['to_mail_ids'][] = $contact['email'];
      }
      else {
        $parameters['cc_mail_ids'][] = $contact['email'];
      }
    }
    $tools->logger('Trying to send invoice to mails with params', $parameters);
  }
  else {
    $tools->logger('No contacts found', 'The script can\'t send an email to the client', 'error');
  }
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
}

// Sending an email.
try {
  $result = $zoho->makeApiRequest(
    'invoices/' . $invoice_id . '/email',
    'POST',
    $parameters
  );
  $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
} catch (Exception $e) {
  $tools->logger(
    'Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
}

$tools->logger('Total Result', 'FIHISHED');

