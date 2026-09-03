# Migrations

One class per change to a database that already exists.

This directory is **empty on purpose**. `up()` handles every table that does not
exist yet; a migration is only needed when a table that *does* exist has to
change — a new column, a widened one, a new index, a data backfill. There is no
such change outstanding today, so there is nothing here. The first real one goes
in when the first real one arises.

The directory itself must stay. `helpers/loader.php` registers it as a resource
and `bin/verify-stage.php` asserts it ships, because an over-eager exclusion rule
that dropped it would make every future migration silently invisible — the
update would report success and change nothing, which is the exact failure this
whole mechanism exists to prevent.

## Writing one

Extend `LBM\Contract\MigrationAbstract` (which lives in `src/Contract`, not here
— read its docblock for why, it is not arbitrary).

```php
namespace LBM\Migration;

use LBM\Contract\MigrationAbstract;

class M202609041200WidenSomething extends MigrationAbstract
{
    protected string $id = '20260904_1200_widen_something';

    protected string $description = 'Widen things.label so a long name is not truncated.';

    public function applies(): bool
    {
        // Probe the thing being CHANGED, not merely that it exists.
        // hasColumn() cannot tell a varchar(50) from a varchar(255).
    }

    public function run(): void
    {
        $on = $this->schema();

        match ($this->driver()) {
            'mysql' => $on->statement('ALTER TABLE `things` MODIFY `label` VARCHAR(255) NULL DEFAULT NULL'),
            'pgsql' => $on->statement('ALTER TABLE "things" ALTER COLUMN "label" TYPE VARCHAR(255)'),
            default => throw new \RuntimeException('Unsupported driver: ' . $this->driver()),
        };
    }
}
```

## The traps, in the order they will bite you

**`statement()` returns `false` on success.** It is `(bool) $pdo->exec($sql)` and
`PDO::exec()` returns `0` for DDL. Never branch on it. A real failure arrives as
a `PDOException`.

**MySQL's `MODIFY` restates the *whole* column definition.** Omit
`NULL DEFAULT NULL` and the column silently becomes `NOT NULL` with no default,
breaking every insert that leaves it out. PostgreSQL's `ALTER COLUMN ... TYPE`
is the opposite: it changes only the type, and nullability and default must
*not* be restated.

**`default => throw`, never a guess.** A guessed dialect fails at the worst
possible moment, on somebody else's server.

**No transaction wraps `run()`.** MySQL commits implicitly on every DDL
statement, so a transaction there is false comfort, and leaning on PostgreSQL's
real transactional DDL would make the same migration safe on one engine and not
the other. Guard each statement instead, and keep one logical change per class.

**Raw DDL is allowed here and nowhere else in the product.**
`bin/verify-stage.php` greps for it at build time and blocks the release if it
appears outside this directory. Everywhere else the rule stands: model methods
only.

## Ids

`YYYYMMDD_HHMM_snake_slug`, so sorting the string sorts the history. **Write it
once and never edit it** — the id is the ledger key in the `migrations` table,
so changing it re-runs the migration on every installation in the world. The
runner refuses to start if two migrations share an id.

## What the runner does

For each migration not already in `migrations`, in id order:

| `applies()` | outcome |
|---|---|
| `false` | recorded `baselined`, `run()` never called — the fresh-install path |
| `true` | `run()`, then recorded `applied` |
| throws | reported, **nothing recorded**, retried next time |
