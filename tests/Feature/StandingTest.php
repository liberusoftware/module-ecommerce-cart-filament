<?php

use Carbon\CarbonImmutable;
use Liberu\Ecommerce\Cart\Actions\AddLine;
use Liberu\Ecommerce\Cart\Actions\RecalculateCart;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Filament\Support\Amount;
use Liberu\Ecommerce\Cart\Filament\Support\CartStanding;
use Liberu\Ecommerce\Cart\Filament\Support\Owner;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\Ecommerce\Cart\Values\Money;

/**
 * `CartStanding` decomposes rules the domain also states, so the only thing that
 * matters is that the two never disagree. This asserts it across the matrix
 * rather than across the handful somebody thought of: a release that changes
 * `Cart::isStale()` or `Cart::totalsAreCurrent()` and forgets this file fails a
 * build instead of the panel quietly lying about somebody's money.
 */
it('agrees with the model about staleness and about the totals', function (CartStatus $status, bool $stale, bool $recalculate) {
    $cart = Cart::factory()->ownedBy(7)->create();

    app(AddLine::class)->handle($cart, 900201, 'Waxed Jacket', Money::of(1999, 'GBP'));

    if ($recalculate) {
        app(RecalculateCart::class)->handle($cart);
    }

    if ($stale) {
        $cart->forceFill(['last_activity_at' => now()->subDays((int) config('cart.guest_cart_ttl_days') + 1)])->save();
    }

    // Written after everything else, because the domain's actions refuse a
    // terminal cart and this matrix needs the terminal rows too.
    $cart->forceFill(['status' => $status->value])->save();
    $cart->refresh();

    $standing = CartStanding::of($cart);

    expect($standing->status)->toBe($cart->status)
        ->and($standing->stale)->toBe($cart->isStale())
        ->and($standing->totalsAreCurrent)->toBe($cart->totalsAreCurrent())
        // A label is one word and a summary is at least one sentence. Neither may
        // be empty: an empty badge is a state carried by colour alone.
        ->and($standing->label())->not->toBe('')
        ->and($standing->color())->not->toBe('')
        ->and($standing->summary())->not->toBe('');
})->with(array_merge(...array_map(
    // Every status against every combination of "has the shopper been here
    // lately" and "were the totals ever computed" — sixteen rows, built rather
    // than typed out, so adding a fifth status covers it without an edit here.
    fn (CartStatus $status): array => [
        [$status, true, true],
        [$status, true, false],
        [$status, false, true],
        [$status, false, false],
    ],
    CartStatus::cases(),
)));

it('calls an active cart live only while the shopper is still using it', function () {
    $fresh = Cart::factory()->ownedBy(7)->create();
    $forgotten = Cart::factory()->ownedBy(7)->stale()->create();

    expect(CartStanding::of($fresh)->label())->toBe('Live')
        ->and(CartStanding::of($forgotten)->label())->toBe('Stale')
        ->and(CartStanding::of($forgotten)->summary())->toContain('signing in will not fold it into an account');
});

it('says of every terminal status what it means and that the rows are kept', function () {
    $expected = [
        CartStatus::Merged->value => ['Merged', 'record of what they had before signing in'],
        CartStatus::Converted->value => ['Converted', 'the order is the record now'],
        CartStatus::Abandoned->value => ['Abandoned', 'somebody can still be emailed about it'],
    ];

    foreach ($expected as $status => [$label, $phrase]) {
        $cart = Cart::factory()->ownedBy(7)->status(CartStatus::from($status))->create();

        expect(CartStanding::of($cart)->label())->toBe($label)
            ->and(CartStanding::of($cart)->summary())->toContain($phrase);
    }
});

it('takes the moment it is asked about, rather than always asking now', function () {
    $cart = Cart::factory()->ownedBy(7)->create();

    // A cart that is live today is stale from the point of view of two months
    // hence, and a helper that ignored the argument would report on a different
    // cart than the caller asked about.
    expect(CartStanding::of($cart)->stale)->toBeFalse()
        ->and(CartStanding::of($cart, CarbonImmutable::now()->addDays(60))->stale)->toBeTrue();
});

it('names the owner without ever handing over the guest token', function () {
    $account = Cart::factory()->forUser(4)->create();
    $company = Cart::factory()->forCompany(11)->create();
    $guest = Cart::factory()->forGuest('a-token-nobody-should-read')->create();

    expect(Owner::describe($account))->toBe('Account #4')
        ->and(Owner::describe($company))->toBe('Company #11')
        ->and(Owner::describe($guest))->toBe('Guest')
        ->and(Owner::kind($account))->toBe('Account')
        ->and(Owner::kind($company))->toBe('Company')
        ->and(Owner::kind($guest))->toBe('Guest');
});

it('formats an amount by the currency\'s own exponent', function () {
    // Three exponents, three answers, and one of them is why this goes through
    // `Money` rather than dividing by a hundred: a yen amount divided by 100 is a
    // basket a hundred times too cheap, and a dinar amount is ten times too dear.
    expect(Amount::format(1999, 'GBP'))->toBe('19.99 GBP')
        ->and(Amount::format(3000, 'JPY'))->toBe('3000 JPY')
        ->and(Amount::format(1999, 'BHD'))->toBe('1.999 BHD')
        ->and(Amount::format(0, 'GBP'))->toBe('0.00 GBP')
        ->and(Amount::format(-500, 'GBP'))->toBe('-5.00 GBP');
});
