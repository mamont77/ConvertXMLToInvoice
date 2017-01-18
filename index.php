<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/SimpleMail/SimpleMail.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;
use SimpleMail\SimpleMail;

$config = new Config;
$config->load('./config/config.php');

function logger($label = '', $message = '') {
  if (is_array($message) && !empty($message)) {
    $message = json_encode($message);
  }
  echo '<b>' . $label . ':</b> ' . $message . '<br />';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!empty($_FILES['form-attachment']['name'])) {

    $file_name          = $_FILES['form-attachment']['name'];
    $temp_name          = $_FILES['form-attachment']['tmp_name'];
    $file_type          = $_FILES['form-attachment']['type'];
    $base               = basename($file_name);
    $extension          = substr($base, strlen($base) - 4, strlen($base));
    $allowed_extensions = array('.xml');

    if (in_array($extension, $allowed_extensions)) {

      $xml_data = simplexml_load_file($temp_name);

      $contact_id = (string) $xml_data->Job->Client->contactID;

      if ($contact_id == '') {
        logger('Client not found in XML by contactID', $contact_id);
      }

      $zoho = new ZohoBooksApi(
        $config->get('zoho.authtoken'),
        $config->get('zoho.organizationID')
      );

      //TODO: crmResp = zoho.crm.searchRecords("Leads", "(Custom Field 1|=| test Or Custom Field 2|=| test@zohocorp.com Or Custom Field 3|=| 12345)");

      try {
        $contact = $zoho->ContactsGet($contact_id);
        logger('Contact was found in Zoho with ID', $contact_id);
      } catch (Exception $e) {
        logger('Zoho Exception', $zoho->lastRequest['dataRaw']);
        logger(
          'Contact not found in Zoho by contactID',
          $contact_id
        );
        exit;
      }

      // Preparing an Invoice.
      $invoice_items = array();
      foreach ($xml_data->Job->Production->InvoiceItems->Product as $key => $item) {
        $item             = (array) $item;
        $rate_mapping     = array(
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

        $invoice_items[] = array(
          'name' => trim((string) $item['ID'], '""'),
          'description' => (string) $item['Description'],
          'quantity' => $current_quantity,
          'rate' => $current_rate_type,
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

      logger('Creating invoice: Try send the data to Zoho', $invoce_data);

      try {
//        $invoice        = $zoho->InvoicesCreate($invoce_data);

        $invoice['invoice_id'] = '159812000000849219';

        $invoice_id     = $invoice['invoice_id'];
        $invoice_number = $invoice['invoice_number'];
        logger(
          'Invoice has been created with ID / NUMBER',
          $invoice_id . ' / ' . $invoice_number
        );
        echo '<a href="https://books.zoho.com/app#/invoices/'
             . $invoice_id
             . '" target="_blank">Open in Zoho!</a>'
             . ' Or upload <a href="/">another</a> XML.<br />';
      } catch (Exception $e) {
        logger('Zoho Exception', $zoho->lastRequest['dataRaw']);
        exit;
      }

      if ($xml_data->Job->Production->WorkStatement) {
        $invoice_attachment = (string) $xml_data->Job->Production->WorkStatement;
        if (!empty($_FILES['form-xlsx']['name']) && $invoice_attachment !== '') {
          if (move_uploaded_file(
            $_FILES['form-xlsx']['tmp_name'],
            __DIR__ . $invoice_attachment
          )) {
            logger(
              'XLSX file has been uploaded and renamed to',
              $invoice_attachment
            );

            // Attach a file.
            $attachment = __DIR__ . $invoice_attachment;
            $mime_type  = mime_content_type($attachment);
            $file_name  = basename($attachment);
            $parameters = new CURLFile($attachment, $mime_type, $file_name);
            logger('Append the attachment to the invoice', $invoice_number);
            try {
              $result = $zoho->makeApiRequest(
                'invoices/' . $invoice_id . '/attachment',
                'POST',
                $parameters
              );
              logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
            } catch (Exception $e) {
              logger('Zoho Exception', $zoho->lastRequest['dataRaw']);
            }

            // Update "can_send_in_mail" param.
            $parameters = array(
              'can_send_in_mail' => TRUE,
            );
            try {
              $result = $zoho->makeApiRequest(
                'invoices/' . $invoice_id . '/attachment',
                'PUT',
                $parameters
              );
              logger('Zoho Result', $zoho->lastRequest['zohoMessage']);
            } catch (Exception $e) {
              logger('Zoho Exception', $zoho->lastRequest['dataRaw']);
            }

          }
          else {
            logger('Error during file uploading', $_FILES['form-xlsx']['name']);
          }
        }

      }

      exit;

      // For some testing.
//      $temporary_invoice = $zoho->InvoicesGet('159812000000833591');
//      echo '<pre>';
//      print_r($temporary_invoice);
//      echo '</pre>';

      exit;

      $mail = new SimpleMail();

      $mail->setTo($config->get('emails.to'));
      $mail->setFrom($config->get('emails.from'));
      $mail->setSender($config->get('sender.name'));
      $mail->setSenderEmail($config->get('emails.to'));
      $mail->setSubject($config->get('subject.prefix'));

      $body = "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
        <html>
            <head>
                <meta charset=\"utf-8\">
            </head>
            <body>
                <p><strong>{$config->get('fields.attachment')}:</strong> {$file_name}</p>
            </body>
        </html>";

      $mail->setHtml($body);
      $mail->send();

      $emailSent = TRUE;
    }
    else {
      $hasError = TRUE;
    }


  }
  else {
    $hasError = TRUE;
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
