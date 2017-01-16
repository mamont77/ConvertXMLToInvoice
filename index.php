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

      $invoice_attachment = (string) $xml_data->Job->Production->WorkStatement;

      //$client_id           = (string) $xml_data->Job->Client->ID;
      $client_display_name = (string) $xml_data->Job->Client->DisplayName;

      if ($client_id == '') {
//        throw new Exception('Client ID not found in XML.');
//        logger('Client ID not found in XML', $client_id);
        logger('Client not found in XML by DisplayName', $client_display_name);
      }

      $zoho = new ZohoBooksApi(
        $config->get('zoho.authtoken'),
        $config->get('zoho.organizationID')
      );

      $contact = $zoho->ContactsList(
        array('contact_name' => $client_display_name)
      );

      if (empty($contact)) {
//        throw new Exception('Contact not found in Zoho.');
        logger(
          'Contact not found in Zoho by DisplayName',
          $client_display_name
        );
      }

      $contact_id = $contact[0]['contact_id'];
      logger('Contact was found in Zoho with ID', $contact_id);

      $invoice_items = array();
      foreach ($xml_data->Job->Production->InvoiceItems->Product as $key => $item) {
        $item         = (array) $item;
        $rate_mapping = array(
          'PricePerInd',
          'PricePerRender',
          'PricePerTick',
          'PricePerSheet',
          'PricePerPose',
        );
        $quantity_mapping  = array(
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
          'name' => (string) $item['ID'],
          'description' => (string) $item['Description'],
          'quantity' => $current_quantity,
          'rate' => $current_rate_type,
        );
      }

      $invoce_data = array(
        'customer_id' => $contact_id,
        // Fixme: Allowed Values: paypal, authorize_net, payflow_pro, stripe, 2checkout and braintree.
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

      logger('Creating invoice: Try send the data to Zoho', $invoce_data);

      $invoice = $zoho->InvoicesCreate($invoce_data);

      $invoice_id     = $invoice['invoice_id'];
      $invoice_number = $invoice['invoice_number'];

      logger(
        'Invoice has been created with ID/NUMBER',
        $invoice_id . '/' . $invoice_number
      );
      echo '<a href="https://books.zoho.com/app#/invoices/' . $invoice_id . '" target="_blank">Open in Zoho!</a>';

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
<?php if (!empty($emailSent)): ?>
    <div class="col-md-6 col-md-offset-3">
        <div class="alert alert-success text-center"><?php echo $config->get(
            'messages.success'
          ); ?></div>
    </div>
<?php else: ?>
  <?php if (!empty($hasError)): ?>
        <div class="col-md-5 col-md-offset-4">
            <div class="alert alert-danger text-center"><?php echo $config->get(
                'messages.error'
              ); ?></div>
        </div>
  <?php endif; ?>

    <div class="col-md-6 col-md-offset-3">
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

</body>
</html>
