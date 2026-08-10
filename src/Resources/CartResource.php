<?php

namespace Liberu\Ecommerce\Cart\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Cart\Actions\MarkCartAbandoned;
use Liberu\Ecommerce\Cart\Actions\RecalculateCart;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Exceptions\CartNotActive;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ListCarts;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages\ViewCart;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Cart\Filament\Support\Amount;
use Liberu\Ecommerce\Cart\Filament\Support\CartStanding;
use Liberu\Ecommerce\Cart\Filament\Support\Owner;
use Liberu\Ecommerce\Cart\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Cart\Models\Cart;
use UnitEnum;

/**
 * Carts, for the people who have to answer for one.
 *
 * **This resource is read, plus two writes that change nothing the shopper
 * chose.** A cart is the shopper's own record of what they have picked out, and
 * every reason to put a form over one — fix a quantity, drop a line, take some
 * money off — is a reason to write into a basket somebody else is about to pay
 * from. The full argument, and everything staff deliberately may not do, is in
 * `docs/domain.md`. The two writes offered are {@see self::recalculateAction()},
 * which recomputes stored totals from lines nobody touched, and
 * {@see self::abandonAction()}, which ends a cart the shopper has visibly
 * forgotten and keeps every row.
 *
 * There is no create page and no edit page, so those two routes do not exist —
 * and the authorization overrides below refuse them a second time, at the class,
 * because a page is not the only thing that can reach an ability.
 */
class CartResource extends Resource
{
    protected static ?string $model = Cart::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Carts';

    /**
     * Filament's tenancy would scope through a relation resolving the
     * *application's* team model from configuration. This package does not
     * require the panel to be tenant-aware at all, so the scope is applied
     * against the `team_id` column instead — see {@see self::getEloquentQuery()}.
     */
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * Team-scoped, with the lines loaded.
     *
     * `items` is eager loaded because the list reports a line count, an item
     * count and a standing per row, and a column that fetched the relation to
     * find out would be a query per cart on a page of fifty.
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelTeam::scope(parent::getEloquentQuery())->with('items');
    }

    // ---------------------------------------------------------------------
    // Authorization
    //
    // `CartPolicy` publishes `viewAny`, `view`, `create`, `update`, `delete` and
    // `updateLine`. Filament asks for rather more than that — `deleteAny`,
    // `forceDelete`, `forceDeleteAny`, `restore`, `restoreAny`, `replicate` and
    // `reorder` — and its `get_authorization_response()` returns **allow** for an
    // ability a present policy has no method for. A model with no policy is
    // exposed rather than safe, and so is a model whose policy is silent on the
    // ability being asked about; the unanswered gate case is permissive in both
    // directions. That has produced a live leak twice in this fleet, which is why
    // every one of them is answered here rather than left to a default.
    //
    // `create`, `update` and `delete` **are** answered by the policy, and are
    // still forced false. That is not defensive duplication — it is this
    // package's own decision, stricter than the domain's, that staff do not make
    // a cart, edit a cart or destroy one. `tests/Feature/SecurityTest.php`
    // asserts all of it, including for an actor the policy would say yes to.
    // ---------------------------------------------------------------------

    /** Answered by `CartPolicy::viewAny()`: an actor working in a team. Restated so the yes is visibly a decision. */
    public static function canViewAny(): bool
    {
        return parent::canViewAny();
    }

    /** Answered by `CartPolicy::view()`: the cart's owner, or staff on its team. */
    public static function canView(Model $record): bool
    {
        return parent::canView($record);
    }

    /**
     * A cart is created *for* a shopper by the surface they are shopping on.
     *
     * `CartPolicy::create()` says yes to anybody signed in, and it is right to:
     * it is answering for a storefront. A staff-side create form would have to
     * invent an owner — a `user_id` typed into a box, or worse a guest token
     * minted by a panel — and a cart whose owner somebody guessed is a cart
     * reachable by the wrong person.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Nothing here edits a cart. See `docs/domain.md`. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Terminal carts are kept on purpose — a merged cart is the only record of
     * what the shopper had before signing in, and an abandoned one is what an
     * abandoned-cart job reads. Deleting is the one operation that destroys
     * exactly the evidence somebody opened this screen to look at.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Unanswered by the policy, and answered here. */
    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Unanswered by the policy, and answered here. Nothing in this domain soft-deletes. */
    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    /** Unanswered by the policy, and answered here. */
    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    /** Unanswered by the policy, and answered here. */
    public static function canRestore(Model $record): bool
    {
        return false;
    }

    /** Unanswered by the policy, and answered here. */
    public static function canRestoreAny(): bool
    {
        return false;
    }

    /**
     * Unanswered by the policy, and answered here.
     *
     * A replicated cart would be a second live basket for one owner in one
     * store, which is precisely the state `CartQuery::activeFor()` assumes
     * cannot happen.
     */
    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    /** Unanswered by the policy, and answered here. Carts have no order to be in. */
    public static function canReorder(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Cart')
                    ->sortable(),
                // The question an operator came with. Status, staleness and
                // whether the totals are current are three separate facts, and
                // this is what they add up to — including, in words, when the
                // money below it is out of date.
                TextColumn::make('standing')
                    ->label('Standing')
                    ->badge()
                    ->state(fn (Cart $record): string => CartStanding::of($record)->label())
                    ->color(fn (Cart $record): string => CartStanding::of($record)->color())
                    ->description(fn (Cart $record): string => CartStanding::of($record)->summary()),
                // Never the guest token. See Support\Owner.
                TextColumn::make('owner')
                    ->label('Owner')
                    ->state(fn (Cart $record): string => Owner::describe($record)),
                TextColumn::make('lines')
                    ->label('Lines')
                    ->state(fn (Cart $record): int => $record->items->count()),
                TextColumn::make('units')
                    ->label('Items')
                    ->state(fn (Cart $record): int => (int) $record->items->sum('quantity')),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (Cart $record): string => Amount::format($record->total_minor, $record->currency)),
                TextColumn::make('last_activity_at')
                    ->label('Shopper last active')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('store_id')
                    ->label('Store')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('owner')
                    ->label('Owner')
                    ->options([
                        'user' => 'Account',
                        'guest' => 'Guest',
                        'company' => 'Company',
                    ])
                    // `whereNotNull` on the one column that identity uses, never
                    // `where('user_id', null)` on the two it does not: a null
                    // binding compiles to `is null`, and each of these three
                    // columns is null on two thirds of the table by design. A
                    // filter written that way would answer "guest" with every
                    // account cart in the deployment.
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'user' => $query->whereNotNull('user_id'),
                        'guest' => $query->whereNotNull('guest_token'),
                        'company' => $query->whereNotNull('company_id'),
                        default => $query,
                    }),
                TernaryFilter::make('totals')
                    ->label('Totals')
                    ->placeholder('Any')
                    ->trueLabel('Out of date')
                    ->falseLabel('Current')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(
                            fn (Builder $stale): Builder => $stale
                                ->whereNull('recalculated_revision')
                                ->orWhereColumn('recalculated_revision', '!=', 'revision'),
                        ),
                        false: fn (Builder $query): Builder => $query
                            ->whereNotNull('recalculated_revision')
                            ->whereColumn('recalculated_revision', '=', 'revision'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                self::recalculateAction(),
                self::abandonAction(),
            ])
            // Newest first: somebody opening this screen is nearly always
            // looking at something that happened in the last few minutes.
            ->defaultSort('id', 'desc');
    }

    /**
     * The one surface in this package that reads the shopper's lines back to
     * them.
     *
     * Only the entries whose value is a decision or a currency amount; the
     * identity columns are summarised by {@see Owner::describe()} rather than
     * printed, so the guest token has nowhere to appear.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('standing')
                ->label('Standing')
                ->state(fn (Cart $record): string => CartStanding::of($record)->summary()),
            TextEntry::make('owner')
                ->label('Owner')
                ->state(fn (Cart $record): string => Owner::describe($record)),
            TextEntry::make('currency')
                ->label('Currency')
                ->helperText('Fixed when the cart was created. There is no exchange rate in this module.'),
            TextEntry::make('store_id')
                ->label('Store')
                ->placeholder('None'),
            TextEntry::make('subtotal')
                ->label('Subtotal')
                ->state(fn (Cart $record): string => Amount::format($record->subtotal_minor, $record->currency)),
            TextEntry::make('discount')
                ->label('Discount')
                ->state(fn (Cart $record): string => Amount::format($record->discount_minor, $record->currency))
                ->helperText('An amount worked out elsewhere and recorded here, spread pro-rata across every line.'),
            TextEntry::make('discount_label')
                ->label('Discount reason')
                ->placeholder('None'),
            TextEntry::make('tax')
                ->label('Tax')
                ->state(fn (Cart $record): string => Amount::format($record->tax_minor, $record->currency))
                ->helperText('An input. This module knows no jurisdiction and looks no rate up.'),
            TextEntry::make('shipping')
                ->label('Shipping')
                ->state(fn (Cart $record): string => Amount::format($record->shipping_minor, $record->currency))
                ->helperText('An input, for the same reason.'),
            TextEntry::make('total')
                ->label('Total')
                ->state(fn (Cart $record): string => Amount::format($record->total_minor, $record->currency)),
            TextEntry::make('recalculated_at')
                ->label('Totals computed')
                ->dateTime()
                ->placeholder('Never'),
            TextEntry::make('last_activity_at')
                ->label('Shopper last active')
                ->dateTime()
                ->placeholder('Never'),
            TextEntry::make('created_at')
                ->label('Started')
                ->dateTime(),
        ]);
    }

    /**
     * Recompute the stored totals from the lines as they stand.
     *
     * The domain makes recalculation explicit and idempotent: no observer fires
     * it, so a cart carries totals from before its last change until something
     * asks. That is the right trade — a cart page changing three lines in one
     * request would otherwise recalculate three times — and it leaves a support
     * agent looking at a number that was true earlier. This is how they fix that
     * without touching a single thing the shopper chose.
     *
     * Offered **only when the totals are actually out of date**. Running it on a
     * current cart is harmless and writes the same numbers, but a button that
     * reports success having changed nothing teaches an operator to stop reading
     * the notification.
     *
     * `EstimateInputs::none()` — no tax, no shipping. Both are inputs the domain
     * refuses to invent and this package has no more idea of a jurisdiction or a
     * carrier than the domain does. What this produces is the cart-page estimate:
     * subtotal, discount spread over the lines, and a total that is `subtotal -
     * discount`. It is documented as such in `docs/domain.md`, and it is why the
     * action is labelled for what it does rather than "refresh".
     */
    public static function recalculateAction(): Action
    {
        return Action::make('recalculate')
            ->label('Recalculate totals')
            ->icon('heroicon-o-calculator')
            ->requiresConfirmation()
            ->modalHeading('Recalculate this cart\'s totals')
            ->modalDescription('Recomputes the subtotal, the per-line discount shares and the total from the lines as they stand. It changes nothing the shopper chose, and it computes no tax and no shipping — both are inputs this module never invents.')
            ->modalSubmitActionLabel('Recalculate')
            ->visible(fn (Cart $record): bool => ! CartStanding::of($record)->totalsAreCurrent
                && Gate::allows('update', $record))
            ->action(function (Cart $record): void {
                try {
                    app(RecalculateCart::class)->handle($record);

                    Notification::make()
                        ->title('Totals recalculated')
                        ->success()
                        ->send();
                } catch (CartNotActive $exception) {
                    // The gate refuses a terminal cart, so this should be
                    // unreachable — but a cart converted in another tab between
                    // render and submit lands here, and losing that race deserves
                    // a sentence rather than a 500.
                    Notification::make()
                        ->title('Totals not recalculated')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * End a cart the shopper has visibly forgotten.
     *
     * Terminal, and the row and every line stay — that is the whole point of the
     * status. It is what an abandoned-cart campaign reads, and deleting instead
     * would be a cart nobody can be emailed about.
     *
     * **Offered only on a cart that is already stale**, which is stricter than
     * `CartPolicy::update()` and deliberately so. Staleness is the domain's own
     * measure of a shopper having walked away, read off `last_activity_at`; a
     * cart somebody is filling in another tab right now is not staff's to end,
     * and offering the button on one makes ending it a mis-click. An operator who
     * genuinely needs to end a live cart has `MarkCartAbandoned` and a console.
     */
    public static function abandonAction(): Action
    {
        return Action::make('abandon')
            ->label('Mark abandoned')
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Mark this cart abandoned')
            ->modalDescription('The cart becomes terminal and the shopper\'s next visit starts a fresh one. Nothing is deleted: the lines stay, which is what an abandoned-cart campaign reads and what support reads when somebody asks where their basket went.')
            ->modalSubmitActionLabel('Mark abandoned')
            ->visible(fn (Cart $record): bool => CartStanding::of($record)->stale
                && $record->status === CartStatus::Active
                && Gate::allows('update', $record))
            ->action(function (Cart $record): void {
                try {
                    app(MarkCartAbandoned::class)->handle($record);

                    Notification::make()
                        ->title('Cart marked abandoned')
                        ->success()
                        ->send();
                } catch (CartNotActive $exception) {
                    Notification::make()
                        ->title('Cart not marked abandoned')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCarts::route('/'),
            'view' => ViewCart::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (CartStatus::cases() as $status) {
            $options[$status->value] = match ($status) {
                CartStatus::Active => 'Active',
                CartStatus::Merged => 'Merged',
                CartStatus::Converted => 'Converted',
                CartStatus::Abandoned => 'Abandoned',
            };
        }

        return $options;
    }
}
