<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Palette;

use Semitexa\PlatformUi\Domain\Model\Palette\UiPaletteItem;

/** Answers the command palette's search; register with #[AsCommandSource]. */
interface UiCommandSourceInterface
{
    /**
     * @param string $query the visitor's query, trimmed, 1–100 characters
     * @return iterable<UiPaletteItem> at most $limit, best first
     */
    public function search(string $query, int $limit): iterable;
}
