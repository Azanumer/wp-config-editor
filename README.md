# WP Config Editor

Edit `wp-config.php` constants from the WordPress admin — without the FTP round-trip and without the risk of a typo taking the whole site down.

## Features

- **Admin UI for constants** — every scalar `define()` in `wp-config.php` listed in a table with a type-aware editor (booleans get a dropdown, numbers and strings get a text field)
- **Syntax check before every write** — the edited file is linted with `php -l` (or a token-based balance check when no CLI is available); a failed check aborts the save and nothing is written
- **Automatic backups** — a timestamped, randomly-named backup is saved in `wp-content` before every save, with one-click restore from the same page
- **Locked by default** — database credentials, salts and `ABSPATH` cannot be edited here (unlock via the `wce_protected_constants` filter if you really need to)
- **Add constants** — define a new constant from the same form; it is inserted before the "That's all, stop editing!" line
- **Safe parsing** — only plain scalar values (string, int, float, bool, null) are rewritten; arrays, expressions and function calls are shown read-only and never touched

## How it works

1. The plugin finds `wp-config.php` in `ABSPATH` or one level above it (both locations WordPress supports).
2. On save it rebuilds only the edited `define()` lines — surrounding formatting and trailing comments are preserved.
3. The result is linted, a backup is written, and only then is the file replaced.

## Filters

- `wce_protected_constants` — the list of constants locked from editing (`$defaults`)

## Requirements

- WordPress 5.8+
- PHP 7.4+
- `wp-config.php` writable by the web server

## License

MIT — see [LICENSE](LICENSE).
