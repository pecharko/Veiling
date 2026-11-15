#!/usr/bin/env python3
"""Read-only release administration report, never a release-closure authority.

Only authenticated upstream systems may establish real provenance. This helper
summarizes evidence availability and keeps releases OPEN unless external
attestations are authenticated by a separately reviewed trust process.
"""
import json
import sys
from release_b_status import summarize

def report(run, artifacts):
    status = summarize(run, artifacts)
    lines = [
        "## Releaseadministratie — bewijsstatus",
        "",
        f"**Run:** `{status['run_id']}` | **Commit:** `{status['release_sha']}`",
        "",
        "| Controle | Status |",
        "|---|---|",
        f"| Deployment-run | {'Geslaagd' if status['deployment_job_success'] else 'Mislukt of onvolledig'} |",
        f"| Evidenceartifact aanwezig | {'Ja' if status['evidence_artifact_available'] else 'Nee'} |",
        f"| Rollbackartifact aanwezig | {'Ja' if status['rollback_artifact_available'] else 'Nee'} |",
        "| Authenticiteit uploader-inhoud | Nog niet vastgesteld in dit rapport |",
        "| Onafhankelijke OVH-readback | PENDING |",
        "| Externe browseracceptatie | PENDING |",
        "| Releaseadministratie | **OPEN** |",
        "",
        "Een groen CI-resultaat, artifactnaam of zelfverklaarde oorsprong is geen onafhankelijke "
        "OVH-controle en sluit de release niet af.",
    ]
    return "\n".join(lines) + "\n"

def main():
    payload = json.load(sys.stdin)
    if not isinstance(payload, dict) or set(payload) != {"run", "artifacts"}:
        raise ValueError("Invalid report payload")
    sys.stdout.write(report(payload["run"], payload["artifacts"]))

if __name__ == "__main__":
    main()
