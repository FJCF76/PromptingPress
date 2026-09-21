<?php
namespace PP\Tests;
use PHPUnit\Framework\TestCase;
class MeasureTest extends TestCase {
    public function testMeasure(): void {
        $GLOBALS['_pp_test_store'] = ['post_meta'=>[], 'posts'=>[], 'options'=>[], 'next_id'=>100];
        fwrite(STDERR, "\nBYTES=" . strlen(\pp_ai_system_prompt()) . "\n");
        $this->assertTrue(true);
    }
}
