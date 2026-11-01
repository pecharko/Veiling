#!/usr/bin/env python3
"""Pure read-only release handoff report for CI summaries.

No authentication, workflow dispatch, repository write, or OVH access.
This consumes only separately collected trusted facts, not owner authorization.
"""
import json
import re
if __package__:
    from .release_handoff_state import evaluate
else:
    from release_handoff_state import evaluate

SAFE = re.compile(r"[^A-Za-z0-9_ .:()/-]")


def summary(facts):
    decision = evaluate(facts)
    action = decision["action"]
    reason = SAFE.sub("_", decision["reason"])[:250]
    rid = decision["release_id"]
    lines = [
        "### Release handoff advisory (read-only)",
        "- Release: `" + rid + "`",
        "- Next step: **" + action + "**",
        "- Reason: " + reason,
        "- Writes, merge and dispatch: **not authorized by this report**",
    ]
    if action == "WAIT_FOR_MAIN_CI":
        lines.append("- Do not request a `go`; refresh the independent push-CI evidence")
    if action == "OWNER_DISPATCH_REQUIRED":
        lines.append("- Use only the existing owner-authenticated controller or one approved manual Actions launch")
    if action == "EVIDENCE_REVIEW_OPEN":
        lines.append("- Browser acceptance does not close independent rollback/OVH evidence findings")
    return "\n".join(lines) + "\n"


def main():
    import argparse
    from pathlib import Path
    parser = argparse.ArgumentParser()
    parser.add_argument("facts", type=Path)
    args = parser.parse_args()
    print(summary(json.loads(args.facts.read_text(encoding="utf-8"))), end="")


if __name__ == "__main__":
    main()
