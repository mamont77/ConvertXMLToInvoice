<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';
require_once __DIR__ . '/vendor/Helpers/Common.class.php';
require_once __DIR__ . '/vendor/ZohoBooksApi/ZohoBooksApi.php';

use Helpers\Config;
use Helpers\Common;

$config = new Config();
$tools = new Common();

$config->load('./config/config.php');

$zoho = new ZohoBooksApi(
  $config->get('zoho.authtoken'),
  $config->get('zoho.organizationID')
);


$zoho = new ZohoBooksApi(
  $config->get('zoho.authtoken'),
  $config->get('zoho.organizationID')
);

$next_invoice_number = $tools->getNextInvoiceNumber($zoho);
$next_invoice_number = json_decode($next_invoice_number);
$next_invoice_number = $next_invoice_number->next_invoice_number;

?><!DOCTYPE html>
<html>
<head>
    <title>Send XML to Zoho Books API</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <link href="//netdna.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" rel="stylesheet" media="screen">
</head>
<style type="text/css">
    .jumbotron {
        padding: 15px 0;
    }

    .jumbotron .h1, .jumbotron h1 {
        font-size: 34px;
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
<div class="jumbotron">
    <div class="container">
        <h1>Send XML to Zoho Books API</h1>
    </div>
</div>

<div class="container">
    <div class="row">
        <form action="/XML2ZohoScript.php?appAuthToken=<?php echo $config->get('app_authtoken'); ?>"
              enctype="multipart/form-data" id="zoho-form"
              class="form-horizontal" method="post">
            <div class="form-group">
                <label for="form-authtoken"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.authtoken'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-authtoken" name="zoho-authtoken" type="text"
                           placeholder="<?php echo $config->get('form_fields.authtoken'); ?>">
                    <p class="help-block">Only if you want to override the authtoken by your value (optional).</p>
                </div>
            </div>
            <div class="form-group">
                <label for="form-invoice-number"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.invoice_number'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-invoice-number" name="zoho-invoice-number" type="text"
                           placeholder="<?php echo $config->get('form_fields.invoice_number'); ?>"
                           value="<?php echo $next_invoice_number; ?>" disabled>
                    <p class="help-block">Next invoice number (just for info).</p>
                </div>
            </div>
            <div class="form-group">
                <label for="form-xml"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.xml'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-xml" name="xml"
                           type="file" placeholder="<?php echo $config->get('form_fields.xml'); ?>
                    " required>
                </div>
            </div>
            <div class="form-group">
                <label for="form-attachment"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.attachment'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-attachment" name="attachment" type="file"
                           placeholder="<?php echo $config->get('form_fields.attachment'); ?>">
                    <p class="help-block">This script can't get attachment file from XML, so for testing we should
                        upload the file, too (optional). Also, helpful if you want to override a file from XML.</p>
                </div>
            </div>
            <div class="form-group">
                <label for="form-charge-payment"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.charge_payment'); ?></label>
                <div class="col-lg-10">
                    <input class="" id="form-charge-payment" name="charge_payment" type="checkbox" value="1" checked>
                </div>
            </div>
            <div class="form-group">
                <label for="form-send-email"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.send_email'); ?></label>
                <div class="col-lg-10">
                    <input class="" id="form-send-email" name="send_email" type="checkbox" value="1" checked>
                    <p class="help-block">Ignored in cases if "<?php
                      echo $config->get('form_fields.charge_payment');
                      ?>" is unchecked or a payment unsuccessful.</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-lg-offset-2 col-lg-10">
                    <button type="submit"
                            class="btn btn-default btn-lg"><?php echo $config->get('form_fields.btn-send'); ?></button>
                </div>
            </div>
        </form>
    </div>

</div>
<footer class="bs-docs-footer">
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
