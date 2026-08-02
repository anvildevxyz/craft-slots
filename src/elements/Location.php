<?php

namespace anvildev\slots\elements;

use anvildev\slots\elements\db\LocationQuery;
use anvildev\slots\records\LocationRecord;
use anvildev\slots\traits\HasElementPermissions;
use anvildev\slots\traits\HasEnabledStatus;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\elements\User;

class Location extends Element
{
    use HasEnabledStatus;
    use HasElementPermissions;

    public ?string $timezone = null;
    public ?string $addressLine1 = null;
    public ?string $addressLine2 = null;
    public ?string $locality = null;
    public ?string $administrativeArea = null;
    public ?string $postalCode = null;
    public ?string $countryCode = null;

    public static function displayName(): string
    {
        return Craft::t('slots', 'element.location');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('slots', 'element.locations');
    }
    public static function refHandle(): ?string
    {
        return 'location';
    }
    public static function hasTitles(): bool
    {
        return true;
    }
    public function canDuplicate(User $user): bool
    {
        return true;
    }

    /** @return LocationQuery */
    public static function find(): LocationQuery
    {
        return new LocationQuery(static::class);
    }

    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Delete::class];
    }

    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => Craft::t('slots', 'element.allLocations'), 'defaultSort' => ['title', 'asc']]];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'timezone' => ['label' => Craft::t('slots', 'labels.timezone')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'timezone'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('app', 'Title'),
                'orderBy' => 'elements_sites.title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('slots', 'labels.timezone'),
                'orderBy' => 'slots_locations.timezone',
                'attribute' => 'timezone',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function permissionKey(): string
    {
        return 'slots-manageLocations';
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['timezone', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode'], 'string'],
            [['timezone'], 'string', 'max' => 50],
            [['postalCode'], 'string', 'max' => 20],
            [['countryCode'], 'string', 'max' => 2],
            [['addressLine1', 'addressLine2', 'locality', 'administrativeArea'], 'string', 'max' => 255],
        ]);
    }

    protected function cpEditUrl(): ?string
    {
        return 'slots/locations/' . $this->id;
    }

    public function getAddress(): string
    {
        return implode(', ', array_filter([
            $this->addressLine1, $this->addressLine2, $this->locality,
            $this->administrativeArea, $this->postalCode, $this->countryCode,
        ]));
    }

    public function afterSave(bool $isNew): void
    {
        $record = $isNew ? new LocationRecord() : (LocationRecord::findOne($this->id)
            ?? throw new \Exception('Invalid location ID: ' . $this->id));
        if ($isNew) {
            $record->id = (int)$this->id;
        }

        $record->timezone = $this->timezone;
        $record->addressLine1 = $this->addressLine1;
        $record->addressLine2 = $this->addressLine2;
        $record->locality = $this->locality;
        $record->administrativeArea = $this->administrativeArea;
        $record->postalCode = $this->postalCode;
        $record->countryCode = $this->countryCode;
        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        LocationRecord::findOne($this->id)?->delete();
        parent::afterDelete();
    }
}
