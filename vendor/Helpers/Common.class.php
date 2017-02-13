<?php

namespace Helpers;

/**
 * Class Common
 * @package Helpers
 */
class Common {

  /**
   * @param string $label
   * @param string $message
   * @param bool $type
   */
  public function logger($label = '', $message = '', $type = FALSE) {
    if (is_array($message) && !empty($message)) {
      $message = json_encode($message);
    }
    $style = '';
    if ($type == 'error') {
      $style = 'style="color: red;"';
    }
    echo '<div class="logger" ' . $style . '><b>' . $label . ':</b> ' . $message . '</div>';
    if ($type == 'error') {
      exit;
    }
  }

  /**
   * @param $filename
   *
   * @return mixed
   */
  public function mime_content_type($filename) {
    $mime_types = array(

      'txt' => 'text/plain',
      'htm' => 'text/html',
      'html' => 'text/html',
      'php' => 'text/html',
      'css' => 'text/css',
      'js' => 'application/javascript',
      'json' => 'application/json',
      'xml' => 'application/xml',
      'swf' => 'application/x-shockwave-flash',
      'flv' => 'video/x-flv',

      // images
      'png' => 'image/png',
      'jpe' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'jpg' => 'image/jpeg',
      'gif' => 'image/gif',
      'bmp' => 'image/bmp',
      'ico' => 'image/vnd.microsoft.icon',
      'tiff' => 'image/tiff',
      'tif' => 'image/tiff',
      'svg' => 'image/svg+xml',
      'svgz' => 'image/svg+xml',

      // archives
      'zip' => 'application/zip',
      'rar' => 'application/x-rar-compressed',
      'exe' => 'application/x-msdownload',
      'msi' => 'application/x-msdownload',
      'cab' => 'application/vnd.ms-cab-compressed',

      // audio/video
      'mp3' => 'audio/mpeg',
      'qt' => 'video/quicktime',
      'mov' => 'video/quicktime',

      // adobe
      'pdf' => 'application/pdf',
      'psd' => 'image/vnd.adobe.photoshop',
      'ai' => 'application/postscript',
      'eps' => 'application/postscript',
      'ps' => 'application/postscript',

      // ms office
      'doc' => 'application/msword',
      'rtf' => 'application/rtf',
      'xls' => 'application/vnd.ms-excel',
      'ppt' => 'application/vnd.ms-powerpoint',

      // open office
      'odt' => 'application/vnd.oasis.opendocument.text',
      'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    );

    $ext = explode('.', $filename);
    $ext = array_pop($ext);
    $ext = strtolower($ext);
    if (array_key_exists($ext, $mime_types)) {
      return $mime_types[$ext];
    }
    elseif (function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME);
      $mimetype = finfo_file($finfo, $filename);
      finfo_close($finfo);
      return $mimetype;
    }
    else {
      return 'application/octet-stream';
    }
  }

  /**
   * @param $bytes
   * @param int $precision
   *
   * @return string
   */
  public function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];

  }

  /**
   * @param $zoho
   *
   * @return object (json)
   */
  public function getNextInvoiceNumber($zoho) {
    try {
      $parameters = array(
        'sort_column' => 'created_time',
        'page' => 1,
        'per_page' => 1,
      );
      $invoice = $zoho->InvoicesList($parameters);
      $invoice = array_pop($invoice);
      $invoice_number = $invoice['invoice_number'];
      $next_invoice_number = explode('-', $invoice_number);
      if (isset($next_invoice_number[1])) {
        $next_invoice_number_suffix_length = strlen($next_invoice_number[1]);
        $next_invoice_number[1]++;
        $next_invoice_number[1] = str_pad($next_invoice_number[1], $next_invoice_number_suffix_length, '0',
          STR_PAD_LEFT);
        $next_invoice_number = implode('-', $next_invoice_number);
        return json_encode(array('next_invoice_number' => $next_invoice_number));
      }
      else {
        return json_encode(array('error', 'Can\'t determinate next invoice number.'));
      }
    } catch (\Exception $e) {
      return json_encode(array('error', $zoho->lastRequest['dataRaw']));
    }
  }

}
