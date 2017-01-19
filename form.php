<?php
require_once __DIR__ . '/vendor/Helpers/Config.class.php';

use Helpers\Config;

$config = new Config();

$config->load('./config/config.php');

?><!DOCTYPE html>
<html>
<head>
    <title>Send XML to Zoho Books API</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <link href="//netdna.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" rel="stylesheet" media="screen">
</head>
<body>
<div class="jumbotron">
    <div class="container">
        <h1>Send XML to Zoho Books API</h1>
    </div>
</div>

<div class="container">
    <div class="row">
        <form action="/index.php"
              enctype="multipart/form-data" id="zoho-form"
              class="form-horizontal" method="post">
            <div class="form-group">
                <label for="form-authtoken"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.authtoken'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-authtoken" name="form-authtoken" type="text"
                           placeholder="<?php echo $config->get('form_fields.authtoken'); ?>">
                    <p class="help-block">Only if you want to override the authtoken by your value (optional).</p>
                </div>
            </div>
            <div class="form-group">
                <label for="form-xml"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.xml'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-xml" name="form-xml"
                           type="file" placeholder="<?php echo $config->get('form_fields.xml'); ?>
                    " required>
                </div>
            </div>
            <div class="form-group">
                <label for="form-attachment"
                       class="col-lg-2 control-label"><?php echo $config->get('form_fields.attachment'); ?></label>
                <div class="col-lg-10">
                    <input class="form-control" id="form-attachment" name="form-attachment" type="file"
                           placeholder="<?php echo $config->get('form_fields.attachment'); ?>">
                    <p class="help-block">This script can't get attachment file from XML, so for testing we should
                        upload the file, too (optional). Also, helpful if you want to override a file from XML.</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-lg-offset-2 col-lg-10">
                    <button type="submit"
                            class="btn btn-default"><?php echo $config->get('form_fields.btn-send'); ?></button>
                </div>
            </div>
        </form>
    </div>

</div>
<footer class="bs-docs-footer">
    <div class="container">
        <ul class="bs-docs-footer-links">
            <li>
                <a href="/contacts-list.php" target="_blank">Get ALL Contacts</a>
            </li>
        </ul>
</footer>
</body>
</html>
