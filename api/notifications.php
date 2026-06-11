<?php
/**
 * Compatibility endpoint for frontend builds that call /api/notifications.
 *
 * The canonical implementation lives in admin_notifications.php and keeps the
 * same admin bearer-token guard, response shape, and mutation behavior.
 */

require __DIR__ . '/admin_notifications.php';
