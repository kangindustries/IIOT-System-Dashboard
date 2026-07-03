<?php
require_once __DIR__ . '/auth.php';
requireAuth();
header('Content-Type: application/json');
echo file_get_contents(LATEST_JSON);