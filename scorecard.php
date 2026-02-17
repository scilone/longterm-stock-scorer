#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHP entrypoint equivalent to scorecard.py.
 * Delegates to the existing Python implementation to keep behavior identical.
 */
function runPythonScorecard(array $argv): int
{
    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'scorecard.py';
    if (!is_file($scriptPath)) {
        fwrite(STDERR, "Error: scorecard.py not found at {$scriptPath}" . PHP_EOL);
        return 1;
    }

    $pythonCandidates = [];
    $pythonFromEnv = getenv('PYTHON_BIN');
    if ($pythonFromEnv !== false && $pythonFromEnv !== '') {
        $pythonCandidates[] = $pythonFromEnv;
    }
    $pythonCandidates[] = 'python3';
    $pythonCandidates[] = 'python';

    foreach ($pythonCandidates as $pythonBin) {
        $cmd = array_merge([$pythonBin, $scriptPath], array_slice($argv, 1));
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));

        passthru($escaped, $status);
        if ($status === 127) {
            continue;
        }

        return $status;
    }

    fwrite(STDERR, "Error: no Python interpreter found. Set PYTHON_BIN or install python3." . PHP_EOL);
    return 127;
}

exit(runPythonScorecard($argv));
