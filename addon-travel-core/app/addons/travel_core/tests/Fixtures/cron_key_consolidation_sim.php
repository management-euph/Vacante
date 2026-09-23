<?php
// Runs SettingsMigrator's cron-key migration against a FAKE settings store, in
// a clean PHP process, and prints what happened as JSON.
//
// Why a subprocess: the real Tygh\Settings is CS-Cart kit code that is not in
// this repository, and defining a stand-in class inside the main PHPUnit
// process would leak into every other test. Here it exists for one run only.
//
// The fake mirrors the real class's documented behaviour, including the one
// that shapes the whole migration: updateValue() on a row that does NOT exist
// is a silent no-op.
//
// argv[1]: path to the travel_core addon directory
// argv[2]: JSON scenario:
//   {"mode": "consolidate" | "rotation",
//    "rows": {"<addon>.<name>": "<value>", ...},   // rows that exist
//    "throw_on_read": ["<addon>.<name>", ...],     // getValue() throws
//    "writes_fail": bool,                          // updateValue() no-ops
//    "rotation": ["<addon>", "<name>"]}            // for mode=rotation

declare(strict_types=1);

$addonDir = $argv[1];
$scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);

define('BOOTSTRAP', true);

$GLOBALS['sim_notices'] = [];
$GLOBALS['sim_logs'] = [];

function __($key, $vars = []) { return is_string($key) ? $key : ''; }
function fn_set_notification($type, $title, $message) { $GLOBALS['sim_notices'][] = [$type, $message]; }
function fn_travel_core_heal_report(string $message): void { $GLOBALS['sim_logs'][] = $message; }

eval('namespace Tygh\Enum; final class SettingTypes {
    const CHECKBOX = "C"; const INPUT = "I"; const PASSWORD = "P"; const TEXTAREA = "T";
    const SELECTBOX = "S"; const MULTIPLE_CHECKBOXES = "N"; const MULTIPLE_SELECT = "M";
    const HEADER = "H"; const INFO = "O"; const NUMBER = "U"; const TEMPLATE = "E"; const HIDDEN = "D";
}');

eval('namespace Tygh; final class Settings {
    public const ADDON_SECTION = "ADDON";
    private static ?Settings $instance = null;
    /** @var array<string, string> */ public array $rows = [];
    /** @var array<string, int> */ private array $ids = [];
    /** @var list<string> */ public array $throwOnRead = [];
    public bool $writesFail = false;

    public static function instance(): Settings { return self::$instance ??= new Settings(); }

    public function seed(array $rows): void {
        $this->rows = $rows;
        $n = 100;
        foreach (array_keys($rows) as $k) { $this->ids[$k] = ++$n; }
    }
    public function isExists($name, $addon): bool { return array_key_exists("$addon.$name", $this->rows); }
    public function getValue($name, $addon) {
        if (in_array("$addon.$name", $this->throwOnRead, true)) {
            throw new \RuntimeException("simulated read failure for $addon.$name");
        }
        return $this->rows["$addon.$name"] ?? null;
    }
    public function updateValue($name, $value, $addon) {
        // The real class: a missing row is a SILENT no-op that reports success.
        if ($this->writesFail || !array_key_exists("$addon.$name", $this->rows)) { return true; }
        $this->rows["$addon.$name"] = (string) $value;
        return true;
    }
    public function getId($name, $addon): int { return $this->ids["$addon.$name"] ?? 0; }
    public function removeById($id): void {
        $key = array_search($id, $this->ids, true);
        if ($key !== false) { unset($this->rows[$key], $this->ids[$key]); }
    }
}');

require $addonDir . '/vendor/autoload.php';

$settings = \Tygh\Settings::instance();
$settings->seed($scenario['rows'] ?? []);
$settings->throwOnRead = $scenario['throw_on_read'] ?? [];
$settings->writesFail = (bool) ($scenario['writes_fail'] ?? false);

$changed = null;
if (($scenario['mode'] ?? 'consolidate') === 'rotation') {
    [$addon, $name] = $scenario['rotation'];
    (new \ReflectionMethod(\Tygh\Addons\TravelCore\Install\SettingsMigrator::class, 'reportRotation'))
        ->invoke(null, $addon, $name);
} else {
    $changed = \Tygh\Addons\TravelCore\Install\SettingsMigrator::consolidateCronKey();
}

echo json_encode([
    'changed' => $changed,
    'rows' => $settings->rows,
    'notices' => $GLOBALS['sim_notices'],
    'logs' => $GLOBALS['sim_logs'],
], JSON_THROW_ON_ERROR);
