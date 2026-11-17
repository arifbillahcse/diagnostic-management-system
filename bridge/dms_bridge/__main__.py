"""Runs the bridge:  python -m dms_bridge path/to/bridge.ini

Reads the settings, opens the connection to the machine, keeps every
message the machine sends in a local queue, and sends the queue to the DMS
server. Stop it with Ctrl+C (or by stopping the Windows service).
"""

from __future__ import annotations

import configparser
import logging
import logging.handlers
import signal
import sys
import threading
from pathlib import Path

from . import __version__
from .astm import AstmReceiver
from .connections import serve_serial, serve_tcp_client, serve_tcp_server
from .hl7 import MllpReceiver
from .outbox import Outbox
from .sender import Sender

log = logging.getLogger("dms_bridge")


def load(path: Path) -> configparser.ConfigParser:
    config = configparser.ConfigParser()
    if not config.read(path, encoding="utf-8"):
        raise SystemExit(f"Settings file not found: {path}")
    for section, keys in {"server": ["url", "key"], "machine": ["protocol", "connection"]}.items():
        for key in keys:
            if not config.get(section, key, fallback="").strip():
                raise SystemExit(f"Missing setting [{section}] {key} in {path}")
    if config["machine"]["protocol"] not in ("astm", "hl7"):
        raise SystemExit("[machine] protocol must be astm or hl7.")
    return config


def make_handler_factory(protocol: str, outbox: Outbox):
    def factory():
        receiver = AstmReceiver() if protocol == "astm" else MllpReceiver()

        def handle(data: bytes) -> bytes:
            reply, messages = receiver.feed(data)
            for message in messages:
                message_id = outbox.add(message)
                log.info("Message %s received from the machine (%d characters); %d waiting to send.",
                         message_id, len(message), outbox.waiting())
            return reply

        return handle

    return factory


def setup_logging(folder: Path) -> None:
    folder.mkdir(parents=True, exist_ok=True)
    handlers = [
        logging.handlers.RotatingFileHandler(folder / "bridge.log", maxBytes=2_000_000, backupCount=5, encoding="utf-8"),
        logging.StreamHandler(sys.stdout),
    ]
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s", handlers=handlers)


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("Usage: python -m dms_bridge path/to/bridge.ini")
        return 2

    config = load(Path(argv[1]))
    folder = Path(config.get("bridge", "data_folder", fallback="data")).resolve()
    setup_logging(folder)
    log.info("DMS bridge %s starting.", __version__)

    outbox = Outbox(folder / "outbox.sqlite3")
    outbox.forget_sent_before(30)
    sender = Sender(outbox, config["server"]["url"], config["server"]["key"].strip(), config.get("server", "ca_file", fallback=None) or None)
    machine = config["machine"]
    factory = make_handler_factory(machine["protocol"], outbox)
    stop = threading.Event()

    def shutdown(*_args) -> None:
        log.info("Stopping.")
        stop.set()
        sender.stop()

    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)

    threading.Thread(target=sender.run, name="sender", daemon=True).start()

    connection = machine["connection"]
    if connection == "serial":
        serve_serial(dict(machine), factory, stop)
    elif connection == "tcp-server":
        serve_tcp_server(machine.get("listen_host", "0.0.0.0"), int(machine.get("listen_port", "5100")), factory, stop)
    elif connection == "tcp-client":
        serve_tcp_client(machine["machine_host"], int(machine["machine_port"]), factory, stop)
    else:
        raise SystemExit("[machine] connection must be serial, tcp-server or tcp-client.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
