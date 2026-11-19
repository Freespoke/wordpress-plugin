<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

/**
 * Resolves a bearer token for API requests.
 */
interface AuthProvider
{
    /**
     * Return a valid bearer token string.
     *
     * Implementations may cache tokens and refresh them transparently.
     */
    public function getToken(): string;
}
