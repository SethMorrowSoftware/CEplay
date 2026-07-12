<?php
/**
 * Reporting helpers shared across the analytics/games endpoints.
 */

require_once __DIR__ . '/db.php';

class Reporting {
    /**
     * The set of game IDs that count as "redemption" for payout math
     * (map of game_id => true).
     *
     * Preferred source of truth: the venue's redemption grouping. Operators
     * classify their ticket-dispensing games either as a CenterEdge game
     * category or as a pause group; we match whichever is named
     * `redemption_group_name` (config, default "Redemption", case-insensitive)
     * and take every game in it. Non-redemption games (rides, cages,
     * attractions) are therefore excluded from the payout denominator even if
     * they occasionally dispense a courtesy ticket — which is exactly what was
     * dragging the venue payout % artificially low under the old heuristic.
     *
     * The grouping is then narrowed to games with an actual ticket history: a
     * game can sit in the Redemption grouping yet never dispense tickets (a
     * non-ticket game grouped only for pausing, or a misconfiguration). Its
     * points would otherwise pad the payout denominator with no matching
     * tickets in the numerator, dragging the % down. If narrowing would empty
     * the set (no grouped game has ticket history at all), the full grouping is
     * kept rather than reporting zero redemption games.
     *
     * Fallback: if no such category/group exists (or it contains no games),
     * fall back to the data-driven rule — a game that has EVER dispensed a
     * ticket — so the metric still works on venues without the grouping.
     *
     * @return array<string,bool>
     */
    public static function redemptionGameIds(): array {
        $name = trim((string)(DB::getConfig('redemption_group_name') ?: 'Redemption'));
        $set = [];

        if ($name !== '') {
            $needle = self::lower($name);

            // Category IDs whose name matches, from every place names live:
            // the cached CenterEdge categories payload and any pause-group
            // category links (which store the name locally).
            $catIds = [];
            $rawCats = DB::getConfig('cache_categories');
            if ($rawCats) {
                $decoded = json_decode($rawCats, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $c) {
                        if (is_array($c) && isset($c['id'], $c['name'])
                            && self::lower((string)$c['name']) === $needle) {
                            $catIds[(string)$c['id']] = true;
                        }
                    }
                }
            }
            foreach (DB::query('SELECT DISTINCT category_id, category_name FROM pause_group_categories') as $pc) {
                if (self::lower((string)($pc['category_name'] ?? '')) === $needle) {
                    $catIds[(string)$pc['category_id']] = true;
                }
            }

            // Games belonging to any matching category.
            if (!empty($catIds)) {
                self::addGamesInCategories($set, $catIds);
            }

            // A pause group named to match: its explicitly-listed games plus
            // any games in the categories it targets.
            foreach (DB::query('SELECT id FROM pause_groups WHERE LOWER(name) = :p0', [$needle]) as $pg) {
                $gid = (int)$pg['id'];
                foreach (DB::query('SELECT game_id FROM pause_group_games WHERE pause_group_id = :p0', [$gid]) as $r) {
                    $g = (string)$r['game_id'];
                    if ($g !== '') $set[$g] = true;
                }
                $groupCats = [];
                foreach (DB::query('SELECT category_id FROM pause_group_categories WHERE pause_group_id = :p0', [$gid]) as $r) {
                    $groupCats[(string)$r['category_id']] = true;
                }
                if (!empty($groupCats)) {
                    self::addGamesInCategories($set, $groupCats);
                }
            }
        }

        if (!empty($set)) {
            // Narrow the grouping to games that have actually dispensed a
            // ticket, so point-only games in the category don't dilute the %.
            $withTickets = self::ticketHistoryGameIds();
            $filtered = [];
            foreach ($set as $gid => $_) {
                if (isset($withTickets[$gid])) {
                    $filtered[$gid] = true;
                }
            }
            // Keep the narrowed set when it has members; otherwise fall back to
            // the full grouping rather than reporting zero redemption games.
            return !empty($filtered) ? $filtered : $set;
        }

        // Fallback: no grouping configured — any game that ever dispensed a ticket.
        return self::ticketHistoryGameIds();
    }

    /**
     * Game IDs that have ever dispensed a redemption ticket — from the raw feed
     * (recent) unioned with the permanent daily rollup (full history, so a
     * game that dispensed tickets before the 30-day raw window still counts).
     *
     * @return array<string,bool>
     */
    public static function ticketHistoryGameIds(): array {
        $set = [];
        foreach (DB::query(
            'SELECT game_id FROM game_play_transactions WHERE redemption_tickets > 0 AND game_id != \'\'
             UNION
             SELECT game_id FROM game_daily_stats WHERE tickets > 0 AND game_id != \'\''
        ) as $r) {
            $set[(string)$r['game_id']] = true;
        }
        return $set;
    }

    /** Add every cached game whose category list intersects $catIds. */
    private static function addGamesInCategories(array &$set, array $catIds): void {
        foreach (DB::query('SELECT game_id, categories FROM game_state_cache') as $g) {
            $cats = json_decode((string)($g['categories'] ?? '[]'), true);
            if (!is_array($cats)) continue;
            foreach ($cats as $cid) {
                if (isset($catIds[(string)$cid])) {
                    $set[(string)$g['game_id']] = true;
                    break;
                }
            }
        }
    }

    private static function lower(string $s): string {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($s)) : strtolower(trim($s));
    }
}
