# Betleague

Betleague is a PHP/MySQL web application that simulates a real-money sportsbook. It ingests live odds from [TheOddsAPI](https://theoddsapi.com/), lets users place wagers with virtual currency, and tracks results with full wallet accounting, notifications, and analytics.

## Link

**betleaguesports.com**

**Demo Login (For recruiters, employers, or anyone interested):** Username=demouser, Password=demouser

## Features

- **Upcoming event hub** – Browse major sports, view moneyline snapshots, and drill into full event markets (moneyline, spreads, totals) with American-style odds formatting.
- **Comprehensive bet flow** – Place wagers, monitor pending tickets, and settle results automatically from official score feeds.
- **Account analytics** – Personal dashboards highlight bankroll, profit/loss, win rate, wager distribution, and profit-over-time charts.
- **Social discovery** – Search for public users, inspect detailed profiles, and follow the global leaderboard ordered by net profit.
- **Price tracking & notifications** – Create odds alerts, receive in-app notifications when thresholds are met, and manage tracked events from a dedicated page.
- **Bookmaker controls** – Restrict imports to a preferred bookmaker, prune unwanted markets (such as `h2h_lay`), and monitor API usage per request.
- **Self-maintaining schema** – Every entry point ensures required tables/columns exist so upgrades deploy without manual migrations.

## Tech Stack

- **Backend:** PHP 8+, PDO (MySQL/MariaDB)
- **Database:** MySQL/MariaDB (InnoDB tables with foreign keys and full-text search)
- **Frontend:** Bootstrap 5, Chart.js, vanilla JS fetch/AJAX helpers
- **Integrations:** TheOddsAPI for odds & scores

## Product Highlights

- **Live betting experience:** Odds refresh from TheOddsAPI so the moneyline, spread, and total markets mimic a production sportsbook.
- **User-centric analytics:** Every account surfaces bankroll health, profit trends, and win/loss ratios with interactive visualizations.
- **Social layer:** Public profiles, global leaderboards, and search/auto-complete enable friendly competition while respecting privacy settings.
- **Actionable alerts:** Bettors can track specific price targets and receive notifications the moment a line moves into range.

## Architecture Snapshot

| Layer | Responsibilities |
| ----- | ---------------- |
| Web UI (`public/`) | PHP-rendered pages styled with Bootstrap 5 and progressive enhancement via vanilla JavaScript/Chart.js. |
| Application core (`src/bootstrap.php`) | Session wiring, dependency bootstrap, helper loading, and schema guard rails. |
| Odds ingestion (`src/fetch_odds.php`, `src/fetch_all_catalog.php`) | Poll TheOddsAPI, normalize bookmaker data, and persist markets/outcomes. |
| Settlement (`src/settle_bets1.php`) | Grades completed events, updates bet status, and books wallet transactions. |
| Tracking & notifications (`src/tracking.php`) | Stores price alerts, records triggered notifications, and exposes helper utilities for the UI. |

## Data Model

- **Users & wallets:** A `users` table manages credentials and profile privacy flags, paired with `wallets` and `wallet_transactions` for ledger accuracy.
- **Events & odds:** The app stores upcoming fixtures (`events`) plus outcome-specific rows (`odds`) that include markets, prices, and optional lines.
- **Bets:** Each wager records the user, market, outcome, stake, price, and settlement details (`status`, `actual_return`, timestamps).
- **Engagement:** `tracked_odds` captures price alerts, while `notifications` logs alerts and other account messages.

## Key Workflows

- **Odds ingestion pipeline:** Periodic jobs poll TheOddsAPI, filter unwanted markets (`h2h_lay`), enforce bookmaker preferences, and upsert outcomes so the UI always reflects current lines.
- **Settlement pass:** Completed events trigger score lookups with combat-sport fallbacks, ensuring moneyline, spread, and total bets settle accurately with wallet credit/debit tracking.
- **Account insights:** Helper utilities aggregate lifetime stats and profit timelines so the dashboard can visualize performance without manual calculations.
- **Search & discovery:** Full-text indexes power user lookup, while event queries prioritize upcoming fixtures to keep navigation focused on future action.

## Operations Overview

- Schema updates are self-managed: every entry point runs `ensure_app_schema()` to add missing tables, columns, and indexes automatically.
- Price alerts leverage `record_tracked_notifications()` to store in-app notices and provide a convenient hook for optional email delivery.
- Odds API integrations log request consumption for visibility into usage quotas.
