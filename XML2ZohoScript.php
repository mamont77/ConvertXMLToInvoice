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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
  header('Location: /XML2ZohoForm.php');
  exit;
}

ob_start();

// Prepare something.
$archives_dir = __DIR__ . '/' . $config->get('paths.archives') . '/';
$allowed_xml_extensions = $config->get('allowed_extensions.xml');
$allowed_attachment_extensions = $config->get('allowed_extensions.attachment');
$xml_file_name = '';
$xml_file_path = '';
$attachment_file_name = '';
$attachment_file_path = '';
$current_time = (string) time();
$invoice_number = '';
$invoice_id = '';
$invoice_total = '';

if (!file_exists($archives_dir)) {
  mkdir($archives_dir, 0777, TRUE);
}

$charge_payment = TRUE;
$send_email = TRUE;

if (!isset($_POST['send_email']) || $_POST['send_email'] != '1') {
  $send_email = FALSE;
}

if (!isset($_POST['charge_payment']) || $_POST['charge_payment'] != '1') {
  $charge_payment = FALSE;
  $send_email = FALSE;
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

// Add Salesperson data.
if ($xml_data->WorkSummary->Invoicing->Client->Salesperson) {
  $invoice_data['salesperson_name'] = (string) $xml_data->WorkSummary->Invoicing->Client->Salesperson;
}

// Add Reseller data as custom field.
if ($xml_data->WorkSummary->Invoicing->Client->Reseller) {
  $invoice_data['custom_fields'] = array(
    '0' => array(
      'label' => 'Reseller',
      'value' => (string) $xml_data->WorkSummary->Invoicing->Client->Reseller,
    ),
  );
}

if (!isset($xml_data->WorkSummary->Invoicing->LineItems)) {
  $tools->logger('Products not found in XML', '', 'error');
}

// Add Product items data.
$invoice_items = array();
$predicted_total = 0;
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
  $predicted_total += (float) $item['UnitPrice'] * $quantity;
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

// Add NoteToClient data.
if ($xml_data->WorkSummary->Invoicing->NoteToClient) {
  $invoice_data['notes'] = (string) $xml_data->WorkSummary->Invoicing->NoteToClient;
}

// Get credit notes.
$total_credits = 0;
$credit_notes = $zoho->CreditNotesList(array('customer_id' => $contact_id, 'status' => 'open'));
if (is_array($credit_notes) && !empty($credit_notes)) {
  $credit_note_id = (string) $credit_notes[0]['creditnote_id'];
  $credit_note = $zoho->CreditNotesGet($credit_note_id);
  $total_credits = $credit_note['total'];

  // Add balance info.
  if ($total_credits <= 0) {
    $invoice_data['notes'] .= "\r\n";
    $invoice_data['notes'] .= "Net Balance: USD$" . ($total_credits - $predicted_total) . "\r\n";
    $invoice_data['notes'] .= "Your prepaid credit balance is zero.\r\n";
    $invoice_data['notes'] .= "Contact Sales@JaleaTech.com or 514 743 1628 if you would like to refill.\r\n";
  }
  elseif ($total_credits < 200) {
    $invoice_data['notes'] .= "\r\n";
    $invoice_data['notes'] .= "Net Balance: USD$" . ($total_credits - $predicted_total) . "\r\n";
    $invoice_data['notes'] .= "After covering this invoice, your prepaid credit balance will be USD$" . ($total_credits - $predicted_total) . ".\r\n";
    $invoice_data['notes'] .= "You are about to run out of credits!\r\n";
    $invoice_data['notes'] .= "You should refill to preserve your volume discount, instead of paying spot pricing.\r\n";
    $invoice_data['notes'] .= "Contact Sales@JaleaTech.com or 514 743 1628 if you would like to proceed.!\r\n";
  }
  elseif ($total_credits < 1000) {
    $invoice_data['notes'] .= "\r\n";
    $invoice_data['notes'] .= "Net Balance: USD$" . ($total_credits - $predicted_total) . "\r\n";
    $invoice_data['notes'] .= "After covering this invoice, your prepaid credit balance will be USD$" . ($total_credits - $predicted_total) . ".\r\n";
    $invoice_data['notes'] .= "You should refill to preserve your volume discount.\r\n";
    $invoice_data['notes'] .= "Contact Sales@JaleaTech.com or 514 743 1628 if you would like to proceed.!\r\n";
  }
  else {
    $invoice_data['notes'] .= "\r\n";
    $invoice_data['notes'] .= "Net Balance: USD$" . ($total_credits - $predicted_total) . "\r\n";
    $invoice_data['notes'] .= "After covering this invoice, your prepaid credit balance will be USD$" . ($total_credits - $predicted_total) . ".\r\n";
  }
}
else {
  // The same as if ($total_credits <= 0).
  $invoice_data['notes'] .= "\r\n";
  $invoice_data['notes'] .= "Net Balance: USD$" . ($total_credits - $predicted_total) . "\r\n";
  $invoice_data['notes'] .= "Your prepaid credit balance is zero.\r\n";
  $invoice_data['notes'] .= "Contact Sales@JaleaTech.com or 514 743 1628 if you would like to refill.\r\n";
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
} catch (Exception $e) {
  $tools->logger('Zoho Exception', $zoho->lastRequest['dataRaw'], 'error');
}

if ($attachment_file_path != '') {
  // Attach a file.
  $mime_type = $tools->mime_content_type($attachment_file_path);
  $parameters = array(
    'attachment' => new CURLFile($attachment_file_path, $mime_type, $attachment_file_name),
    'can_send_in_mail' => 'TRUE',
  );
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
}

// We can skip charge_payment. In this case we shouldn't sent an email.
if ($charge_payment === TRUE) {
  goto step_charge_payment;
}
else {
  $tools->logger('Charge payment', 'Skipped');
  $tools->setWarning(TRUE);
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
  $tools->setWarning(TRUE);
}

step_send_email:

// We can skip send_email.
if ($send_email === TRUE && $invoice_was_paid === TRUE) {
  // Nothing to do.
}
else {
  $tools->logger('Send email', 'Skipped');
  $tools->setWarning(TRUE);
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
  'cc_mail_ids' => array(
    $config->get('zoho.cc_for_mail'),
  ),
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
$details = ob_get_contents();
ob_end_clean();
?><!DOCTYPE html>
<html>
<head>
    <title>Zoho Books API Result</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <link href="//netdna.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" rel="stylesheet" media="screen">
</head>
<style type="text/css">
    /* Sticky footer styles */
    html {
        position: relative;
        min-height: 100%;
    }

    body {
        /* Margin bottom by footer height */
        margin-bottom: 60px;
    }

    .footer {
        position: absolute;
        bottom: 0;
        width: 100%;
        /* Set the fixed height of the footer here */
        height: 60px;
        background-color: #f5f5f5;
    }

    .row {
        margin-bottom: 15px;
    }

    .summary-info {
        padding-top: 30px;
    }

    .summary-info .label {
        padding: 15px 20px;
        display: inline-block;
    }

    .summary-info .glyphicon {
        font-size: 32px;
        line-height: 38px;
    }

    .summary-info h1 {
        display: inline-block;
        margin: 0;
        position: relative;
        top: -5px;
    }

    .summary-info a {
        color: #fff;
    }

    .summary-info h1 .label {
        font-size: 32px;
        line-height: 38px;
    }

    .total-info strong {
        font-size: 24px;
    }

    .bs-docs-footer {
        border-top: 1px solid #ccc;
        padding: 15px;
        background-color: #eee;
    }

    .bs-docs-footer-links {
        margin-bottom: 0;
    }

    .bs-docs-footer-links li {
        display: inline;
        margin-right: 15px;
    }
</style>
<body>
<div class="container">

    <div class="row">

        <div class="summary-info">
          <?php if ($tools->isError() === TRUE): ?>
              <span class="label label-danger"><span class="glyphicon glyphicon-fire" aria-hidden="true"></span></span>
          <?php elseif ($tools->isWarning() === TRUE): ?>
              <span class="label label-warning"><span class="glyphicon glyphicon-warning-sign"
                                                      aria-hidden="true"></span></span>
          <?php else: ?>
              <span class="label label-success"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span></span>
          <?php endif; ?>
          <?php if ($invoice_id != ''): ?>
              <h1>
                <span class="label label-info">Invoice <a type="button"
                                                          href="https://books.zoho.com/app#/invoices/<?php print $invoice_id; ?>"
                                                          target="_blank">
                  <?php print $invoice_number; ?>
                    </a>
                </span>
              </h1>
          <?php endif; ?>
        </div>
    </div>
  <?php if ($invoice_total != ''): ?>
      <div class="row">
          <div class="total-info">
              Invoice total: <strong><?php print $invoice_total; ?></strong>
          </div>
      </div>
  <?php endif; ?>

    <div class="row">
        <a type="button" href="https://books.zoho.com/app#/invoices/<?php print $invoice_id; ?>" target="_blank"
           class="btn btn-primary btn-lg">Open in Zoho</a>
        <a type="button" href="/XML2ZohoForm.php" class="btn btn-default btn-lg">Upload another XML</a>
    </div>

    <div class="row">
        <pre><?php print $details; ?></pre>
    </div>

    <div class="row">
        <div class="panel panel-default">
            <div class="panel-heading">Notes</div>
            <div class="panel-body">
                <div class="alert alert-success" role="alert">
                    <span class="glyphicon glyphicon-ok" aria-hidden="true"></span>
                    <strong>Completely okay!</strong>
                    Invoice created, credits consumed, email sent.
                </div>
                <div class="alert alert-warning" role="alert">
                    <span class="glyphicon glyphicon-warning-sign" aria-hidden="true"></span>
                    <strong>Needs attention!</strong>
                    Invoice created, but insufficient credits or manual credit card charge required, or mail was
                    skipped.
                </div>
                <div class="alert alert-danger" role="alert">
                    <span class="glyphicon glyphicon-fire" aria-hidden="true"></span>
                    <strong>Oh snap!</strong>
                    Failure of some kind.
                </div>
            </div>
        </div>

    </div>

</div>
<footer class="footer bs-docs-footer">
    <div class="container">
        <ul class="bs-docs-footer-links">
            <li>
                <a href="/XML2ZohoContactList.php?appAuthToken=<?php print $config->get('app_authtoken'); ?>"
                   target="_blank">Get ALL Contacts</a>
            </li>
            <li>
                <a href="/XML2ZohoGetLastInvoiceNumber.php?appAuthToken=<?php print $config->get('app_authtoken'); ?>"
                   target="_blank">Get Next Invoice Number</a>
            </li>
        </ul>
</footer>
</body>
</html>
<?php
