<?php
// Lightweight health endpoint for Railway. Intentionally does NOT touch the
// database, so a DB hiccup can never fail a deployment health check.
// SPDX-License-Identifier: AGPL-3.0-or-later
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'service' => 'mirzabot',
    'php'     => PHP_VERSION,
    'time'    => date('c'),
], JSON_UNESCAPED_SLASHES);
