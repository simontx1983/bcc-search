<?php

namespace BCC\Search;

use BCC\Search\Controllers\SearchController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight service container for the Search plugin.
 */
final class Plugin
{
    private static ?self $instance = null;

    private ?SearchController $controller = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function controller(): SearchController
    {
        if ($this->controller === null) {
            $this->controller = new SearchController();
        }
        return $this->controller;
    }
}
