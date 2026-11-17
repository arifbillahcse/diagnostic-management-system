"""Posts waiting messages to the DMS server over HTTPS, oldest first."""

from __future__ import annotations

import json
import logging
import ssl
import threading
import urllib.error
import urllib.request

from . import __version__
from .outbox import Outbox

log = logging.getLogger("dms_bridge.sender")


class Sender:
    def __init__(self, outbox: Outbox, url: str, key: str, ca_file: str | None = None, timeout: float = 20.0) -> None:
        if not url.startswith("https://") and not url.startswith("http://127.0.0.1") and not url.startswith("http://localhost"):
            raise ValueError("The server address must start with https:// (plain http is only allowed for localhost tests).")
        self.outbox = outbox
        self.url = url.rstrip("/") + "/api/analyzers/messages"
        self.key = key
        self.timeout = timeout
        self.context = ssl.create_default_context(cafile=ca_file) if url.startswith("https://") else None
        self._stop = threading.Event()

    def send_one(self) -> str:
        """Sends the oldest waiting message. Returns what happened:
        'empty', 'sent', 'rejected', 'bad_key' or 'retry'."""
        item = self.outbox.next()
        if item is None:
            return "empty"
        message_id, raw = item

        request = urllib.request.Request(
            self.url,
            data=json.dumps({"raw": raw}).encode("utf-8"),
            method="POST",
            headers={
                "Authorization": f"Bearer {self.key}",
                "Content-Type": "application/json",
                "Accept": "application/json",
                "User-Agent": f"DMS-Bridge/{__version__}",
            },
        )

        try:
            with urllib.request.urlopen(request, timeout=self.timeout, context=self.context) as response:
                body = response.read().decode("utf-8", errors="replace")
                if response.status == 202:
                    self.outbox.sent(message_id)
                    log.info("Message %s sent: %s", message_id, body[:200])
                    return "sent"
                self.outbox.failed(message_id, f"HTTP {response.status}")
                return "retry"
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", errors="replace")[:300]
            if e.code == 401:
                self.outbox.failed(message_id, "The server did not accept the machine key.")
                log.error("The server did not accept the machine key. Check the key in the settings file.")
                return "bad_key"
            if e.code in (413, 422):
                self.outbox.rejected(message_id, f"HTTP {e.code}: {detail}")
                log.error("The server refused message %s: %s", message_id, detail)
                return "rejected"
            self.outbox.failed(message_id, f"HTTP {e.code}: {detail}")
            log.warning("Server answered %s for message %s; will try again.", e.code, message_id)
            return "retry"
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            self.outbox.failed(message_id, str(e))
            log.warning("Could not reach the server (%s); will try again.", e)
            return "retry"

    def run(self) -> None:
        """Sends forever: at once while there is work, waiting longer after each failure (up to 5 minutes)."""
        delay = 2.0
        while not self._stop.is_set():
            outcome = self.send_one()
            if outcome in ("sent", "rejected"):
                delay = 2.0
                continue
            if outcome == "empty":
                self._stop.wait(2.0)
                continue
            wait = 300.0 if outcome == "bad_key" else delay
            delay = min(delay * 2, 300.0)
            self._stop.wait(wait)

    def stop(self) -> None:
        self._stop.set()
