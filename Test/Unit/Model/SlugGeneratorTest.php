<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Test\Unit\Model;

use ETechFlow\SeoLayeredNav\Model\SlugGenerator;
use PHPUnit\Framework\TestCase;

class SlugGeneratorTest extends TestCase
{
    private SlugGenerator $slugger;

    protected function setUp(): void
    {
        $this->slugger = new SlugGenerator();
    }

    /**
     * @dataProvider labels
     */
    public function testSlugify(string $label, string $expected): void
    {
        $this->assertSame($expected, $this->slugger->slugify($label));
    }

    public static function labels(): array
    {
        return [
            'simple'            => ['Yale', 'yale'],
            'spaces'            => ['ABG Locks', 'abg-locks'],
            'trailing plus'     => ['Premier 2000+ ', 'premier-2000'],
            'collapse spaces'   => ['  multiple   spaces  ', 'multiple-spaces'],
            'symbols become -'  => ['a/b & c', 'a-b-c'],
            'leading/trailing'  => ['---Yale---', 'yale'],
            'symbols only'      => ['+++', ''],
            'empty'             => ['', ''],
            'mixed case digits' => ['HU100 Blade', 'hu100-blade'],
        ];
    }

    public function testDeterministic(): void
    {
        $this->assertSame($this->slugger->slugify('Auto Remote Man'), $this->slugger->slugify('Auto Remote Man'));
    }
}
