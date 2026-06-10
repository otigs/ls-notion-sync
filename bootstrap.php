<?php

/**
 * Notion Status Sync — Hook registration.
 *
 * Wires WordPress post-status transitions to the Notion API
 * via Action Scheduler for non-blocking, retryable delivery.
 */

use LightlySalted\NotionSync\StatusListener;
use LightlySalted\NotionSync\NotionClient;
use LightlySalted\NotionSync\DeletionLog;
use LightlySalted\NotionSync\Rest\ContentController;
use LightlySalted\NotionSync\Rest\MediaController;
use LightlySalted\NotionSync\Rest\WriteController;

if (! defined('ABSPATH')) {
    exit;
}

// Catch all post status transitions (publish, draft, trash, restore, etc.)
add_action('transition_post_status', [StatusListener::class, 'onTransition'], 10, 3);

// Enqueue sync job before permanent deletion removes post meta
add_action('before_delete_post', [StatusListener::class, 'onDelete'], 10, 2);

// Action Scheduler callback — queries Notion DB then patches page status
add_action('ls_notion_sync_status', [NotionClient::class, 'updateStatus'], 10, 2);

// Content export API consumed by the Notion worker (WP → Notion content sync)
add_action('rest_api_init', [ContentController::class, 'registerRoutes']);

// Write API for the reverse direction (Notion → WP) and Unsplash sideloading
add_action('rest_api_init', [WriteController::class, 'registerRoutes']);
add_action('rest_api_init', [MediaController::class, 'registerRoutes']);

// Record permanent deletions for the worker's deletions feed.
// Priority 9: runs before StatusListener::onDelete while meta is intact.
add_action('before_delete_post', [DeletionLog::class, 'onDelete'], 9, 2);
