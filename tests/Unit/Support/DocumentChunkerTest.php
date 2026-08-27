<?php

namespace Tests\Unit\Support;

use App\Support\Knowledge\DocumentChunker;
use Tests\TestCase;

/**
 * Phase 10 STEP 12: heading-aware chunking, with a fixed-size paragraph
 * fallback for content that has no Markdown headings at all.
 */
class DocumentChunkerTest extends TestCase
{
    public function test_it_splits_on_markdown_headings(): void
    {
        $content = "# Refund Policy\n\nRefunds are issued within 14 days.\n\n## Exceptions\n\nCustom orders are non-refundable.";

        $chunks = (new DocumentChunker)->chunk($content);

        $this->assertCount(2, $chunks);
        $this->assertSame('Refund Policy', $chunks[0]['heading']);
        $this->assertStringContainsString('14 days', $chunks[0]['content']);
        $this->assertSame('Exceptions', $chunks[1]['heading']);
        $this->assertStringContainsString('non-refundable', $chunks[1]['content']);
    }

    public function test_content_before_the_first_heading_is_kept_as_its_own_chunk(): void
    {
        $content = "Intro paragraph with no heading yet.\n\n# Section One\n\nBody text.";

        $chunks = (new DocumentChunker)->chunk($content);

        $this->assertNull($chunks[0]['heading']);
        $this->assertStringContainsString('Intro paragraph', $chunks[0]['content']);
        $this->assertSame('Section One', $chunks[1]['heading']);
    }

    public function test_content_with_no_headings_falls_back_to_paragraph_grouping(): void
    {
        $content = implode("\n\n", array_fill(0, 5, str_repeat('word ', 100)));

        $chunks = (new DocumentChunker)->chunk($content);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertNull($chunk['heading']);
            $this->assertLessThanOrEqual(1600, mb_strlen($chunk['content']));
        }
    }

    public function test_empty_content_produces_no_chunks(): void
    {
        $this->assertSame([], (new DocumentChunker)->chunk('   '));
    }

    public function test_a_short_single_paragraph_with_no_heading_produces_one_chunk(): void
    {
        $chunks = (new DocumentChunker)->chunk('Just one short paragraph.');

        $this->assertCount(1, $chunks);
        $this->assertSame('Just one short paragraph.', $chunks[0]['content']);
    }
}
