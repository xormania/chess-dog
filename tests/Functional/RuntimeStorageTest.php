<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RuntimeStorageTest extends TestCase
{
    public function testCacheClearPreservesTheOtherRuntimeAndSharedProfiler(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $id = bin2hex(random_bytes(8));
        $scratchDir = $projectDir.'/var/cache/storage-regression-'.$id;
        $profilerMarker = $projectDir.'/var/profiler/test/.storage-regression-'.$id;
        $filesystem = new Filesystem();

        try {
            // Never load or clear a real Docker cache with the host interpreter.
            $host = $this->storagePaths('test', $scratchDir.'/host', true);
            $docker = $this->storagePaths('test', $scratchDir.'/docker', true);
            self::assertSame($scratchDir.'/host/test', $host['cache']);
            self::assertSame($scratchDir.'/docker/test', $docker['cache']);
            self::assertSame($host['cache'], $host['build']);
            self::assertSame($docker['cache'], $docker['build']);
            self::assertSame('file:'.$projectDir.'/var/profiler/test', $host['profiler']);
            self::assertSame($host['profiler'], $docker['profiler']);

            $hostMarker = $host['cache'].'/retain';
            $dockerMarker = $docker['cache'].'/retain';
            foreach ([$hostMarker, $dockerMarker, $profilerMarker] as $path) {
                $filesystem->dumpFile($path, 'retain');
            }

            $this->runPhp(['bin/console', 'cache:clear', '--env=test', '--no-warmup'], $scratchDir.'/host');
            clearstatcache();

            self::assertFileExists($dockerMarker);
            self::assertFileExists($profilerMarker);

            $filesystem->dumpFile($hostMarker, 'retain');
            $this->runPhp(['bin/console', 'cache:clear', '--env=test', '--no-warmup'], $scratchDir.'/docker');
            clearstatcache();

            self::assertFileExists($hostMarker);
            self::assertFileExists($profilerMarker);
        } finally {
            $filesystem->remove([$scratchDir, $profilerMarker]);
        }
    }

    public function testRuntimePathsCanBeResolvedWithoutLoadingTheirCompiledCaches(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $dev = $this->storagePaths('dev');
        $test = $this->storagePaths('test');
        $docker = $this->storagePaths('dev', 'var/cache/docker');
        $prod = $this->storagePaths('prod');

        self::assertSame($projectDir.'/var/cache/host/dev', $dev['cache']);
        self::assertSame($dev['cache'], $dev['build']);
        self::assertSame($projectDir.'/var/cache/host/test', $test['cache']);
        self::assertSame($test['cache'], $test['build']);
        self::assertSame($projectDir.'/var/cache/docker/dev', $docker['cache']);
        self::assertSame($docker['cache'], $docker['build']);
        self::assertSame($projectDir.'/var/cache/prod', $prod['cache']);
        self::assertSame($prod['cache'], $prod['build']);
    }

    /** @return array{cache: string, build: string, profiler: ?string} */
    private function storagePaths(string $environment, ?string $cacheDir = null, bool $boot = false): array
    {
        $process = $this->runPhp(['-r', <<<'PHP'
            require 'vendor/autoload.php';
            (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
            $kernel = new App\Kernel($_SERVER['APP_ENV'], $_SERVER['APP_DEBUG']);
            $profiler = null;
            if ('boot' === $argv[1]) {
                $kernel->boot();
                $profiler = $kernel->getContainer()->getParameter('profiler.storage.dsn');
            }
            echo json_encode([
                'cache' => $kernel->getCacheDir(),
                'build' => $kernel->getBuildDir(),
                'profiler' => $profiler,
            ], JSON_THROW_ON_ERROR);
            $kernel->shutdown();
            PHP, $boot ? 'boot' : 'paths-only'], $cacheDir, $environment);

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $arguments */
    private function runPhp(array $arguments, ?string $cacheDir = null, string $environment = 'test'): Process
    {
        $process = new Process([PHP_BINARY, ...$arguments], \dirname(__DIR__, 2), [
            'APP_ENV' => $environment,
            'APP_DEBUG' => 'prod' === $environment ? '0' : '1',
            'APP_CACHE_DIR' => $cacheDir ?? false,
            'APP_BUILD_DIR' => false,
            // Model a fresh shell, not inherited PHPUnit Dotenv bookkeeping.
            'SYMFONY_DOTENV_VARS' => false,
            'TEST_TOKEN' => '',
        ]);
        $process->setTimeout(60);
        $process->mustRun();

        return $process;
    }
}
