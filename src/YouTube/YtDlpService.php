<?php

namespace App\YouTube;

use App\Service\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class YtDlpService
{
    public function __construct(private CommandRunner $runner, private YouTubeUrl $urls,
        #[Autowire('%env(YTDLP_BINARY)%')] private string $binary,
        #[Autowire('%env(float:YTDLP_TIMEOUT)%')] private float $timeout,
        #[Autowire('%env(YTDLP_COOKIES_FILE)%')] private string $cookiesFile = '',
        #[Autowire('%env(YTDLP_JS_RUNTIME)%')] private string $jsRuntime = 'node') {}

    public function metadata(string $url): array
    {
        $data = json_decode($this->run(['--dump-single-json', '--skip-download', '--no-write-subs', '--no-write-auto-subs'], $url), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['id'] ?? '') !== $this->urls->extractId($url)) {
            throw new \RuntimeException('Métadonnées YouTube invalides.');
        }
        if (($data['is_live'] ?? false) || ($data['live_status'] ?? '') === 'is_upcoming') {
            throw new \RuntimeException('Les directs en cours ou à venir ne sont pas pris en charge.');
        }
        return $data;
    }

    public function subtitles(string $url, string $language, bool $automatic, string $directory): ?string
    {
        $this->run(['--skip-download', $automatic ? '--write-auto-subs' : '--write-subs',
            $automatic ? '--no-write-subs' : '--no-write-auto-subs', '--sub-langs', '^'.preg_quote($language, '/').'$',
            '--sub-format', 'json3', '--output', $directory.'/captions.%(ext)s'], $url);
        $files = glob($directory.'/*.json3') ?: [];
        return $files === [] ? null : file_get_contents($files[0]);
    }

    public function audio(string $url, string $directory): string
    {
        $this->run(['-f', 'bestaudio/best', '--extract-audio', '--audio-format', 'mp3', '--audio-quality', '64K',
            '--output', $directory.'/audio.%(ext)s'], $url);
        $path = $directory.'/audio.mp3';
        if (!is_file($path) || filesize($path) === 0) { throw new \RuntimeException('Le téléchargement audio n’a produit aucun fichier.'); }
        return $path;
    }

    private function run(array $arguments, string $url): string
    {
        $canonical = 'https://www.youtube.com/watch?v='.$this->urls->extractId($url);
        $base = [$this->binary, '--ignore-config', '--no-playlist', '--no-progress', '--no-warnings', '--socket-timeout', '30', '--retries', '2'];
        if ($this->jsRuntime !== '') { array_push($base, '--js-runtimes', $this->jsRuntime); }
        if ($this->cookiesFile !== '') { array_push($base, '--cookies', $this->cookiesFile); }
        return $this->runner->run([...$base, ...$arguments, '--', $canonical], $this->timeout);
    }
}
