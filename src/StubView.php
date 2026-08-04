<?php

namespace Dynart\Dpress\Test;

use Dynart\Micro\MicroException;
use Dynart\Micro\ViewInterface;

/**
 * A view backed by an in-memory template map
 *
 * Keyed by view path without the `.phtml`, so `mail/reset.txt` is the text body of
 * `mail/reset` - the same convention the real view uses once it appends the extension.
 */
class StubView implements ViewInterface {

    public array $fetched = [];

    /**
     * @param array<string, string> $templates [view path => rendered output]
     */
    public function __construct(private array $templates = []) {}

    public function set(string $name, mixed $value): void {}
    public function get(string $name, mixed $default = null): mixed { return $default; }
    public function addScript(string $src, array $attributes = [], int $priority = 50): void {}
    public function scripts(): array { return []; }
    public function addStyle(string $src, array $attributes = [], int $priority = 50): void {}
    public function styles(): array { return []; }
    public function useLayout(string $path): void {}
    public function layout(): string { return ''; }
    public function block(string $name): string { return ''; }
    public function startBlock(string $name): void {}
    public function endBlock(): void {}
    public function addFolder(string $namespace, string $path): void {}
    public function folder(string $namespace): string { return ''; }
    public function setTheme(string $path): void {}
    public function theme(): string { return ''; }

    public function exists(string $path): bool {
        return array_key_exists($path, $this->templates);
    }

    public function fetch(string $__viewPath, array $__vars = []): string {
        if (!array_key_exists($__viewPath, $this->templates)) {
            throw new MicroException("Can't find view: $__viewPath");
        }
        $this->fetched[] = ['path' => $__viewPath, 'vars' => $__vars];
        $result = $this->templates[$__viewPath];
        foreach ($__vars as $name => $value) {
            if (is_scalar($value)) {
                $result = str_replace('{'.$name.'}', (string)$value, $result);
            }
        }
        return $result;
    }
}
