<?php

declare(strict_types=1);

/*
 * Test-only stand-in for CS-Cart's global \Tygh class (Tygh::$app, a Pimple
 * container: ArrayAccess), with a recording mailer and AJAX response.
 *
 * Load it ONLY from a #[RunInSeparateProcess] test: a global class cannot be
 * unloaded.
 */

if (!class_exists('Tygh', false)) {
    eval('class Tygh { /** @var mixed */ public static $app; }');
}

final class FgoTestMailer
{
    /** @var list<array{message: array<string, mixed>, area: mixed, lang: mixed}> */
    public array $sent = [];

    public bool|\Throwable $outcome = true;

    /**
     * @param array<string, mixed> $message
     */
    public function send(array $message, $area = null, $lang_code = null): bool
    {
        $this->sent[] = ['message' => $message, 'area' => $area, 'lang' => $lang_code];
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class FgoTestAjax
{
    /** @var array<string, mixed> */
    public array $assigned = [];

    public function assign(string $name, mixed $value): void
    {
        $this->assigned[$name] = $value;
    }
}
