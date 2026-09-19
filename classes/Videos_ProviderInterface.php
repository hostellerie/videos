<?php

if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

/**
 * Minimal external video provider contract.
 *
 * Keep provider-specific transport and response handling behind this boundary
 * so the rest of Videos can depend on the capabilities it actually needs.
 */
interface Videos_ProviderInterface
{
    public function search($query, $parameters);

    public function videos($ids);

    public function channels($ids);

    public function getLastError();
}
