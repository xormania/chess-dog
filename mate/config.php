<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Native CLI is the default; use Docker explicitly to inspect its PHP runtime.
        ->set('mate.invocation', 'vendor/bin/mate')

        // This checks PHP major.minor, not host/container identity or loaded extensions.
        ->set('mate.php_version', '8.5')

        // Introspection reads the compiled XML without booting either runtime's PHP cache.
        ->set('ai_mate_symfony.cache_dir', [
            'host' => '%mate.root_dir%/var/cache/host',
            'docker' => '%mate.root_dir%/var/cache/docker',
        ])

        // Both development runtimes write profiler data into the shared project directory.
        ->set('ai_mate_symfony.profiler_dir', '%mate.root_dir%/var/profiler/dev')
    ;
};
