<?php

namespace App\Transcription;

interface TranscriptionProviderInterface
{
    public function transcribe(string $audioPath): TranscriptionResult;
}
