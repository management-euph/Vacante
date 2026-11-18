<?php

// TEMPORARY probe: a deliberate syntax error, to check that Qodana reports it.
// Reverted right after the scan.

function qodana_probe(int $value): int
{
    $doubled = $value * 2
    return $doubled;
}
