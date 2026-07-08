<?php
/**
 * PHPUnit bootstrap for bcc-search unit tests.
 *
 * Deliberately minimal — pure-unit, no WordPress core (same house style
 * as bcc-core/tests/bootstrap.php and bcc-trust/tests/bootstrap.php):
 *   - Defines ABSPATH so production files' `if (!defined('ABSPATH')) exit;`
 *     guard lets them parse when autoloaded.
 *   - Loads the shared WP-function shims (esc_url_raw, get_avatar_url, …)
 *     that services/repositories call unqualified. Each is
 *     function_exists()-guarded so a real WP load (if ever) wins.
 *   - Registers Composer's autoloader (PSR-4 `BCC\Search\` -> app/).
 *
 * Per-test collaborator doubles (e.g. a fake UserSearchRepository at its
 * production FQN) live in their own tests/Stubs/*.php files and are
 * required from inside a @runInSeparateProcess subprocess so they never
 * leak into the main process — see UserSearchServiceTest.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/Stubs/wp-stubs.php';

require_once __DIR__ . '/../vendor/autoload.php';
