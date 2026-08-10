<?php

namespace Liberu\Ecommerce\Cart\Filament\Resources\CartResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Cart\Filament\Support\Amount;
use Liberu\Ecommerce\Cart\Models\Cart;
use Liberu\Ecommerce\Cart\Models\CartItem;

/**
 * What is in the cart. Read, and nothing else.
 *
 * **There is no editable quantity here, and that is the decision this package is
 * really about.** A quantity box on a staff screen looks like a convenience and
 * is a write into a basket the shopper is about to pay from. The domain would
 * dispatch `LineQuantityChanged` for it, carrying the old number and the new,
 * with nothing anywhere recording that it was not the shopper who did it; the
 * next thing they see is a total they did not agree to. Removing a line is worse
 * — `RemoveLine` is a hard delete, and the line is gone with no record it existed.
 * The argument in full is in `docs/domain.md`.
 *
 * Two mechanisms enforce it, because one of them is a default:
 *
 * - {@see self::isReadOnly()} returns true unconditionally, which is what
 *   Filament consults *before* any policy for `CreateAction`, `EditAction`,
 *   `DeleteAction`, `AttachAction`, `DetachAction`, `AssociateAction`,
 *   `DissociateAction`, `ReplicateAction`, `RestoreAction`, `ForceDeleteAction`,
 *   every bulk equivalent, and reordering. One line, denied at the root, rather
 *   than an override per ability that a new Filament release could route round.
 * - the table below ships no header actions, no record actions and no bulk
 *   actions at all, so there is nothing for a reader to check the gate on.
 *
 * The second matters on its own, because `CartPolicy` serves `CartItem` as well
 * as `Cart` and its `view()`, `update()` and `delete()` are typed against
 * `Cart`. An action here that let Filament ask the gate about a `CartItem` would
 * not be denied — it would be a `TypeError` from inside the policy. `updateLine()`
 * is the one ability the domain publishes for a line, and this package asks for
 * none of it.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Lines';

    /**
     * Visible exactly as far as the cart is.
     *
     * Filament's default would call `authorize('viewAny', CartItem::class)`,
     * which `CartPolicy::viewAny()` answers with "does this actor work in a
     * team" — true for every member of staff, about every cart in the
     * deployment. The question worth asking is the one about *this* cart.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Cart && Gate::allows('view', $ownerRecord);
    }

    /**
     * Read-only, whatever the panel is configured to do.
     *
     * Filament's own default makes a relation manager read-only on a
     * `ViewRecord` page only when the panel opts in, and this package must not
     * depend on a host having opted in.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var Cart $cart */
        $cart = $this->getOwnerRecord();

        // Read once from the owner rather than through `$record->cart` per row:
        // every line in a cart is in the cart's currency by construction, and a
        // column reaching back through the relation would be a query per line for
        // a value that cannot differ.
        $currency = $cart->currency;

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                // The name the shopper was shown when they chose it, not one read
                // back out of a catalogue. Catalog is not a dependency of this
                // package or of the domain, and a line that renamed itself when a
                // merchant edited a product would be showing an operator
                // something the shopper never saw.
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('None')
                    ->searchable(),
                // A number, deliberately. See docs/domain.md — this package
                // resolves no product, so there is no name to show and no link to
                // follow.
                TextColumn::make('product_id')
                    ->label('Product'),
                TextColumn::make('variant')
                    ->label('Variant')
                    ->placeholder('None')
                    // `variantId()` rather than the column: `0` is this table's
                    // way of writing "no variant", and printing the sentinel would
                    // be an operator searching a catalogue for variant zero.
                    ->state(fn (CartItem $record): ?int => $record->variantId()),
                TextColumn::make('quantity')
                    ->label('Quantity'),
                TextColumn::make('unit_price')
                    ->label('Unit price')
                    ->state(fn (CartItem $record): string => Amount::format($record->unit_price_minor, $currency)),
                TextColumn::make('line_total')
                    ->label('Line total')
                    ->state(fn (CartItem $record): string => Amount::format($record->unit_price_minor * $record->quantity, $currency)),
                // The share of the cart discount the last recalculation allocated
                // to this line, pro-rata by line total. Stored rather than
                // derived, so what is shown is what was allocated.
                TextColumn::make('discount')
                    ->label('Discount share')
                    ->state(fn (CartItem $record): string => Amount::format($record->discount_minor, $currency)),
                // Words, not a tick. A tick is legible to a sighted reader and
                // silent to a screen reader, and the badge colour repeats what
                // the text says rather than carrying it.
                TextColumn::make('taxable')
                    ->label('Tax')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Taxed' : 'Not taxed'),
            ])
            ->defaultSort('id');
    }
}
