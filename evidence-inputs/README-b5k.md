# B5k read-only evidence input — sold-postit-20261028

This is an **auditable transcription of an existing GitHub B4 readback job log**, not a fresh OVH verification or permission to run a workflow.

- Deployment run: https://github.com/pecharko/Veiling/actions/runs/40646499593
- Read-only B4 verification run: https://github.com/pecharko/Veiling/actions/runs/40660372269
- Source readback job: https://github.com/pecharko/Veiling/actions/runs/40660372269/job/122721609873
- The B4 job printed a complete JSON observation around **2026-10-28 08:56:25 UTC**. Values in the sibling JSON are transcribed from that output, including the three matched SHA-256 hashes.
- Release commit: `6b8860ec72785895d757db30512d2bfce012f667`; release run `40646499593`.
- Original authorized verification main: `8ec47b9314096c8d67aa8b454a4f278b4e82014e`.
- **Actual B4 GitHub run main:** `5bfaa86d2f25bf3bd7f9c8ff80c7435e5fc9ce94`, **not the authorized SHA**. This is a known mismatch; previous limited technical evidence acceptance does not authorize a `VERIFIED` verdict against the original main.
- The B4 observation says `independent_execution_authenticated: false` and `browser_acceptance: PENDING`. Never elevate either flag or infer approval from a successful Actions run.
- The workflow requires an opt-in manual `workflow_dispatch` with the exact input path and `report_mode: github-readonly`; **creating this PR does not dispatch it**. The tool may produce `MISMATCH` or `INCOMPLETE` because of commit drift and API metadata limitations. These are valid fail-closed outcomes.
- **No OVH, database, production, credentials, workflow rights or deployment changes**.

The input JSON is not an endorsement of the claimed OVH state: it is a source-linked record of the old B4 log.
