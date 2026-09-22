<?php
/**
 * Verify a slot token across a fresh WordPress/PHP process.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

$token = (string)getenv('CEMB_SLOT_TOKEN');
$typeId = (int)getenv('CEMB_TYPE_ID');
$payload = (new Cemb\Tokens\SlotTokenService())->verify($token);

if (!$payload || (int)$payload['type_id'] !== $typeId) {
    echo 'TOKEN_BAD' . PHP_EOL;
    return;
}

echo 'TOKEN_OK' . PHP_EOL;
