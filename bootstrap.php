<?php

/**
 * Notion Status Sync — Hook registration.
 *
 * Wires WordPress post-status transitions to the Notion API
 * via Action Scheduler for non-blocking, retryable delivery.
 */

use LightlySalted\NotionSync\StatusListener;
use LightlySalted\NotionSync\NotionClient;

if (! defined('ABSPATH')) {
    exit;
}

// Catch all post status transitions (publish, draft, trash, restore, etc.)
add_action('transition_post_status', [StatusListener::class, 'onTransition'], 10, 3);

// Capture _notion_page_id before permanent deletion removes post meta
add_action('before_delete_post', [StatusListener::class, 'onDelete'], 10, 2);

// Action Scheduler callback — executes the Notion API PATCH
add_action('ls_notion_sync_status', [NotionClient::class, 'updateStatus'], 10, 3);
