<?php

declare(strict_types=1);

namespace Bambamboole\AcornTesting\Testing;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

/**
 * Structured outcome of a `Lighthouse::run()` invocation. Wraps the
 * Illuminate ProcessResult plus the per-URL audits parsed from
 * `.unlighthouse/ci-result.json`.
 *
 * The shape mirrors ProcessResult intentionally — `successful()`,
 * `failed()`, `exitCode()`, `output()`, `errorOutput()`, `throw()` —
 * so the call site reads the same whether you care about the parsed
 * audits or just the pass/fail signal.
 */
final readonly class LighthouseReport
{
    /**
     * @param  list<UrlAudit>  $audits
     */
    public function __construct(
        private ProcessResult $process,
        public array $audits,
    ) {}

    public function successful(): bool
    {
        return $this->process->successful();
    }

    public function failed(): bool
    {
        return $this->process->failed();
    }

    public function exitCode(): int
    {
        return (int) $this->process->exitCode();
    }

    public function output(): string
    {
        return $this->process->output();
    }

    public function errorOutput(): string
    {
        return $this->process->errorOutput();
    }

    /**
     * Throw if the audit failed. Returns `$this` on success so call sites
     * can chain: `Lighthouse::for(...)->run()->throw()`.
     */
    public function throw(): self
    {
        if ($this->successful()) {
            return $this;
        }

        $detail = trim($this->errorOutput()) !== ''
            ? trim($this->errorOutput())
            : trim($this->output());

        throw new RuntimeException(sprintf(
            "Lighthouse audit failed (exit %d).\n%s",
            $this->exitCode(),
            $detail !== '' ? $detail : '(no output captured)',
        ));
    }

    /**
     * Find an audit by exact path match (e.g. '/', '/blog/').
     */
    public function audit(string $path): ?UrlAudit
    {
        foreach ($this->audits as $audit) {
            if ($audit->path === $path) {
                return $audit;
            }
        }

        return null;
    }

    /**
     * @param  string  $category  One of: 'score', 'performance', 'accessibility',
     *                            'bestPractices', 'seo'.
     * @return list<UrlAudit>     Audits with that category score < $floor (0.0-1.0).
     */
    public function below(string $category, float $floor): array
    {
        $valid = ['score', 'performance', 'accessibility', 'bestPractices', 'seo'];

        if (! in_array($category, $valid, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown category "%s". Expected one of: %s.',
                $category,
                implode(', ', $valid),
            ));
        }

        return array_values(array_filter(
            $this->audits,
            static fn (UrlAudit $a): bool => $a->{$category} < $floor,
        ));
    }

    public function processResult(): ProcessResult
    {
        return $this->process;
    }
}
