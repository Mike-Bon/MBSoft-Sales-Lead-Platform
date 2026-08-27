<?php

namespace App\Support\Knowledge;

/**
 * Phase 10 STEP 12: splits a document's raw Markdown/text into
 * section-sized, context-preserving chunks — heading-aware where
 * headings exist, falling back to fixed-size paragraph grouping
 * otherwise. Deliberately simple (no NLP/ML dependency): this is a
 * plain-text splitter, matching CLAUDE.md's "prefer Laravel's native
 * facilities... add a package only when it has a concrete, approved
 * purpose."
 */
final class DocumentChunker
{
    /**
     * Fixed-size fallback target, in characters, when the content has
     * no Markdown headings to split on. Small enough to keep a single
     * chunk's content bounded for cost control (STEP 45), large enough
     * to preserve a paragraph's context.
     */
    private const FALLBACK_CHUNK_SIZE = 800;

    /**
     * @return list<array{heading: ?string, content: string}>
     */
    public function chunk(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $headingChunks = $this->splitByHeadings($content);

        if ($headingChunks !== null) {
            return $headingChunks;
        }

        return $this->splitByParagraphGroups($content);
    }

    /**
     * @return ?list<array{heading: ?string, content: string}>
     */
    private function splitByHeadings(string $content): ?array
    {
        $lines = preg_split('/\R/', $content);
        $sections = [];
        $currentHeading = null;
        $currentLines = [];
        $sawHeading = false;

        foreach ($lines as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $matches) === 1) {
                $sawHeading = true;

                if ($currentLines !== [] || $currentHeading !== null) {
                    $sections[] = ['heading' => $currentHeading, 'content' => trim(implode("\n", $currentLines))];
                }

                $currentHeading = trim($matches[1]);
                $currentLines = [];

                continue;
            }

            $currentLines[] = $line;
        }

        if (! $sawHeading) {
            return null;
        }

        $sections[] = ['heading' => $currentHeading, 'content' => trim(implode("\n", $currentLines))];

        return array_values(array_filter($sections, fn (array $section) => $section['content'] !== '' || $section['heading'] !== null));
    }

    /**
     * @return list<array{heading: ?string, content: string}>
     */
    private function splitByParagraphGroups(string $content): array
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $content))));

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $buffer === '' ? $paragraph : $buffer."\n\n".$paragraph;

            if (mb_strlen($candidate) > self::FALLBACK_CHUNK_SIZE && $buffer !== '') {
                $chunks[] = ['heading' => null, 'content' => $buffer];
                $buffer = $paragraph;

                continue;
            }

            $buffer = $candidate;
        }

        if ($buffer !== '') {
            $chunks[] = ['heading' => null, 'content' => $buffer];
        }

        return $chunks;
    }
}
