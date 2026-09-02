<?php

namespace Dynart\Dpress\Test;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\PdoBuilder;
use PDOStatement;

/**
 * A database that answers what it was told to and remembers what it was asked
 *
 * Enough to unit test something whose logic *is* the query it writes - which window it counts
 * over, which key it counts, what it deletes when it clears. Those are assertions about the SQL
 * and its parameters, and a real server would only confirm that MariaDB can count.
 */
class StubDatabase extends Database {

    /** @var array[] Every query, as ['sql' => ..., 'params' => ...] */
    public array $queries = [];

    /** What the next `fetchOne()` returns, in order; the last one repeats */
    public array $answers = [];

    /** What every `fetchColumn()` returns */
    public array $column = [];

    public function __construct(?ConfigInterface $config = null) {
        parent::__construct($config ?? new StubConfig(), new StubLogger(), new PdoBuilder());
    }

    protected function connect(): void {}

    public function escapeName(string $name): string {
        return '`'.$name.'`';
    }

    public function escapeLike(string $string): string {
        return $string;
    }

    public function query(string $query, array $params = [], bool $closeCursor = false): PDOStatement {
        $this->queries[] = ['sql' => $query, 'params' => $params];
        return new PDOStatement();
    }

    public function fetchOne(string $query, array $params = []): mixed {
        $this->queries[] = ['sql' => $query, 'params' => $params];
        return count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? 0);
    }

    public function fetchColumn(string $query, array $params = []): array {
        $this->queries[] = ['sql' => $query, 'params' => $params];
        return $this->column;
    }

    public function lastInsertId(?string $name = null): string|false {
        return '1';
    }

    /**
     * @return array[] The recorded queries whose SQL contains this fragment
     */
    public function matching(string $fragment): array {
        return array_values(array_filter($this->queries, fn($q) => str_contains($q['sql'], $fragment)));
    }

    public function forget(): void {
        $this->queries = [];
    }
}
