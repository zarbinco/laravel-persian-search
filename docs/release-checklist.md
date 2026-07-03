# Release Checklist

- Run `composer validate --strict`.
- Run `composer install`.
- Run `composer test`.
- Run `composer analyse`.
- Run `composer format -- --test`.
- Test installation in a fresh Laravel application.
- Publish configuration and migrations.
- Run database migrations.
- Define a searchable model.
- Manually index a model.
- Verify automatic indexing on save, delete, and restore.
- Search normal Persian input.
- Search wrong-keyboard input.
- Enable and test synonym expansion.
- Run the reindex command.
- Run the flush command.
- Confirm the delivery zip excludes cache, vendor, Git, log, coverage, and temporary files.
- Confirm the `zarbinco/laravel-persian-core` dependency constraint is ready before tagging a stable release.
