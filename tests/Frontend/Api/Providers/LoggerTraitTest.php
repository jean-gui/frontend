<?php
declare(strict_types=1);

namespace App\Tests\Frontend\Api\Providers;

use PHPUnit\Framework\TestCase;
use Studio24\Frontend\Api\Provider\WordpressApiProvider;

class LoggerTraitTest extends TestCase
{
    public function testFormatArray()
    {
        $api = new WordpressApiProvider('');

        $data = [
            'one' => 100,
            'two' => 200,
            'http_errors' => false
        ];
        $this->assertEquals('one=100, two=200, http_errors=0', $api->formatArray($data));
    }
}
