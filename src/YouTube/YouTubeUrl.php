<?php

namespace App\YouTube;

final class YouTubeUrl
{
    public function extractId(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new \InvalidArgumentException('Saisissez une URL YouTube valide (https://…).');
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = null;
        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('~^/(?:shorts|embed|live)/([^/]+)/?$~D', $path, $matches)) {
                $id = $matches[1];
            }
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
            throw new \InvalidArgumentException('Cette URL ne contient pas un identifiant de vidéo YouTube valide.');
        }
        return $id;
    }
}
