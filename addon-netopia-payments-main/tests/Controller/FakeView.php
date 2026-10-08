<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Controller;

/**
 * Stand-in for CS-Cart's Smarty view: the two methods orders.post.php uses.
 */
final class FakeView
{
    /** @var array<string, mixed> */
    public array $vars = [];

    public function getTemplateVars(string $name): mixed
    {
        return $this->vars[$name] ?? null;
    }

    public function assign(string $name, mixed $value): void
    {
        $this->vars[$name] = $value;
    }
}
