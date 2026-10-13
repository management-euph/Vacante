<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes;

use Tygh\Addons\EuphProfileTypes\Db\Database;
use Tygh\Addons\EuphProfileTypes\Repository\FieldRuleRepository;
use Tygh\Addons\EuphProfileTypes\Repository\ProfileTypeRepository;
use Tygh\Addons\EuphProfileTypes\Service\FieldVisibility;
use Tygh\Addons\EuphProfileTypes\Service\LegacyImporter;
use Tygh\Addons\EuphProfileTypes\Service\TypeAdmin;
use Tygh\Addons\EuphProfileTypes\Service\TypeCatalog;

/**
 * Wires the add-on's services. Built once per request at the CS-Cart
 * boundary (fn_euph_profile_types_container()), which reads the settings
 * and the edition and hands them in.
 */
final class Container
{
    private ?ProfileTypeRepository $types = null;
    private ?FieldRuleRepository $rules = null;
    private ?TypeCatalog $catalog = null;

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly bool $multiVendor,
    ) {
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function isMultiVendor(): bool
    {
        return $this->multiVendor;
    }

    public function types(): ProfileTypeRepository
    {
        return $this->types ??= new ProfileTypeRepository($this->db);
    }

    public function rules(): FieldRuleRepository
    {
        return $this->rules ??= new FieldRuleRepository($this->db);
    }

    public function catalog(): TypeCatalog
    {
        return $this->catalog ??= new TypeCatalog($this->types(), $this->settings, $this->multiVendor);
    }

    /** Built on the current rules: they change when the administrator saves a field. */
    public function visibility(): FieldVisibility
    {
        return new FieldVisibility($this->rules()->all());
    }

    public function admin(): TypeAdmin
    {
        return new TypeAdmin($this->db, $this->types(), $this->rules(), $this->multiVendor);
    }

    public function importer(): LegacyImporter
    {
        return new LegacyImporter($this->db, $this->types(), $this->rules(), $this->multiVendor);
    }
}
