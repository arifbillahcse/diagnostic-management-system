"""Tests for the pretend analyzer. Run from the bridge folder:  python -m unittest discover -s tests -t ."""

from __future__ import annotations

import socket
import threading
import time
import unittest
from datetime import datetime

from dms_bridge import simulator
from dms_bridge.__main__ import make_handler_factory
from dms_bridge.connections import serve_tcp_server
from dms_bridge.sender import Sender

from .test_bridge import FakeDms, WithFakeDms

WHEN = datetime(2026, 11, 19, 10, 30, 0)


def segments(message: str) -> list[list[str]]:
    return [s.split("|") for s in message.split("\r") if s]


class MessageTest(unittest.TestCase):
    def test_the_message_has_the_layout_the_server_reads(self):
        message = simulator.build_message("2611180005", when=WHEN)
        parts = segments(message)

        self.assertEqual(parts[0][0], "MSH")
        self.assertEqual(parts[0][8], "ORU^R01")
        self.assertEqual(parts[0][11], "2.3.1")
        obr = next(s for s in parts if s[0] == "OBR")
        self.assertEqual(obr[3], "2611180005")  # the sample number is where DMS looks first
        obx = [s for s in parts if s[0] == "OBX"]
        self.assertEqual([s[3] for s in obx][:2], ["WBC", "NEU#"])
        self.assertTrue(all(s[2] == "NM" for s in obx))
        self.assertTrue(all(s[-1] == "F" for s in obx))

    def test_three_part_and_five_part_panels(self):
        three = [s[3] for s in segments(simulator.build_message("1", kind="3part")) if s[0] == "OBX"]
        five = [s[3] for s in segments(simulator.build_message("1", kind="5part")) if s[0] == "OBX"]
        self.assertIn("GRA#", three)
        self.assertNotIn("NEU#", three)
        self.assertIn("NEU#", five)
        self.assertNotIn("GRA#", five)

    def test_loinc_style_puts_the_code_first_and_the_name_second(self):
        message = simulator.build_message("1", code_style="loinc")
        wbc = next(s for s in segments(message) if s[0] == "OBX" and "WBC" in s[3])
        self.assertEqual(wbc[3], "6690-2^WBC^LN")

    def test_counts_can_be_sent_per_cumm(self):
        si = next(s for s in segments(simulator.build_message("1", units="si")) if s[0] == "OBX" and s[3] == "WBC")
        cumm = next(s for s in segments(simulator.build_message("1", units="cumm")) if s[0] == "OBX" and s[3] == "WBC")
        self.assertEqual(si[6], "10*9/L")
        self.assertEqual(cumm[6], "/cumm")
        self.assertAlmostEqual(float(cumm[5]), float(si[5]) * 1000, delta=10)

    def test_abnormal_values_are_flagged(self):
        obx = {s[3]: s for s in segments(simulator.build_message("1", abnormal=True)) if s[0] == "OBX"}
        self.assertEqual(obx["HGB"][8], "L")
        self.assertEqual(obx["WBC"][8], "H")
        self.assertEqual(obx["PLT"][8], "L")
        normal = {s[3]: s for s in segments(simulator.build_message("1")) if s[0] == "OBX"}
        self.assertEqual(normal["HGB"][8], "N")

    def test_the_same_sample_gives_the_same_numbers(self):
        a = simulator.build_message("2611180005", when=WHEN)
        b = simulator.build_message("2611180005", when=WHEN)
        c = simulator.build_message("2611180006", when=WHEN)
        self.assertEqual(a, b)
        self.assertNotEqual(a, c)

    def test_histograms_are_added_as_image_segments(self):
        message = simulator.build_message("1", histograms=True)
        self.assertTrue(any(s[0] == "OBX" and s[2] == "ED" for s in segments(message)))


class ThroughTheBridgeTest(WithFakeDms):
    def run_bridge(self):
        probe = socket.socket()
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
        probe.close()
        stop, ready = threading.Event(), threading.Event()
        threading.Thread(target=serve_tcp_server, args=("127.0.0.1", port, make_handler_factory("hl7", self.outbox), stop, ready), daemon=True).start()
        self.assertTrue(ready.wait(5))
        self.addCleanup(stop.set)
        return port

    def test_two_samples_reach_the_server_through_the_bridge(self):
        port = self.run_bridge()
        machine = socket.create_connection(("127.0.0.1", port), timeout=5)
        messages = [simulator.build_message("2611180005"), simulator.build_message("2611180006", abnormal=True)]
        failed = simulator.send_all(machine, messages, interval=0)
        machine.close()
        self.assertEqual(failed, 0)

        sender = Sender(self.outbox, self.url, "anz_" + "k" * 40)
        deadline = time.time() + 5
        while self.outbox.waiting() < 2 and time.time() < deadline:
            time.sleep(0.05)
        self.assertEqual([sender.send_one(), sender.send_one()], ["sent", "sent"])
        raw = [r["body"]["raw"] for r in FakeDms.received]
        self.assertIn("OBR|1||2611180005|", raw[0])
        self.assertIn("OBR|1||2611180006|", raw[1])
        self.assertIn("|WBC||13.60|", raw[1])

    def test_a_recorded_file_is_sent_as_it_is(self):
        port = self.run_bridge()
        recorded = "MSH|^~\\&|X|Y|LIS|DMS|20261119||ORU^R01|77|P|2.3.1\rOBR|1||555\rOBX|1|NM|WBC||6.1|10*9/L\r"
        machine = socket.create_connection(("127.0.0.1", port), timeout=5)
        self.assertEqual(simulator.send_all(machine, [recorded], interval=0), 0)
        machine.close()
        deadline = time.time() + 5
        while self.outbox.waiting() < 1 and time.time() < deadline:
            time.sleep(0.05)
        Sender(self.outbox, self.url, "anz_" + "k" * 40).send_one()
        self.assertIn("OBR|1||555", FakeDms.received[0]["body"]["raw"])


class NoAcknowledgementTest(unittest.TestCase):
    def test_a_silent_server_is_reported_not_hidden(self):
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]
        threading.Thread(target=lambda: server.accept(), daemon=True).start()
        machine = socket.create_connection(("127.0.0.1", port), timeout=2)
        original = simulator.read_ack
        simulator.read_ack = lambda sock, timeout=10.0: original(sock, 0.3)
        try:
            self.assertEqual(simulator.send_all(machine, [simulator.build_message("1")], interval=0), 1)
        finally:
            simulator.read_ack = original
            machine.close()
            server.close()


if __name__ == "__main__":
    unittest.main()
