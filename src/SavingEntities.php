<?php

namespace Dynart\Dpress\Test;

use Dynart\Micro\Entities\Entity;
use Dynart\Micro\Entities\EntityManager;

/**
 * An entity manager that keeps what it was asked to save, and writes nothing
 *
 * For a test whose question is *which* entities went through the entity manager - and so were
 * audited - rather than what SQL a save would have produced.
 */
class SavingEntities extends EntityManager {

    /** @var Entity[] */
    public array $saved = [];

    public function save(Entity $entity): void {
        $this->saved[] = $entity;
    }
}
