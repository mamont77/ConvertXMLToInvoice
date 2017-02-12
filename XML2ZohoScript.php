<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /XML2ZohoForm.php');
  exit;
}

// Prepare something.
$archives_dir = __DIR__ . '/' . $config->get('paths.archives') . '/';
$allowed_xml_extensions = $config->get('allowed_extensions.xml');
$allowed_attachment_extensions = $config->get('allowed_extensions.attachment');
$xml_file_name = '';
$xml_file_path = '';
$attachment_file_name = '';
$attachment_file_path = '';
$current_time = (string) time();

if (!file_exists($archives_dir)) {
  mkdir($archives_dir, 0777, TRUE);
}

// Check and move XML file.
if (isset($_FILES['xml']) && !empty($_FILES['xml']['name'])) {
  $xml_file_name = $_FILES['xml']['name'];
  $extension = pathinfo($_FILES['xml']['name'], PATHINFO_EXTENSION);
  $extension = strtolower($extension);
  if (!in_array($extension, $allowed_xml_extensions)) {
    $tools->logger('Wrong extension for XML file. Allowed', $allowed_xml_extensions, 'error');
  }
  $xml_file_path = $archives_dir . $current_time . '-' . $xml_file_name;
  if (move_uploaded_file($_FILES['xml']['tmp_name'], $xml_file_path)) {
    $tools->logger('XML file has been uploaded and renamed to', $xml_file_path);
  }
  else {
    $tools->logger('Error during XML file uploading', $xml_file_name, 'error');
  }
}

// Check and move attachment file.
// If the form or CURL request contain the file,
// we use it instead of native attachment from XML (from WorkStatements).
if (isset($_FILES['attachment']) && !empty($_FILES['attachment']['name'])) {
  $attachment_file_name = $_FILES['attachment']['name'];
  $extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
  $extension = strtolower($extension);
  if (!in_array($extension, $allowed_attachment_extensions)) {
    $tools->logger('Wrong extension for attachment file. Allowed', $allowed_attachment_extensions, 'error');
  }
  // Zoho has limit 5MB per file for attachments, so checking.
  if ($_FILES['attachment']['size'] > 5242880) {
    $tools->logger(
      'You can upload a maximum 5MB', 'The current size is ' . $tools->formatBytes($_FILES['attachment']['size']),
      'error'
    );
  }
  $attachment_file_path = $archives_dir . $current_time . '-' . $attachment_file_name;
  if (move_uploaded_file($_FILES['attachment']['tmp_name'], $attachment_file_path)) {
    $tools->logger('Attachment file has been uploaded and renamed to', $attachment_file_path);
  }
  else {
    $tools->logger('Error during attachment file uploading', $attachment_file_name, 'error');
  }
}

// Parse basic info from XML.
$xml_data = simplexml_load_file($xml_file_path);

$contact_id = (string) $xml_data->WorkSummary->Invoicing->Client->ZohoContactID;
if ($contact_id == '') {
  $tools->logger('Client not found in XML by contactID', $contact_id, 'error');
}

$zoho_authtoken = (isset($_POST['zoho-authtoken']) && !empty($_POST['zoho-authtoken']))
  ? trim($_POST['zoho-authtoken'])
  : $config->get('zoho.authtoken');

// Define $zoho object.
$zoho = new ZohoBooksApi($zoho_authtoken, $config->get('zoho.organizationID'));

// For debuging. Must be after $zoho = new ZohoBooksApi().
//goto step_convert;
//goto step_charge_payment;
//goto step_send_email;
//goto step_finish;

step_convert:
// STEP 1: ConvertXMLToInvoice.
$tools->logger('STEP 1', 'ConvertXMLToInvoice');

try {
  $contact = $zoho->ContactsGet($contact_id);
  $tools->logger('Contact was found in Zoho with ID', $contact_id);
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
  $tools->logger('Contact not found in Zoho by contactID', $contact_id, 'error');
}

// Preparing an Invoice.
$invoice_data = array();

if ($xml_data->WorkSummary->Invoicing->Client->Salesperson) {
  $salesperson = (string) $xml_data->WorkSummary->Invoicing->Client->Salesperson;
  $result = $zoho->ContactsList(array('contact_name' => $salesperson));
  if (empty($result)) {
    $tools->logger('Salesperson doesn\'t exist', '', 'error');
  }
  elseif (count($result) > 1) {
    $tools->logger('Result', $result);
    $tools->logger('A few people found satisfying the specified criteria', '', 'error');
  }
  else {
    $tools->logger('Salesperson has been found. Apply Salesperson to the invoice ',
      'Company: ' . $result[0]['company_name']
      . ' / First Name: ' . $result[0]['first_name']
      . ' / Last Name: ' . $result[0]['last_name']);
    $invoice_data['salesperson_name'] = $salesperson;
  }
}

if (!isset($xml_data->WorkSummary->Invoicing->LineItems)) {
  $tools->logger('Products not found in XML', '', 'error');
}
$invoice_items = array();
foreach ($xml_data->WorkSummary->Invoicing->LineItems->Product as $key => $item) {
  $item = (array) $item;

  $name = trim((string) $item['ID'], '""');
  $description = (string) $item['Description'];
  $quantity = (string) $item['Qty'];
  $rate = (string) $item['UnitPrice'];

  if ($name == '' || $description == '' || $quantity == '' || $rate == '') {
    $tools->logger('Something is missing in XML. Check', '[ID, Description, Qty, UnitPrice]', 'error');
  }
  $invoice_items[] = array(
    'name' => $name,
    'description' => $description,
    'quantity' => $quantity,
    'rate' => $rate,
  );
}

$invoice_data += array(
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

if ($xml_data->WorkSummary->Invoicing->NoteToClient) {
  $invoice_data['notes'] = (string) $xml_data->WorkSummary->Invoicing->NoteToClient;
}

$tools->logger('Creating invoice: Try send the data to Zoho', $invoice_data);

try {
  $invoice = $zoho->InvoicesCreate($invoice_data);
  // $invoice['invoice_id'] = '159812000000849219'; // For testing.
  $invoice_id = $invoice['invoice_id'];
  $invoice_number = $invoice['invoice_number'];
  $invoice_total = $invoice['currency_symbol'] . $invoice['total'];
  $tools->logger('Invoice has been created with ID / NUMBER', $invoice_id . ' / ' . $invoice_number);
  $tools->logger('Invoice total', $invoice_total);
  echo '<a href="https://books.zoho.com/app#/invoices/'
       . $invoice_id
       . '" target="_blank">Open in Zoho!</a>'
       . ' Or upload <a href="/XML2ZohoForm.php">another</a> XML.<br />';
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
}

if ($attachment_file_path != '') {
  // Attach a file.
  $mime_type = $tools->mime_content_type($attachment_file_path);
  $parameters = new CURLFile($attachment_file_path, $mime_type, $attachment_file_name);
  $tools->logger('Append the attachment to the invoice', $invoice_number);
  try {
    $result = $zoho->makeApiRequest(
      'invoices/' . $invoice_id . '/attachment',
      'POST',
      $parameters);
    $tools->logger('Zoho Result', $zoho->lastRequest['dataRaw']);
  } catch (Exception $e) {
    $tools->logger(
      'Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
  }

  // Update "can_send_in_mail" param.
  // You don't have permission to perform this operation.
  // Please contact your Administrator.
  // Commented for now.
  /**
   * For testing/debugging.
   */
  // $invoice_id = '159812000000866001';
  $tools->logger('Try to set "can_send_in_mail" = TRUE for attachment');
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
    $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw']);
  }

}

// We can skip charge_payment. In this case we shouldn't sent an email.
if (isset($_POST['charge_payment']) && trim($_POST['charge_payment']) == '1') {
  goto step_charge_payment;
}
else {
  $tools->logger('Charge payment', 'Skipped');
  goto step_finish;
}

step_charge_payment:
// STEP 2: Handle Payment.
$tools->logger('STEP 2', 'Handle Payment');

/**
 * For testing/debugging.
 * Ruslan (Credit Note) UNUSED CREDITS USD$1,000.00
 */
//$contact_id = '159812000000849015';
//$invoice['total'] = '0.01';
//$invoice_id = '159812000000864039';
/**
 * For testing/debugging.
 * Ruslan (Credit Note) UNUSED CREDITS ZERO
 */
//$contact_id = '159812000000854121';
//$invoice['total'] = '0.01';
//$invoice_id = '159812000000867017';
//$contact['cards'][0] = array(
//  'card_id' => '159812000000854155',
//  'status' => 'active',
//  'is_expired' => FALSE,
//);
/**
 * For testing/debugging.
 * Ruslan (no payment method)
 */
//$contact_id = '159812000000854133';
//$invoice['total'] = '0.01';
//$invoice_id = '159812000000864025';

$invoice_was_paid = FALSE;
$credit_notes = $zoho->CreditNotesList(array('customer_id' => $contact_id, 'status' => 'open'));
if (is_array($credit_notes) && !empty($credit_notes)) {
  $credit_note_id = (string) $credit_notes[0]['creditnote_id'];
  $credit_note = $zoho->CreditNotesGet($credit_note_id);
  $total_credits = $credit_note['total'];
  if ($total_credits >= $invoice['total']) {
    // Try to pay by credit notes. Apply credit notes.
    try {
      $parameters = array(
        'apply_creditnotes' => array(
          array(
            'creditnote_id' => $credit_note_id,
            'amount_applied' => $invoice['total'],
          ),
        ),
      );
      $result = $zoho->makeApiRequest(
        'invoices/' . $invoice_id . '/credits',
        'POST',
        $parameters
      );
      $tools->logger('Apply credits to the invoice', $zoho->lastRequest['zohoMessage']);
      $invoice_was_paid = TRUE;
    } catch (Exception $e) {
      $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
    }
  }
}

// Try to pay by credit card.
$card_id = NULL;
if ($invoice_was_paid === FALSE && is_array($contact['cards']) && !empty($contact['cards'])) {
  foreach ($contact['cards'] as $card) {
    if ($card['status'] == 'active' && $card['is_expired'] === FALSE) {
      $card_id = $card['card_id'];
      break;
    }
  }
  $parameters = array(
    'card_id' => $card_id,
    'payment_amount' => '',
  );
  try {
    $result = $zoho->makeApiRequest(
      'invoices/' . $invoice_id . '/forcepay',
      'POST',
      $parameters
    );
    $tools->logger('Force pay by credit card', $zoho->lastRequest['zohoMessage']);
    $invoice_was_paid = TRUE;
  } catch (Exception $e) {
    //Zoho Exception: {"code":9096,"message":"Force payment can be made only for the invoices generated from recurring invoices."}
    $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw']);
  }
}

if ($invoice_was_paid === FALSE) {
  $tools->logger('The client doesn\'t have any credit note or credit card or unsuccessful. Skipped.');
}

step_send_email:

// We can skip send_email.
if (isset($_POST['send_email']) && trim($_POST['send_email']) == '1' && $invoice_was_paid === TRUE) {
  // Nothing to do.
}
else {
  $tools->logger('Send email', 'Skipped');
  goto step_finish;
}

// STEP 3: Send Email.
$tools->logger('STEP 3', 'Send Email');

/**
 * $invoice_id for testing/debugging.
 */
//$invoice_id = '159812000000867017';
//FIXME: Is it work? Is it need in $parameters?
$parameters = array(
  'send_customer_statement' => TRUE,
  'send_attachment' => TRUE,
  'send_from_org_email_id' => TRUE,
);

// Find primary and secondary emails.
try {
  $result = $zoho->makeApiRequest(
    'contacts/' . $contact_id . '/contactpersons',
    'GET'
  );
  $result = $result['zohoResponse'];
  $tools->logger('Get person\'s contact info', $zoho->lastRequest['zohoMessage']);
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
  }
  else {
    $tools->logger('No contacts found', 'The script can\'t send an email to the client', 'error');
  }
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
}

// Sending an email.
$tools->logger('Trying to send invoice to mails with params', $parameters);
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

step_finish:
$tools->logger('Total Result', 'FIHISHED');
