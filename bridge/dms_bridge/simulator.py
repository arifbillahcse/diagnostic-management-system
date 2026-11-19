"""A pretend haematology analyzer, for testing the bridge and DMS without a machine.

It sends HL7 v2 result messages (ORU^R01 inside MLLP), the way many
analyzers do over the network, and checks that the bridge acknowledges each
one. It is a test tool: it makes the messages up in the standard HL7 layout,
so it proves your set-up works, but it cannot prove that a particular real
machine will work. For that, record a real message and send it with --file.

Examples (run inside the bridge folder):

  Bridge waiting for the machine (connection = tcp-server in bridge.ini):
    python -m dms_bridge.simulator --connect 127.0.0.1:5100 --sample 2611180005

  Bridge connecting to the machine (connection = tcp-client):
    python -m dms_bridge.simulator --listen 127.0.0.1:5100 --sample 2611180005

  Just look at the message, send nothing:
    python -m dms_bridge.simulator --show --sample 2611180005 --panel 5part

  Send a message recorded from a real machine:
    python -m dms_bridge.simulator --connect 127.0.0.1:5100 --file captured.hl7
"""

from __future__ import annotations

import argparse
import random
import socket
import sys
import time
from dataclasses import dataclass
from datetime import datetime

VT, FS, CR = b"\x0b", b"\x1c", b"\r"


@dataclass(frozen=True)
class Parameter:
    name: str  # short name the machine sends, for example WBC
    loinc: str  # LOINC code, for the "loinc" code style
    unit_si: str
    unit_count: str  # unit when counts are sent as /cumm
    low: float
    high: float
    typical: float
    decimals: int
    count_scale: float = 1.0  # multiply by this to go from the SI value to /cumm


# Adult reference ranges and typical values, for the flags and the numbers the
# simulator makes up. These are generic illustrations, not the clinic's ranges.
COMMON = [
    Parameter("WBC", "6690-2", "10*9/L", "/cumm", 4.0, 10.0, 7.2, 2, 1000),
    Parameter("RBC", "789-8", "10*12/L", "million/cumm", 4.0, 5.5, 4.8, 2),
    Parameter("HGB", "718-7", "g/dL", "g/dL", 12.0, 16.0, 13.8, 1),
    Parameter("HCT", "4544-3", "%", "%", 36.0, 48.0, 41.0, 1),
    Parameter("MCV", "787-2", "fL", "fL", 80.0, 100.0, 88.0, 1),
    Parameter("MCH", "785-6", "pg", "pg", 27.0, 34.0, 29.5, 1),
    Parameter("MCHC", "786-4", "g/dL", "g/dL", 32.0, 36.0, 33.8, 1),
    Parameter("RDW-CV", "788-0", "%", "%", 11.5, 14.5, 13.0, 1),
    Parameter("PLT", "777-3", "10*9/L", "/cumm", 150.0, 450.0, 245.0, 0, 1000),
    Parameter("MPV", "32623-1", "fL", "fL", 7.0, 11.0, 9.0, 1),
]

THREE_PART = [
    Parameter("LYM#", "731-0", "10*9/L", "/cumm", 1.0, 4.0, 2.2, 2, 1000),
    Parameter("MID#", "", "10*9/L", "/cumm", 0.1, 1.0, 0.5, 2, 1000),
    Parameter("GRA#", "", "10*9/L", "/cumm", 2.0, 7.0, 4.5, 2, 1000),
    Parameter("LYM%", "736-9", "%", "%", 20.0, 40.0, 30.0, 1),
    Parameter("MID%", "", "%", "%", 3.0, 10.0, 7.0, 1),
    Parameter("GRA%", "", "%", "%", 50.0, 70.0, 63.0, 1),
]

FIVE_PART = [
    Parameter("NEU#", "751-8", "10*9/L", "/cumm", 2.0, 7.0, 4.3, 2, 1000),
    Parameter("LYM#", "731-0", "10*9/L", "/cumm", 1.0, 4.0, 2.2, 2, 1000),
    Parameter("MON#", "742-7", "10*9/L", "/cumm", 0.1, 1.0, 0.5, 2, 1000),
    Parameter("EOS#", "711-2", "10*9/L", "/cumm", 0.0, 0.5, 0.1, 2, 1000),
    Parameter("BAS#", "704-7", "10*9/L", "/cumm", 0.0, 0.1, 0.03, 2, 1000),
    Parameter("NEU%", "770-8", "%", "%", 40.0, 75.0, 60.0, 1),
    Parameter("LYM%", "736-9", "%", "%", 20.0, 45.0, 30.0, 1),
    Parameter("MON%", "5905-5", "%", "%", 2.0, 10.0, 7.0, 1),
    Parameter("EOS%", "713-8", "%", "%", 0.0, 6.0, 2.0, 1),
    Parameter("BAS%", "706-2", "%", "%", 0.0, 1.0, 0.5, 1),
]

# With --abnormal: push these out of range (a typical anaemia with a raised
# white count and low platelets), so flags can be checked.
ABNORMAL = {"HGB": 9.4, "WBC": 13.6, "PLT": 118.0, "MCV": 72.0}


def panel(kind: str) -> list[Parameter]:
    extra = THREE_PART if kind == "3part" else FIVE_PART
    by_name = {p.name: p for p in COMMON + extra}
    order = ["WBC"] + [p.name for p in extra if p.name.endswith("#")] + [p.name for p in extra if p.name.endswith("%")]
    order += ["RBC", "HGB", "HCT", "MCV", "MCH", "MCHC", "RDW-CV", "PLT", "MPV"]
    return [by_name[n] for n in order]


def make_value(p: Parameter, rng: random.Random, abnormal: bool) -> float:
    if abnormal and p.name in ABNORMAL:
        return ABNORMAL[p.name]
    spread = (p.high - p.low) * 0.12
    return max(0.0, p.typical + rng.uniform(-spread, spread))


def flag(p: Parameter, value: float) -> str:
    return "L" if value < p.low else "H" if value > p.high else "N"


def fmt(value: float, decimals: int) -> str:
    return f"{value:.{decimals}f}"


def build_message(
    sample: str,
    *,
    kind: str = "5part",
    units: str = "si",
    code_style: str = "plain",
    abnormal: bool = False,
    histograms: bool = False,
    patient: str = "Test^Patient",
    sex: str = "F",
    seed: int | None = None,
    when: datetime | None = None,
    machine_name: str = "SIMULATOR",
) -> str:
    """One HL7 ORU^R01 message for one sample, segments separated by CR."""
    rng = random.Random(seed if seed is not None else sample)
    now = (when or datetime.now()).strftime("%Y%m%d%H%M%S")
    control_id = f"{now}{rng.randint(100, 999)}"
    segments = [
        f"MSH|^~\\&|{machine_name}|LAB|LIS|DMS|{now}||ORU^R01|{control_id}|P|2.3.1||||||UNICODE",
        f"PID|1||{sample}||{patient}||19900101|{sex}",
        f"OBR|1||{sample}|00001^Automated Count^99MRC||{now}|{now}||||||||||||||||HM",
    ]
    number = 0
    for p in panel(kind):
        number += 1
        value = make_value(p, rng, abnormal)
        mark = flag(p, value)  # judged in the machine's own (SI) units
        low, high, unit, decimals = p.low, p.high, p.unit_si, p.decimals
        if units == "cumm":
            unit = p.unit_count
            if p.count_scale != 1.0:
                value, low, high, decimals = value * p.count_scale, low * p.count_scale, high * p.count_scale, 0
        code = f"{p.loinc}^{p.name}^LN" if code_style == "loinc" and p.loinc else p.name
        segments.append(f"OBX|{number}|NM|{code}||{fmt(value, decimals)}|{unit}|{fmt(low, decimals)}-{fmt(high, decimals)}|{mark}|||F")
    if histograms:
        # Real machines send charts too. They are not results and must be skipped.
        number += 1
        segments.append(f"OBX|{number}|ED|15^WBC Histogram^99MRC||{machine_name}^Image^PNG^Base64^iVBORw0KGgo=||||||F")
    return "\r".join(segments) + "\r"


def wrap(message: str) -> bytes:
    return VT + message.replace("\n", "\r").encode("utf-8") + FS + CR


def read_ack(sock: socket.socket, timeout: float = 10.0) -> str:
    sock.settimeout(timeout)
    data = b""
    while FS not in data:
        chunk = sock.recv(4096)
        if not chunk:
            break
        data += chunk
    return data.replace(VT, b"").replace(FS, b"").decode("utf-8", errors="replace").strip()


def send_all(sock: socket.socket, messages: list[str], interval: float) -> int:
    failed = 0
    for i, message in enumerate(messages, 1):
        sock.sendall(wrap(message))
        try:
            ack = read_ack(sock)
        except OSError:  # no answer in time, or the other side hung up
            ack = ""
        accepted = "MSA|AA" in ack
        sample = message.split("OBR|", 1)[1].split("|")[2] if "OBR|" in message else "?"
        print(f"[{i}/{len(messages)}] sample {sample}: {'acknowledged (MSA|AA)' if accepted else 'NO acknowledgement: ' + (ack or 'nothing received')}")
        failed += 0 if accepted else 1
        if i < len(messages):
            time.sleep(interval)
    return failed


def connect(address: str, wait: float) -> socket.socket:
    host, _, port = address.rpartition(":")
    deadline = time.time() + wait
    while True:
        try:
            return socket.create_connection((host or "127.0.0.1", int(port)), timeout=5)
        except OSError as e:
            if time.time() >= deadline:
                raise SystemExit(f"Could not connect to {address}: {e}. Is the bridge running with connection = tcp-server?")
            time.sleep(1)


def listen(address: str, wait: float) -> socket.socket:
    host, _, port = address.rpartition(":")
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((host or "127.0.0.1", int(port)))
    server.listen(1)
    server.settimeout(wait)
    print(f"Waiting for the bridge to connect on {address} ...")
    try:
        conn, peer = server.accept()
    except socket.timeout:
        raise SystemExit("The bridge did not connect in time. Is it running with connection = tcp-client?")
    finally:
        server.close()
    print(f"Bridge connected from {peer[0]}.")
    return conn


def parse_args(argv: list[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(prog="python -m dms_bridge.simulator", description="A pretend haematology analyzer that sends HL7 results.")
    where = p.add_mutually_exclusive_group()
    where.add_argument("--connect", metavar="HOST:PORT", help="connect to the bridge (bridge: connection = tcp-server)")
    where.add_argument("--listen", metavar="HOST:PORT", help="wait for the bridge to connect (bridge: connection = tcp-client)")
    where.add_argument("--show", action="store_true", help="print the message and send nothing")
    p.add_argument("--sample", action="append", default=[], help="sample number as printed on the DMS label; repeat for several")
    p.add_argument("--file", help="send this recorded message file instead of making one up")
    p.add_argument("--panel", choices=["3part", "5part"], default="5part", help="3part (WBC, LYM, MID, GRA) or 5part (WBC with NEU, LYM, MON, EOS, BAS)")
    p.add_argument("--codes", choices=["plain", "loinc"], default="plain", help="plain: WBC.  loinc: 6690-2^WBC^LN (code, then name)")
    p.add_argument("--units", choices=["si", "cumm"], default="si", help="si: WBC 7.20 10*9/L.  cumm: WBC 7200 /cumm")
    p.add_argument("--abnormal", action="store_true", help="make Hb, WBC, platelets and MCV abnormal, to check flags")
    p.add_argument("--histograms", action="store_true", help="also send a histogram image segment, which DMS must skip")
    p.add_argument("--patient", default="Test^Patient", help="patient name as Family^Given")
    p.add_argument("--interval", type=float, default=1.0, help="seconds between messages")
    p.add_argument("--wait", type=float, default=30.0, help="seconds to wait for the bridge")
    args = p.parse_args(argv)
    if not args.file and not args.sample:
        p.error("give at least one --sample (the sample number on the label) or a --file")
    if not args.show and not args.connect and not args.listen:
        p.error("choose --connect, --listen or --show")
    return args


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    if args.file:
        with open(args.file, encoding="utf-8", errors="replace") as handle:
            messages = [handle.read().replace("\r\n", "\r").replace("\n", "\r")]
    else:
        messages = [
            build_message(s, kind=args.panel, units=args.units, code_style=args.codes, abnormal=args.abnormal,
                          histograms=args.histograms, patient=args.patient)
            for s in args.sample
        ]

    if args.show:
        for m in messages:
            print(m.replace("\r", "\n"))
        return 0

    sock = connect(args.connect, args.wait) if args.connect else listen(args.listen, args.wait)
    try:
        failed = send_all(sock, messages, args.interval)
    finally:
        sock.close()
    print("Done." if not failed else f"{failed} message(s) were not acknowledged.")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
