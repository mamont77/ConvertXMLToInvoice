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

}
