<?php

use Liberu\Ecommerce\Cart\Actions\AddLine;
use Liberu\Ecommerce\Cart\Exceptions\UnknownProductModel;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ListCarts;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ViewCart;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\Ecommerce\Cart\Models\CartItem;
use Liberu\Ecommerce\Cart\Values\Money;
use Livewire\Livewire;

/*
 * The wave-4 boundary, proved rather than asserted.
 *
 * Cart does not import Checkout, Catalog, Pricing or Inventory Ledger, and
 * neither does this package. Everything that crosses is an identifier or a value
 * already resolved: a product id, a variant id, an amount in integer minor units,
 * a currency code.
 *
 * The whole of this suite already runs with no catalogue installed and
 * `cart.product_model` deliberately unset — see `tests/TestCase.php`. What is
 * left to prove is that the panel renders anyway, which is where a presentation
 * package would most plausibly reach for a product name.
 */

it('renders a cart whose product ids nothing in the database has heard of', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();

    // There is no products table in this application at all, let alone a row with
    // this id. A cart line holding one is a supported state and not a broken one:
    // the name, SKU and unit price on the line are what the shopper was shown,
    // copied at the moment they chose it.
    app(AddLine::class)->handle($cart, 987654321, 'Waxed Jacket', Money::of(1999, 'GBP'), 2, sku: 'JKT-001');

    Livewire::test(ViewCart::class, ['record' => $cart->getRouteKey()])
        ->assertOk()
        ->assertSee('19.99 GBP');

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $cart,
        'pageClass' => ViewCart::class,
    ])
        ->assertOk()
        ->assertSee('Waxed Jacket')
        ->assertSee('987654321');

    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$cart]);
});

it('never dereferences a product, so an unconfigured product model is never reached', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    $line = CartItem::factory()->for($cart)->create();

    // The domain's own escape hatch throws rather than guessing at a class name,
    // and every deployment running the cart without a catalogue leaves it unset.
    // A column that had reached for it would have failed above rather than here.
    expect(fn () => $line->product())->toThrow(UnknownProductModel::class);
});

it('imports no sibling ecommerce module anywhere in src', function () {
    $src = dirname(__DIR__, 2).'/src';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

    $offenders = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // `Liberu\Ecommerce\Cart\` is this package's own domain and is exactly
        // what it exists to present. Anything else under `Liberu\Ecommerce\` is a
        // sibling module, and a presentation package reaching for one couples two
        // domains through the panel — which is the boundary breach nobody would
        // look for in a resource file.
        if (preg_match('/use\s+Liberu\\\\Ecommerce\\\\(?!Cart\\\\)/', $source) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
