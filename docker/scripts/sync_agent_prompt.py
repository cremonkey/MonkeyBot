#!/usr/bin/env python3
"""Push an AI agent profile's prompt into every store that also holds a copy of it.

The bot's business prompt for a page can live in FOUR places (SPEC-22 notes):

  1. ai_agent_profiles.sales_system_prompt      <- the source of truth, edited in AI Agents
  2. messenger_bot.message                       <- the RUNTIME template the webhook reads
  3. visual_flow_builder_campaign.json_data      <- the Flow Builder UI's copy of the same
  4. page_response_autoreply.ai_training_data    <- comment campaigns (not touched here)

2 and 3 must agree or the Flow Builder will show one thing and the bot will say another.
2 lands in the prompt as the CAMPAIGN layer, so a stale copy there silently overrides a
fixed profile — that is exactly what happened on Kemzo (2026-07-16): the profile was
corrected and Messenger kept using the old two-price example from the flow.

Usage (from the host):
  docker exec -i monkeybot-db-1 ... # not needed; this talks to the DB itself
  python3 docker/scripts/sync_agent_prompt.py --profile 6 --bots 71,72 --flows 66,67
  python3 docker/scripts/sync_agent_prompt.py --profile 6 --bots 71,72 --flows 66,67 --dry-run

Every write is verified by reading the row back and comparing character count AND the
carriage-return count: these prompts are CRLF, and both python text mode and the mariadb
client silently strip CR, which deletes ~1 char per line without any error.
"""
import argparse, json, subprocess, sys

DB_CONTAINER = "monkeybot-db-1"
DB_NAME = "monkeybot"


def sql(query, raw=False):
    """Run SQL in the db container. utf8mb4 is mandatory: the client defaults to utf8mb3
    and turns every emoji into four literal '?' on the way in.

    Deliberately NOT text=True: python's universal-newline decoding rewrites every CRLF
    in the captured output to LF. These prompts are CRLF, so text=True silently drops
    ~1 char per line — and if you then write that back and compare it against itself, the
    check passes while the data is already damaged. Read bytes, decode by hand.
    """
    flags = "-N --raw" if raw else "-N"
    cmd = ['docker', 'exec', DB_CONTAINER, 'sh', '-c',
           'mariadb --default-character-set=utf8mb4 -u root -p"$MARIADB_ROOT_PASSWORD" %s %s -e %s'
           % (DB_NAME, flags, json_shell_quote(query))]
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode != 0:
        raise RuntimeError("SQL failed: %s" % r.stderr.decode("utf-8", "replace").strip()[:300])
    return r.stdout.decode("utf-8")


def json_shell_quote(s):
    return "'" + s.replace("'", "'\"'\"'") + "'"


def sql_literal(s):
    """Escape into a ONE-LINE literal. Raw CR must never reach the client: it reads the
    script line by line and strips CR, silently shortening the value."""
    return (s.replace("\\", "\\\\").replace("'", "\\'")
             .replace("\r", "\\r").replace("\n", "\\n"))


def run_update(stmt):
    if "\r" in stmt:
        raise RuntimeError("statement contains a raw CR; the client would strip it — escape as \\r")
    p = subprocess.Popen(
        ['docker', 'exec', '-i', DB_CONTAINER, 'sh', '-c',
         'mariadb --default-character-set=utf8mb4 -u root -p"$MARIADB_ROOT_PASSWORD" ' + DB_NAME],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    out, err = p.communicate((stmt + "\n").encode("utf-8"))
    if p.returncode != 0:
        raise RuntimeError("update failed: %s" % err.decode("utf-8", "replace").strip()[:300])


def fetch_text(table, col, row_id):
    return sql("SELECT `%s` FROM `%s` WHERE id=%d;" % (col, table, row_id), raw=True)


def stats(s):
    return len(s), s.count("\r")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--profile", type=int, required=True)
    ap.add_argument("--bots", default="", help="messenger_bot ids, comma separated")
    ap.add_argument("--flows", default="", help="visual_flow_builder_campaign ids, comma separated")
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()

    prompt = fetch_text("ai_agent_profiles", "sales_system_prompt", a.profile)
    if prompt.endswith("\n") and not prompt.endswith("\r\n"):
        prompt = prompt[:-1]          # the client's own trailing newline, not the data's
    n, cr = stats(prompt)
    if n == 0:
        sys.exit("profile %d has an empty sales_system_prompt — refusing to wipe the templates" % a.profile)
    print("source: ai_agent_profiles %d -> %d chars, %d CR" % (a.profile, n, cr))

    for bid in [int(x) for x in a.bots.split(",") if x.strip()]:
        raw = fetch_text("messenger_bot", "message", bid)
        d = json.loads(raw)
        try:
            msg = d["out"][0]["reply"]["webhook_response"][0]["message"]
        except (KeyError, IndexError):
            print("  ! messenger_bot %d is not an AI template (no out/reply/webhook_response) — skipped" % bid)
            continue
        if msg.get("text_from") != "AI":
            print("  ! messenger_bot %d has text_from=%r, not AI — skipped" % (bid, msg.get("text_from")))
            continue
        msg["text"] = prompt
        if a.dry_run:
            print("  would update messenger_bot %d" % bid); continue
        run_update("UPDATE `messenger_bot` SET `message`='%s' WHERE `id`=%d;"
                   % (sql_literal(json.dumps(d, ensure_ascii=False)), bid))
        back = json.loads(fetch_text("messenger_bot", "message", bid))
        t = back["out"][0]["reply"]["webhook_response"][0]["message"]["text"]
        ok = stats(t) == (n, cr)
        print("  messenger_bot %d -> %d chars, %d CR  %s" % (bid, len(t), t.count("\r"), "OK" if ok else "MISMATCH!"))
        if not ok:
            sys.exit("write was not faithful — stopping before more damage")

    for fid in [int(x) for x in a.flows.split(",") if x.strip()]:
        d = json.loads(fetch_text("visual_flow_builder_campaign", "json_data", fid))
        node = next((v for v in d.get("nodes", {}).values() if v.get("name") == "Open AI"), None)
        if node is None:
            print("  ! flow %d has no 'Open AI' node — skipped" % fid); continue
        node["data"]["textMessage"] = prompt
        if a.dry_run:
            print("  would update flow %d" % fid); continue
        run_update("UPDATE `visual_flow_builder_campaign` SET `json_data`='%s' WHERE `id`=%d;"
                   % (sql_literal(json.dumps(d, ensure_ascii=False)), fid))
        back = json.loads(fetch_text("visual_flow_builder_campaign", "json_data", fid))
        t = next(v for v in back["nodes"].values() if v.get("name") == "Open AI")["data"]["textMessage"]
        ok = stats(t) == (n, cr)
        print("  flow %d -> %d chars, %d CR  %s" % (fid, len(t), t.count("\r"), "OK" if ok else "MISMATCH!"))
        if not ok:
            sys.exit("write was not faithful — stopping before more damage")

    print("done — every copy now matches profile %d" % a.profile)


if __name__ == "__main__":
    main()
