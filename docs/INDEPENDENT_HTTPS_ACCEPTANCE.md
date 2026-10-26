# Independent HTTPS acceptance for OVH Application Firewall

## Known fact
OVH Application Firewall in Hosting Configuration rejects GitHub-hosted runners with HTTP 403. Confirmed by workflow runs 40327048692 and 40326235254. A 403 from those runners does **not** establish public downtime and cannot count as a passing smoke test.

## Candidate acceptance path (not yet authorized to replace release gates)
1. Keep OVH Application Firewall enabled; never spoof origin or bypass access controls.
2. Run `bash scripts/independent_https_acceptance.sh` from a trusted user-operated machine on a normal client network, both **before** and **after** any prospective release. Record timestamp, network context and the command's output (including exact HTTP statuses). Optionally compare in a regular browser.
3. Keep GitHub Actions CI, exact commit, one-file allowlist, pinned-hostkey read-only SFTP hashes, drift gate, code backup, rollback and SHA-256 readback as separate mandatory evidence.
4. Record the known runner HTTP 403 as *blocked/unverified*, never success. An independent local check is not proof of runner reachability, nor a GitHub Actions success.
5. A future deployment workflow could require an explicit manual approval checkpoint **after** independent pre-check, and an independent post-check before release acceptance. Such a change to an existing HTTP gate is security-sensitive and requires separate reviewed PR and new exact SHA-specific production authorization. Do not modify the deployed workflow as part of this read-only proposal.
6. If the independent post-check fails, invoke the existing approved rollback/recovery procedure and assess service health; never mark a release verified on SFTP checks alone.

## Limits
This tool only validates the publicly reachable homepage and login endpoint, not snapshot restoration, image visibility, or admin authorization. It has no production write access. A local script run without trusted evidence must not automatically authorize a GitHub deployment.
