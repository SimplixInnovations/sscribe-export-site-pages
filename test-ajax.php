<?php
require_once 'fake-wp/wp-load.php';
$_POST['filter_level'] = 'ALL';
$_POST['search'] = '';
$_POST['session_id'] = '';
$_POST['offset'] = 0;
$_POST['limit'] = 500;

try {
    $admin = SScribe_Container::instance()->get(SScribe_Admin::class);
    $debug = new SScribe_Admin_Debug();
    $debug->ajax_debug_fetch_logs();
} catch (Throwable $e) {
    echo 'Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}
