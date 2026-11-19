<?php

declare (strict_types=1);
namespace FreespokeDeps\Freespoke\Partner;

use FreespokeDeps\League\OAuth2\Client\Provider\GenericProvider;
use FreespokeDeps\League\OAuth2\Client\Token\AccessTokenInterface;
/**
 * Authenticates via OAuth2 client credentials grant.
 *
 * Tokens are cached and refreshed automatically when they expire.
 */
class TokenAuthProvider implements AuthProvider
{
    private GenericProvider $provider;
    private ?AccessTokenInterface $token = null;
    public function __construct(GenericProvider $provider)
    {
        $this->provider = $provider;
    }
    public function getToken(): string
    {
        if ($this->token === null || $this->token->hasExpired()) {
            $this->token = $this->provider->getAccessToken('client_credentials');
        }
        return $this->token->getToken();
    }
}
