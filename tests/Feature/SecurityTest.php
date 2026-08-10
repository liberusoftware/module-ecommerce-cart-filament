<?php

use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ListCarts;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ViewCart;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Cart\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\Ecommerce\Cart\Models\CartItem;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('answers no team rather than every team when nobody is signed in', function () {
    expect(PanelTeam::id())->toBeNull()
        ->and(CartResource::getEloquentQuery()->count())->toBe(0);
});

it('never lists the orphan carts a null team id would match', function () {
    $this->actingAs(TestUser::factory()->create());

    Cart::factory()->create();
    Cart::factory()->create();

    // `where('team_id', null)` compiles to `is null`, so an actor with no team
    // would be shown precisely the carts nobody may touch. On this table that is
    // not an edge case: `team_id` is nullable and unconstrained, and every cart a
    // storefront created without knowing a team has it null. The guard is an
    // explicit `whereRaw('1 = 0')`.
    expect(CartResource::getEloquentQuery()->count())->toBe(0);
});

it('refuses an unowned cart to the team that can see it in no list', function () {
    $this->actorForTeam(7);

    $orphan = Cart::factory()->create();

    expect(CartResource::getEloquentQuery()->count())->toBe(0);

    Livewire::test(ListCarts::class)
        ->assertCanNotSeeTableRecords([$orphan]);
});

it('keeps another team\'s carts out of the list and out of reach', function () {
    $this->actorForTeam(7);

    $mine = Cart::factory()->ownedBy(7)->create();
    $theirs = Cart::factory()->ownedBy(9)->create();

    Livewire::test(ListCarts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(CartResource::canView($theirs))->toBeFalse()
        ->and(CartResource::canView($mine))->toBeTrue();
});

/**
 * The rule that has leaked twice, asserted where it bites.
 *
 * `CartPolicy` publishes `viewAny`, `view`, `create`, `update`, `delete` and
 * `updateLine`. Filament's `get_authorization_response()` returns **allow** when a
 * policy exists but has no method for the ability asked about, so `deleteAny`,
 * `forceDelete`, `forceDeleteAny`, `restore`, `restoreAny`, `replicate` and
 * `reorder` are all permitted by default. `create`, `update` and `delete` the
 * policy *does* answer, and answers yes for the actor below — this package
 * refuses them anyway, which is its own decision and not the domain's.
 *
 * The actor here is the one the policy is most generous to: staff on the cart's
 * own team, on a live cart. If any of these is true for them it is true for
 * everybody who can reach the screen.
 */
it('answers every ability the policy does not publish, and overrides three it does', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();

    // The policy says yes to both of these for this actor …
    expect(Gate::allows('update', $cart))->toBeTrue()
        ->and(Gate::allows('delete', $cart))->toBeTrue();

    // … and the resource says no anyway.
    expect(CartResource::canCreate())->toBeFalse()
        ->and(CartResource::canEdit($cart))->toBeFalse()
        ->and(CartResource::canDelete($cart))->toBeFalse()
        // Unanswered by the policy. Permissive without these overrides.
        ->and(CartResource::canDeleteAny())->toBeFalse()
        ->and(CartResource::canForceDelete($cart))->toBeFalse()
        ->and(CartResource::canForceDeleteAny())->toBeFalse()
        ->and(CartResource::canRestore($cart))->toBeFalse()
        ->and(CartResource::canRestoreAny())->toBeFalse()
        ->and(CartResource::canReplicate($cart))->toBeFalse()
        ->and(CartResource::canReorder())->toBeFalse();

    // The two the package does answer yes to, so the restatement is visibly a
    // decision rather than a copy-paste of `false`.
    expect(CartResource::canViewAny())->toBeTrue()
        ->and(CartResource::canView($cart))->toBeTrue();
});

it('never renders a guest token, on any page', function () {
    $this->actorForTeam(7);

    $token = 'guest-token-1d4f8c2b-never-render-me';
    $cart = Cart::factory()->ownedBy(7)->forGuest($token)->create();
    CartItem::factory()->for($cart)->create();

    // Anything holding the token can be handed the cart by the surface that
    // issued it. A support screen is shared, screenshotted and pasted into
    // tickets, so a token on one is a way of taking over somebody's basket.
    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertSee('Guest')
        ->assertDontSee($token);

    Livewire::test(ViewCart::class, ['record' => $cart->getRouteKey()])
        ->assertOk()
        ->assertDontSee($token);
});

it('holds the lines read-only, whatever the panel is configured to do', function () {
    $this->actorForTeam(7);

    $cart = Cart::factory()->ownedBy(7)->create();
    CartItem::factory()->for($cart)->create();

    $manager = Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $cart,
        'pageClass' => ViewCart::class,
    ])->assertOk();

    /** @var LinesRelationManager $instance */
    $instance = $manager->instance();

    // `isReadOnly()` is what Filament consults *before* any policy for every
    // mutating default action, so one true here is the whole denial.
    expect($instance->isReadOnly())->toBeTrue();

    // Asserted through the resolver Filament actually calls, rather than by
    // trusting the flag: an action absent from this table today is an action a
    // later release could add, and the answer must be no before it is asked for.
    $actions = [
        CreateAction::make(),
        EditAction::make(),
        DeleteAction::make(),
        DeleteBulkAction::make(),
        AttachAction::make(),
        DetachAction::make(),
        AssociateAction::make(),
        ReplicateAction::make(),
        RestoreAction::make(),
        ForceDeleteAction::make(),
    ];

    foreach ($actions as $action) {
        expect($instance->getDefaultActionAuthorizationResponse($action)?->allowed())
            ->toBeFalse($action::class.' must be denied on a shopper\'s cart lines.');
    }
});

it('shows a cart\'s lines only to somebody who may see the cart', function () {
    $this->actorForTeam(7);

    $mine = Cart::factory()->ownedBy(7)->create();
    $theirs = Cart::factory()->ownedBy(9)->create();
    $orphan = Cart::factory()->create();

    expect(LinesRelationManager::canViewForRecord($mine, ViewCart::class))->toBeTrue()
        ->and(LinesRelationManager::canViewForRecord($theirs, ViewCart::class))->toBeFalse()
        // Deliberately stricter than the read scope has to be: a cart belonging
        // to no team is nobody's, and `CartPolicy::staffFor()` says so.
        ->and(LinesRelationManager::canViewForRecord($orphan, ViewCart::class))->toBeFalse();
});

it('offers neither write on a cart that is already finished with', function () {
    $this->actorForTeam(7);

    // Terminal, stale, and with totals that were never computed — every
    // precondition for both actions except the one that matters.
    $converted = Cart::factory()->ownedBy(7)->stale()->status(CartStatus::Converted)->create();

    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertActionHidden(TestAction::make('recalculate')->table($converted))
        ->assertActionHidden(TestAction::make('abandon')->table($converted));
});

it('will not offer to end a cart the shopper is still using', function () {
    $this->actorForTeam(7);

    $live = Cart::factory()->ownedBy(7)->create();

    // Stricter than `CartPolicy::update()`, deliberately. Staleness is the
    // domain's own measure of a shopper having walked away; a cart being filled
    // in another tab is not staff's to end, and a button on one makes ending it a
    // mis-click.
    Livewire::test(ListCarts::class)
        ->assertOk()
        ->assertActionHidden(TestAction::make('abandon')->table($live));
});

it('filters by owner with whereNotNull rather than a null binding', function () {
    $this->actorForTeam(7);

    $account = Cart::factory()->ownedBy(7)->forUser(4)->create();
    $guest = Cart::factory()->ownedBy(7)->forGuest()->create();
    $company = Cart::factory()->ownedBy(7)->forCompany(11)->create();

    // Each of the three identity columns is null on two thirds of this table by
    // design, so a filter written `where('guest_token', null)` would answer
    // "guest" with every account and company cart in the deployment.
    Livewire::test(ListCarts::class)
        ->filterTable('owner', 'guest')
        ->assertCanSeeTableRecords([$guest])
        ->assertCanNotSeeTableRecords([$account, $company]);
});
