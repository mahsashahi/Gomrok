<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Http\ViewRenderer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A real {@see ViewRenderer} over the actual admin Twig templates — used by
 * the validation-preserving-forms tests (`.claude/docs/Ui.md`) so they
 * exercise the real `packaging.html.twig` / `clients.html.twig` / etc.
 * markup (the `x-init="open(...)"` reopen wiring, the error banner, chip
 * prefill) rather than a stub that would hide a template bug.
 */
final class RealAdminViewRenderer
{
    public static function create(): ViewRenderer
    {
        $viewsDir = \dirname(__DIR__, 2) . '/src/Modules/Admin/Views';
        $loader = new FilesystemLoader($viewsDir);
        $twig = new Environment($loader, ['cache' => false, 'debug' => false, 'auto_reload' => true]);

        return new ViewRenderer($twig);
    }
}
