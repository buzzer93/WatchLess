<?php

namespace App\Twig;

use League\CommonMark\CommonMarkConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class WatchLessExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('duration', $this->duration(...)), new TwigFilter('safe_markdown', $this->markdown(...), ['is_safe' => ['html']])];
    }

    public function duration(?int $seconds): string
    {
        if ($seconds === null) { return 'Durée inconnue'; }
        return $seconds >= 3600 ? sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)) : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }

    public function markdown(string $source): string
    {
        return (string) (new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]))->convert($source);
    }
}
