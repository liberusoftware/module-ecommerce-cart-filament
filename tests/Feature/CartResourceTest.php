<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Cart\Actions\AddLine;
use Liberu\Ecommerce\Cart\Actions\ApplyDiscount;
use Liberu\Ecommerce\Cart\Actions\RecalculateCart;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ListCarts;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ViewCart;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\Ecommerce\Cart\Models\CartItem;
use Liberu\Ecommerce\Cart\Values\Money;
use Livewire\Livewire;

it('lists a team\'s carts with what each is worth', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->forUser(4)->create();
    // 2 × 19.99 = 39.98, and 1999 rather than 1998: the domain converts by string
    // arithmetic, and `(int) (19.99 * 100)` is 1998.
    app(AddLine::class)->handle($cart, 900001, 'Waxed Jacket', Money::fromDecimal('19.99', 'GBP'), 2);
    app(RecalculateCart::class)->handle($cart);

    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$cart])
        ->assertSee('Account #4')
        ->assertSee('39.98 GBP');
});

it('reads a cart\'s amounts back in the currency it is denominated in', function () {
    $this->actorForTeam(7);

    // JPY has an exponent of 0. A view that divided by 100 would show a shopper's
    // 3,000 yen basket as 30.00 — the mistake a hardcoded formatter makes and a
    // currency-aware one cannot.
    $cart = Cart::factory()->ownedBy(7)->currency('JPY')->create();
    app(AddLine::class)->handle($cart, 900002, 'Enamel Pin', Money::of(3000, 'JPY'), 1);
    app(RecalculateCart::class)->handle($cart);

    Livewire::test(ViewCart::class, ['record' => $cart->getRouteKey()])
        ->assertOk()
        ->assertSee('3000 JPY');
});

it('shows the lines underneath, with the sentinel variant read as none', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    app(AddLine::class)->handle($cart, 900003, 'Waxed Jacket', Money::of(1999, 'GBP'), 2, sku: 'JKT-001');

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $cart,
        'pageClass' => ViewCart::class,
    ])
        ->assertOk()
        ->assertSee('Waxed Jacket')
        ->assertSee('JKT-001')
        // `variant_id` is `0` for "no variant", never null — SQL treats NULLs as
        // distinct in a unique index, so a nullable column would let one product
        // be added to one cart any number of times.
        ->assertSee('None');
});

it('recalculates a cart\'s totals without touching a line', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    app(AddLine::class)->handle($cart, 900004, 'Waxed Jacket', Money::of(2000, 'GBP'), 3);
    app(ApplyDiscount::class)->handle($cart, Money::of(500, 'GBP'), 'Launch');

    expect($cart->refresh()->totalsAreCurrent())->toBeFalse();

    Livewire::test(ListCarts::class)
        ->callAction(TestAction::make('recalculate')->table($cart));

    $cart->refresh();

    expect($cart->totalsAreCurrent())->toBeTrue()
        ->and($cart->subtotal_minor)->toBe(6000)
        // No tax and no shipping: both are inputs the domain refuses to invent,
        // and this package has no more idea of a jurisdiction or a carrier.
        ->and($cart->tax_minor)->toBe(0)
        ->and($cart->shipping_minor)->toBe(0)
        ->and($cart->total_minor)->toBe(5500)
        // The lines are untouched, other than being told their share of the
        // discount.
        ->and($cart->items()->sum('quantity'))->toBe(3)
        ->and($cart->items()->sum('discount_minor'))->toBe(500);
});

it('stops offering to recalculate once the totals are current', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    app(AddLine::class)->handle($cart, 900005, 'Waxed Jacket', Money::of(2000, 'GBP'));
    app(RecalculateCart::class)->handle($cart);

    // A button that reports success having changed nothing teaches an operator to
    // stop reading the notification.
    Livewire::test(ListCarts::class)
        ->assertActionHidden(TestAction::make('recalculate')->table($cart->refresh()));
});

it('ends a cart the shopper walked away from, and keeps every row', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->stale()->create();
    CartItem::factory()->for($cart)->count(2)->create();

    Livewire::test(ViewCart::class, ['record' => $cart->getRouteKey()])
        ->assertOk()
        ->callAction('abandon');

    expect($cart->refresh()->status)->toBe(CartStatus::Abandoned)
        // Terminal and kept. Deleting instead would be a cart nobody can be
        // emailed about and a basket support cannot explain the loss of.
        ->and($cart->items()->count())->toBe(2)
        ->and(Cart::query()->whereKey($cart->getKey())->exists())->toBeTrue();
});

it('offers both writes on the view page of a stale cart', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->stale()->create();
    CartItem::factory()->for($cart)->create();

    Livewire::test(ViewCart::class, ['record' => $cart->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('recalculate')
        ->assertActionVisible('abandon');
});

it('filters carts by status', function () {
    $this->actorForTeam(7);

    $active = Cart::factory()->ownedBy(7)->create();
    $converted = Cart::factory()->ownedBy(7)->status(CartStatus::Converted)->create();

    Livewire::test(ListCarts::class)
        ->filterTable('status', CartStatus::Converted->value)
        ->assertCanSeeTableRecords([$converted])
        ->assertCanNotSeeTableRecords([$active]);
});

it('filters carts by whether their totals can be believed', function () {
    $this->actorForTeam(7);

    $current = Cart::factory()->ownedBy(7)->create();
    app(RecalculateCart::class)->handle($current);

    $outOfDate = Cart::factory()->ownedBy(7)->create();
    app(AddLine::class)->handle($outOfDate, 900006, 'Waxed Jacket', Money::of(2000, 'GBP'));

    Livewire::test(ListCarts::class)
        ->filterTable('totals', true)
        ->assertCanSeeTableRecords([$outOfDate])
        ->assertCanNotSeeTableRecords([$current->refresh()]);
});

it('names every status the domain has, so a filter can reach all of them', function () {
    expect(CartResource::statusOptions())->toBe([
        'active' => 'Active',
        'merged' => 'Merged',
        'converted' => 'Converted',
        'abandoned' => 'Abandoned',
    ]);
});
