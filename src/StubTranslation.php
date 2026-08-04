<?php

namespace Dynart\Dpress\Test;

use Dynart\Micro\TranslationInterface;

/**
 * A translation that returns whatever it was handed
 *
 * Missing ids come back as `#namespace:id#`, the way `Translation` does it, so the fallback in
 * `Form::message()` can be exercised.
 */
class StubTranslation implements TranslationInterface {

    public function __construct(private array $texts = []) {}

    public function add(string $namespace, string $folder): void {}

    public function allLocales(): array {
        return ['en'];
    }

    public function hasMultiLocales(): bool {
        return false;
    }

    public function locale(): string {
        return 'en';
    }

    public function setLocale(string $locale): void {}

    public function get(string $id, array $params = []): string {
        return $this->texts[$id] ?? '#'.$id.'#';
    }
}
