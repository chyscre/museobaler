<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Everything the code needs must actually be committed.
 *
 * The audit that prompted this found eleven files that the committed code
 * depended on but that were never `git add`ed - among them
 * App\Support\ExhibitImage, which both exhibit controllers call, and the
 * day-nav component that /records and /logs render. Locally everything
 * worked, because the files were sitting in the working tree. deploy.sh
 * builds a release with `git clone`, so none of them would have reached the
 * server: the panel's Tourism screens and the whole visitor exhibit API
 * would have been a fatal error on a deploy that otherwise looked clean.
 * The health check could not catch it either - it asks /up, which has no
 * opinion about any of this.
 *
 * So this walks the TRACKED file list, not the working tree, and resolves
 * every first-party reference in it against that same list. Run locally it
 * fails the moment something is referenced but unstaged, which is while it
 * is still cheap to fix; run in CI it is a second opinion on a clean
 * checkout.
 *
 * Each check below is one of the five ways the eleven files were reached.
 */
class SelfContainedTest extends TestCase
{
    /** Paths whose contents are deliberately not in git - uploads and build output. */
    private const UNTRACKED_BY_DESIGN = [
        'public/build/',
        'public/storage/',
        'public/images/exhibits/',
        'public/images/training/',
        'public/images/qr/',
        'public/images/branding/',
        'public/audio/',
    ];

    /** @var array<string,true>|null Tracked paths, repo-relative with forward slashes. */
    private static ?array $tracked = null;

    /** The file list git would hand a release, as a set for O(1) lookups. */
    private function tracked(): array
    {
        if (self::$tracked !== null) {
            return self::$tracked;
        }

        $root = base_path();
        $out  = [];
        $code = 0;
        exec('git -C ' . escapeshellarg($root) . ' ls-files 2>&1', $out, $code);

        if ($code !== 0) {
            $this->markTestSkipped('git is not available, or this is not a repository.');
        }

        $set = [];
        foreach ($out as $line) {
            $line = trim($line);
            if ($line !== '') {
                $set[$line] = true;
            }
        }

        if ($set === []) {
            $this->markTestSkipped('git reported no tracked files.');
        }

        return self::$tracked = $set;
    }

    private function isTracked(string $path): bool
    {
        return isset($this->tracked()[$path]);
    }

    /** True when the path sits under a directory whose contents are gitignored on purpose. */
    private function untrackedByDesign(string $path): bool
    {
        foreach (self::UNTRACKED_BY_DESIGN as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Tracked files under $dir whose name ends with one of $extensions. */
    private function trackedFiles(string $dir, array $extensions): array
    {
        $found = [];
        foreach (array_keys($this->tracked()) as $path) {
            if (!str_starts_with($path, $dir)) {
                continue;
            }
            foreach ($extensions as $ext) {
                if (str_ends_with($path, $ext)) {
                    $found[] = $path;
                    break;
                }
            }
        }

        return $found;
    }

    private function read(string $path): string
    {
        return (string) @file_get_contents(base_path($path));
    }

    /**
     * 1. A first-party class name must resolve to a tracked file under app/.
     *
     * Note this reads comments as well as code, so a docblock naming a class
     * that no longer exists fails too. That is deliberate - a stale reference
     * in a comment is worth knowing about - but it is why the illustrative
     * names here are written as App\<Namespace>\<Class> rather than spelled out.
     *
     * This is the check that App\Support\ExhibitImage and App\Support\DayPage
     * would have failed. A prefix that names a directory is a namespace, not
     * a class, so only leaves are required to be files.
     */
    public function test_every_first_party_class_reference_is_committed(): void
    {
        $bs      = chr(92);
        $classRe = '/' . 'App' . $bs . $bs . '((?:[A-Z][A-Za-z0-9_]*' . $bs . $bs . ')*[A-Z][A-Za-z0-9_]*)/';
        $dangling = [];

        foreach ($this->trackedFiles('', ['.php']) as $file) {
            $src = $this->read($file);
            // "namespace App\Support;" names a directory, not a class.
            $src = preg_replace('/^\s*namespace\s+[^;]+;/m', '', $src);

            preg_match_all($classRe, $src, $m);
            foreach (array_unique($m[1]) as $class) {
                $relative = 'app/' . str_replace($bs, '/', $class);

                if (is_dir(base_path($relative)) || $this->isTracked($relative . '.php')) {
                    continue;
                }

                $dangling[] = "App{$bs}{$class}  (referenced by {$file})";
            }
        }

        $this->assertSame([], $dangling, $this->explain(
            'These classes are referenced by committed code but no matching file is tracked',
            $dangling
        ));
    }

    /**
     * 2. `<x-thing>` must resolve to a tracked component view.
     *
     * The day-nav component, which records/index and logs/index both render.
     */
    public function test_every_blade_component_is_committed(): void
    {
        $dangling = [];

        foreach ($this->trackedFiles('resources/views/', ['.blade.php']) as $file) {
            preg_match_all('/<x-([a-z0-9][a-z0-9._-]*)/i', $this->read($file), $m);

            foreach (array_unique($m[1]) as $name) {
                // <x-slot> is Blade's own, not a component file.
                if ($name === 'slot') {
                    continue;
                }

                $path = str_replace('.', '/', $name);
                $ok = $this->isTracked("resources/views/components/{$path}.blade.php")
                    || $this->isTracked("resources/views/components/{$path}/index.blade.php");

                if (!$ok) {
                    $dangling[] = "<x-{$name}>  (referenced by {$file})";
                }
            }
        }

        $this->assertSame([], $dangling, $this->explain(
            'These Blade components are referenced but no matching view is tracked',
            $dangling
        ));
    }

    /**
     * 3. A literal view name must resolve to a tracked view.
     *
     * How resources/views/vendor/pagination/museo.blade.php is reached:
     * Paginator::defaultView('vendor.pagination.museo') in AppServiceProvider,
     * a string no class or component check would ever see.
     */
    public function test_every_literal_view_name_is_committed(): void
    {
        // Either quote style: AppServiceProvider names the pagination view with
        // double quotes, and a single-quote-only pattern silently missed it.
        $string   = '([\'"])([a-z0-9._-]+)\\1';
        $patterns = [
            '/\\bview\\(\\s*' . $string . '/i',
            '/\\bdefaultView\\(\\s*' . $string . '/i',
            '/\\bdefaultSimpleView\\(\\s*' . $string . '/i',
            '/@include\\(\\s*' . $string . '/i',
            '/@extends\\(\\s*' . $string . '/i',
        ];

        $dangling = [];
        $files = array_merge(
            $this->trackedFiles('app/', ['.php']),
            $this->trackedFiles('resources/views/', ['.blade.php']),
            $this->trackedFiles('routes/', ['.php'])
        );

        foreach ($files as $file) {
            $src = $this->read($file);

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $src, $m);

                foreach (array_unique($m[2]) as $name) {
                    // A package view ("pagination::default") is not ours to ship.
                    if (str_contains($name, '::')) {
                        continue;
                    }

                    $path = str_replace('.', '/', $name);
                    $ok = $this->isTracked("resources/views/{$path}.blade.php")
                        || $this->isTracked("resources/views/{$path}.php");

                    if (!$ok) {
                        $dangling[] = "view '{$name}'  (referenced by {$file})";
                    }
                }
            }
        }

        $this->assertSame([], $dangling, $this->explain(
            'These views are named by committed code but are not tracked',
            $dangling
        ));
    }

    /**
     * 4. `asset('literal')` must resolve to a tracked file under public/.
     *
     * The login page's seal and wordmark, which were referenced by
     * auth/login.blade.php and never committed.
     */
    public function test_every_literal_asset_reference_is_committed(): void
    {
        $dangling = [];
        $files = array_merge(
            $this->trackedFiles('resources/views/', ['.blade.php']),
            $this->trackedFiles('app/', ['.php'])
        );

        foreach ($files as $file) {
            // Either quote style, and nothing interpolated.
            preg_match_all('/\\basset\\(\\s*([\'"])([^\'"${}]+)\\1/', $this->read($file), $m);

            foreach (array_unique($m[2]) as $ref) {
                $path = 'public/' . ltrim($ref, '/');

                if ($this->untrackedByDesign($path) || $this->isTracked($path)) {
                    continue;
                }

                $dangling[] = "asset('{$ref}')  (referenced by {$file})";
            }
        }

        $this->assertSame([], $dangling, $this->explain(
            "These asset() paths are referenced but are not tracked under public/",
            $dangling
        ));
    }

    /**
     * 5. The visitor app's own static references must be committed.
     *
     * index.html and app.css are plain files served as-is, so nothing in PHP
     * ever mentions seal.png, wordmark.png or museum-facade.jpg. All three
     * were untracked, which would have left the PWA's landing screen blank.
     *
     * @param string $file  a tracked file under public/visitor/
     */
    #[DataProvider('visitorAppFiles')]
    public function test_the_visitor_apps_static_references_are_committed(string $file): void
    {
        if (!$this->isTracked($file)) {
            $this->markTestSkipped("{$file} is not tracked.");
        }

        $src = $this->read($file);
        $dir = dirname($file);

        // src="./img/seal.png", url('../img/museum-facade.jpg') and friends.
        preg_match_all('/(?:src|href)\s*=\s*"([^"]+)"|url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $src, $m);
        $refs = array_filter(array_merge($m[1], $m[2]));

        $media = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.ico', '.mp3', '.wav', '.woff', '.woff2', '.ttf'];
        $dangling = [];

        foreach (array_unique($refs) as $ref) {
            // Only same-tree relative references to a real media file.
            if (!str_starts_with($ref, './') && !str_starts_with($ref, '../')) {
                continue;
            }
            if (str_contains($ref, '$') || str_contains($ref, '{')) {
                continue;
            }

            $ext = strtolower('.' . pathinfo(parse_url($ref, PHP_URL_PATH) ?? $ref, PATHINFO_EXTENSION));
            if (!in_array($ext, $media, true)) {
                continue;
            }

            $path = $this->normalise($dir . '/' . strtok($ref, '?#'));

            if ($this->untrackedByDesign($path) || $this->isTracked($path)) {
                continue;
            }

            $dangling[] = "{$ref}  ->  {$path}";
        }

        $this->assertSame([], $dangling, $this->explain(
            "These files are referenced by {$file} but are not tracked",
            $dangling
        ));
    }

    /**
     * 6. Nothing under app/ or resources/views/ is left untracked at all.
     *
     * The backstop for code nothing names. Laravel auto-discovers console
     * commands from app/Console/Commands, so BuildExhibitThumbs.php - which
     * provides `exhibits:thumbs`, the command ExhibitImage's own docblock
     * tells operators to run - is referenced by no class name, no view and
     * no asset path. Every check above missed it while it was untracked.
     *
     * Neither directory has anything gitignored in it, so on-disk-but-
     * untracked here always means forgotten rather than deliberate.
     *
     * @param string $dir a first-party source directory
     */
    #[DataProvider('sourceDirectories')]
    public function test_no_first_party_source_file_is_left_untracked(string $dir, array $extensions): void
    {
        $root = base_path($dir);
        if (!is_dir($root)) {
            $this->markTestSkipped("{$dir} does not exist.");
        }

        $untracked = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $path = $dir . substr($path, strlen(str_replace('\\', '/', $root)));
            $path = $this->normalise($path);

            $matches = false;
            foreach ($extensions as $ext) {
                if (str_ends_with($path, $ext)) {
                    $matches = true;
                    break;
                }
            }

            if ($matches && !$this->isTracked($path)) {
                $untracked[] = $path;
            }
        }

        sort($untracked);

        $this->assertSame([], $untracked, $this->explain(
            "These files are on disk under {$dir} but are not tracked",
            $untracked
        ));
    }

    /**
     * 7. A relative link in the documentation must point at a tracked file.
     *
     * Not fatal the way a missing class is, but the handover docs are what the
     * external evaluators read first: README linked docs/HOSTING.md while that
     * file was untracked, so a fresh clone had a dead link on the one page
     * explaining how the hosting was bought and handed over.
     */
    public function test_documentation_links_point_at_committed_files(): void
    {
        $dangling = [];

        foreach ($this->trackedFiles('', ['.md']) as $file) {
            $dir = dirname($file);
            preg_match_all('/\]\(([^)\s]+)\)/', $this->read($file), $m);

            foreach (array_unique($m[1]) as $link) {
                // External, anchor-only, or mailto - nothing in the repo to find.
                if (preg_match('~^(https?:|mailto:|//|\#)~i', $link)) {
                    continue;
                }

                $target = strtok($link, '#');
                if ($target === false || $target === '') {
                    continue;
                }

                $path = $this->normalise(str_starts_with($target, '/') ? ltrim($target, '/') : $dir . '/' . $target);

                // A link to a directory is fine as long as something is tracked under it.
                if ($this->isTracked($path) || $this->untrackedByDesign($path)) {
                    continue;
                }
                if (is_dir(base_path($path)) && $this->trackedFiles(rtrim($path, '/') . '/', ['']) !== []) {
                    continue;
                }

                $dangling[] = "{$link}  ->  {$path}  (linked from {$file})";
            }
        }

        $this->assertSame([], $dangling, $this->explain(
            'These documentation links point at files that are not tracked',
            $dangling
        ));
    }

    /** Directories whose every source file must be committed. */
    public static function sourceDirectories(): array
    {
        return [
            'app'              => ['app', ['.php']],
            'resources/views'  => ['resources/views', ['.blade.php', '.php']],
            'routes'           => ['routes', ['.php']],
            'database'         => ['database', ['.php']],
        ];
    }

    /** The visitor app's static entry points. */
    public static function visitorAppFiles(): array
    {
        return [
            'index.html'  => ['public/visitor/index.html'],
            'app.css'     => ['public/visitor/css/app.css'],
            'manifest'    => ['public/visitor/manifest.json'],
        ];
    }

    /** Collapse "a/b/../c" to "a/c" without touching the filesystem. */
    private function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** A failure message that says what to do about it. */
    private function explain(string $headline, array $items): string
    {
        if ($items === []) {
            return '';
        }

        return $headline . ":\n  - " . implode("\n  - ", $items)
            . "\n\nA release is built with `git clone` (deploy/deploy.sh), so anything "
            . "not tracked never reaches the server even though it works locally. "
            . "Either `git add` the file, or remove the reference.";
    }
}
