<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The visitor app's service worker, from the server's side.
 *
 * The worker precaches a fixed list of files on install, and a single
 * missing one fails the whole install - every phone would then run with no
 * offline support and no error anyone sees. So the list is read out of
 * sw.js here and each entry checked against public/. The rules themselves
 * are tested under node (tests/js/sw.test.mjs, `npm test`).
 */
class ServiceWorkerTest extends TestCase
{
    private function worker(): string
    {
        return file_get_contents(public_path('visitor/sw.js'));
    }

    /** @return string[] the entries of one const array in sw.js */
    private function list(string $name): array
    {
        preg_match('/const ' . $name . ' = \[(.*?)\];/s', $this->worker(), $m);
        $this->assertNotEmpty($m, "$name not found in sw.js");
        preg_match_all("/'([^']+)'/", $m[1], $entries);

        return $entries[1];
    }

    public function test_every_required_shell_asset_exists(): void
    {
        $required = $this->list('SHELL_REQUIRED');
        $this->assertGreaterThan(8, count($required));

        foreach ($required as $entry) {
            // Resolved the way the worker resolves them: against /visitor/sw.js.
            $path = public_path('visitor/' . $entry);
            if (str_ends_with($entry, '/')) {
                $path .= 'index.html';
            }
            $this->assertFileExists($path, "sw.js precaches $entry but it is not in public/");
        }
    }

    public function test_the_optional_model_files_are_the_ones_the_panel_writes(): void
    {
        $this->assertSame(
            ['./model/model.json', './model/weights.bin', './model/metadata.json'],
            $this->list('SHELL_OPTIONAL')
        );
    }

    public function test_the_worker_is_served_uncached_and_the_app_registers_it(): void
    {
        $this->assertStringContainsString('<Files "sw.js">', file_get_contents(public_path('.htaccess')));
        $this->assertStringContainsString("navigator.serviceWorker.register('./sw.js')", file_get_contents(public_path('visitor/js/app.js')));
        $this->assertStringNotContainsString('retireServiceWorker', file_get_contents(public_path('visitor/js/app.js')));
    }

    public function test_the_version_line_is_what_deploy_sh_rewrites(): void
    {
        $this->assertMatchesRegularExpression("/^const VERSION = '[^']+';/m", $this->worker());
        $this->assertStringContainsString('const VERSION = .*/const VERSION', file_get_contents(base_path('deploy/deploy.sh')));
    }
}
