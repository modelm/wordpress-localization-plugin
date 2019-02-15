<?php

namespace Smartling\Tests\Smartling\Helpers;

use PHPUnit\Framework\TestCase;
use Smartling\Helpers\EventParameters\TranslationStringFilterParameters;
use Smartling\Helpers\FieldsFilterHelper;
use Smartling\Helpers\GutenbergBlockHelper;
use Smartling\Tests\Traits\DbAlMock;
use Smartling\Tests\Traits\EntityHelperMock;
use Smartling\Tests\Traits\InvokeMethodTrait;
use Smartling\Tests\Traits\SettingsManagerMock;
use Smartling\Tests\Traits\SiteHelperMock;
use Smartling\Tests\Traits\SubmissionManagerMock;

/**
 * Class GutenbergBlockHelperTest
 *
 * @package Smartling\Tests\Smartling\Helpers
 * @covers  \Smartling\Helpers\GutenbergBlockHelper
 */
class GutenbergBlockHelperTest extends TestCase
{
    use InvokeMethodTrait;
    use SubmissionManagerMock;
    use DbAlMock;
    use EntityHelperMock;
    use SiteHelperMock;
    use SettingsManagerMock;

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|GutenbergBlockHelper
     */
    private function mockHelper()
    {
        return $this->getMockBuilder('\Smartling\Helpers\GutenbergBlockHelper')
                    ->setMethods(['postReceiveFiltering', 'preSendFiltering', 'processAttributes'])
                    ->getMock();
    }

    /**
     * @var GutenbergBlockHelper|null
     */
    public $helper = null;

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        $this->helper = new GutenbergBlockHelper();
    }

    /**
     * @covers \Smartling\Helpers\GutenbergBlockHelper::registerFilters
     */
    public function testRegisterFilters()
    {
        $result = $this->helper->registerFilters([]);
        $expected = [
            ['pattern' => 'type', 'action' => 'copy'],
            ['pattern' => 'providerNameSlug', 'action' => 'copy'],
            ['pattern' => 'align', 'action' => 'copy'],
            ['pattern' => 'className', 'action' => 'copy'],
        ];
        self::assertEquals($expected, $result);
    }

    /**
     * @covers                   \Smartling\Helpers\GutenbergBlockHelper::register
     * @expectedException        \Smartling\Exception\SmartlingConfigException
     * @expectedExceptionMessage Execution requires declared ABSPATH const.
     */
    public function testRegister()
    {
        $this->helper->register();
    }

    /**
     * @param $blockName
     * @param $flatAttributes
     * @param $postFilterMock
     * @param $preFilterMock
     * @dataProvider processAttributesDataProvider
     * @covers       \Smartling\Helpers\GutenbergBlockHelper::processAttributes
     */
    public function testProcessAttributes($blockName, $flatAttributes, $postFilterMock, $preFilterMock)
    {
        $helper = $this->mockHelper();
        $helper
            ->expects((!empty($flatAttributes) ? self::once() : self::never()))
            ->method('postReceiveFiltering')
            ->with($flatAttributes)
            ->willReturn($postFilterMock);

        $helper
            ->expects((!empty($flatAttributes) ? self::once() : self::never()))
            ->method('preSendFiltering')
            ->with($flatAttributes)
            ->willReturn($preFilterMock);

        $result = $helper->processAttributes($blockName, $flatAttributes);

        self::assertEquals($preFilterMock, $result);

    }

    /**
     * @return array
     */
    public function processAttributesDataProvider()
    {
        return [
            'empty' => [
                'block',
                [],
                [],
                [],
            ],
            'simple' => [
                'block',
                [
                    'a/0' => 'first',
                    'a/1' => 'second',
                    'a/2/0' => '5',
                ],
                [
                    'a/0' => 'first',
                    'a/1' => 'second',
                    'a/2/0' => '6',
                ],
                [
                    'a/0' => 'first',
                    'a/1' => 'second',
                ],
            ],
        ];
    }

    /**
     * @covers       \Smartling\Helpers\GutenbergBlockHelper::hasBlocks
     * @dataProvider hasBlocksDataProvider
     * @param string $sample
     * @param bool   $expectedResult
     * @throws \ReflectionException
     */
    public function testHasBlocks($sample, $expectedResult)
    {
        $result = $this->invokeMethod($this->helper, 'hasBlocks', [$sample]);
        self::assertEquals($expectedResult, $result);
    }

    /**
     * @return array
     */
    public function hasBlocksDataProvider()
    {
        return [
            'simple text' => ['lorem ipsum dolor', false],
            'block with 1 space' => ['lorem <!-- wp:ipsum dolor', true],
            'block with several spaces' => ['lorem <!--  wp:ipsum dolor', true],
        ];
    }

    /**
     * @throws \ReflectionException
     * @covers \Smartling\Helpers\GutenbergBlockHelper::packData
     */
    public function testPackData()
    {
        $sample = ['foo' => 'bar'];
        $expected = base64_encode(serialize($sample));
        $result = $this->invokeMethod($this->helper, 'packData', [$sample]);
        self::assertEquals($expected, $result);
    }

    /**
     * @throws \ReflectionException
     * @covers \Smartling\Helpers\GutenbergBlockHelper::unpackData
     */
    public function testUnpackData()
    {
        $sample = ['foo' => 'bar'];
        $source = base64_encode(serialize($sample));
        $result = $this->invokeMethod($this->helper, 'unpackData', [$source]);
        self::assertEquals($sample, $result);
    }

    /**
     * @throws \ReflectionException
     */
    public function testPackUnpack()
    {
        $sample = ['foo' => 'bar'];
        $processed = $this->invokeMethod(
            $this->helper,
            'unpackData',
            [
                $this->invokeMethod(
                    $this->helper,
                    'packData',
                    [
                        $sample,
                    ]
                ),
            ]
        );
        self::assertEquals($processed, $sample);
    }

    /**
     * @param array  $block
     * @param string $expected
     * @throws \ReflectionException
     * @dataProvider placeBlockDataProvider
     * @covers       \Smartling\Helpers\GutenbergBlockHelper::placeBlock
     */
    public function testPlaceBlock($block, $expected)
    {
        $helper = $this->mockHelper();
        $params = new TranslationStringFilterParameters();
        $params->setDom(new \DOMDocument('1.0', 'utf8'));

        $helper->setParams($params);
        $helper->setFieldsFilter(new FieldsFilterHelper($this->getSettingsManagerMock()));
        $helper->expects(self::any())
               ->method('processAttributes')
               ->willReturnCallback(function ($blockName, $attributes) {
                   return $attributes;
               });

        $result = $this->invokeMethod($helper, 'placeBlock', [$block]);
        $xmlNodeRendered = $params->getDom()->saveXML($result);
        self::assertEquals($expected, $xmlNodeRendered);
    }

    /**
     * @return array
     */
    public function placeBlockDataProvider()
    {
        return [
            'no nested' => [
                [
                    'blockName' => 'test',
                    'attrs' => [
                        'foo' => 'bar',
                    ],
                    'innerContent' => [
                        'chunk a',
                        'chunk b',
                        'chunk c',
                    ],
                ],
                '<gutenbergBlock blockName="test" originalAttributes="YToxOntzOjM6ImZvbyI7czozOiJiYXIiO30="><![CDATA[]]><contentChunk><![CDATA[chunk a]]></contentChunk><contentChunk><![CDATA[chunk b]]></contentChunk><contentChunk><![CDATA[chunk c]]></contentChunk><blockAttribute name="foo"><![CDATA[bar]]></blockAttribute></gutenbergBlock>',
            ],
            'nested block' => [
                [
                    'blockName' => 'test',
                    'attrs' => [
                        'foo' => 'bar',
                    ],
                    'innerBlocks' => [
                        [
                            'blockName' => 'test1',
                            'attrs' => [
                                'bar' => 'foo',
                            ],
                            'innerContent' => [
                                'chunk d',
                                'chunk e',
                                'chunk f',
                            ],
                        ],
                    ],
                    'innerContent' => [
                        'chunk a',
                        null,
                        'chunk c',
                    ],
                ],
                '<gutenbergBlock blockName="test" originalAttributes="YToxOntzOjM6ImZvbyI7czozOiJiYXIiO30="><![CDATA[]]><contentChunk><![CDATA[chunk a]]></contentChunk><gutenbergBlock blockName="test1" originalAttributes="YToxOntzOjM6ImJhciI7czozOiJmb28iO30="><![CDATA[]]><contentChunk><![CDATA[chunk d]]></contentChunk><contentChunk><![CDATA[chunk e]]></contentChunk><contentChunk><![CDATA[chunk f]]></contentChunk><blockAttribute name="bar"><![CDATA[foo]]></blockAttribute></gutenbergBlock><contentChunk><![CDATA[chunk c]]></contentChunk><blockAttribute name="foo"><![CDATA[bar]]></blockAttribute></gutenbergBlock>',
            ],
        ];
    }

    /**
     * @param string $blockName
     * @param array  $attributes
     * @param array  $chunks
     * @param string $expected
     * @throws \ReflectionException
     * @dataProvider \Smartling\Helpers\GutenbergBlockHelper::renderGutenbergBlock
     */
    public function testRenderGutenbergBlock($blockName, array $attributes, array $chunks, $expected)
    {
        $result = $this->invokeMethod($this->helper, 'renderGutenbergBlock', [$blockName, $attributes, $chunks]);
        self::assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function renderGutenbergBlockDataProvider()
    {
        return [
            'inline' => [
                'inline',
                [
                    'a' => 'b',
                    'c' => 'd',
                ],
                [],
                '<!-- wp:inline {"a":"b","c":"d"} /-->',
            ],
            'block' => [
                'block',
                [
                    'a' => 'b',
                    'c' => 'd',
                ],
                [
                    'some',
                    ' ',
                    'chunks',

                ],
                '<!-- wp:block {"a":"b","c":"d"} -->some chunks<!-- /wp:block -->',
            ],

        ];
    }

    /**
     * @covers       \Smartling\Helpers\GutenbergBlockHelper::processTranslationAttributes
     * @dataProvider processTranslationAttributesDataSource
     * @param string $blockName
     * @param array  $originalAttributes
     * @param array  $translatedAttributes
     * @param array  $expected
     * @throws \ReflectionException
     */
    public function testProcessTranslationAttributes($blockName, $originalAttributes, $translatedAttributes, $expected)
    {
        $helper = $this->mockHelper();

        $helper->setFieldsFilter(new FieldsFilterHelper($this->getSettingsManagerMock()));

        $helper->expects(self::any())
               ->method('postReceiveFiltering')
               ->willReturnCallback(function ($attrs) {
                   return $attrs;
               });


        $result = $this->invokeMethod(
            $helper,
            'processTranslationAttributes',
            [
                $blockName,
                $originalAttributes,
                $translatedAttributes,
            ]
        );

        self::assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function processTranslationAttributesDataSource()
    {
        return [
            'structured attributes' => [
                'block',
                ['data' => ['texts' => ['foo', 'bar']]],
                [
                    'data/texts/0' => 'foo1',
                    'data/texts/1' => 'bar1',
                ],
                ['data' => ['texts' => ['foo1', 'bar1']]],
            ],
        ];
    }

    /**
     * @covers \Smartling\Helpers\GutenbergBlockHelper::renderTranslatedBlockNode
     */
    public function testRenderTranslatedBlockNode()
    {
        $xmlPart = '<gutenbergBlock blockName="core/foo" originalAttributes="YToxOntzOjQ6ImRhdGEiO2E6Mzp7czo2OiJ0ZXh0X2EiO3M6NzoiVGl0bGUgMSI7czo2OiJ0ZXh0X2IiO3M6NzoiVGl0bGUgMiI7czo1OiJ0ZXh0cyI7YToyOntpOjA7czo1OiJsb3JlbSI7aToxO3M6NToiaXBzdW0iO319fQ==">
			<![CDATA[]]>
			<contentChunk hash="d3d67cc32ac556aae106e606357f449e">
				<![CDATA[
<p>Inner HTML</p>
]]>
			</contentChunk>
			<blockAttribute name="data/text_a" hash="90bc6d3874182275bd4cd88cbd734fe9">
				<![CDATA[Title 1]]>
			</blockAttribute>
			<blockAttribute name="data/text_b" hash="e4bb56dda4ecb60c34ccb89fd50506df">
				<![CDATA[Title 2]]>
			</blockAttribute>
			<blockAttribute name="data/texts/0" hash="d2e16e6ef52a45b7468f1da56bba1953">
				<![CDATA[lorem]]>
			</blockAttribute>
			<blockAttribute name="data/texts/1" hash="e78f5438b48b39bcbdea61b73679449d">
				<![CDATA[ipsum]]>
			</blockAttribute>
		</gutenbergBlock>';

        $expectedBlock = '<!-- wp:core/foo {"data":{"text_a":"Title 1","text_b":"Title 2","texts":["lorem","ipsum"]}} /-->';

        $dom = new \DOMDocument('1.0', 'utf8');
        $dom->loadXML($xmlPart);
        $xpath = new \DOMXPath($dom);

        $list = $xpath->query('/gutenbergBlock');
        $node = $list->item(0);
        $helper = $this->mockHelper();

        $helper->setFieldsFilter(new FieldsFilterHelper($this->getSettingsManagerMock()));
        $helper->expects(self::any())
               ->method('postReceiveFiltering')
               ->willReturnCallback(function ($attrs) {
                   return $attrs;
               });


        $result = $helper->renderTranslatedBlockNode($node);
        self::assertEquals($expectedBlock, $result);
    }
}