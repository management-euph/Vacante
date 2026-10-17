<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks;

use Tygh\Addons\EuphPagesInBlocks\Db\Database;
use Tygh\Addons\EuphPagesInBlocks\View\BlockPresenter;

/**
 * Wires the add-on's services. Built once per request at the CS-Cart
 * boundary (fn_euph_pages_in_blocks_container()), which hands in the
 * database and CS-Cart's own page and URL functions.
 */
final class Container
{
    private ?PageLoader $loader = null;
    private ?BlockPresenter $presenter = null;

    /**
     * @param \Closure(string): string $url fn_url()
     * @param \Closure(int, string): mixed $fetchPage fn_get_page_data()
     */
    public function __construct(
        private readonly Database $db,
        private readonly \Closure $url,
        private readonly \Closure $fetchPage,
    ) {
    }

    public function loader(): PageLoader
    {
        return $this->loader ??= new PageLoader($this->fetchPage);
    }

    /** One per request: it keeps the block DOM ids unique in the page. */
    public function presenter(): BlockPresenter
    {
        return $this->presenter ??= new BlockPresenter($this->url);
    }

    public function cleaner(): BlockCleaner
    {
        return new BlockCleaner($this->db);
    }
}
