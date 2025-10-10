# Paper-Betting
Paper Betting

## Event visibility

The browsing and search pages only display events whose `commence_time`
is in the future. If you import historical fixtures for testing, they
will be hidden automatically once the scheduled start time passes. To
see NHL games (or any other sport) in the UI, ensure that your database
contains upcoming events with a `commence_time` that is later than the
current UTC time.
