<?php

use Filament\Actions\Action;
use Liberu\Ecommerce\Cart\Actions\AddLine;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ListCarts;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ViewCart;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\Ecommerce\Cart\Values\Money;
use Livewire\Livewire;

// What a keyboard and a screen reader can get out of these surfaces. The two
// things worth a test are the two that regress silently: a state rendered as
// colour or as an icon with no words, and an action whose only affordance is an
// icon with no accessible name. Both look correct in a screenshot.

it('renders a cart\'s standing as a word and not only as a badge colour', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->stale()->create();

    // Asserted on the column's formatted state rather than on the page text: the
    // status filter puts every status name into the HTML regardless of what is in
    // the table, so `assertSee('Stale')` could pass on an empty list.
    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertTableColumnFormattedStateSet('standing', 'Stale', $cart);
});

it('says out loud when the money on the screen is out of date', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    app(AddLine::class)->handle($cart, 900101, 'Waxed Jacket', Money::of(1999, 'GBP'));

    // A total that was true two clicks ago looks exactly like one that is true
    // now. An amber badge says so to a sighted reader who knows the convention
    // and to nobody else; the sentence says it to everyone.
    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertSee('every amount shown here is out of date');

    Livewire::test(ViewCart::class, ['record' => $cart->refresh()->getRouteKey()])
        ->assertOk()
        ->assertSee('every amount shown here is out of date');
});

it('names the reason a terminal cart cannot be acted on', function () {
    $this->actorForTeam(7);

    $merged = Cart::factory()->ownedBy(7)->status(CartStatus::Merged)->create();

    // A row with no actions and no explanation reads as a broken panel. This one
    // says what happened to the cart and why its lines are still there.
    Livewire::test(ViewCart::class, ['record' => $merged->getRouteKey()])
        ->assertOk()
        ->assertSee('Folded into an account\'s cart when the shopper signed in');
});

it('says in words whether a line is taxed', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    app(AddLine::class)->handle($cart, 900102, 'Waxed Jacket', Money::of(1999, 'GBP'));
    app(AddLine::class)->handle($cart, 900103, 'Gift Card', Money::of(2500, 'GBP'), taxable: false);

    // A tick and a cross are indistinguishable to a screen reader and to a reader
    // who cannot separate the two colours they are drawn in.
    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $cart,
        'pageClass' => ViewCart::class,
    ])
        ->assertOk()
        ->assertSee('Taxed')
        ->assertSee('Not taxed');
});

it('gives every icon-bearing action an accessible name', function () {
    /** @var array<string, Action> $actions */
    $actions = [
        'recalculate' => CartResource::recalculateAction(),
        'abandon' => CartResource::abandonAction(),
    ];

    foreach ($actions as $name => $action) {
        // An action carrying an icon is an action Filament may render as an icon
        // button, and an icon button with no label is a control a screen reader
        // announces as "button".
        expect($action->getIcon())->not->toBeNull($name)
            ->and((string) $action->getLabel())->not->toBe('');
    }
});

it('says what a destructive confirmation is about to do', function () {
    $abandon = CartResource::abandonAction();

    expect((string) $abandon->getModalHeading())->toBe('Mark this cart abandoned')
        // "Are you sure?" is not a description. The thing worth saying is the
        // thing an operator is afraid of: that nothing is deleted.
        ->and((string) $abandon->getModalDescription())->toContain('Nothing is deleted');
});

it('heads computed columns with words rather than with their attribute names', function () {
    $this->actorForTeam(7);

    Cart::factory()->ownedBy(7)->create();

    // Filament humanises a column name into a heading when none is given, which
    // is how `store_id` becomes "Store id" and `last_activity_at` becomes "Last
    // activity at". The label is the only thing keeping them out.
    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertSee('Standing')
        ->assertSee('Shopper last active')
        ->assertDontSee('Last activity at');
});
