<?php

declare(strict_types=1);

namespace Vsh\Core\Http;

/**
 * Pagination à partir de ?page=&per_page= (bornée pour limiter les volumes transférés).
 */
final class Pagination
{
    /** @var int */
    private $page;

    /** @var int */
    private $perPage;

    public function __construct(int $page, int $perPage)
    {
        $this->page = max(1, $page);
        $this->perPage = max(1, $perPage);
    }

    public static function fromRequest(Request $request, int $default = 25, int $max = 100): self
    {
        $perPage = (int) $request->query('per_page', (string) $default);
        return new self((int) $request->query('page', '1'), min($max, $perPage > 0 ? $perPage : $default));
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
