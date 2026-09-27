<?php

namespace App\Service;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CommandRunner
{
    /** Arguments are always passed as an array, never to a shell. */
    public function run(array $arguments, float $timeout): string
    {
        $process = new Process($arguments, timeout: $timeout);
        try { $process->run(); }
        catch (ProcessTimedOutException) { throw new \RuntimeException('Le délai du traitement externe a été dépassé.'); }
        if (!$process->isSuccessful()) {
            // Do not expose complete subprocess output: it may contain signed URLs or cookies.
            $error = strtolower($process->getErrorOutput());
            $reason = match (true) {
                str_contains($error, 'private video') => 'La vidéo est privée.',
                str_contains($error, 'removed'), str_contains($error, 'not available'), str_contains($error, 'unavailable') => 'La vidéo est supprimée ou inaccessible.',
                str_contains($error, 'sign in'), str_contains($error, 'bot') => 'YouTube demande une authentification ou bloque cet accès.',
                str_contains($error, '429') => 'YouTube limite temporairement les requêtes (429).',
                default => 'Vérifiez l’exécutable, sa configuration et l’accès à la vidéo.',
            };
            throw new \RuntimeException(sprintf('Échec de %s (code %s). %s', basename($arguments[0]), $process->getExitCode(), $reason));
        }
        return $process->getOutput();
    }
}
