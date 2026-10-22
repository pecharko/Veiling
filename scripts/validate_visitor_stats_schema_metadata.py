#!/usr/bin/env python3
"""Validate sanitized, independently obtained production information_schema rows.

This does not connect to production and never asserts that input is authentic.
"""
import json
import sys

REQUIRED = {
    "visitor_stats_daily": {"visit_date", "page_kind", "visitor_id", "views", "lot_id"},
    "visitor_stats_day_totals": {"visit_date", "visitor_days", "views", "lot_views"},
    "visitor_stats_lot_totals": {"visit_date", "lot_id", "visitor_days", "views"},
}
DATE = {"date"}
INTEGER = {"tinyint", "smallint", "mediumint", "int", "integer", "bigint"}
def check(rows):
    if not isinstance(rows, list) or len(rows) > 100:
        raise ValueError("Invalid limited metadata list")
    seen = {}
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError("Non-object schema row")
        table = row.get("table_name")
        column = row.get("column_name")
        dtype = str(row.get("data_type", "")).lower()
        key = str(row.get("column_key", ""))
        if table not in REQUIRED or not isinstance(column, str) or not column.isidentifier():
            raise ValueError("Unexpected metadata record")
        pair = (table, column)
        if pair in seen:
            raise ValueError("Duplicated column metadata")
        seen[pair] = (dtype, key)
    for table, columns in REQUIRED.items():
        missing = columns - {column for t, column in seen if t == table}
        if missing:
            raise ValueError("Missing columns in " + table + ": " + ", ".join(sorted(missing)))
        dtype, _ = seen[(table, "visit_date")]
        if dtype not in DATE:
            raise ValueError("Unexpected visit_date type in " + table)
        for column in columns & {"views", "visitor_days", "lot_views", "lot_id"}:
            if seen[(table, column)][0] not in INTEGER:
                raise ValueError("Unexpected numeric type for " + table + "." + column)
    if seen[("visitor_stats_daily", "visit_date")][1] != "PRI":
        raise ValueError("Daily visit_date must belong to PRIMARY index")
    print(json.dumps({"status": "SCHEMA_STRUCTURE_MATCH", "tables": sorted(REQUIRED),
                      "metadata_origin_verified": False,
                      "production_release_authorized": False,
                      "note": "Manual schema metadata still requires independent provenance review"}, sort_keys=True))

if __name__ == "__main__":
    try:
        check(json.loads(sys.stdin.read()))
    except (ValueError, KeyError, TypeError) as exc:
        print("SCHEMA STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
