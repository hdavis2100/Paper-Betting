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

### Tracking API usage

Every script that talks to TheOddsAPI (`fetch_odds.php`,
`fetch_all_catalog.php`, and `settle_bets1.php`) prints the
`requests-used` and `requests-remaining` counters returned by the API so
you can monitor consumption on each run. The catalog fetcher always hits
the live endpoints to ensure newly posted odds are imported right away.

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

## Account management

* The dashboard at `index.php` now summarises each player's wallet balance, lifetime spending, net profit, win/loss record, and win rate.
* Use `/public/users.php` to search for other bettors and view their public profiles. Private profiles only reveal usernames and join dates until the owner makes them public again.
* The new settings page lets users toggle their profile visibility and permanently delete their account (along with its wallets and bets) after confirming their username.

## Price alerts and notifications

* Event pages include a **Set a price alert** form underneath the bet slip. Choose a market selection, enter your target odds (American format such as `+160` or decimal like `2.6`), and the system will watch for price updates.
* Visit `/public/tracked.php` or click the **Tracked events** button on your account overview to review and remove alerts. Each row shows the target odds, latest notification time, and a quick link back to the event.
* When odds meet or beat your target, the importer scripts create an entry in `/public/notifications.php`. Open that page (or use the navigation link) to view, mark as read, or clear alerts. Unread counts also surface in the global navigation bar.
