<?php
/**
 * Travel Core - one rule for letters with marks in CS-Cart URL names
 *
 * fn_generate_name() (app/functions/fn.common.php) turns a name into a URL
 * name through this schema: app/schemas/literal_converter/schema.php, which
 * merges general.php (punctuation), ru.php and umlauts.php, then drops
 * whatever is left outside a-z 0-9 - . The stock lists had no ă ș ț ş ţ ğ ı
 * ("Mărăști" became "mrti", "Ağaoğlu Işıklı" "aaolu-ikl") and wrote ö as
 * "oe" ("Gözde" → "goezde").
 *
 * One rule for every language instead: a letter with a mark becomes its
 * plain letter (ă â → a, î → i, ș ş → s, ț ţ → t, ö → o, ü → u, ğ → g,
 * ı → i), as the add-ons' own addresses do (fn_travel_core_slugify,
 * intl Latin-ASCII) and as Booking's are ("gozde-butik-otel-belek"). These
 * entries win over CS-Cart's; its punctuation ("&" → "and") and Cyrillic
 * lists stay. Letters without a mark keep their usual spelling (ß → ss,
 * æ → ae, ø → o).
 *
 * CS-Cart loads this file after its own, for every name it makes: products,
 * categories and pages added by hand too. No core file is modified.
 *
 * The "general" list (non-Latin URL names enabled in the SEO add-on) is left
 * alone: that setting keeps these letters on purpose.
 */

/** @var array<string, string> $schema */

return array_merge($schema, [
    // Romanian: ș ț with a comma below (correct) and ş ţ with a cedilla (older texts)
    'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I', 'ș' => 's', 'Ș' => 'S',
    'ț' => 't', 'Ț' => 'T', 'ş' => 's', 'Ş' => 'S', 'ţ' => 't', 'Ţ' => 'T',
    // Turkish
    'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O',
    'ü' => 'u', 'Ü' => 'U',
    // Every other Latin letter with a mark (Latin-1 Supplement, Latin Extended-A)
    'À' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'È' => 'E', 'É' => 'E',
    'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Ï' => 'I', 'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O',
    'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ý' => 'Y',
    'Þ' => 'TH', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
    'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'ï' => 'i', 'ð' => 'd',
    'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u',
    'û' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y', 'Ā' => 'A', 'ā' => 'a', 'Ą' => 'A', 'ą' => 'a',
    'Ć' => 'C', 'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c', 'Ċ' => 'C', 'ċ' => 'c', 'Č' => 'C', 'č' => 'c',
    'Ď' => 'D', 'ď' => 'd', 'Đ' => 'D', 'đ' => 'd', 'Ē' => 'E', 'ē' => 'e', 'Ĕ' => 'E', 'ĕ' => 'e',
    'Ė' => 'E', 'ė' => 'e', 'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e', 'Ĝ' => 'G', 'ĝ' => 'g',
    'Ġ' => 'G', 'ġ' => 'g', 'Ģ' => 'G', 'ģ' => 'g', 'Ĥ' => 'H', 'ĥ' => 'h', 'Ħ' => 'H', 'ħ' => 'h',
    'Ĩ' => 'I', 'ĩ' => 'i', 'Ī' => 'I', 'ī' => 'i', 'Ĭ' => 'I', 'ĭ' => 'i', 'Į' => 'I', 'į' => 'i',
    'Ĳ' => 'IJ', 'ĳ' => 'ij', 'Ĵ' => 'J', 'ĵ' => 'j', 'Ķ' => 'K', 'ķ' => 'k', 'ĸ' => 'q', 'Ĺ' => 'L',
    'ĺ' => 'l', 'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L', 'ľ' => 'l', 'Ŀ' => 'L', 'ŀ' => 'l', 'Ł' => 'L',
    'ł' => 'l', 'Ń' => 'N', 'ń' => 'n', 'Ņ' => 'N', 'ņ' => 'n', 'Ň' => 'N', 'ň' => 'n', 'Ŋ' => 'N',
    'ŋ' => 'n', 'Ō' => 'O', 'ō' => 'o', 'Ŏ' => 'O', 'ŏ' => 'o', 'Ő' => 'O', 'ő' => 'o', 'Œ' => 'OE',
    'œ' => 'oe', 'Ŕ' => 'R', 'ŕ' => 'r', 'Ŗ' => 'R', 'ŗ' => 'r', 'Ř' => 'R', 'ř' => 'r', 'Ś' => 'S',
    'ś' => 's', 'Ŝ' => 'S', 'ŝ' => 's', 'Š' => 'S', 'š' => 's', 'Ť' => 'T', 'ť' => 't', 'Ŧ' => 'T',
    'ŧ' => 't', 'Ũ' => 'U', 'ũ' => 'u', 'Ū' => 'U', 'ū' => 'u', 'Ŭ' => 'U', 'ŭ' => 'u', 'Ů' => 'U',
    'ů' => 'u', 'Ű' => 'U', 'ű' => 'u', 'Ų' => 'U', 'ų' => 'u', 'Ŵ' => 'W', 'ŵ' => 'w', 'Ŷ' => 'Y',
    'ŷ' => 'y', 'Ÿ' => 'Y', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z',
    'ſ' => 's',
]);
