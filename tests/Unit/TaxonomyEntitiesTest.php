<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Security\Permissions;
use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the Phase 3 auditing and cascade decisions
 */
class TaxonomyEntitiesTest extends TestCase {

    private function isAuditable(string $className): bool {
        return (new ReflectionClass($className))->getAttributes(Auditable::class) !== [];
    }

    /**
     * Re-tagging a post changes no row in `content`, so without their own mirrors these changes
     * would leave no trace at all.
     */
    public function testTheRelationTablesAreAudited(): void {
        foreach ([ContentCategory::class, ContentTag::class, ContentAttachment::class] as $className) {
            $this->assertTrue($this->isAuditable($className), "$className should be auditable");
        }
    }

    public function testTheTaxonomyRecordsAreAudited(): void {
        foreach ([Category::class, Tag::class, Media::class] as $className) {
            $this->assertTrue($this->isAuditable($className), "$className should be auditable");
        }
    }

    /**
     * A cascade happens inside the database, where no event fires and no audit row is written.
     * Every audited relation removes its rows through the service instead.
     */
    public function testNoAuditedRelationCascades(): void {
        foreach ([ContentCategory::class, ContentTag::class, ContentAttachment::class] as $className) {
            foreach ((new ReflectionClass($className))->getProperties() as $property) {
                foreach ($property->getAttributes(Column::class) as $attribute) {
                    $this->assertNull(
                        $attribute->newInstance()->onDelete,
                        "$className::\${$property->getName()} must not cascade, it would skip the audit"
                    );
                }
            }
        }
    }

    public function testEveryNewEntityDeclaresItsTableName(): void {
        foreach (DpressServices::ENTITIES as $className) {
            $attributes = (new ReflectionClass($className))->getAttributes(Table::class);
            $this->assertNotEmpty($attributes, "$className has no #[Table]");
            $this->assertNotNull($attributes[0]->newInstance()->name, "$className does not name its table");
        }
    }

    public function testTaxonomySlugsAreUnique(): void {
        foreach ([Category::class, Tag::class] as $className) {
            $property = (new ReflectionClass($className))->getProperty('slug');
            $this->assertTrue($property->getAttributes(Column::class)[0]->newInstance()->unique);
        }
    }

    /**
     * Two items may share a name, but never a stored path - that is what write-once means.
     */
    public function testTheMediaPathIsUnique(): void {
        $property = (new ReflectionClass(Media::class))->getProperty('path');
        $this->assertTrue($property->getAttributes(Column::class)[0]->newInstance()->unique);
    }

    public function testTaxonomyAndMediaPermissionsAreRegistered(): void {
        $permissions = new Permissions();
        foreach (['category.view', 'category.create', 'tag.view', 'tag.delete',
                  'media.view', 'media.create', 'media.delete', 'media.purge'] as $permission) {
            $this->assertTrue($permissions->has($permission), "$permission is not registered");
        }
    }
}
