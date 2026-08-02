<?php

namespace anvildev\slots\tests\Unit\Helpers;

use anvildev\slots\tests\Support\TestCase;

class IcsLineFoldingTest extends TestCase
{
    public function testLineFoldingUsesMbStrcut(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/helpers/IcsHelper.php');
        $this->assertStringContainsString('mb_strcut', $source, 'IcsHelper must use mb_strcut for UTF-8 safe line folding');
        $this->assertStringNotContainsString('substr($line, 0, 75)', $source, 'IcsHelper must not use substr for line folding');
    }
}
