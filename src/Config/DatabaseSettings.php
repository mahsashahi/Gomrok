<?php

declare(strict_types=1);

namespace Gomrok\Config;

final readonly class DatabaseSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
        public string $charset,
    ) {
    }

    /**
     * PDO DSN for a MySQL connection (no credentials — pass those to the PDO constructor).
     */
    public function dsn(): string
    {
        return \sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset,
        );
    }
}
