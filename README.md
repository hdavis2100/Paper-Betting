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
