#!/usr/bin/env python3
"""Edit the local .env without ever printing a secret value.

Usage:
  scripts/dev-env.py status KEY [KEY...]        filled/empty and length only
  scripts/dev-env.py gen KEY BYTES [--force]    set KEY to random hex (only if empty, unless --force)
  scripts/dev-env.py default KEY VALUE          set a NON-secret value only if KEY is absent/empty
  scripts/dev-env.py put KEY VALUE              set a NON-secret value (e.g. APP_URL)

Secrets are generated here (os.urandom) and written straight to .env, so they never appear in a
command line, shell history or `ps`. Output is limited to "filled/empty (N chars)".
"""
import os
import re
import secrets
import sys
import tempfile
from pathlib import Path

ENV = Path(__file__).resolve().parent.parent / ".env"


def read_lines():
    return ENV.read_text().splitlines() if ENV.exists() else []


def get(key):
    for line in read_lines():
        m = re.match(rf"^{re.escape(key)}=(.*)$", line)
        if m:
            return m.group(1).strip().strip('"').strip("'")
    return None


def put(key, value):
    lines = read_lines()
    for i, line in enumerate(lines):
        if re.match(rf"^{re.escape(key)}=", line):
            lines[i] = f"{key}={value}"
            break
    else:
        lines.append(f"{key}={value}")
    fd, tmp = tempfile.mkstemp(dir=ENV.parent, prefix=".env.")
    with os.fdopen(fd, "w") as fh:
        fh.write("\n".join(lines) + "\n")
    os.chmod(tmp, ENV.stat().st_mode if ENV.exists() else 0o600)
    os.replace(tmp, ENV)


def describe(key):
    v = get(key)
    if v is None:
        return f"{key}: tidak ada"
    if v == "":
        return f"{key}: kosong"
    return f"{key}: terisi ({len(v)} karakter)"


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 2
    cmd, args = argv[1], argv[2:]
    if cmd == "status":
        for key in args:
            print(describe(key))
    elif cmd == "gen":
        key, nbytes, force = args[0], int(args[1]), "--force" in args
        if get(key) and not force:
            print(describe(key) + " (dibiarkan)")
        else:
            put(key, secrets.token_hex(nbytes))
            print(describe(key) + " (dibuat)")
    elif cmd == "default":
        key, value = args[0], args[1]
        if get(key):
            print(describe(key) + " (dibiarkan)")
        else:
            put(key, value)
            print(f"{key}: diset")
    elif cmd == "put":
        put(args[0], args[1])
        print(f"{args[0]}: diset")
    else:
        print(__doc__)
        return 2
    return 0


sys.exit(main(sys.argv))
