"""Bridge tests. Run from the bridge folder:  python -m unittest discover -s tests -t ."""

from __future__ import annotations

import json
import socket
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

from dms_bridge.astm import ACK, ENQ, EOT, ETB, ETX, NAK, STX, AstmReceiver, checksum, frames_for
from dms_bridge.connections import serve_tcp_server
from dms_bridge.hl7 import FS, VT, MllpReceiver, ack_for
from dms_bridge.outbox import Outbox
from dms_bridge.sender import Sender
from dms_bridge.__main__ import make_handler_factory

MESSAGE = "H|\\^&|||Analyzer\rO|1|2611180001||^^^CBC\rR|1|^^^HGB|13.5|g/dL\rL|1|N"


class AstmTest(unittest.TestCase):
    def test_a_transfer_is_acknowledged_and_gives_one_message(self):
        receiver = AstmReceiver()
        replies = b""
        reply, messages = receiver.feed(ENQ)
        replies += reply
        for frame in frames_for(MESSAGE):
            # bytes may arrive split anywhere
            for chunk in (frame[:5], frame[5:]):
                reply, got = receiver.feed(chunk)
                replies += reply
                messages += got
        reply, got = receiver.feed(EOT)
        messages += got

        self.assertEqual(replies, ACK * 5)  # ENQ + 4 frames
        self.assertEqual(messages, [MESSAGE.replace("\n", "\r") + "\r"])

    def test_a_damaged_frame_is_refused_and_a_repeated_one_is_not_added_twice(self):
        receiver = AstmReceiver()
        receiver.feed(ENQ)
        good = frames_for("H|\\^&\rL|1|N")
        bad = good[0][:-4] + b"00\r\n"
        self.assertEqual(receiver.feed(bad)[0], NAK)
        self.assertEqual(receiver.feed(good[0])[0], ACK)
        self.assertEqual(receiver.feed(good[0])[0], ACK)  # our ACK was lost; the machine repeats
        receiver.feed(good[1])
        _, messages = receiver.feed(EOT)
        self.assertEqual(messages, ["H|\\^&\rL|1|N\r"])

    def test_a_long_record_split_with_etb_is_joined(self):
        receiver = AstmReceiver()
        receiver.feed(ENQ)
        first = b"1R|1|^^^HG" + ETB
        second = b"2B|13.5|g/dL\r" + ETX
        receiver.feed(STX + first + checksum(first) + b"\r\n")
        receiver.feed(STX + second + checksum(second) + b"\r\n")
        _, messages = receiver.feed(EOT)
        self.assertEqual(messages, ["R|1|^^^HGB|13.5|g/dL\r"])


class Hl7Test(unittest.TestCase):
    def test_framed_messages_are_taken_apart_and_acknowledged(self):
        msg = "MSH|^~\\&|Analyzer|Lab|||20261118||ORU^R01|1001|P|2.3.1\rOBR|1||2611180001\rOBX|1|NM|718-7^HGB||13.5|g/dL"
        receiver = MllpReceiver()
        data = VT + msg.encode() + FS + b"\r" + VT + msg.replace("1001", "1002").encode() + FS + b"\r"

        reply1, got1 = receiver.feed(data[:30])
        reply2, got2 = receiver.feed(data[30:])

        self.assertEqual(got1, [])
        self.assertEqual(len(got2), 2)
        self.assertIn(b"MSA|AA|1001", reply2)
        self.assertIn(b"MSA|AA|1002", reply2)
        self.assertTrue(reply2.startswith(VT))

    def test_the_acknowledgement_echoes_the_control_id_and_version(self):
        ack = ack_for("MSH|^~\\&|BC-5000|Lab|||20261118||ORU^R01|77|P|2.4")
        self.assertIn("ACK^R01|77|P|2.4", ack)
        self.assertIn("MSA|AA|77", ack)


class OutboxTest(unittest.TestCase):
    def test_messages_survive_a_restart_until_sent(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "outbox.sqlite3"
            first = Outbox(path)
            a = first.add("one")
            first.add("two")
            first.sent(a)

            again = Outbox(path)
            self.assertEqual(again.next()[1], "two")
            self.assertEqual(again.waiting(), 1)

            again.rejected(again.next()[0], "too big")
            self.assertIsNone(again.next())


class FakeDms(BaseHTTPRequestHandler):
    """Answers like the DMS server and remembers what it was sent."""

    answers: list[int] = []
    received: list[dict] = []

    def do_POST(self):  # noqa: N802
        body = self.rfile.read(int(self.headers["Content-Length"]))
        FakeDms.received.append({"path": self.path, "auth": self.headers.get("Authorization"), "agent": self.headers.get("User-Agent"), "body": json.loads(body)})
        code = FakeDms.answers.pop(0) if FakeDms.answers else 202
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"status":"applied"}')

    def log_message(self, *args):
        pass


class WithFakeDms(unittest.TestCase):
    def setUp(self):
        FakeDms.answers, FakeDms.received = [], []
        self.server = HTTPServer(("127.0.0.1", 0), FakeDms)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.url = f"http://127.0.0.1:{self.server.server_port}"
        self.folder = tempfile.TemporaryDirectory()
        self.outbox = Outbox(Path(self.folder.name) / "outbox.sqlite3")

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.folder.cleanup()


class SenderTest(WithFakeDms):
    def test_messages_go_with_the_key_and_failures_are_kept_for_later(self):
        sender = Sender(self.outbox, self.url, "anz_" + "k" * 40)
        self.outbox.add("first")
        self.outbox.add("second")
        FakeDms.answers = [500, 202, 401, 422]

        self.assertEqual(sender.send_one(), "retry")
        self.assertEqual(sender.send_one(), "sent")
        self.assertEqual(sender.send_one(), "bad_key")
        self.assertEqual(self.outbox.waiting(), 1, "A wrong key never loses the message")
        self.assertEqual(sender.send_one(), "rejected")
        self.assertEqual(sender.send_one(), "empty")

        sent = FakeDms.received[0]
        self.assertEqual(sent["path"], "/api/analyzers/messages")
        self.assertEqual(sent["auth"], "Bearer anz_" + "k" * 40)
        self.assertTrue(sent["agent"].startswith("DMS-Bridge/"))
        self.assertEqual(sent["body"], {"raw": "first"})

    def test_plain_http_is_refused_except_for_local_tests(self):
        with self.assertRaises(ValueError):
            Sender(self.outbox, "http://clinic.example.com", "anz_x")


class EndToEndTest(WithFakeDms):
    def test_a_machine_on_the_network_reaches_the_server(self):
        stop, ready = threading.Event(), threading.Event()
        probe = socket.socket()
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
        probe.close()

        threading.Thread(target=serve_tcp_server, args=("127.0.0.1", port, make_handler_factory("astm", self.outbox), stop, ready), daemon=True).start()
        self.assertTrue(ready.wait(5))

        machine = socket.create_connection(("127.0.0.1", port), timeout=5)
        acks = b""
        machine.sendall(ENQ)
        acks += machine.recv(1)
        for frame in frames_for(MESSAGE):
            machine.sendall(frame)
            acks += machine.recv(1)
        machine.sendall(EOT)
        machine.close()
        self.assertEqual(acks, ACK * 5)

        sender = Sender(self.outbox, self.url, "anz_" + "k" * 40)
        deadline = time.time() + 5
        while self.outbox.waiting() == 0 and time.time() < deadline:
            time.sleep(0.05)
        self.assertEqual(sender.send_one(), "sent")
        stop.set()

        self.assertIn("R|1|^^^HGB|13.5|g/dL", FakeDms.received[0]["body"]["raw"])


if __name__ == "__main__":
    unittest.main()
