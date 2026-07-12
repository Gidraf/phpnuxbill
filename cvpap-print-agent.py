#!/usr/bin/env python3
"""
cvpap-print-agent.py — runs on a computer next to ANY printer.

It bridges any local printer (Epson, HP, Canon, Brother… USB / WiFi / network)
to the internet: it polls CVPAP for paid/queued print jobs, downloads each file,
prints it on the chosen printer, and marks it done. The customer uploads + pays
on the captive portal; this makes the paper come out. It also self-registers the
printer so it appears on the portal automatically — no API calls by hand.

SETUP (once):
  1. Make sure the printer works normally from this computer (print any PDF).
  2. See which printers this computer has:
        python3 cvpap-print-agent.py --list-printers
  3. In the CVPAP dashboard: WiFi -> Printers -> "Print agent" -> copy the token.
  4. Run it (leave it running). Pick a specific printer, or omit PRINTER_NAME to
     use the OS default:
        CVPAP_API_BASE=https://api.ajiriwa.gidraf.dev \
        CVPAP_PRINT_TOKEN=<paste-token> \
        PRINTER_NAME="HP LaserJet 1020"   \
        PRINTER_LABEL="Reception printer" \
        python3 cvpap-print-agent.py
     (Windows: `set` the variables, then `python cvpap-print-agent.py`.)

  Optional: AUTO_PRINT=0 to only download jobs into ./cvpap-print-queue for the
  operator to print manually instead of printing automatically.

Dependencies: just Python 3 + `requests`  (pip install requests). Printing uses
the OS: `lp` on macOS/Linux, PowerShell on Windows — so it works with any
printer/driver the OS supports.
"""
import os
import platform
import subprocess
import sys
import tempfile
import time

try:
    import requests
except ImportError:
    sys.exit("Please install requests:  pip install requests")

API_BASE = os.environ.get("CVPAP_API_BASE", "https://api.ajiriwa.gidraf.dev").rstrip("/")
TOKEN = os.environ.get("CVPAP_PRINT_TOKEN", "").strip()
POLL_SECONDS = int(os.environ.get("POLL_SECONDS", "8"))
AUTO_PRINT = os.environ.get("AUTO_PRINT", "1") != "0"
QUEUE_DIR = os.environ.get("QUEUE_DIR", os.path.join(os.getcwd(), "cvpap-print-queue"))
IS_WINDOWS = platform.system() == "Windows"
# Which local printer to use (ANY brand). Empty = the OS default printer.
PRINTER_NAME = os.environ.get("PRINTER_NAME", "").strip()
PRINTER_LABEL = os.environ.get("PRINTER_LABEL", "").strip()   # customer-facing name


def list_os_printers():
    """Every printer this computer knows about — any brand, USB/WiFi/network."""
    names = []
    try:
        if IS_WINDOWS:
            out = subprocess.check_output(
                ["powershell", "-NoProfile", "-Command", "Get-Printer | Select-Object -ExpandProperty Name"],
                text=True, timeout=30)
            names = [l.strip() for l in out.splitlines() if l.strip()]
        else:
            out = subprocess.check_output(["lpstat", "-a"], text=True, timeout=30)
            names = [l.split()[0] for l in out.splitlines() if l.strip()]
    except Exception as exc:
        print(f"(could not list printers: {exc})")
    return names


def default_os_printer():
    try:
        if IS_WINDOWS:
            out = subprocess.check_output(
                ["powershell", "-NoProfile", "-Command",
                 "Get-CimInstance Win32_Printer | Where-Object {$_.Default} | Select-Object -ExpandProperty Name"],
                text=True, timeout=30)
            return out.strip() or None
        out = subprocess.check_output(["lpstat", "-d"], text=True, timeout=30)
        # "system default destination: <name>"
        return out.split(":", 1)[1].strip() if ":" in out else None
    except Exception:
        return None


if "--list-printers" in sys.argv:
    print("Printers this computer can use:")
    for n in list_os_printers():
        print(f"  - {n}")
    d = default_os_printer()
    print(f"\nDefault: {d or '(none set)'}")
    print("\nRun the agent with  PRINTER_NAME='<one of the above>'  to target a specific printer,")
    print("or leave it unset to use the default.")
    sys.exit(0)

if not TOKEN:
    sys.exit("Set CVPAP_PRINT_TOKEN (get it in the dashboard: WiFi -> Printers -> Print agent).\n"
             "Tip: run with --list-printers first to see this computer's printers.")

# resolve which printer we'll drive, and how it appears to customers
TARGET_PRINTER = PRINTER_NAME or default_os_printer() or ""
DISPLAY_NAME = PRINTER_LABEL or TARGET_PRINTER or "Shop printer"


def _url(path):
    sep = "&" if "?" in path else "?"
    return f"{API_BASE}/api/v1/wifi/print-agent/{path}{sep}token={TOKEN}"


def fetch_jobs():
    r = requests.get(_url("jobs"), timeout=20)
    if r.status_code == 403:
        sys.exit("Token rejected — regenerate it in the dashboard and update CVPAP_PRINT_TOKEN.")
    r.raise_for_status()
    return r.json().get("data", [])


def download(job):
    r = requests.get(_url(f"jobs/{job['id']}/file"), timeout=60)
    r.raise_for_status()
    ext = (job.get("file_type") or "bin").lower()
    fd, path = tempfile.mkstemp(prefix="cvpap-print-", suffix=f".{ext}")
    with os.fdopen(fd, "wb") as f:
        f.write(r.content)
    return path


def os_print(path, copies=1, color=False):
    """Send a file to the chosen printer (any brand). Returns True on success."""
    try:
        if IS_WINDOWS:
            if TARGET_PRINTER:
                ps = (f'$d=(Get-CimInstance Win32_Printer|?{{$_.Default}}).Name;'
                      f'try{{(Get-Printer -Name "{TARGET_PRINTER}")|Out-Null;'
                      f'Set-Printer -Name "{TARGET_PRINTER}" -IsDefault $true}}catch{{}};'
                      f'Start-Process -FilePath "{path}" -Verb Print -WindowStyle Hidden;'
                      f'Start-Sleep -Seconds 3;'
                      f'if($d){{Set-Printer -Name $d -IsDefault $true}}')
            else:
                ps = f'Start-Process -FilePath "{path}" -Verb Print -WindowStyle Hidden'
            subprocess.run(["powershell", "-NoProfile", "-Command", ps], check=True, timeout=120)
        else:
            # macOS / Linux (CUPS). -d target printer, -n copies.
            cmd = ["lp"]
            if TARGET_PRINTER:
                cmd += ["-d", TARGET_PRINTER]
            cmd += ["-n", str(max(1, copies))]
            if not color:
                cmd += ["-o", "ColorModel=Gray"]
            cmd.append(path)
            subprocess.run(cmd, check=True, timeout=120)
        return True
    except Exception as exc:
        print(f"  ! print failed: {exc}")
        return False


def register_printer():
    """Tell CVPAP which printer this agent drives so it shows on the portal.
    Idempotent — safe to call on every startup."""
    try:
        r = requests.post(_url("register-printer"), json={
            "os_printer_name": TARGET_PRINTER or "default",
            "name": DISPLAY_NAME,
            "platform": platform.system(),
            "supports_color": True,
        }, timeout=20)
        if r.ok:
            print(f"Registered printer '{DISPLAY_NAME}' "
                  f"(driving: {TARGET_PRINTER or 'OS default'}).")
        else:
            print(f"(printer register returned {r.status_code}: {r.text[:120]})")
    except Exception as exc:
        print(f"(could not register printer: {exc})")


def mark(job_id, failed=False, error=""):
    try:
        requests.post(_url(f"jobs/{job_id}/{'done'}"),
                      json={"failed": failed, "error": error}, timeout=20)
    except Exception as exc:
        print(f"  ! could not mark job {job_id}: {exc}")


def claim(job_id):
    try:
        r = requests.post(_url(f"jobs/{job_id}/claim"), timeout=20)
        return r.json().get("data", {}).get("id") and r.json().get("claimed", True)
    except Exception:
        return False


def handle(job):
    jid = job["id"]
    print(f"[job {jid[:8]}] {job.get('file_name')} · {job.get('page_count')}p x{job.get('copies')} "
          f"· {job.get('color_mode')} · {job.get('customer_phone')}")
    if not claim(jid):
        print("  (already taken, skipping)")
        return
    try:
        path = download(job)
    except Exception as exc:
        print(f"  ! download failed: {exc}")
        mark(jid, failed=True, error=f"download: {exc}")
        return

    if not AUTO_PRINT:
        os.makedirs(QUEUE_DIR, exist_ok=True)
        dest = os.path.join(QUEUE_DIR, f"{jid[:8]}-{job.get('file_name')}")
        os.replace(path, dest)
        print(f"  saved for manual printing: {dest}")
        mark(jid)  # consider it handled; operator prints from the folder
        return

    ok = os_print(path, copies=int(job.get("copies") or 1),
                  color=(job.get("color_mode") == "color"))
    try:
        os.remove(path)
    except OSError:
        pass
    if ok:
        print("  ✓ printed")
        mark(jid)
    else:
        mark(jid, failed=True, error="OS print command failed")


def main():
    print(f"CVPAP print agent — polling {API_BASE} every {POLL_SECONDS}s "
          f"(auto-print: {'on' if AUTO_PRINT else 'off'}). Ctrl-C to stop.")
    register_printer()   # self-register so the printer appears on the portal
    while True:
        try:
            jobs = fetch_jobs()
            for job in jobs:
                handle(job)
        except requests.RequestException as exc:
            print(f"(network) {exc}")
        except Exception as exc:
            print(f"(error) {exc}")
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nstopped.")
