<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;

final readonly class ConfigDefinitionSource implements DefinitionSource
{
    public function __construct(private Repository $config) {}

    public function open(): WorkdayDefinitions
    {
        $definitions = $this->config->get('workdays.definitions');
        if (! is_array($definitions)
            || array_diff(array_keys($definitions), ['default_profile', 'include_start_date', 'hijri', 'profile_routing', 'profiles']) !== []
            || ! is_array($definitions['profiles'] ?? null)) {
            throw new DefinitionSourceException('Config source requires workdays.definitions with operational settings and a profiles map.');
        }
        $profiles = [];
        $errors = [];
        foreach ($definitions['profiles'] as $key => $profile) {
            $name = (string) $key;
            if (trim($name) === '') {
                throw new DefinitionSourceException('Definition profile names must not be empty.');
            }
            try {
                $profiles[$name] = WorkdayDefinitions::detach($profile, 'Definition profile ['.$name.']');
            } catch (InvalidArgumentException $error) {
                $profiles[$name] = null;
                $errors[$name] = $error;
            }
        }
        unset($definitions['profiles']);
        try {
            return new WorkdayDefinitions($definitions,
                static function (string $name) use ($profiles, $errors): mixed {
                    if (isset($errors[$name])) {
                        throw $errors[$name];
                    }

                    return $profiles[$name];
                },
                static fn (string $name): bool => array_key_exists($name, $profiles));
        } catch (InvalidArgumentException $error) {
            throw new DefinitionSourceException('Invalid Config source operational settings: '.$error->getMessage(), previous: $error);
        }
    }
}
