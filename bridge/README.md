# DMS lab bridge

A small program for the lab PC that sits next to an analyzer. It takes
every result message the machine sends and delivers it to the clinic's DMS
server, where it is matched to the sample and saved as a draft result.

- Talks to the machine over a **serial (RS-232) cable** or the **network**.
- Speaks the machine's link protocol: **ASTM E1381/E1394** (ENQ/ACK frames
  with checksums) or **HL7 v2 over MLLP** (with an HL7 acknowledgement for
  every message).
- Keeps every message in a local queue on disk first, so nothing is lost if
  the internet or the server is down or the PC restarts; it sends the queue
  as soon as it can (retrying with growing waits, up to 5 minutes).
- Sends over HTTPS with the machine's own key. It never decides anything
  about results; the server reads and matches them.

## What you need

- A Windows or Linux PC connected to the machine (cable or network) and to
  the internet.
- Python 3.10 or newer (python.org; tick "Add python.exe to PATH").
- For a serial cable only: `pip install -r requirements.txt` (pyserial).
- In DMS, under **Lab machines > Machines**, add the machine and copy its key
  (shown once), then map its test codes under **Lab machines > Test codes**.
- The machine's interface (LIS / host) settings from its manual: protocol,
  and for a cable the port speed (baud), data bits, parity and stop bits;
  for the network the IP address and port, and who connects to whom.

## Set up

1. Copy this `bridge` folder to the PC, for example to `C:\dms-bridge`.
2. Copy `bridge.example.ini` to `bridge.ini` and fill it in: the clinic's
   DMS address, the machine key, the protocol and the connection.
3. Try it in a window first:

   ```
   cd C:\dms-bridge
   python -m dms_bridge bridge.ini
   ```

   Run a test or a control on the machine. The window shows "Message …
   received" and then "Message … sent"; the result appears as a draft in the
   lab work list, or in DMS under **Lab machines > Machine inbox** with the
   reason it was not saved (most often a test code that is not mapped yet).
4. Start it with Windows: in **Task Scheduler**, create a task that runs
   `python -m dms_bridge C:\dms-bridge\bridge.ini` in `C:\dms-bridge`, "At
   startup", "Run whether user is logged on or not", and "If the task fails,
   restart every 1 minute".

The log is in the data folder (`bridge.log`, kept small automatically), and
so is the queue (`outbox.sqlite3`). Sent messages are removed from the queue
after 30 days.

## Connections

| Setting | When |
|---|---|
| `connection = serial` | The machine has an RS-232 cable to this PC (`port = COM1`, …). |
| `connection = tcp-server` | The machine connects to this PC over the network (`listen_port`). Allow that port in the Windows firewall. |
| `connection = tcp-client` | This PC connects to the machine (`machine_host`, `machine_port`). |

## Tests

```
cd bridge
python -m unittest discover -s tests -t .
```

The tests need no machine and no extra packages: they use a fake port, a
fake machine on the network, and a fake DMS server.
