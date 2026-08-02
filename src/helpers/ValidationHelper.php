<?php

namespace anvildev\slots\helpers;

class ValidationHelper
{
    public const TIME_FORMAT_PATTERN = '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/';
    public const DATE_VALIDATOR = 'date';
    public const DATE_FORMAT = 'php:Y-m-d';
}
