<?php

namespace Liberu\Ecommerce\Cart\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The team the panel user is working in.
 *
 * The same column `CartPolicy::staffFor()` reads, deliberately: a list scoped by
 * one rule and authorized by another shows rows every row action then refuses,
 * which reads as a broken panel rather than as a denied one.
 *
 * Read off the actor rather than off `Filament::getTenant()` because this package
 * does not require the panel to be tenant-aware — an application may attach the
 * plugin to a panel with no tenancy at all, and a null tenant there would
 * silently widen the scope to every merchant's carts.
 *
 * Not the user model: no package may name the application's. `getAttribute()` on
 * `Model` is as far as this goes, and a guard that is not one answers null.
 */
final class PanelTeam
{
    public static function id(): ?int
    {
        $actor = Auth::user();

        $teamId = $actor instanceof Model ? $actor->getAttribute('current_team_id') : null;

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * Narrow a query to the actor's team, on a table that carries `team_id`.
     *
     * The null case is a `whereRaw('1 = 0')` rather than `where('team_id', null)`:
     * the query builder turns a null binding into `is null`, which would list
     * precisely the unowned carts `CartPolicy` denies every action on. On this
     * table that is not a rare edge — `team_id` is nullable, unconstrained, and
     * written only when whatever created the cart knew a team, so an
     * `is null` scope is a plausible majority of the rows rather than a handful.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $column = 'team_id'): Builder
    {
        $teamId = self::id();

        return $query->when(
            $teamId === null,
            fn (Builder $scoped) => $scoped->whereRaw('1 = 0'),
            fn (Builder $scoped) => $scoped->where($column, $teamId),
        );
    }
}
