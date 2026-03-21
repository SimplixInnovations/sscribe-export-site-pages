<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
define('ABSPATH', 1);
define('WP_PLUGIN_DIR', __DIR__);
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://localhost/wp-content/plugins/sscribe-export-site-pages/'; }
function plugin_basename($f) { return 'sscribe-export-site-pages/sscribe-export-site-pages.php'; }
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
require 'c:\Users\Ahmed\Desktop\sscribe-export-site-pages\sscribe-export-site-pages.php';
echo 'SUCCESS';
