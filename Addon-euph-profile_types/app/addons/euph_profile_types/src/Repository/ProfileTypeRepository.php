<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Repository;

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Db\Database;
use Tygh\Addons\EuphProfileTypes\Enum\LinkKind;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Model\ProfileType;

/**
 * Profile types, their names and their links.
 *
 * Every type of the store is read in one query per language and kept for the
 * request: there are a handful of them, and the storefront asks for them on
 * every profile field fetch.
 */
final class ProfileTypeRepository
{
    public const TABLE = '?:euph_profile_types';
    public const DESCRIPTIONS = '?:euph_profile_type_descriptions';
    public const LINKS = '?:euph_profile_type_links';

    /** @var array<string, list<ProfileType>> lang code => types */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Every type, customers first, by position.
     *
     * @return list<ProfileType>
     */
    public function all(string $langCode): array
    {
        if (isset($this->cache[$langCode])) {
            return $this->cache[$langCode];
        }
        $rows = $this->db->rows(
            'SELECT t.*, d.name, d.description FROM ?:euph_profile_types AS t'
            . ' LEFT JOIN ?:euph_profile_type_descriptions AS d ON d.type_id = t.type_id AND d.lang_code = ?s'
            . ' ORDER BY t.user_type ASC, t.position ASC, t.type_id ASC',
            $langCode,
        );
        $links = $this->links();
        $types = [];
        foreach ($rows as $row) {
            try {
                $types[] = ProfileType::fromRow($row, $links[Cast::int($row['type_id'] ?? 0)] ?? []);
            } catch (\InvalidArgumentException) {
                // A row without an id or a code is not a type.
            }
        }

        return $this->cache[$langCode] = $types;
    }

    /**
     * @return list<ProfileType>
     */
    public function ofKind(UserKind $kind, string $langCode): array
    {
        return array_values(array_filter(
            $this->all($langCode),
            static fn (ProfileType $type): bool => $type->kind === $kind,
        ));
    }

    public function find(int $typeId, string $langCode): ?ProfileType
    {
        foreach ($this->all($langCode) as $type) {
            if ($type->id === $typeId) {
                return $type;
            }
        }

        return null;
    }

    public function findByCode(string $code, string $langCode): ?ProfileType
    {
        if ($code === '') {
            return null;
        }
        foreach ($this->all($langCode) as $type) {
            if (strcasecmp($type->code, $code) === 0) {
                return $type;
            }
        }

        return null;
    }

    public function standard(UserKind $kind, string $langCode): ?ProfileType
    {
        foreach ($this->ofKind($kind, $langCode) as $type) {
            if ($type->isStandard) {
                return $type;
            }
        }

        return null;
    }

    public function codeExists(string $code, int $exceptTypeId = 0): bool
    {
        return Cast::int($this->db->value(
            'SELECT type_id FROM ?:euph_profile_types WHERE code = ?s AND type_id != ?i',
            $code,
            $exceptTypeId,
        )) > 0;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(
            static fn (mixed $code): string => Cast::string($code),
            $this->db->column('SELECT code FROM ?:euph_profile_types'),
        );
    }

    /**
     * Inserts or updates a type. The name and the description go to $langCode;
     * a new type gets them in every language of $langCodes too, so it never
     * shows up nameless in another language.
     *
     * @param array{code?: string, user_type?: string, status?: string, position?: int, activation?: string, is_standard?: string} $fields
     * @param list<string> $langCodes
     * @param array<string, list<int>> $links object type => ids; only the object types given are replaced
     */
    public function save(
        int $typeId,
        array $fields,
        string $name,
        string $description,
        string $langCode,
        array $langCodes,
        array $links,
    ): int {
        if ($typeId > 0) {
            if ($fields !== []) {
                $this->db->execute('UPDATE ?:euph_profile_types SET ?u WHERE type_id = ?i', $fields, $typeId);
            }
            $this->saveDescription($typeId, $langCode, $name, $description);
        } else {
            $typeId = $this->db->execute('INSERT INTO ?:euph_profile_types ?e', $fields);
            foreach (array_unique([$langCode, ...$langCodes]) as $lang) {
                $this->saveDescription($typeId, $lang, $name, $description);
            }
        }

        foreach ($links as $objectType => $ids) {
            $this->db->execute(
                'DELETE FROM ?:euph_profile_type_links WHERE type_id = ?i AND object_type = ?s',
                $typeId,
                $objectType,
            );
            foreach ($ids as $id) {
                $this->db->execute(
                    'REPLACE INTO ?:euph_profile_type_links ?e',
                    ['type_id' => $typeId, 'object_type' => $objectType, 'object_id' => $id],
                );
            }
        }
        $this->forget();

        return $typeId;
    }

    public function saveDescription(int $typeId, string $langCode, string $name, string $description): void
    {
        $this->db->execute('REPLACE INTO ?:euph_profile_type_descriptions ?e', [
            'type_id' => $typeId,
            'lang_code' => $langCode,
            'name' => $name,
            'description' => $description,
        ]);
        $this->forget();
    }

    /** One default type per kind. */
    public function setDefault(int $typeId, UserKind $kind): void
    {
        $this->db->execute('UPDATE ?:euph_profile_types SET is_default = ?s WHERE user_type = ?s', 'N', $kind->value);
        $this->db->execute(
            'UPDATE ?:euph_profile_types SET is_default = ?s WHERE type_id = ?i AND user_type = ?s',
            'Y',
            $typeId,
            $kind->value,
        );
        $this->forget();
    }

    /**
     * @param array<int, int> $positions type id => position
     */
    public function savePositions(array $positions): void
    {
        foreach ($positions as $typeId => $position) {
            $this->db->execute('UPDATE ?:euph_profile_types SET position = ?i WHERE type_id = ?i', $position, $typeId);
        }
        $this->forget();
    }

    public function delete(int $typeId): void
    {
        $this->db->execute('DELETE FROM ?:euph_profile_types WHERE type_id = ?i', $typeId);
        $this->db->execute('DELETE FROM ?:euph_profile_type_descriptions WHERE type_id = ?i', $typeId);
        $this->db->execute('DELETE FROM ?:euph_profile_type_links WHERE type_id = ?i', $typeId);
        $this->forget();
    }

    /**
     * How many users (customers) or companies (vendors) have each code.
     *
     * @return array<string, int> code => count
     */
    public function usage(UserKind $kind): array
    {
        $rows = $kind === UserKind::Customer
            ? $this->db->rows(
                'SELECT euph_profile_type AS code, COUNT(*) AS cnt FROM ?:users WHERE user_type = ?s GROUP BY euph_profile_type',
                UserKind::Customer->value,
            )
            : $this->db->rows('SELECT euph_profile_type AS code, COUNT(*) AS cnt FROM ?:companies GROUP BY euph_profile_type');
        $usage = [];
        foreach ($rows as $row) {
            $usage[Cast::string($row['code'] ?? '')] = Cast::int($row['cnt'] ?? 0);
        }

        return $usage;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<int, array<string, list<int>>> type id => object type => ids
     */
    private function links(): array
    {
        $links = [];
        $rows = $this->db->rows(
            'SELECT type_id, object_type, object_id FROM ?:euph_profile_type_links ORDER BY object_id ASC',
        );
        foreach ($rows as $row) {
            $objectType = Cast::string($row['object_type'] ?? '');
            if (LinkKind::tryFrom($objectType) === null) {
                continue;
            }
            $links[Cast::int($row['type_id'] ?? 0)][$objectType][] = Cast::int($row['object_id'] ?? 0);
        }

        return $links;
    }
}
