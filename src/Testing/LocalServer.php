<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

/**
 * A bootable test server with a known base URL.
 *
 * Lighthouse::local() depends on this rather than on FrankenPhpDriver
 * directly, so the audit doesn't have to know about the FrankenPHP
 * subprocess lifecycle — and so unit tests can substitute a stub
 * implementation.
 */
interface LocalServer
{
    /**
     * Start the server if it isn't already running. Must be idempotent.
     */
    public function bootstrap(): void;

    /**
     * The absolute base URL the server is reachable at, with no trailing
     * slash — e.g. "http://127.0.0.1:8080".
     */
    public function url(): string;
}
