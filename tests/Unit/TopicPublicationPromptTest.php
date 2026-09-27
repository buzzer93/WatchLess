<?php

namespace App\Tests\Unit;

use App\Prompt\TopicPublicationPrompt;
use PHPUnit\Framework\TestCase;

final class TopicPublicationPromptTest extends TestCase
{
    public function testPublicationPromptRequestsCopyReadyTextWithoutInternalMarkers(): void
    {
        $prompt = (new TopicPublicationPrompt())->generate('social', 'Sujet', '', 'Fait [V1].');

        self::assertStringContainsString('aucune référence interne de type [Vn]', $prompt);
        self::assertStringContainsString('Fait [V1].', $prompt);
    }

    public function testEachFormatHasItsOwnEditorialIntent(): void
    {
        $prompt = new TopicPublicationPrompt();

        $socialPrompt = $prompt->generate('social', 'Sujet', '', 'Rapport');
        self::assertStringContainsString('vraie narration', $socialPrompt);
        self::assertStringContainsString('180 à 300 mots', $socialPrompt);
        self::assertStringContainsString('ne pose pas de question', $socialPrompt);
        self::assertStringContainsString('orientée actualité', $prompt->generate('newsletter', 'Sujet', '', 'Rapport'));
        self::assertStringContainsString('bon angle selon le contenu', $prompt->generate('blog', 'Sujet', '', 'Rapport'));
        self::assertStringContainsString('uniquement un résumé synthétique', $prompt->generate('short', 'Sujet', '', 'Rapport'));
    }
}
