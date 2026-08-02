<?php

namespace anvildev\slots\fields;

use anvildev\slots\elements\Service;
use Craft;
use craft\fields\BaseRelationField;

class SlotsServices extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('slots', 'field.bookedServices');
    }

    public static function elementType(): string
    {
        return Service::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('slots', 'field.addService');
    }
}
