<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SlotsCpAsset extends AssetBundle
{
    public $depends = [CpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/utils/date-time.js',
            'js/cp/lightswitch-toggle.js',
        ];
        $this->css = ['css/cp/slots-cp.css'];
        parent::init();
    }
}
