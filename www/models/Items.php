<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * An entry in the editable list, mapped to the original 2017 `items` table.
 *
 * The class name stays `Items` (rather than a more natural `Item`) on purpose: renaming it
 * would be a gratuitous change with no product value, and the constitution asks for
 * incremental modernisation rather than redesign.
 *
 * @property int         $id
 * @property string|null $name
 */
class Items extends ActiveRecord
{
    /**
     * Maximum name length in CHARACTERS, matching `varchar(255)` in PostgreSQL (which also
     * counts characters, not bytes) and `maxLength: 255` in contracts/openapi.yaml.
     */
    public const NAME_MAX_LENGTH = 255;

    public static function tableName(): string
    {
        return 'items';
    }

    /**
     * FR-003: trim, then require 1-255 Unicode characters.
     *
     * Order matters. `trim` runs first so that " Milk " is stored as "Milk" and so that a
     * whitespace-only name is rejected rather than persisted as a blank-looking row -- the
     * 2017 rules (`required` + `string(max)`) stored the raw value untouched.
     *
     * `string` uses mb_strlen, so a 255-character 2-byte name (510 bytes) is accepted; the
     * legacy fixture contains exactly such a row.
     *
     * @return array<int, array<mixed>>
     */
    public function rules(): array
    {
        return [
            [['name'], 'trim'],
            [['name'], 'required'],
            [['name'], 'string', 'min' => 1, 'max' => self::NAME_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
        ];
    }

    /**
     * The exact `Item` schema from contracts/openapi.yaml: an integer id and a string
     * name, and nothing else. Building it explicitly (rather than returning `$this->
     * attributes`) means a future column cannot silently leak into the public API.
     *
     * @return array{id: int, name: string}
     */
    public function toApiRepresentation(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
        ];
    }

    /**
     * Validation errors in the shape of the OpenAPI `Error.details` field:
     * a field name mapped to a list of messages.
     *
     * @return array<string, list<string>>
     */
    public function validationDetails(): array
    {
        $details = [];

        foreach ($this->getErrors() as $attribute => $messages) {
            $details[$attribute] = array_values($messages);
        }

        return $details;
    }
}
