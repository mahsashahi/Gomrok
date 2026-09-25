<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * Carries a failed create/edit modal submission back into the same screen's
 * render context so the screen's root `x-data` element can reopen it with
 * everything the user typed still in place — the validation-preserving
 * forms rule (`.claude/docs/Ui.md`). A write action builds one of these
 * instead of redirecting on {@see \Gomrok\Shared\Domain\Result}::isErr(),
 * and passes it to its screen's `render()` method.
 *
 * `values` is whatever was in the submitted form body (job of the write
 * action to shape it the way the modal's `open(name, data)` call already
 * expects — the same shape the "Edit" button's own `open(...)` call uses).
 * `fieldErrors` is best-effort: only populated where a {@see DomainError}'s
 * `context` key is known to name an actual field in that modal, never
 * invented — the general `error` message is the one guaranteed, always-shown
 * feedback.
 */
final readonly class AdminModalReopen
{
    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        public string $modal,
        public array $values,
        public string $error,
        public array $fieldErrors = [],
    ) {
    }
}
