#!/usr/bin/env python3
"""Cek konsistensi antar artefak. Jalankan dari akar repo: python3 tools/check_parity.py
Butuh: pip install pyyaml openapi-spec-validator
Gagal (exit 1) bila OpenAPI, SQL, dan PHP tidak sinkron."""
import re
import sys
import yaml
from openapi_spec_validator import validate

spec = yaml.safe_load(open("docs/openapi.yaml", encoding="utf-8"))
validate(spec)
errors = []

api = spec["components"]["schemas"]["ReportStatus"]["enum"]
sql = open("db/schema.sql", encoding="utf-8").read()
sql_enum = re.findall(r"'([a-z_]+)'", re.search(r"CREATE TYPE report_status AS ENUM \((.*?)\);", sql, re.S).group(1))
php = re.findall(r"case \w+ = '([a-z_]+)';", open("core/src/Domain/ReportStatus.php", encoding="utf-8").read())
if api != sql_enum:
    errors.append("enum status OpenAPI != SQL")
if api != php:
    errors.append("enum status OpenAPI != PHP")

rules = set(re.findall(r"new Transition\('(T-[0-9a-z]+)'", open("core/src/Domain/ReportStateMachine.php", encoding="utf-8").read()))
used = {t for p in spec["paths"].values() for op in p.values() if isinstance(op, dict) for t in op.get("x-transition", [])}
unknown = used - rules
if unknown:
    errors.append(f"x-transition tak dikenal di PHP: {sorted(unknown)}")

# Aturan yang dipicu SISTEM (bukan endpoint): wajar tidak punya x-transition.
system_only = {"T-02", "T-03", "T-04", "T-07", "T-14"}
orphan = rules - used - system_only
if orphan:
    errors.append(f"aturan tanpa endpoint dan bukan pemicu sistem: {sorted(orphan)}")

ops = sum(1 for p in spec["paths"].values() for m in p if m in ("get", "post", "patch", "put", "delete"))
print(f"OpenAPI valid: {len(spec['paths'])} path, {ops} operasi; aturan transisi: {len(rules)}")
if errors:
    print("TIDAK SINKRON:\n- " + "\n- ".join(errors))
    sys.exit(1)
print("Sinkron: enum status (OpenAPI = SQL = PHP) dan ID transisi.")
