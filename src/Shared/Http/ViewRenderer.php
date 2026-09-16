<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * Writes rendered Twig template bodies — the admin panel's counterpart to
 * {@see JsonResponder} (Phase 27).
 */
final readonly class ViewRenderer
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(ResponseInterface $response, string $template, array $context = [], int $status = 200): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, $context));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
