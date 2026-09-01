<?php

/**
 * @file tools/SettingsHealthCheck/ProgressReporter.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ProgressReporter
 *
 * @brief Terminal progress bar and status line for long-running scan/fix passes.
 */

namespace APP\tools\settingsHealthCheck\src;

final class ProgressReporter
{
    private int $total;
    private int $current = 0;
    private bool $tty;
    private int $barWidth = 40;

    public function __construct(int $total)
    {
        $this->total = max(1, $total);
        $this->tty = function_exists('stream_isatty') && stream_isatty(STDERR);
    }

    public function step(string $table, string $scenario): void
    {
        $this->current++;
        $pct = (int) floor($this->current * 100 / $this->total);
        $filled = (int) floor($this->barWidth * $this->current / $this->total);
        $bar = str_repeat('=', $filled) . '>' . str_repeat(' ', max(0, $this->barWidth - $filled - 1));

        $status = sprintf(
            'Working on %s - %s now',
            $table,
            $scenario
        );
        $progress = sprintf('[%s] %3d%% (%d/%d)', $bar, $pct, $this->current, $this->total);
        $line = $status . "\n" . $progress;

        if ($this->tty) {
            fwrite(STDERR, "\033[2K\r" . $status . "\n\033[2K\r" . $progress);
        } else {
            fwrite(STDERR, $line . "\n");
        }
    }

    public function message(string $text): void
    {
        if ($this->tty) {
            fwrite(STDERR, "\033[2K\r");
        }
        fwrite(STDERR, ReportWriter::color($text, 'cyan') . "\n");
    }

    public function finish(string $label = 'Scan complete.'): void
    {
        if ($this->tty) {
            fwrite(STDERR, "\033[2K\r");
        }
        fwrite(STDERR, ReportWriter::color($label, 'green') . "\n");
    }
}
