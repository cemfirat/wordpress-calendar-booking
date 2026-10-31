#!/usr/bin/env python3
"""Local SQL storage double; not a replacement for WordPress/MySQL CI.

Execute the production repository SQL against an isolated in-memory SQLite DB.
Only MySQL transaction/INSERT IGNORE spelling is translated. PHP models named
lock acquisition separately; this fixture does not prove cross-process locking.
No financial state transition or amount calculation is implemented here.
"""
import json
import sqlite3
import sys

SCHEMA = """
CREATE TABLE wp_wpcb_payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, payment_uuid TEXT NOT NULL UNIQUE,
 booking_id INTEGER NOT NULL, provider TEXT NOT NULL DEFAULT '',
 provider_reference TEXT, amount_minor INTEGER NOT NULL,
 refunded_minor INTEGER NOT NULL DEFAULT 0,
 refund_pending_minor INTEGER NOT NULL DEFAULT 0,
 refund_inflight_minor INTEGER NOT NULL DEFAULT 0,
 currency TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
 expires_at TEXT, paid_at TEXT, refunded_at TEXT, created_at TEXT, updated_at TEXT,
 UNIQUE(provider, provider_reference)
);
CREATE TABLE wp_wpcb_payment_refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT, refund_uuid TEXT NOT NULL UNIQUE,
 payment_id INTEGER NOT NULL, provider TEXT NOT NULL,
 idempotency_key TEXT NOT NULL, provider_refund_id TEXT,
 amount_minor INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL,
 failure_reason TEXT, last_provider_event_created_at INTEGER NOT NULL DEFAULT 0,
 completed_at TEXT, created_at TEXT, updated_at TEXT,
 UNIQUE(provider, idempotency_key), UNIQUE(provider, provider_refund_id)
);
CREATE TABLE wp_wpcb_payment_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL,
 provider TEXT NOT NULL, provider_event_id TEXT NOT NULL, event_type TEXT,
 created_at TEXT, UNIQUE(provider, provider_event_id)
);
CREATE TABLE wp_wpcb_bookings (
 id INTEGER PRIMARY KEY, booking_uuid TEXT, booking_type_id INTEGER DEFAULT 1,
 series_id INTEGER, series_occurrence INTEGER, status TEXT, updated_at TEXT
);
CREATE TABLE wp_wpcb_booking_series (id INTEGER PRIMARY KEY, occurrence_count INTEGER);
CREATE TABLE wp_wpcb_booking_status_log (
 id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, old_status TEXT,
 new_status TEXT, context TEXT, changed_by TEXT, note TEXT, created_at TEXT
);
"""


def main():
    connection = sqlite3.connect(":memory:", isolation_level=None)
    connection.row_factory = sqlite3.Row
    connection.executescript(SCHEMA)
    for line in sys.stdin:
        request = json.loads(line)
        sql = request["sql"]
        if sql == "START TRANSACTION":
            sql = "BEGIN"
        sql = sql.replace("INSERT IGNORE INTO", "INSERT OR IGNORE INTO")
        try:
            cursor = connection.execute(sql, request.get("args", []))
            result = {
                "rows": [dict(row) for row in cursor.fetchall()],
                "affected": max(0, cursor.rowcount),
                "insert_id": cursor.lastrowid or 0,
            }
        except sqlite3.Error as error:
            result = {"error": str(error)}
        print(json.dumps(result), flush=True)


if __name__ == "__main__":
    main()
