<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

class SlotsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/utils/validation.js',
            'js/utils/date-time.js',
        ];
        $this->css = ['css/slots.css'];
        $this->jsOptions['position'] = \craft\web\View::POS_HEAD;
        parent::init();
    }
}
