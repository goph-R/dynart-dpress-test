<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Security\Permissions;
use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Dynart\Dpress\Entity\Content
 */
class ContentEntityTest extends TestCase {

    public function testContentIsAudited(): void {
        $this->assertNotEmpty((new ReflectionClass(Content::class))->getAttributes(Auditable::class));
    }

    public function testTypeAndStatusHelpers(): void {
        $content = new Content();
        $content->type = Content::TYPE_POST;
        $this->assertTrue($content->isPost());
        $this->assertFalse($content->isPage());
        $content->type = Content::TYPE_PAGE;
        $this->assertTrue($content->isPage());
    }

    public function testIsPublished(): void {
        $content = new Content();
        $this->assertFalse($content->isPublished());
        $content->status = Content::STATUS_PUBLISHED;
        $this->assertTrue($content->isPublished());
    }

    /**
     * A document with no lead separator is all lead, so "has a body" has to be false rather than
     * an empty string being rendered as one.
     */
    public function testHasBody(): void {
        $content = new Content();
        $this->assertFalse($content->hasBody());
        $content->body_html = '';
        $this->assertFalse($content->hasBody());
        $content->body_html = '<p>x</p>';
        $this->assertTrue($content->hasBody());
    }

    public function testTheSlugIsUnique(): void {
        $column = $this->column('slug');
        $this->assertTrue($column->unique, 'the slug is one flat namespace across posts and pages');
    }

    /**
     * The main listing filters on all three, so they belong in one index rather than three.
     */
    public function testTheListingIndexIsDeclared(): void {
        $attributes = (new ReflectionClass(Content::class))->getAttributes(Table::class);
        $this->assertNotEmpty($attributes);
        $table = $attributes[0]->newInstance();
        $this->assertContains(['type', 'status', 'published_at'], $table->index);
    }

    /**
     * `Media` does not exist yet, so the column cannot carry a foreign key until it does.
     */
    public function testTheFeaturedMediaColumnHasNoForeignKeyYet(): void {
        $this->assertNull($this->column('featured_media_id')->foreignKey);
    }

    public function testTypesAndStatusesAreDistinct(): void {
        $this->assertSame(count(Content::TYPES), count(array_unique(Content::TYPES)));
        $this->assertSame(count(Content::STATUSES), count(array_unique(Content::STATUSES)));
    }

    /**
     * The service resolves the permission from the row's type, so both have to exist for both.
     */
    public function testEveryContentTypeHasItsPermissions(): void {
        $permissions = new Permissions();
        foreach (Content::TYPES as $type) {
            foreach (['view', 'create', 'update', 'delete', 'publish'] as $action) {
                $permission = Permissions::forContent($type, $action);
                $this->assertTrue($permissions->has($permission), "$permission is not registered");
            }
        }
    }

    private function column(string $name): Column {
        $property = (new ReflectionClass(Content::class))->getProperty($name);
        return $property->getAttributes(Column::class)[0]->newInstance();
    }
}
