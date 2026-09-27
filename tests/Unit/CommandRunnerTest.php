<?php

namespace App\Tests\Unit;

use App\Service\CommandRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandRunnerTest extends TestCase
{
    public static function failures(): array
    {
        return [
            ['Private video', 'privée'],
            ['Video has been removed', 'supprimée ou inaccessible'],
            ['Video unavailable', 'supprimée ou inaccessible'],
            ['Sign in to confirm you are not a bot', 'authentification'],
            ['HTTP Error 429', 'limite temporairement'],
            ['Download failed', 'configuration'],
        ];
    }

    #[DataProvider('failures')]
    public function testExternalFailureIsActionableWithoutLeakingStderr(string $stderr, string $expected): void
    {
        $script = 'fwrite(STDERR, '.var_export($stderr.' secret-cookie=do-not-expose', true).'); exit(1);';
        try {
            (new CommandRunner())->run([PHP_BINARY, '-r', $script], 10);
            self::fail('Expected subprocess failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertStringNotContainsString('do-not-expose', $exception->getMessage());
        }
    }

    public function testTimeoutIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('délai');
        (new CommandRunner())->run([PHP_BINARY, '-r', 'sleep(10);'], 0.1);
    }
}
