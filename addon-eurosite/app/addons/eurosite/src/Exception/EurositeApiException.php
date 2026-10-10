<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Exception;

/**
 * The Eurosite web service answered with an error envelope (Response
 * ResponseType="Error" carrying <Errors><Error><ErrorId/><ErrorText/>), or
 * with a body that could not be parsed at all. Thrown by the read methods of
 * EurositeApiClient so an API failure is never mistaken for an empty catalog.
 */
final class EurositeApiException extends \RuntimeException
{
}
