<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Security\Permissions;
use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Table;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Phase 4 decisions: what is audited, and what is not
 */
class PresentationTest extends TestCase {

    private function isAuditable(string $className): bool {
        return (new ReflectionClass($className))->getAttributes(Auditable::class) !== [];
    }

    /**
     * "Who turned registration on" is the same question as "who granted this role", and because
     * a setting is keyed by name the mirror gives per-setting history with no replay.
     */
    public function testSettingsAreAudited(): void {
        $this->assertTrue($this->isAuditable(Setting::class));
    }

    /**
     * Deliberately not audited (plan §4.4): a menu editor rewrites the tree wholesale, so the
     * history would record churn rather than meaning.
     */
    public function testMenusAreNotAudited(): void {
        $this->assertFalse($this->isAuditable(Menu::class));
        $this->assertFalse($this->isAuditable(MenuItem::class));
    }

    public function testTheNewEntitiesDeclareTheirTableNames(): void {
        foreach ([Setting::class, Menu::class, MenuItem::class] as $className) {
            $attributes = (new ReflectionClass($className))->getAttributes(Table::class);
            $this->assertNotEmpty($attributes, "$className has no #[Table]");
            $this->assertNotNull($attributes[0]->newInstance()->name);
        }
    }

    public function testTheNewEntitiesAreRegistered(): void {
        foreach ([Setting::class, Menu::class, MenuItem::class] as $className) {
            $this->assertContains($className, DpressServices::ENTITIES);
        }
    }

    /**
     * A setting is keyed by its name, which is what makes its audit mirror a per-setting
     * timeline rather than something that has to be replayed.
     */
    public function testTheSettingKeyIsItsName(): void {
        $property = (new ReflectionClass(Setting::class))->getProperty('name');
        $column = $property->getAttributes(\Dynart\Micro\Entities\Attribute\Column::class)[0]->newInstance();
        $this->assertTrue($column->primaryKey);
    }

    public function testMenuTargetTypesAreDistinct(): void {
        $this->assertSame(count(MenuItem::TARGETS), count(array_unique(MenuItem::TARGETS)));
        $this->assertContains(MenuItem::TARGET_CONTENT, MenuItem::TARGETS);
        $this->assertContains(MenuItem::TARGET_URL, MenuItem::TARGETS);
    }

    public function testIsExternal(): void {
        $item = new MenuItem();
        $item->target_type = MenuItem::TARGET_CONTENT;
        $this->assertFalse($item->isExternal());
        $item->target_type = MenuItem::TARGET_URL;
        $this->assertTrue($item->isExternal());
    }

    public function testMenuAndThemePermissionsAreRegistered(): void {
        $permissions = new Permissions();
        foreach ([Permissions::MENU_VIEW, Permissions::MENU_UPDATE,
                  Permissions::THEME_VIEW, Permissions::THEME_SWITCH] as $permission) {
            $this->assertTrue($permissions->has($permission), "$permission is not registered");
        }
    }
}
