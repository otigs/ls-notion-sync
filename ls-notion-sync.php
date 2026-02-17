<?php

/**
 * Plugin Name: Lightly Salted — Notion Status Sync
 * Description: One-directional sync from WordPress post status changes to Notion page Status property via Action Scheduler.
 * Version: 1.0.0
 * Author: Lightly Salted
 */

if (! defined('ABSPATH')) {
    exit;
}

$notionSyncAutoloader = __DIR__ . '/notion-sync/vendor/autoload.php';

if (! file_exists($notionSyncAutoloader)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Notion Status Sync:</strong> ';
        echo 'Dependencies not installed. Run <code>composer install</code> in <code>mu-plugins/notion-sync/</code>.';
        echo '</p></div>';
    });

    return;
}

require_once $notionSyncAutoloader;
require_once __DIR__ . '/notion-sync/bootstrap.php';
