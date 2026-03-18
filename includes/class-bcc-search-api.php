<?php
/**
 * Backward-compatibility bridge.
 *
 * @deprecated Use \BCC\Search\Controllers\SearchController instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BCC_Search_API extends \BCC\Search\Controllers\SearchController {}
