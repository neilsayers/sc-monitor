<?php

namespace SCMonitor\Contracts;

/**
 * A self-contained feature that wires its own WordPress hooks.
 * New functionality is added by writing one of these and listing it
 * in Plugin::boot() — nothing else needs to change.
 */
interface Hookable
{
    public function register(): void;
}
