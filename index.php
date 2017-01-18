<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/Helpers/Common.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;
use Helpers\Common;

$config = new Config();
$tools = new Common();

$archives_dir = __DIR__ . '/archives';

$config->load('./config/config.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!empty($_FILES['form-attachment']['name'])) {

    $file_name = $_FILES['form-attachment']['name'];
    $temp_name = $_FILES['form-attachment']['tmp_name'];
    $file_type = $_FILES['form-attachment']['type'];
    $base = basename($file_name);
    $extension = substr($base, strlen($base) - 4, strlen($base));

    $allowed_xml_extensions = array('.xml');
    $allowed_attachment_extensions = array('.xls', 'xlsx', 'pdf');

    if (in_array($extension, $allowed_xml_extensions)) {
      $xml_file_path = $archives_dir . '/' . (string) time(
        ) . '-' . $_FILES['form-attachment']['name'];
      if (move_uploaded_file(
        $_FILES['form-attachment']['tmp_name'],
        $xml_file_path
      )) {
        $tools->logger(
          'XML file has been uploaded and renamed to',
          $xml_file_path
        );
      }
      else {
        $tools->logger(
          'Error during file uploading',
          $file_name,
          'error'
        );
      }

      // Parse XML.
      $xml_data = simplexml_load_file($xml_file_path);

      $contact_id = (string) $xml_data->Job->Client->contactID;

      if ($contact_id == '') {
        $tools->logger(
          'Client not found in XML by contactID',
          $contact_id,
          'error'
        );
      }

      $invoice_attachment = '';
      if ($xml_data->Job->Production->WorkStatement) {
        $invoice_attachment = (string) $xml_data->Job->Production->WorkStatement;
        $invoice_attachment = basename($invoice_attachment);
        if (!empty($_FILES['form-xlsx']['name']) && $invoice_attachment !== '') {
          // Check extension for attachment.
          $attachment_file_name = $_FILES['form-xlsx']['name'];
          $base = basename($attachment_file_name);
          $extension = substr($base, strlen($base) - 4, strlen($base));
          if (!in_array($extension, $allowed_attachment_extensions)) {
            $tools->logger(
              'Wrong extension for attachment file. Allowed',
              $allowed_attachment_extensions,
              'error'
            );
          }
          // Zoho has limit 5MB per file.
          if ($_FILES['form-xlsx']['size'] > 5242880) {
            $tools->logger(
              'You can upload a maximum 5MB',
              'The current size is ' . $tools->formatBytes(
                $_FILES['form-xlsx']['size']
              ),
              'error'
            );
          }

          $attachment_file_path = $archives_dir . '/'
                                  . (string) time()
                                  . '-' . $invoice_attachment;

          if (move_uploaded_file(
            $_FILES['form-xlsx']['tmp_name'],
            $attachment_file_path
          )) {
            $tools->logger(
              'XLSX file has been uploaded and renamed to',
              $attachment_file_path
            );
          }
          else {
            $tools->logger(
              'Error during file uploading',
              $attachment_file_name,
              'error'
            );
          }
        }
      }

      $authtoken = ($_POST['form-authtoken']) ? trim(
        $_POST['form-authtoken']
      ) : $config->get('zoho.authtoken');

      $zoho = new ZohoBooksApi(
        $authtoken,
        $config->get('zoho.organizationID')
      );

      // STEP 1: ConvertXMLToInvoice.
      try {
        $contact = $zoho->ContactsGet($contact_id);
        $tools->logger('Contact was found in Zoho with ID', $contact_id);
      } catch (Exception $e) {
        $tools->logger(
          'Zoho Exception',
          $zoho->lastRequest['dataRaw'],
          'error'
        );
        $tools->logger(
          'Contact not found in Zoho by contactID',
          $contact_id,
          'error'
        );
      }

      // Preparing an Invoice.
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
          $tools->logger(
            'Something is missing',
            '[sku,qty,price,description]',
            'error'
          );
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

      $tools->logger(
        'Creating invoice: Try send the data to Zoho',
        $invoce_data
      );

      try {
        $invoice = $zoho->InvoicesCreate($invoce_data);
        //$invoice['invoice_id'] = '159812000000849219'; // For testing.

        $invoice_id = $invoice['invoice_id'];
        $invoice_number = $invoice['invoice_number'];
        $invoice_total = $invoice['currency_symbol'] . $invoice['total'];
        $tools->logger(
          'Invoice has been created with ID / NUMBER',
          $invoice_id . ' / ' . $invoice_number
        );
        $tools->logger('Invoice total', $invoice_total);
        echo '<a href="https://books.zoho.com/app#/invoices/'
             . $invoice_id
             . '" target="_blank">Open in Zoho!</a>'
             . ' Or upload <a href="/">another</a> XML.<br />';
      } catch (Exception $e) {
        $tools->logger(
          'Zoho Exception',
          $zoho->lastRequest['dataRaw'],
          'error'
        );
      }

      if ($invoice_attachment != '') {
        // Attach a file.
        $mime_type = mime_content_type($attachment_file_path);
        $file_name = basename($invoice_attachment);
        $parameters = new CURLFile(
          $attachment_file_path, $mime_type, $file_name
        );
        $tools->logger(
          'Append the attachment to the invoice',
          $invoice_number
        );
        try {
          $result = $zoho->makeApiRequest(
            'invoices/' . $invoice_id . '/attachment',
            'POST',
            $parameters
          );
          $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
        } catch (Exception $e) {
          $tools->logger(
            'Zoho Exception',
            $zoho->lastRequest['dataRaw'],
            'error'
          );
        }

        // Update "can_send_in_mail" param.
        // Seems we don't need this.
//        $parameters = array(
//          'can_send_in_mail' => TRUE,
//        );
//        try {
//          $result = $zoho->makeApiRequest(
//            'invoices/' . $invoice_id . '/attachment',
//            'PUT',
//            $parameters
//          );
//          $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
//        } catch (Exception $e) {
//          $tools->logger(
//            'Zoho Exception',
//            $zoho->lastRequest['dataRaw'],
//            'error'
//          );
//        }
      }

      // STEP 2: Handle Payment.

      /**
       * For testing/debugging.
       */
//      $contact_id       = '159812000000572643';
//      $invoice['total'] = '500';

//      $credit_notes = $zoho->CreditNotesList(
//        array('customer_id' => $contact_id, 'status' => 'open')
//      );
//
//      if (is_array($credit_notes)) {
//        if (!empty($credit_notes)) {
//          $credit_note_id = (string) $credit_notes[0]['creditnote_id'];
//          $credit_note = $zoho->CreditNotesGet($credit_note_id);
//          $total = $credit_note['total'];
//
//          if ($total >= $invoice['total']) {
//            try {
//              $parameters = array(
//                'apply_creditnotes' => array(
//                  'creditnote_id' => $credit_note_id,
//                  'amount_applied' => $invoice['total'],
//                ),
//              );
//              $result = $zoho->makeApiRequest(
//                'invoices/' . $invoice_id . '/credits',
//                'POST',
//                $parameters
//              );
//              $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
//            } catch (Exception $e) {
//              $tools->logger(
//                'Zoho Exception',
//                $zoho->lastRequest['dataRaw'],
//                'error'
//              );
//            }
//          }
//
//        }
//        else {
//          // Assume a client doesn't have any credits.
//        }
//      }


      // STEP 3: Send Email.

      /**
       * $invoice_id for testing/debugging.
       */
//      $invoice_id = '159812000000857049';

      //FIXME: Is it work? Is it need?
      $parameters = array(
//        'send_customer_statement' => FALSE,
//        'send_attachment' => FALSE,
//        'send_from_org_email_id' => FALSE,
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
          $tools->logger(
            'Trying to send invoice to mails with params',
            $parameters
          );
        }
        else {
          $tools->logger(
            'No contacts found',
            'The script can\'t send an email to the client',
            'error'
          );
        }
      } catch (Exception $e) {
        $tools->logger(
          'Zoho Exception',
          $zoho->lastRequest['dataRaw'],
          'error'
        );
      }

      // Send email.
      try {
        $result = $zoho->makeApiRequest(
          'invoices/' . $invoice_id . '/email',
          'POST',
          $parameters
        );
        $tools->logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
      } catch (Exception $e) {
        $tools->logger(
          'Zoho Exception',
          $zoho->lastRequest['dataRaw'],
          'error'
        );
      }


      // For some testing.
//      $temporary_invoice = $zoho->InvoicesGet('159812000000833591');
//      echo '<pre>';
//      print_r($temporary_invoice);
//      echo '</pre>';

    }
    else {
      $tools->logger(
        'Wrong extension for XML file. Allowed',
        $allowed_xml_extensions,
        'error'
      );
    }
  }
}
?><!DOCTYPE html>
<html>
<head>
    <title>Send XML to Zoho Books API</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <link href="//netdna.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css"
          rel="stylesheet" media="screen">
</head>
<body>
<div class="jumbotron">
    <div class="container">
        <h1>Send XML to Zoho Books API</h1>
    </div>
</div>

<div class="container">
  <?php if (!empty($emailSent)): ?>
      <div class="alert alert-success text-center"><?php echo $config->get(
          'messages.success'
        ); ?></div>
  <?php else: ?>
    <?php if (!empty($hasError)): ?>
          <div class="alert alert-danger text-center"><?php echo $config->get(
              'messages.error'
            ); ?></div>
    <?php endif; ?>

      <div class="row">
          <form action="<?php echo $_SERVER['REQUEST_URI']; ?>"
                enctype="multipart/form-data" id="zoho-form"
                class="form-horizontal" method="post">
              <div class="form-group">
                  <label for="form-authtoken"
                         class="col-lg-2 control-label"><?php echo $config->get(
                      'fields.authtoken'
                    ); ?></label>
                  <div class="col-lg-10">
                      <input class="form-control" id="form-authtoken"
                             name="form-authtoken" type="text"
                             placeholder="<?php echo $config->get(
                               'fields.authtoken'
                             ); ?>">
                      <p class="help-block">Only if you want to override the
                          authtoken by your value (optional).
                      </p>
                  </div>
              </div>
              <div class="form-group">
                  <label for="form-attachment"
                         class="col-lg-2 control-label"><?php echo $config->get(
                      'fields.attachment'
                    ); ?></label>
                  <div class="col-lg-10">
                      <input class="form-control" id="form-attachment"
                             name="form-attachment" type="file"
                             placeholder="<?php echo $config->get(
                               'fields.attachment'
                             ); ?>" required>
                  </div>
              </div>
              <div class="form-group">
                  <label for="form-attachment"
                         class="col-lg-2 control-label"><?php echo $config->get(
                      'fields.xlsx'
                    ); ?></label>
                  <div class="col-lg-10">
                      <input class="form-control" id="form-xlsx"
                             name="form-xlsx" type="file"
                             placeholder="<?php echo $config->get(
                               'fields.xlsx'
                             ); ?>">
                      <p class="help-block">The script can't get XLSX file from
                          XML, so for testing we should upload the file too
                          (optional).
                      </p>
                  </div>
              </div>
              <div class="form-group">
                  <div class="col-lg-offset-2 col-lg-10">
                      <button type="submit"
                              class="btn btn-default"><?php echo $config->get(
                          'fields.btn-send'
                        ); ?></button>
                  </div>
              </div>
          </form>
      </div>
  <?php endif; ?>

</div>
<footer class="bs-docs-footer">
    <div class="container">
        <ul class="bs-docs-footer-links">
            <li><a href="/contacts-list.php" target="_blank">Get ALL
                    Contacts</a>
            </li>
        </ul>
</footer>

</body>
</html>
