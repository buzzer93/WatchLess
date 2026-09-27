<?php

namespace App\Tests\Unit;

use App\YouTube\YouTubeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeUrlTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function testExtraction(string $url): void
    {
        self::assertSame('dQw4w9WgXcQ', (new YouTubeUrl())->extractId($url));
    }
    public static function validUrls(): iterable
    {
        yield ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&utm_source=test&list=123'];
        yield ['https://youtu.be/dQw4w9WgXcQ?t=23'];
        yield ['https://youtube.com/shorts/dQw4w9WgXcQ'];
        yield ['https://m.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield [' https://youtube.com/embed/dQw4w9WgXcQ '];
        yield ['https://www.youtube.com/live/dQw4w9WgXcQ'];
    }
    #[DataProvider('invalidUrls')]
    public function testInvalidUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YouTubeUrl())->extractId($url);
    }
    public static function invalidUrls(): iterable
    {
        foreach (['', 'file:///etc/passwd', 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
            'https://youtube.com@evil.test/watch?v=dQw4w9WgXcQ', 'https://youtu.be/short',
            'https://youtube.com/watch?v[]=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ/extra',
            'https://youtube.com:443/watch?v=dQw4w9WgXcQ', 'https://youtube.com/playlist?list=test',
            'https://127.0.0.1/watch?v=dQw4w9WgXcQ'] as $url) { yield [$url]; }
    }
}
