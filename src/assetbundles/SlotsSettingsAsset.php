<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

class SlotsSettingsAsset extends AssetBundle
{
    public $depends = [SlotsCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/cp/booking-settings.js',
            'js/cp/security-settings.js',
        ];
        parent::init();
    }
}
