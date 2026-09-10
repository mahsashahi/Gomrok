<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\CreateProviderAccount;

/**
 * Connect a provider account for a client. `secretKey` is plaintext — the
 * handler encrypts it and it is never stored or logged raw.
 */
final readonly class CreateProviderAccountCommand
{
    /**
     * @param list<string> $countries ISO 3166-1 alpha-2
     * @param list<string> $methods   `PaymentMethod` values
     */
    public function __construct(
        public int $clientId,
        public string $providerTypeCode,
        public string $mode,
        public string $name,
        public string $secretKey,
        public ?string $publicKey = null,
        public ?string $slug = null,
        public array $countries = [],
        public array $methods = [],
    ) {
    }
}
