<?php

namespace Liberu\Ecommerce\Cart\Filament\Tests;

use Filament\Facades\Filament;
use Liberu\Ecommerce\Cart\CartServiceProvider;
use Liberu\Ecommerce\Cart\Filament\Tests\Fixtures\TestPanelProvider;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;

/**
 * Filament is the one dependency `PackageTestCase`'s scoped discovery cannot
 * cover on its own: it registers `extra.laravel.providers` of this package's
 * *direct* requirements, which for `filament/filament` is a single provider. A
 * panel needs the rest of the stack — support, schemas, forms, tables, actions,
 * notifications, widgets, Livewire, the icon packages — and every one of those
 * is transitive. So this widens the same walk to everything installed, which is
 * what Laravel's own discovery does in an application, and appends the fixture
 * panel.
 */
abstract class TestCase extends PackageTestCase
{
    use UsesTestUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing has resolved a panel from a request, and a resource page needs
        // one to be current before it can mount.
        Filament::setCurrentPanel('admin');
    }

    /**
     * An actor working in a team, which is the whole of the staff half of
     * `CartPolicy`.
     *
     * `current_team_id` is set in memory rather than migrated onto `users`: the
     * column belongs to the application's own schema, and a package that added
     * it to the shared testbench table would be asserting against a users table
     * no deployment has.
     */
    protected function actorForTeam(int $teamId): TestUser
    {
        $user = TestUser::factory()->create();
        $user->setAttribute('current_team_id', $teamId);

        $this->actingAs($user);

        return $user;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The domain's default names the host's `App\Models\Team`, a class no
        // package tree has. Nothing here loads the relation, but pointing it
        // somewhere real keeps an accidental eager load from failing on a missing
        // class rather than on the thing under test.
        $app['config']->set('cart.team_model', TestUser::class);

        // Left unset on purpose, and that is the wave-4 boundary proof rather
        // than an omission. Cart does not depend on Catalog, so this whole suite
        // runs with no catalogue present and every line holds a product id
        // nothing in the database has heard of. Configuring a product model here
        // would stop proving it — and `CartItem::product()` throws when it is
        // unset, so any surface that reached for a product would fail loudly.
        $app['config']->set('cart.product_model', null);
        $app['config']->set('cart.company_model', null);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            ...$this->discoveredProviders(),
            // Named as well as dev-required. `PackageTestCase` boots a sibling's
            // manifest provider off `require-dev`, and this package declares the
            // domain there for exactly that reason — but this resource has no
            // tables to query without the migrations it loads and no policy
            // without the gates it binds, so the dependency is stated here too
            // rather than left to a manifest key.
            CartServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }

    /**
     * Every `extra.laravel.providers` entry in the installed tree.
     *
     * Sibling Liberu modules are unaffected: their manifests declare that array
     * empty precisely so installation never implies boot, so this picks up the
     * framework packages and nothing else.
     *
     * @return array<int, class-string>
     */
    private function discoveredProviders(): array
    {
        $installed = json_decode(
            (string) file_get_contents($this->packageRoot().'/vendor/composer/installed.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $providers = [];

        foreach ($installed['packages'] ?? [] as $package) {
            foreach ((array) ($package['extra']['laravel']['providers'] ?? []) as $provider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
