<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

use Tygh\Addons\FgoInvoicing\Repository\ProfileFieldCatalog;

/**
 * ProfileFieldCatalog double: the fields a test declares, and a count of how
 * often the resolver asked (the production catalog is one query per request,
 * so a resolver that asks when it has no need to is a wasted query).
 */
final class InMemoryProfileFieldCatalog implements ProfileFieldCatalog
{
    public int $reads = 0;

    /** @var array<int, array{field_name: string, section: string, descriptions: list<string>}> */
    private array $fields = [];

    /**
     * @param array<int, string|list<string>> $descriptions field_id => description(s)
     */
    public static function withDescriptions(array $descriptions): self
    {
        $catalog = new self();
        foreach ($descriptions as $id => $texts) {
            $catalog->add($id, (array) $texts);
        }

        return $catalog;
    }

    /**
     * @param list<string> $descriptions
     */
    public function add(int $fieldId, array $descriptions, string $fieldName = '', string $section = 'B'): self
    {
        $this->fields[$fieldId] = [
            'field_name' => $fieldName,
            'section' => $section,
            'descriptions' => $descriptions,
        ];

        return $this;
    }

    #[\Override]
    public function fields(): array
    {
        $this->reads++;

        return $this->fields;
    }
}
