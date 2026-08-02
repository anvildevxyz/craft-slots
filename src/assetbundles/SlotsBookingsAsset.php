<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

class SlotsBookingsAsset extends AssetBundle
{
    public $depends = [SlotsCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/cp/bookings-index.js',
            'js/cp/booking-time.js',
        ];
        parent::init();
    }
}
