<?php

use Filament\Facades\Filament;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Filament\CartPlugin;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;
use Liberu\Ecommerce\Cart\Filament\Widgets\CartsOverview;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('contributes its resource and widget to the panel that attaches it', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getPlugin(CartPlugin::make()->getId()))->toBeInstanceOf(CartPlugin::class)
        ->and($panel->getResources())->toContain(CartResource::class)
        ->and($panel->getWidgets())->toContain(CartsOverview::class);
});

it('declares in its manifest exactly the plugin it ships', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    $plugins = $manifest['presentation']['filament']['admin'];

    expect($plugins)->toBe([CartPlugin::class]);

    foreach ($plugins as $plugin) {
        expect(class_exists($plugin))->toBeTrue();
    }
});

/**
 * The plugin lists what it contributes rather than scanning `src/` for it, which
 * is what makes `filament:cache-components` a no-op worth running and a boot with
 * no filesystem walk. The cost of a list is that a class added later is simply
 * absent from the panel — no error, no missing-file warning, just a resource
 * nobody can reach. This is the test that turns that silence into a failure.
 */
it('registers exactly the resources and widgets the package ships', function () {
    $panel = Filament::getPanel('admin');
    $src = dirname(__DIR__, 2).'/src';

    $shipped = function (string $directory, string $namespace) use ($src): array {
        $classes = array_map(
            fn (string $path): string => $namespace.'\\'.basename($path, '.php'),
            (array) glob($src.'/'.$directory.'/*.php'),
        );

        sort($classes);

        return $classes;
    };

    $registered = function (array $classes): array {
        $ours = array_values(array_filter(
            $classes,
            fn (string $class): bool => str_starts_with($class, 'Liberu\\Ecommerce\\Cart\\Filament\\'),
        ));

        sort($ours);

        return $ours;
    };

    expect($registered($panel->getResources()))
        ->toBe($shipped('Resources', 'Liberu\\Ecommerce\\Cart\\Filament\\Resources'))
        ->and($registered($panel->getWidgets()))
        ->toBe($shipped('Widgets', 'Liberu\\Ecommerce\\Cart\\Filament\\Widgets'));
});

it('counts the shopping nobody has finished, for this team only', function () {
    $this->actorForTeam(7);

    Cart::factory()->ownedBy(7)->create();
    Cart::factory()->ownedBy(7)->stale()->create();
    Cart::factory()->ownedBy(7)->status(CartStatus::Converted)->create();
    // Another team's, and live. Counting it would put somebody else's shoppers
    // on this merchant's dashboard.
    Cart::factory()->ownedBy(9)->create();

    Livewire::test(CartsOverview::class)
        ->assertOk()
        ->assertSee('Active carts')
        ->assertSee('Gone quiet')
        ->assertSee('Totals out of date');
});

it('shows the widget to nobody working outside a team', function () {
    $this->actingAs(TestUser::factory()->create());

    expect(CartsOverview::canView())->toBeFalse();
});

it('registers no create page and no edit page for a shopper\'s cart', function () {
    // A route that does not exist is the cheapest denial there is, and the one a
    // future contributor cannot re-open by relaxing a policy.
    expect(array_keys(CartResource::getPages()))->toBe(['index', 'view'])
        ->and(CartResource::hasPage('create'))->toBeFalse()
        ->and(CartResource::hasPage('edit'))->toBeFalse();
});
