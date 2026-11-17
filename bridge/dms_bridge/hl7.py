"""HL7 v2 over MLLP: messages arrive as ``VT <message> FS CR``.

Most analyzers wait for an HL7 acknowledgement (ACK with MSA|AA) before
sending the next message, so one is built for every message received.
"""

from __future__ import annotations

from datetime import datetime

VT, FS, CR = b"\x0b", b"\x1c", b"\r"


class MllpReceiver:
    def __init__(self) -> None:
        self._buffer = b""

    def feed(self, data: bytes) -> tuple[bytes, list[str]]:
        """Returns (acknowledgements to send back, finished messages)."""
        self._buffer += data
        reply = b""
        messages: list[str] = []

        while True:
            start = self._buffer.find(VT)
            if start == -1:
                self._buffer = b""
                break
            end = self._buffer.find(FS, start + 1)
            if end == -1:
                self._buffer = self._buffer[start:]
                break
            raw = self._buffer[start + 1 : end].decode("utf-8", errors="replace")
            self._buffer = self._buffer[end + 1 :].lstrip(CR)
            if raw.strip():
                messages.append(raw)
                reply += wrap(ack_for(raw))

        return reply, messages


def wrap(message: str) -> bytes:
    return VT + message.encode("utf-8") + FS + CR


def ack_for(message: str) -> str:
    """An 'application accept' acknowledgement echoing the message's control id."""
    segments = [s for s in message.replace("\n", "\r").split("\r") if s]
    msh = segments[0] if segments and segments[0].startswith("MSH") else "MSH|^~\\&"
    sep = msh[3] if len(msh) > 3 else "|"
    fields = msh.split(sep)
    control_id = fields[9] if len(fields) > 9 else ""
    version = fields[11] if len(fields) > 11 and fields[11] else "2.3.1"
    sender_app = fields[2] if len(fields) > 2 else ""
    sender_fac = fields[3] if len(fields) > 3 else ""
    now = datetime.now().strftime("%Y%m%d%H%M%S")
    return (
        f"MSH{sep}^~\\&{sep}DMS{sep}LIS{sep}{sender_app}{sep}{sender_fac}{sep}{now}{sep}{sep}ACK^R01{sep}{control_id}{sep}P{sep}{version}\r"
        f"MSA{sep}AA{sep}{control_id}\r"
    )
