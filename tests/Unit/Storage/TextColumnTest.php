<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\Storage;

use LaravelSqlMonitor\Storage\TextColumn;
use PHPUnit\Framework\TestCase;

class TextColumnTest extends TestCase
{
    public function testShortValueIsKeptAsIs(): void
    {
        $this->assertSame('["a",1]', TextColumn::fit('["a",1]', TextColumn::MAX_BYTES));
    }

    public function testNullStaysNull(): void
    {
        $this->assertNull(TextColumn::fit(null, TextColumn::MAX_BYTES));
    }

    public function testValueAtLimitIsKeptAsIs(): void
    {
        $value = str_repeat('x', TextColumn::MAX_BYTES);

        $this->assertSame($value, TextColumn::fit($value, TextColumn::MAX_BYTES));
    }

    public function testOversizedValueFitsTextColumnWithMarker(): void
    {
        $value = str_repeat('x', 104650);

        $fitted = TextColumn::fit($value, TextColumn::MAX_BYTES);

        $this->assertLessThanOrEqual(TextColumn::MAX_BYTES, strlen($fitted));
        $this->assertStringStartsWith('xxxx', $fitted);
        $this->assertStringEndsWith('[sql-monitor truncated: 104650 bytes]', $fitted);
    }

    public function testMultibyteValueIsCutOnCharacterBoundary(): void
    {
        $value = str_repeat('菲', 30000);

        $fitted = TextColumn::fit($value, TextColumn::MAX_BYTES);

        $this->assertLessThanOrEqual(TextColumn::MAX_BYTES, strlen($fitted));
        $this->assertTrue(mb_check_encoding($fitted, 'UTF-8'));
    }

    public function testUnlimitedColumnKeepsOversizedValue(): void
    {
        $value = str_repeat('x', 104650);

        $this->assertSame($value, TextColumn::fit($value, null));
    }
}
