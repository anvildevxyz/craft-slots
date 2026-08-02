<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

class SlotsServiceEditAsset extends AssetBundle
{
    public $depends = [SlotsCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/cp/service-schedules.js',
        ];
        parent::init();
    }
}
