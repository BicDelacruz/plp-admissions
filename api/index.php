<?php
// ============================================================
// api/index.php — Vercel serverless entry point
// Routes live in core/routes.php (shared with public/index.php)
// ============================================================

require_once dirname(__DIR__) . '/core/bootstrap.php';

$router = new Router();
require dirname(__DIR__) . '/core/routes.php';

$router->dispatch();
