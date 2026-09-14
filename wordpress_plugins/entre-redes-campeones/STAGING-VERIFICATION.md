# Staging verification — slice 1 (plugin skeleton + schema + repositories)

`composer test` cannot observe two things in this slice, because the SQLite
test shim strips `ENGINE=` clauses and has no live MySQL server behind it.
Both must be checked by hand, once, after activating the plugin on a staging
copy of the site.

## 1. Confirm `ENGINE=InnoDB` actually took effect

`InitialSchemaTest::test_both_create_table_statements_declare_engine_innodb`
proves the CREATE TABLE **SQL string** literally contains `ENGINE=InnoDB`.
It cannot prove the live database actually created the tables with that
engine — a server whose default storage engine changed (e.g. to MyISAM)
would silently ignore the clause in some misconfigurations, and no test
run against the SQLite shim can catch that regression.

Steps:

1. Activate `entre-redes-campeones` on a staging copy of the site (this runs
   `InitialSchema::up()` via the activation hook).
2. Run, against the staging database:
   ```sql
   SHOW CREATE TABLE wp_campeones_titulo;
   SHOW CREATE TABLE wp_campeones_plantel;
   ```
   (adjust the `wp_` prefix if the staging site uses a different one).
3. Confirm both statements end in `ENGINE=InnoDB` (or report the actual
   engine if not, and fix the server's default before proceeding to later
   slices — slice 6's importer relies on real `START TRANSACTION` /
   `ROLLBACK` semantics, which are no-ops on a non-transactional engine).

## 2. Confirm activation is clean end-to-end

1. Activate the plugin on a staging copy with no prior `campeones_*` tables.
2. Confirm no PHP warnings/notices/fatals appear in the debug log.
3. Confirm the `campeones_db_version` option was set to the current
   `ENTRE_REDES_CAMPEONES_VERSION` (check via `wp option get campeones_db_version`
   or the options table directly).
4. Deactivate the plugin and confirm both tables are **still present**
   (deactivation must never drop data — only `uninstall.php` does, and only
   on explicit uninstall).
5. Re-activate and confirm no errors — `InitialSchema::up()` must be a
   no-op on a schema that already matches.

## Out of scope for this checklist

Everything else in slice 1 (repository CRUD, conflict rejection, ordered
reads, the `manual`-exclusion query) is fully covered by `composer test`
and needs no manual check. Matching, admin screens, the importer and the
REST API do not exist yet — they arrive in slices 2 through 7a.
