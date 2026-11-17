"""Messages waiting to be sent, kept on disk (SQLite) so nothing is lost when
the internet or the server is down, or the PC restarts."""

from __future__ import annotations

import sqlite3
import threading
import time
from pathlib import Path


class Outbox:
    def __init__(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        self._db = sqlite3.connect(str(path), check_same_thread=False, isolation_level=None)
        self._lock = threading.Lock()
        with self._lock:
            self._db.execute("PRAGMA journal_mode=WAL")
            self._db.execute(
                "CREATE TABLE IF NOT EXISTS messages ("
                " id INTEGER PRIMARY KEY AUTOINCREMENT, raw TEXT NOT NULL, created_at REAL NOT NULL,"
                " attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT, sent_at REAL, rejected INTEGER NOT NULL DEFAULT 0)"
            )

    def add(self, raw: str) -> int:
        with self._lock:
            cur = self._db.execute("INSERT INTO messages (raw, created_at) VALUES (?, ?)", (raw, time.time()))
            return int(cur.lastrowid)

    def next(self) -> tuple[int, str] | None:
        with self._lock:
            row = self._db.execute(
                "SELECT id, raw FROM messages WHERE sent_at IS NULL AND rejected = 0 ORDER BY id LIMIT 1"
            ).fetchone()
        return (int(row[0]), str(row[1])) if row else None

    def sent(self, message_id: int) -> None:
        with self._lock:
            self._db.execute("UPDATE messages SET sent_at = ?, last_error = NULL WHERE id = ?", (time.time(), message_id))

    def failed(self, message_id: int, error: str) -> None:
        with self._lock:
            self._db.execute("UPDATE messages SET attempts = attempts + 1, last_error = ? WHERE id = ?", (error[:500], message_id))

    def rejected(self, message_id: int, error: str) -> None:
        """The server refused the message itself (it will never be accepted): keep it, stop sending it."""
        with self._lock:
            self._db.execute("UPDATE messages SET rejected = 1, last_error = ? WHERE id = ?", (error[:500], message_id))

    def waiting(self) -> int:
        with self._lock:
            return int(self._db.execute("SELECT COUNT(*) FROM messages WHERE sent_at IS NULL AND rejected = 0").fetchone()[0])

    def forget_sent_before(self, days: int = 30) -> None:
        with self._lock:
            self._db.execute("DELETE FROM messages WHERE sent_at IS NOT NULL AND sent_at < ?", (time.time() - days * 86400,))
