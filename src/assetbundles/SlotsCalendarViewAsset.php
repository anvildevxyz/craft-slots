<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

class SlotsCalendarViewAsset extends AssetBundle
{
    public $depends = [SlotsCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/cp/calendar-filters.js',
        ];
        parent::init();
    }
}
