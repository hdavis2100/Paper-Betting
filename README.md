# Paper-Betting
Paper Betting

## Event visibility

The browsing and search pages only display events whose `commence_time`
is in the future. If you import historical fixtures for testing, they
will be hidden automatically once the scheduled start time passes. To
see NHL games (or any other sport) in the UI, ensure that your database
contains upcoming events with a `commence_time` that is later than the
current UTC time.

## Limiting odds to a single bookmaker

To ingest odds from only one bookmaker, add the following keys to
`/var/www/secure_config/sportsbet_config.php`:

```php
return [
    'odds_api_key' => '...',
    'preferred_bookmaker_key' => 'betmgm',      // API bookmaker key
    'preferred_bookmaker_title' => 'BetMGM',    // optional: match API title
    'preferred_bookmaker_label' => 'BetMGM',    // optional: UI label override
];
```

You can override any of those values at runtime with CLI arguments, for
example:

```
php src/fetch_odds.php sports=basketball_nba bookmaker_key=betmgm
php src/fetch_all_catalog.php bookmaker_key=betmgm bookmaker_label="BetMGM"
```

Both fetch scripts will skip odds from other bookmakers and log a
message whenever an event lacks prices from the preferred source.

To clean up existing odds that were imported from other bookmakers, run

```
php src/prune_bookmaker_odds.php
```

The script keeps rows whose `bookmaker` field matches either the
preferred key, title, or label and removes everything else. Pass explicit
overrides (for example `bookmaker_key=betmgm`) if you have not added the
config keys yet. Existing bets are safe to keep: wager tickets store the
decimal price that was used at placement time, so deleting non-preferred
odds rows does not retroactively change or void previously placed bets.

## Removing unwanted markets

If you no longer want to keep a market such as `h2h_lay`, you can purge
it (and its associated odds rows) without altering your schema:

```
php src/prune_markets.php             # defaults to removing h2h_lay
php src/prune_markets.php markets=h2h_lay,totals_alt
```

The script deletes matching entries from the `odds` table and, when
present, removes the market keys from the `markets` table. Future fetch
runs will also skip any market listed in the removal script's default
set, so the unwanted records do not return.

## Spreads and totals support

* Odds ingestion now records the line (point spread / total) alongside each price. When the `odds` table is missing the `line` column the application adds it automatically.
* The betslip records a wager's market and line, so spreads and totals can be placed from the browse page and settled correctly once scores arrive.
* Event listings and the bet form display American-formatted odds with the spread or total baked into the selection label, making it clear what number you are backing before you submit the ticket.
