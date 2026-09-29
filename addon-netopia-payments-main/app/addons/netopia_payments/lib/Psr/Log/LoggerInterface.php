<?php

namespace Psr\Log;

/**
 * Describes a logger instance.
 *
 * The message MUST be a string or object implementing __toString().
 *
 * The message MAY contain placeholders in the form: {foo} where foo
 * will be replaced by the context data in key "foo".
 *
 * The context array can contain arbitrary data. The only assumption that
 * can be made by implementors is that if an Exception instance is given
 * to produce a stack trace, it MUST be in a key named "exception".
 *
 * See https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
 * for the full interface specification.
 */
interface LoggerInterface
{
    /** @param string|\Stringable $message */
    public function emergency($message, array $context = []);

    /** @param string|\Stringable $message */
    public function alert($message, array $context = []);

    /** @param string|\Stringable $message */
    public function critical($message, array $context = []);

    /** @param string|\Stringable $message */
    public function error($message, array $context = []);

    /** @param string|\Stringable $message */
    public function warning($message, array $context = []);

    /** @param string|\Stringable $message */
    public function notice($message, array $context = []);

    /** @param string|\Stringable $message */
    public function info($message, array $context = []);

    /** @param string|\Stringable $message */
    public function debug($message, array $context = []);

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param mixed[] $context
     */
    public function log($level, $message, array $context = []);
}
