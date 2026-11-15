# Niveau B — lokale downloadvergelijking, geen onafhankelijke attestatie

Deze procedure vergelijkt uitsluitend **vier al lokaal gedownloade** OVH-bestanden
met de bestanden van een **exacte, onveranderlijke Git-commit**. Het hulpmiddel
maakt zelf **geen** SFTP-/OVH-verbinding, wijzigt niets op de server, leest geen
credentials en publiceert geen PHP-/CSS-bestandsinhoud.

## Gebruik

Download vooraf met de bestaande OVH-client de vier bestanden in een lokale map
met dezelfde paden als in de repository:

- `catalogus-public/admin/admin.css`
- `catalogus-public/admin/verkoper.php`
- `catalogus-public/admin/verkopers.php`
- `private/lot_sellers.php`

Voer vanuit de bestaande `Veiling`-clone op macOS uit:

```sh
python3 scripts/release_b_local_readback_report.py \
  "$HOME/Downloads/OVH-readback-20261115" \
  "$PWD" \
  --commit c51d4abec94ed9ec21e0672390b6c84d14c3e0c8
```

Het programma leest alleen de bovengenoemde, lokaal aanwezige reguliere
bestanden en de bijbehorende Git-blobs (`git show <commit>:<path>`).
Niet-bestaande/lege/grote bestanden, symlinks en ongeldige of ontbrekende
commitversies stoppen de controle. Het JSON-rapport verschijnt alleen op
stdout, met `comparison=MATCH` of `MISMATCH`. Een afwijking resulteert
in exitcode 1. Er wordt geen rapportbestand aangemaakt en er wordt niets
automatisch geupload.

## Wat deze controle wel en niet bewijst

`MATCH` betekent: de opgegeven lokaal gedownloade bytes zijn gelijk aan de
Git-bronbytes van die commit. Het bewijst **niet** wie de download uitvoerde,
van welk OVH-account de bytes kwamen, wanneer de server deze bytes had, of
wat onmiddellijk na de historische release
`artist-three-statuses-20261114` in productie stond.

Omdat OVH Starter geen afzonderlijk bewezen read-only SFTP-account biedt,
mag de bestaande schrijfgerechtigde SFTP-identiteit **niet** worden voorgesteld
als een onafhankelijk, alleen-lezen account. De historisch geauthenticeerde
GitHub-release-joblogbinding blijft een apart bewijs. Ook een geslaagde lokale
browserproef is geen zelfstandig geauthenticeerde browserherkomst.

Daarom blijven in het rapport onvoorwaardelijk:
`download_origin_authenticated=false`,
`separate_read_only_sftp_identity_verified=false`,
`historical_release_readback_proven=false`,
`independent_ovh_readback=PENDING`, `release_administration=OPEN`.
Dit rapport is **geen releasecloseout, niet geschikt als vervanging van een
geauthenticeerde onafhankelijke SFTP-readback** en geeft nooit merge-, workflow-
of productietoestemming.

Offline tests (fictieve lokale Git-repository en bytes):

```sh
python3 -B -m unittest discover -s tests -p 'release_b_local_readback_report_test.py' -v
```
