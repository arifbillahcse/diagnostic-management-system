"""ASTM E1381 low-level link layer, receiving side.

The analyzer opens a transfer with ENQ, sends frames
``STX <frame no> <text> ETB|ETX <checksum> CR LF`` and closes with EOT.
Each good frame is acknowledged with ACK, a bad one with NAK (the machine
sends it again). Frames ending in ETB continue in the next frame; ETX ends a
record. At EOT the records of the transfer form one message.

The checksum is the sum of the bytes from the frame number up to and
including ETB or ETX, modulo 256, written as two upper-case hex digits.
"""

from __future__ import annotations

ENQ, ACK, NAK, EOT, STX, ETX, ETB = b"\x05", b"\x06", b"\x15", b"\x04", b"\x02", b"\x03", b"\x17"
CR, LF = b"\r", b"\n"


def checksum(body: bytes) -> bytes:
    """Checksum of the bytes from the frame number to ETB/ETX inclusive."""
    return f"{sum(body) % 256:02X}".encode("ascii")


class AstmReceiver:
    """Feed it bytes as they arrive; it returns bytes to answer and finished messages."""

    def __init__(self) -> None:
        self._buffer = b""
        self._text: list[bytes] = []
        self._expected = 1
        self._open = False

    def feed(self, data: bytes) -> tuple[bytes, list[str]]:
        """Returns (bytes to send back to the machine, finished messages)."""
        self._buffer += data
        reply = b""
        messages: list[str] = []

        while self._buffer:
            first = self._buffer[:1]

            if first == ENQ:
                self._buffer = self._buffer[1:]
                self._reset()
                self._open = True
                reply += ACK
            elif first == EOT:
                self._buffer = self._buffer[1:]
                if self._text:
                    messages.append(b"".join(self._text).decode("latin-1"))
                self._reset()
            elif first == STX:
                frame, rest, complete = self._take_frame(self._buffer)
                if not complete:
                    break  # wait for the rest of the frame
                self._buffer = rest
                reply += self._accept(frame)
            else:
                # Noise between frames (stray CR/LF, a NAK echo): skip it.
                self._buffer = self._buffer[1:]

        return reply, messages

    def _take_frame(self, buffer: bytes) -> tuple[bytes, bytes, bool]:
        """A whole frame from STX to the LF after the checksum, if it has arrived."""
        positions = [p for p in (buffer.find(ETX, 1), buffer.find(ETB, 1)) if p != -1]
        if not positions:
            return b"", buffer, False
        end = min(positions)
        # end mark, 2 checksum characters, CR, LF
        if len(buffer) < end + 5:
            return b"", buffer, False
        frame = buffer[: end + 5]
        return frame, buffer[end + 5 :], True

    def _accept(self, frame: bytes) -> bytes:
        body = frame[1:-4]  # frame number .. ETB/ETX
        given = frame[-4:-2].upper()
        if len(body) < 2 or checksum(body) != given:
            return NAK

        number = body[:1]
        if not number.isdigit():
            return NAK

        # A repeated frame (our ACK was lost) is acknowledged but not added twice.
        expected = str(self._expected % 8).encode("ascii")
        if number != expected:
            previous = str((self._expected - 1) % 8).encode("ascii")
            return ACK if number == previous else NAK

        text = body[1:-1]
        self._text.append(text if body.endswith(ETB) else text.rstrip(b"\r") + b"\r")
        self._expected += 1
        return ACK

    def _reset(self) -> None:
        self._text = []
        self._expected = 1
        self._open = False


def frames_for(message: str) -> list[bytes]:
    """Splits a message into ASTM frames, one record per frame (for tests and simulators)."""
    frames = []
    for i, record in enumerate([r for r in message.replace("\n", "\r").split("\r") if r]):
        body = str((i + 1) % 8).encode("ascii") + record.encode("latin-1") + CR + ETX
        frames.append(STX + body + checksum(body) + CR + LF)
    return frames
