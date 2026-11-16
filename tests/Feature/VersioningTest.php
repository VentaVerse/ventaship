<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** VERSION, CHANGELOG.md and the version the app shows agree (rules in CONTRIBUTING.md). */
class VersioningTest extends TestCase
{
    use RefreshDatabase;

    private function changelog(): string
    {
        return (string) file_get_contents(base_path('CHANGELOG.md'));
    }

    public function test_version_file_is_semver(): void
    {
        $raw = trim((string) file_get_contents(base_path('VERSION')));
        $this->assertMatchesRegularExpression(AppVersion::PATTERN, $raw);
        $this->assertSame($raw, AppVersion::current());
    }

    public function test_version_equals_the_newest_changelog_entry(): void
    {
        preg_match_all('/^## (\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?) \((\d{4}-\d{2}-\d{2}|unreleased)\)$/m', $this->changelog(), $m);
        $this->assertNotEmpty($m[1], 'at least one version');
        $this->assertSame(AppVersion::current(), $m[1][0], 'VERSION is the top heading, released or not');
        foreach ($m[2] as $date) {
            if ($date !== 'unreleased') {
                $this->assertNotFalse(\DateTimeImmutable::createFromFormat('!Y-m-d', $date), $date);
            }
        }
    }

    public function test_only_the_top_version_can_be_unreleased(): void
    {
        $log = $this->changelog();
        $this->assertStringStartsWith("# Changelog\n\n## ", $log);
        $this->assertStringNotContainsString('## Unreleased', $log);

        // Every second-level heading is a version, newest first; only the first may be unreleased.
        preg_match_all('/^## (.+)$/m', $log, $m);
        $versions = [];
        foreach ($m[1] as $i => $heading) {
            $this->assertMatchesRegularExpression($i === 0
                ? '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)? \((\d{4}-\d{2}-\d{2}|unreleased)\)$/'
                : '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)? \(\d{4}-\d{2}-\d{2}\)$/', $heading);
            $versions[] = explode(' ', $heading)[0];
        }
        $sorted = $versions;
        usort($sorted, fn ($a, $b) => version_compare($b, $a));
        $this->assertSame($sorted, $versions, 'newest first');
        $this->assertSame(count($versions), count(array_unique($versions)), 'each version once');
    }

    public function test_sections_are_the_three_allowed_ones(): void
    {
        preg_match_all('/^### (.+)$/m', $this->changelog(), $m);
        foreach ($m[1] as $section) {
            $this->assertContains($section, ['New features', 'Updates', 'Bug fixes']);
        }
        $this->assertStringNotContainsString('**', $this->changelog(), 'no bold');
    }

    public function test_app_shows_the_version(): void
    {
        $admin = Admin::query()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'correct-horse-battery']);

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertSee('<span class="x-side__ver">Ventaship '.AppVersion::current().'</span>', false);
    }

    public function test_login_shows_the_same_version_as_the_menu(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<div class="lg-foot">Ventaship '.AppVersion::current().'</div>', false);
    }

    public function test_reader_accepts_pre_release_and_rejects_junk(): void
    {
        $dir = sys_get_temp_dir().'/vs-version-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (["1.1.0-beta.1\n" => '1.1.0-beta.1', '2.0.0' => '2.0.0', 'v1.0' => '0.0.0', '' => '0.0.0', "1.0.0\n<script>" => '0.0.0'] as $content => $want) {
                file_put_contents($dir.'/VERSION', $content);
                $this->assertSame($want, AppVersion::read($dir.'/VERSION'), var_export($content, true));
            }
            $this->assertSame('0.0.0', AppVersion::read($dir.'/missing'));
        } finally {
            @unlink($dir.'/VERSION');
            @rmdir($dir);
        }
    }
}
