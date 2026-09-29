<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Pdf;

/**
 * A PDF could not be downloaded, or what came back was not one. The message
 * is shown to the admin next to the order; it never carries the PDF URL's
 * query string (FGO's links are signed).
 */
final class PdfFetchException extends \RuntimeException
{
}
