<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

/**
 * Authenticates with a static API key (bearer token).
 */
class APIKeyAuthProvider implements AuthProvider
{
    private string $apiKey;
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }
    public function getToken(): string
    {
        return $this->apiKey;
    }
}
