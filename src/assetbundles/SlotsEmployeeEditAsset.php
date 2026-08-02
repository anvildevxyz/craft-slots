<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

class SlotsEmployeeEditAsset extends AssetBundle
{
    public $depends = [SlotsCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/cp/user-selector-disabled.js',
        ];
        parent::init();
    }
}
