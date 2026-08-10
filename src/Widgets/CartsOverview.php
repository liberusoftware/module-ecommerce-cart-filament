<?php

namespace Liberu\Ecommerce\Cart\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Cart\Models\Cart;

/**
 * How much of this merchant's shopping is sitting unfinished.
 *
 * Three numbers, and the middle one is the reason the widget exists: an active
 * cart nobody has touched inside the deployment's window is a shopper who walked
 * away, and it is the population an abandoned-cart campaign is built from.
 *
 * The third counts active carts whose stored totals no longer match their
 * revision. That is not a fault — recalculation is explicit by design — but a
 * deployment where it never falls is a deployment whose surfaces are showing
 * shoppers numbers from before their last click, and this is the only place that
 * shows up as a number rather than as a support ticket.
 */
class CartsOverview extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return PanelTeam::id() !== null;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $days = (int) config('cart.guest_cart_ttl_days', 30);
        $cutoff = now()->subDays($days);

        $active = PanelTeam::scope(Cart::query())->where('status', CartStatus::Active->value);

        $stale = $active->clone()
            // The same expression `CartQuery::stale()` uses, including the
            // fallback: a cart created and never filled is the most abandoned
            // kind there is, and one measured on `updated_at` would look alive
            // because a nightly job touched it.
            ->whereRaw('coalesce(last_activity_at, created_at) < ?', [$cutoff]);

        $outOfDate = $active->clone()->where(
            fn (Builder $query): Builder => $query
                ->whereNull('recalculated_revision')
                ->orWhereColumn('recalculated_revision', '!=', 'revision'),
        );

        return [
            Stat::make('Active carts', (string) $active->clone()->count())
                ->description('shopping nobody has finished'),
            Stat::make('Gone quiet', (string) $stale->count())
                ->description('untouched for over '.$days.' days, so a sign-in will not merge them'),
            Stat::make('Totals out of date', (string) $outOfDate->count())
                ->description('changed since they were last recalculated'),
        ];
    }
}
