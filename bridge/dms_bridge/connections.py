"""How the bridge reaches the machine: a serial (RS-232) cable, or the
network, either waiting for the machine to connect (tcp-server) or
connecting to the machine (tcp-client). All three hand bytes to a
receiver and write its answers back."""

from __future__ import annotations

import logging
import socket
import threading
import time
from typing import Callable, Protocol

log = logging.getLogger("dms_bridge.connection")

Handler = Callable[[bytes], bytes]  # bytes in -> bytes to answer


class Link(Protocol):
    def read(self) -> bytes: ...
    def write(self, data: bytes) -> None: ...
    def close(self) -> None: ...


def pump(link: Link, handle: Handler, stop: threading.Event) -> None:
    """Reads from the link until it closes or stop is set."""
    while not stop.is_set():
        data = link.read()
        if data is None:
            break
        if data:
            reply = handle(data)
            if reply:
                link.write(reply)


class SerialLink:
    def __init__(self, port: str, baud: int, bytesize: int, parity: str, stopbits: float) -> None:
        import serial  # pyserial, only needed for serial machines

        self._port = serial.Serial(port=port, baudrate=baud, bytesize=bytesize, parity=parity, stopbits=stopbits, timeout=1)

    def read(self) -> bytes:
        return self._port.read(4096)

    def write(self, data: bytes) -> None:
        self._port.write(data)

    def close(self) -> None:
        self._port.close()


class SocketLink:
    def __init__(self, sock: socket.socket) -> None:
        self._sock = sock
        self._sock.settimeout(1.0)

    def read(self) -> bytes | None:
        try:
            data = self._sock.recv(4096)
        except socket.timeout:
            return b""
        except OSError:
            return None
        return data if data else None  # b"" from recv means the other side closed

    def write(self, data: bytes) -> None:
        self._sock.sendall(data)

    def close(self) -> None:
        try:
            self._sock.close()
        except OSError:
            pass


def serve_serial(settings: dict, make_handler: Callable[[], Handler], stop: threading.Event) -> None:
    while not stop.is_set():
        try:
            link = SerialLink(settings["port"], int(settings.get("baud", 9600)), int(settings.get("bytesize", 8)),
                              settings.get("parity", "N"), float(settings.get("stopbits", 1)))
        except Exception as e:  # noqa: BLE001 - the port may be missing or busy; try again
            log.error("Cannot open serial port %s (%s). Trying again in 10 seconds.", settings.get("port"), e)
            stop.wait(10)
            continue
        log.info("Serial port %s open.", settings["port"])
        try:
            pump(link, make_handler(), stop)
        finally:
            link.close()


def serve_tcp_server(host: str, port: int, make_handler: Callable[[], Handler], stop: threading.Event,
                     ready: threading.Event | None = None) -> None:
    """Waits for the machine to connect. One machine at a time, as analyzers do."""
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((host, port))
    server.listen(1)
    server.settimeout(1.0)
    log.info("Waiting for the machine on %s:%s.", host, port)
    if ready is not None:
        ready.set()
    try:
        while not stop.is_set():
            try:
                conn, address = server.accept()
            except socket.timeout:
                continue
            log.info("Machine connected from %s.", address[0])
            link = SocketLink(conn)
            try:
                pump(link, make_handler(), stop)
            finally:
                link.close()
                log.info("Machine disconnected.")
    finally:
        server.close()


def serve_tcp_client(host: str, port: int, make_handler: Callable[[], Handler], stop: threading.Event) -> None:
    """Connects to the machine, and again whenever the connection drops."""
    while not stop.is_set():
        try:
            sock = socket.create_connection((host, port), timeout=10)
        except OSError as e:
            log.error("Cannot reach the machine at %s:%s (%s). Trying again in 10 seconds.", host, port, e)
            stop.wait(10)
            continue
        log.info("Connected to the machine at %s:%s.", host, port)
        link = SocketLink(sock)
        try:
            pump(link, make_handler(), stop)
        finally:
            link.close()
        time.sleep(1)
