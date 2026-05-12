<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

/**
 * Per-URL Lighthouse scores, parsed from `.unlighthouse/ci-result.json`.
 *
 * Scores are in the 0.0–1.0 range — same as Lighthouse itself. The
 * `score` property is Unlighthouse's average across the four
 * categories (Performance, Accessibility, Best Practices, SEO).
 */
final readonly class UrlAudit
{
    public function __construct(
        public string $path,
        public float $score,
        public float $performance,
        public float $accessibility,
        public float $bestPractices,
        public float $seo,
    ) {}

    /**
     * @param  array<string, mixed>  $data  An entry from `ci-result.json`,
     *                                      e.g. `{"path":"/", "score":0.86,
     *                                      "performance":0.97, ...}`.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string) ($data['path'] ?? ''),
            score: (float) ($data['score'] ?? 0.0),
            performance: (float) ($data['performance'] ?? 0.0),
            accessibility: (float) ($data['accessibility'] ?? 0.0),
            bestPractices: (float) ($data['best-practices'] ?? 0.0),
            seo: (float) ($data['seo'] ?? 0.0),
        );
    }
}
