<?php

namespace App\Enum;

enum VideoStatus: string
{
    case Pending = 'pending';
    case Metadata = 'metadata';
    case Transcribing = 'transcribing';
    case Transcribed = 'transcribed';
    case Summarizing = 'summarizing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente du traitement',
            self::Metadata => 'Récupération des informations…',
            self::Transcribing => 'Transcription en cours…',
            self::Transcribed => 'Transcription enregistrée',
            self::Summarizing => 'Génération du compte rendu…',
            self::Completed => 'Analyse terminée',
            self::Failed => 'Analyse interrompue',
        };
    }

    public function terminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
