<?php

declare(strict_types=1);

namespace App\Hosting;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(brand_name:APP_NAME)%`: the instance's name by {@see Brand::resolveName()}, for
 * config that needs it as a plain value — the translator's `app_name` global, so every
 * translation can say `{app_name}` (translation.yaml). Resolved at runtime, so it works in
 * web requests and in console commands (scheduled emails) alike.
 */
final class BrandNameEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $mode = strtolower(trim((string) $getEnv('APP_HOSTING_MODE')));

        return Brand::resolveName(
            (string) $getEnv($name),
            $mode !== HostingMode::SELF,
            (string) $getEnv('DEFAULT_URI'),
        );
    }

    public static function getProvidedTypes(): array
    {
        return ['brand_name' => 'string'];
    }
}
