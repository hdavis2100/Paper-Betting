# Betleague

Betleague is a PHP/MySQL web application that simulates a real-money sportsbook. It ingests live odds from [TheOddsAPI](https://theoddsapi.com/), lets users place wagers with virtual currency, and tracks results with full wallet accounting, notifications, and analytics.

**Link:** https://BetLeagueSports.com

**Demo Login (For recruiters, employers, or anyone interested):** Username=demouser, Password=demouser

This is a hobby project I used to practice:

- Modeling betting data and account balances in a relational database
- Talking to a third party API on a schedule
- Building small admin style pages for analytics and search

---

## Main features

- **Upcoming events hub.** Browse major sports, see upcoming matchups, and drill into a single event to view current markets and prices.
- **Paper betting flow.** Place bets with virtual currency, check open tickets, and watch them settle as scores come in.
- **Wallet and history.** Each user has a wallet balance, transaction history, and a list of settled and unsettled bets.
- **Basic analytics.** Personal dashboard shows bankroll, past profit and loss, and simple win or loss stats per user.
- **Social view.** Public profiles and a leaderboard ordered by profit so users can compare results.
- **Price tracking.** Track specific odds, set alert thresholds, and view a feed of price notifications.
- **Self checking schema.** On each request the app makes sure the required tables, columns, and indexes exist so deployments do not depend on manual migrations.

---

## Tech stack

- **Language and runtime:** PHP 8, PDO
- **Database:** MySQL or MariaDB
- **Frontend:** Server rendered PHP templates using Bootstrap and a bit of vanilla JavaScript
- **Charts:** Simple charts on the dashboard using Chart.js
- **External API:** TheOddsAPI for odds and scores

---

## Application structure

- `public/`  
  HTTP entry points and pages such as:
  - `index.php` user dashboard
  - `events.php` upcoming events
  - `bet.php` place a new bet
  - `my_bets.php` bet history
  - `leaderboard.php` public leaderboard
  - `tracked.php` tracked odds
  - `notifications.php` notifications feed
  - `login.php`, `register.php`, `logout.php` auth pages

- `src/`  
  Core application code and scripts:
  - `bootstrap.php` session setup, shared helpers, schema check
  - `db.php` database connection using a secure config file
  - `schema.php` helper functions that create or alter tables and indexes at runtime
  - `tracking.php` odds conversion utilities and tracking helpers
  - `http.php` wrapper for TheOddsAPI calls
  - `fetch_all_catalog.php` import of sports, events, and markets from TheOddsAPI
  - `fetch_odds.php` periodic odds refresh
  - `settle_bets1.php` settlement pass that marks bets won or lost and updates wallets
  - `prune_*.php` scripts used to clean up stale data

---

## Data model

The main tables are:

- **users**  
  Accounts and profile settings, including privacy flags for public profiles.

- **wallets** and **wallet_transactions**  
  Wallet balance per user and a ledger for every change to that balance. Bets and settlements go through this layer so the history is auditable.

- **events**  
  Upcoming and recent fixtures imported from TheOddsAPI. Each row tracks sport, teams, and kickoff time.

- **odds**  
  Market and outcome data per event, including bookmaker, market type, outcome, decimal odds and any line value.

- **bets**  
  Bets placed by users. Stores market, outcome, stake, potential return, current status, and timestamps.

- **tracked_odds**  
  User defined price alerts for particular event, market, and outcome combinations.

- **notifications**  
  Stored notifications for things like triggered price alerts or other account messages.

Full text indexes are used in a few places to speed up user search and basic discovery.

---

## Odds and settlement workflow

The app uses a couple of scripts and jobs to keep odds fresh and settle bets.

1. **Catalog import**  
   `src/fetch_all_catalog.php` pulls sports and events from TheOddsAPI and inserts or updates rows in the `events` table. It can be limited to certain sports, regions, or markets by CLI flags.

2. **Odds refresh**  
   `src/fetch_odds.php` fetches current odds for active events, filters out unwanted markets, and upserts into the `odds` table. It reuses the same schema helper so it can safely run as the database evolves.

3. **Bet settlement**  
   `src/settle_bets1.php` hits TheOddsAPI scores endpoint, figures out final results for recent events, and marks related bets as won, lost, or void. It credits or debits the user wallet through `wallet_transactions` so every change is recorded.

4. **Pruning**  
   The `prune_*` scripts remove stale odds and events to keep the database small and queries fast.

5. **Tracking and alerts**  
   Code in `tracking.php` and related helpers convert odds between decimal and American formats, record tracked odds, and store notifications when alerts trigger.

---

## Running the app locally

The repository expects a small PHP config file that holds database and API credentials.

1. **Create a config file**

   By default `src/db.php` and the CLI scripts load:

   ```php
   /var/www/secure_config/sportsbet_config.php
