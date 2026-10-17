<?php
// ============================================================
// public/index.php
// Single entry point — all requests route through here
// Apache: mod_rewrite → index.php (see .htaccess)
// Routes live in core/routes.php (shared with api/index.php)
// ============================================================

require_once dirname(__DIR__) . '/core/bootstrap.php';

$router = new Router();
require dirname(__DIR__) . '/core/routes.php';

$router->dispatch();
